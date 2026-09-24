<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductMedia;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use Psr\Http\Message\ResponseInterface;

/**
 * Talks to the Higgsfield generation API. Credentials only travel in the Authorization header of
 * calls to the API host and never appear in results or log lines.
 */
class HiggsfieldClient
{
    public const XML_PATH_KEY_ID = 'mago/higgsfield/api_key_id';
    public const XML_PATH_KEY_SECRET = 'mago/higgsfield/api_key_secret';

    private const BASE_URL = 'https://api.higgsfield.ai/';
    private const TIMEOUT = 30;
    private const DOWNLOAD_TIMEOUT = 120;

    /** Messages for HTTP status codes the API documents. */
    private const STATUS_ERRORS = [
        401 => 'Higgsfield rejected the API key. Check the key ID and secret in the configuration.',
        403 => 'The Higgsfield account has not enough credits for this request.',
        404 => 'Higgsfield does not know this request or model for the configured account.',
        423 => 'The Higgsfield model is temporarily blocked. Try again later.',
        503 => 'The Higgsfield model is not available right now. Try again later.',
    ];

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly Json $json,
        private readonly ErrorLogger $errorLogger
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->keyId() !== '' && $this->keySecret() !== '';
    }

    /**
     * Queues a generation; the result holds request_id and status, or error.
     *
     * @param string $endpoint Model endpoint, e.g. "marketing-studio/image"
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function submit(string $endpoint, array $body): array
    {
        return $this->call('POST', $endpoint, $body);
    }

    /**
     * Cost of a generation with these parameters, or null when the API gives no estimate.
     *
     * @param string $endpoint
     * @param array<string,mixed> $body
     * @return array{credits:string,usd:string}|null
     */
    public function estimate(string $endpoint, array $body): ?array
    {
        $result = $this->call('POST', 'estimate/' . $endpoint, $body);
        if (isset($result['error']) || !isset($result['credits'])) {
            return null;
        }

        return ['credits' => (string)$result['credits'], 'usd' => (string)($result['usd'] ?? '')];
    }

    /**
     * Current state and, when completed, the output URLs of a request.
     *
     * @param string $requestId
     * @return array<string,mixed>
     */
    public function status(string $requestId): array
    {
        return $this->call('GET', 'requests/' . rawurlencode($requestId) . '/status');
    }

    /**
     * Uploads an input image to Higgsfield storage and returns its public URL.
     *
     * @param string $contents
     * @param string $contentType
     * @return array<string,string> public_url, or error
     */
    public function uploadImage(string $contents, string $contentType): array
    {
        $upload = $this->call('POST', 'files/generate-upload-url', ['content_type' => $contentType]);
        if (isset($upload['error'])) {
            return ['error' => $upload['error']];
        }

        $uploadUrl = (string)($upload['upload_url'] ?? '');
        $publicUrl = (string)($upload['public_url'] ?? '');
        if (!$this->isHttps($uploadUrl) || !$this->isHttps($publicUrl)) {
            return ['error' => 'Higgsfield returned no upload URL.'];
        }

        $headers = array_map('strval', (array)($upload['upload_headers'] ?? ['Content-Type' => $contentType]));

        try {
            // Presigned storage URL: the API credentials are deliberately not sent here.
            $response = $this->httpClient->request('PUT', $uploadUrl, [
                'headers' => $headers,
                'body' => $contents,
                'timeout' => self::DOWNLOAD_TIMEOUT,
                'connect_timeout' => 10,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            $this->errorLogger->addLog('Higgsfield', 'Upload failed: ' . $e->getMessage());
            return ['error' => 'Uploading the product image to Higgsfield failed.'];
        }

        if ($response->getStatusCode() >= 300) {
            $this->errorLogger->addLog('Higgsfield', 'Upload HTTP ' . $response->getStatusCode());
            return ['error' => 'Uploading the product image to Higgsfield failed.'];
        }

        return ['public_url' => $publicUrl];
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
     * Calls the API with the configured key.
     *
     * @param string $method
     * @param string $path
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Higgsfield is not configured.'];
        }

        $options = [
            'headers' => [
                'Authorization' => 'Key ' . $this->keyId() . ':' . $this->keySecret(),
                'Accept' => 'application/json',
            ],
            'timeout' => self::TIMEOUT,
            'connect_timeout' => 10,
            'http_errors' => false,
        ];
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = $this->json->serialize($body);
        }

        try {
            $response = $this->httpClient->request($method, self::BASE_URL . $path, $options);
        } catch (GuzzleException $e) {
            $this->errorLogger->addLog('Higgsfield', $method . ' ' . $path . ': ' . $e->getMessage());
            return ['error' => 'Higgsfield could not be reached.'];
        }

        return $this->decode($response, $method . ' ' . $path);
    }

    /**
     * Decoded body of a successful response, or a readable error.
     *
     * @param ResponseInterface $response
     * @param string $context
     * @return array<string,mixed>
     */
    private function decode(ResponseInterface $response, string $context): array
    {
        $status = $response->getStatusCode();
        try {
            $data = $this->json->unserialize((string)$response->getBody());
        } catch (\InvalidArgumentException) {
            $data = null;
        }

        if ($status >= 200 && $status < 300 && is_array($data)) {
            return $data;
        }

        $detail = is_array($data) ? ($data['detail'] ?? '') : '';
        $detail = is_string($detail) ? $detail : (string)$this->json->serialize($detail);
        $this->errorLogger->addLog('Higgsfield', $context . ' HTTP ' . $status . ' ' . $detail);

        if (isset(self::STATUS_ERRORS[$status])) {
            return ['error' => self::STATUS_ERRORS[$status]];
        }
        if (($status === 400 || $status === 422) && $detail !== '') {
            return ['error' => 'Higgsfield refused the request: ' . mb_substr($detail, 0, 300)];
        }

        return ['error' => 'Higgsfield answered with HTTP ' . $status . '.'];
    }

    private function isHttps(string $url): bool
    {
        return str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function keyId(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_KEY_ID));
    }

    private function keySecret(): string
    {
        $value = (string)$this->scopeConfig->getValue(self::XML_PATH_KEY_SECRET);

        return $value === '' ? '' : trim($this->encryptor->decrypt($value));
    }
}
