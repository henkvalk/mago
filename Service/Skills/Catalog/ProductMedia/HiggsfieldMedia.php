<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Higgsfield\McpClient;

/**
 * Generation calls on the Higgsfield MCP server: upload an input image, preflight the cost, submit
 * a job, read its state and download the outputs.
 */
class HiggsfieldMedia
{
    public const TERMINAL_FAILURES = ['failed', 'nsfw', 'canceled', 'ip_detected'];

    private const DOWNLOAD_TIMEOUT = 120;
    private const UUID_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    public function __construct(
        private readonly McpClient $mcp,
        private readonly ClientInterface $httpClient,
        private readonly ErrorLogger $errorLogger
    ) {
    }

    public function isConnected(): bool
    {
        return $this->mcp->isConnected();
    }

    /**
     * Uploads an image to Higgsfield storage and returns its media id.
     *
     * @param string $contents
     * @param string $contentType
     * @param string $filename
     * @return array<string,string> media_id, or error
     */
    public function uploadImage(string $contents, string $contentType, string $filename): array
    {
        $upload = $this->call('media_upload', ['filename' => $filename, 'content_type' => $contentType]);
        if (isset($upload['error'])) {
            return $upload;
        }

        $slot = is_array($upload['uploads'][0] ?? null) ? $upload['uploads'][0] : $upload;
        $uploadUrl = (string)($slot['upload_url'] ?? '');
        $mediaId = (string)($slot['media_id'] ?? '');
        if (!$this->isHttps($uploadUrl) || $mediaId === '') {
            return ['error' => 'Higgsfield returned no upload URL.'];
        }

        try {
            // Presigned storage URL: the OAuth token is deliberately not sent here.
            $response = $this->httpClient->request('PUT', $uploadUrl, [
                'headers' => ['Content-Type' => (string)($slot['content_type'] ?? $contentType)],
                'body' => $contents,
                'timeout' => self::DOWNLOAD_TIMEOUT,
                'connect_timeout' => 10,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            $this->errorLogger->addLog('Higgsfield', 'Upload failed: ' . $e->getMessage());
            return ['error' => 'Uploading the image to Higgsfield failed.'];
        }
        if ($response->getStatusCode() !== 200) {
            $this->errorLogger->addLog('Higgsfield', 'Upload HTTP ' . $response->getStatusCode());
            return ['error' => 'Uploading the image to Higgsfield failed.'];
        }

        $confirm = $this->call('media_confirm', ['type' => 'image', 'media_id' => $mediaId]);

        return isset($confirm['error']) ? $confirm : ['media_id' => $mediaId];
    }

    /**
     * Credits a generation with these parameters would cost, or null when unknown.
     *
     * @param string $tool generate_image or generate_video
     * @param array<string,mixed> $params
     * @return float|null
     */
    public function cost(string $tool, array $params): ?float
    {
        $result = $this->call($tool, ['params' => ['get_cost' => true] + $params]);
        $credits = $result['cost']['credits'] ?? null;

        return is_int($credits) || is_float($credits) ? (float)$credits : null;
    }

    /**
     * Submits one generation, paid with credits, and returns its job id.
     *
     * @param string $tool generate_image or generate_video
     * @param array<string,mixed> $params
     * @return array<string,string> job_id and status, or error
     */
    public function submit(string $tool, array $params): array
    {
        $result = $this->call($tool, ['params' => $params + ['count' => 1, 'use_unlim' => false]]);
        if (isset($result['error'])) {
            return $result;
        }

        $jobId = $this->jobId($result);
        if ($jobId === null) {
            $this->errorLogger->addLog('Higgsfield', $tool . ': no job id in ' . implode(',', array_keys($result)));
            return ['error' => 'Higgsfield accepted the request but returned no job id.'];
        }

        $status = $result['results'][0]['status'] ?? $result['status'] ?? 'queued';

        return ['job_id' => $jobId, 'status' => (string)$status];
    }

    /**
     * State of a job and, when completed, its output URLs.
     *
     * @param string $jobId
     * @return array<string,mixed> status, type and urls, or error
     */
    public function status(string $jobId): array
    {
        $result = $this->call('job_status', ['jobId' => $jobId]);
        if (isset($result['error'])) {
            return $result;
        }

        $generation = is_array($result['generation'] ?? null) ? $result['generation'] : $result;
        if (!isset($generation['status'])) {
            return ['error' => 'Higgsfield returned no status.'];
        }

        return [
            'status' => (string)$generation['status'],
            'type' => (string)($generation['type'] ?? ''),
            'urls' => $this->outputUrls($generation['results'] ?? null),
        ];
    }

    /**
     * Saves a generated output file to a local path.
     *
     * @param string $url
     * @param string $targetPath
     * @return bool
     */
    public function download(string $url, string $targetPath): bool
    {
        if (!$this->isHttps($url)) {
            return false;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'sink' => $targetPath,
                'timeout' => self::DOWNLOAD_TIMEOUT,
                'connect_timeout' => 10,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            $this->errorLogger->addLog('Higgsfield', 'Download failed: ' . $e->getMessage());
            return false;
        }

        return $response->getStatusCode() === 200;
    }

    /**
     * Structured output of a tool call, or a readable error.
     *
     * @param string $tool
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function call(string $tool, array $arguments): array
    {
        if (!$this->mcp->isConnected()) {
            return ['error' => 'Higgsfield is not connected.'];
        }

        $result = $this->mcp->callTool($tool, $arguments);
        if (isset($result['error'])) {
            return ['error' => (string)$result['error']];
        }
        if ($result['is_error'] || $result['structured'] === null) {
            $text = trim((string)$result['text']);
            $this->errorLogger->addLog('Higgsfield', $tool . ': ' . mb_substr($text, 0, 500));
            return [
                'error' => 'Higgsfield refused the request' . ($text !== '' ? ': ' . mb_substr($text, 0, 300) : '.'),
            ];
        }

        return $result['structured'];
    }

    /**
     * Job id from a generate response, which lists the submitted jobs.
     *
     * @param array<string,mixed> $result
     * @return string|null
     */
    private function jobId(array $result): ?string
    {
        $candidates = [
            $result['results'][0]['id'] ?? null,
            $result['job_id'] ?? null,
            $result['jobId'] ?? null,
            $result['id'] ?? null,
            $result['job_ids'][0] ?? null,
            $result['jobs'][0]['job_id'] ?? null,
            $result['jobs'][0]['id'] ?? null,
            $result['jobs'][0] ?? null,
            $result['generation']['id'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match(self::UUID_PATTERN, $candidate) === 1) {
                return strtolower($candidate);
            }
        }

        return null;
    }

    /**
     * Full-size output URLs of a finished job.
     *
     * @param mixed $results
     * @return list<string>
     */
    private function outputUrls(mixed $results): array
    {
        if (!is_array($results)) {
            return [];
        }

        $items = array_is_list($results) ? $results : [$results];
        $urls = [];
        foreach ($items as $item) {
            $url = is_array($item) ? ($item['rawUrl'] ?? $item['url'] ?? null) : $item;
            if (is_string($url) && $this->isHttps($url)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function isHttps(string $url): bool
    {
        return str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
