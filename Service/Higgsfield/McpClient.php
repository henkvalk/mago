<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Higgsfield;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal MCP client for the Higgsfield server over streamable HTTP: initialize, tools/list and
 * tools/call. The bearer token is only sent to the MCP endpoint and never logged.
 */
class McpClient
{
    private const PROTOCOL_VERSION = '2025-06-18';
    private const TIMEOUT = 120;

    /**
     * @var string|null Session id the server assigned at initialize
     */
    private ?string $sessionId = null;

    /**
     * @var int Next JSON-RPC request id
     */
    private int $nextId = 1;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly OAuthService $oauth,
        private readonly Json $json,
        private readonly ErrorLogger $errorLogger
    ) {
    }

    public function isConnected(): bool
    {
        return $this->oauth->isConnected();
    }

    /**
     * Tools the server offers, each with name, description and inputSchema.
     *
     * @return array<string,mixed> tools, or error
     */
    public function listTools(): array
    {
        $tools = [];
        $cursor = null;
        do {
            $result = $this->rpc('tools/list', $cursor !== null ? ['cursor' => $cursor] : []);
            if (isset($result['error'])) {
                return $result;
            }
            $tools = array_merge($tools, (array)($result['tools'] ?? []));
            $cursor = isset($result['nextCursor']) ? (string)$result['nextCursor'] : null;
        } while ($cursor !== null);

        return ['tools' => $tools];
    }

    /**
     * Calls a tool and returns its output: structured content when given, otherwise the text parts.
     *
     * @param string $name
     * @param array<string,mixed> $arguments
     * @return array<string,mixed> structured, text and is_error, or error
     */
    public function callTool(string $name, array $arguments): array
    {
        $result = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object)$arguments]);
        if (isset($result['error'])) {
            return $result;
        }

        $text = [];
        foreach ((array)($result['content'] ?? []) as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text') {
                $text[] = (string)($part['text'] ?? '');
            }
        }

        $structured = $result['structuredContent'] ?? null;
        if ($structured === null && count($text) === 1) {
            try {
                $decoded = $this->json->unserialize($text[0]);
                $structured = is_array($decoded) ? $decoded : null;
            } catch (\InvalidArgumentException) {
                $structured = null;
            }
        }

        return [
            'structured' => is_array($structured) ? $structured : null,
            'text' => implode("\n", $text),
            'is_error' => ($result['isError'] ?? false) === true,
        ];
    }

    /**
     * Sends a JSON-RPC request, opening a session first when needed.
     *
     * @param string $method
     * @param array<string,mixed> $params
     * @return array<string,mixed> result, or error
     */
    private function rpc(string $method, array $params): array
    {
        if ($this->sessionId === null) {
            $init = $this->send('initialize', [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => (object)[],
                'clientInfo' => ['name' => 'mago-assistant', 'version' => '1.0'],
            ]);
            if (isset($init['error'])) {
                return $init;
            }
            $this->notify('notifications/initialized');
        }

        return $this->send($method, $params);
    }

    /**
     * One request with a single retry after refreshing an expired token.
     *
     * @param string $method
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function send(string $method, array $params): array
    {
        $id = $this->nextId++;
        $body = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object)$params];

        $response = $this->post($body);
        if ($response instanceof ResponseInterface && $response->getStatusCode() === 401 && $this->oauth->refresh()) {
            $response = $this->post($body);
        }
        if (!$response instanceof ResponseInterface) {
            return ['error' => 'Higgsfield could not be reached.'];
        }

        $status = $response->getStatusCode();
        if ($status === 401) {
            return ['error' => 'The Higgsfield connection has expired. Reconnect it under Stores > Configuration > '
                . 'Mago Assistant > General > Higgsfield Media Generation.'];
        }
        if ($status === 404 && $this->sessionId !== null) {
            // The server dropped the session; start a new one.
            $this->sessionId = null;
            return $method === 'initialize'
                ? ['error' => 'Higgsfield ended the session.']
                : $this->rpc($method, $params);
        }
        if ($status < 200 || $status >= 300) {
            $this->errorLogger->addLog('Higgsfield MCP', $method . ' HTTP ' . $status);
            return ['error' => 'Higgsfield answered with HTTP ' . $status . '.'];
        }

        $session = $response->getHeaderLine('Mcp-Session-Id');
        if ($session !== '') {
            $this->sessionId = $session;
        }

        $message = $this->message($response, $id);
        if ($message === null) {
            $this->errorLogger->addLog('Higgsfield MCP', $method . ': no JSON-RPC response');
            return ['error' => 'Higgsfield sent an unreadable answer.'];
        }
        if (isset($message['error'])) {
            $detail = is_array($message['error']) ? (string)($message['error']['message'] ?? '') : '';
            $this->errorLogger->addLog('Higgsfield MCP', $method . ': ' . $detail);
            $suffix = $detail !== '' ? ': ' . mb_substr($detail, 0, 300) : '.';

            return ['error' => 'Higgsfield refused the request' . $suffix];
        }

        return is_array($message['result'] ?? null) ? $message['result'] : [];
    }

    private function notify(string $method): void
    {
        $this->post(['jsonrpc' => '2.0', 'method' => $method]);
    }

    /**
     * Posts a JSON-RPC body with the bearer token; null when not connected or unreachable.
     *
     * @param array<string,mixed> $body
     * @return ResponseInterface|null
     */
    private function post(array $body): ?ResponseInterface
    {
        $token = $this->oauth->accessToken();
        if ($token === null) {
            return null;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
        ];
        if ($this->sessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        try {
            return $this->httpClient->request('POST', OAuthService::RESOURCE, [
                'headers' => $headers,
                'body' => $this->json->serialize($body),
                'timeout' => self::TIMEOUT,
                'connect_timeout' => 10,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            $this->errorLogger->addLog('Higgsfield MCP', (string)($body['method'] ?? '') . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The JSON-RPC message with the given id from a JSON or event-stream response.
     *
     * @param ResponseInterface $response
     * @param int $id
     * @return array<string,mixed>|null
     */
    private function message(ResponseInterface $response, int $id): ?array
    {
        $body = (string)$response->getBody();
        $payloads = str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')
            ? $this->eventData($body)
            : [$body];

        foreach ($payloads as $payload) {
            try {
                $message = $this->json->unserialize($payload);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (is_array($message) && ($message['id'] ?? null) === $id) {
                return $message;
            }
        }

        return null;
    }

    /**
     * Data fields of the events in a server-sent event stream.
     *
     * @param string $stream
     * @return list<string>
     */
    private function eventData(string $stream): array
    {
        $events = [];
        foreach (preg_split('/\r?\n\r?\n/', $stream) ?: [] as $block) {
            $data = [];
            foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5));
                }
            }
            if ($data !== []) {
                $events[] = implode("\n", $data);
            }
        }

        return $events;
    }
}
