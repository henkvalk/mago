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

/**
 * Connects the shop to the Higgsfield MCP server with OAuth 2.1: dynamic client registration,
 * authorization code with PKCE, and token refresh. Tokens never leave the server.
 */
class OAuthService
{
    public const RESOURCE = 'https://mcp.higgsfield.ai/mcp';

    private const METADATA_URL = 'https://mcp.higgsfield.ai/.well-known/oauth-authorization-server';
    private const SCOPE = 'openid email offline_access';
    private const CLIENT_NAME = 'Mago Assistant (Magento)';
    private const TIMEOUT = 20;

    /** Seconds before expiry at which the access token is refreshed. */
    private const REFRESH_MARGIN = 60;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly TokenStore $tokenStore,
        private readonly Json $json,
        private readonly ErrorLogger $errorLogger
    ) {
    }

    /**
     * Authorization URL to send the admin to, with the state and PKCE verifier to keep in the session.
     *
     * @param string $redirectUri
     * @return array<string,string> url, state and verifier, or error
     */
    public function startAuthorization(string $redirectUri): array
    {
        $metadata = $this->metadata();
        if (isset($metadata['error'])) {
            return ['error' => (string)$metadata['error']];
        }

        $client = $this->client($redirectUri, $metadata);
        if (isset($client['error'])) {
            return ['error' => (string)$client['error']];
        }

        $state = bin2hex(random_bytes(16));
        $verifier = $this->base64Url(random_bytes(48));
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'resource' => self::RESOURCE,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'url' => (string)$metadata['authorization_endpoint'] . '?' . $query,
            'state' => $state,
            'verifier' => $verifier,
        ];
    }

    /**
     * Exchanges the authorization code for tokens and stores them.
     *
     * @param string $code
     * @param string $verifier
     * @param string $redirectUri
     * @return string|null Error message, null on success
     */
    public function completeAuthorization(string $code, string $verifier, string $redirectUri): ?string
    {
        $stored = $this->tokenStore->load();
        if (!isset($stored['client_id'], $stored['token_endpoint'])) {
            return 'The Higgsfield connection was not started from this shop. Try connecting again.';
        }

        $tokens = $this->tokenRequest($stored, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
            'resource' => self::RESOURCE,
        ]);
        if (isset($tokens['error'])) {
            return (string)$tokens['error'];
        }

        $this->storeTokens($tokens, true);

        return null;
    }

    /**
     * A valid access token, refreshed when it is about to expire; null when not connected.
     */
    public function accessToken(): ?string
    {
        $stored = $this->tokenStore->load();
        $token = (string)($stored['access_token'] ?? '');
        if ($token === '') {
            return null;
        }

        $expiresAt = (int)($stored['expires_at'] ?? 0);
        if ($expiresAt === 0 || $expiresAt - self::REFRESH_MARGIN > time()) {
            return $token;
        }

        return $this->refresh() ? (string)($this->tokenStore->load()['access_token'] ?? '') : null;
    }

    /**
     * Uses the refresh token to get a new access token.
     */
    public function refresh(): bool
    {
        $stored = $this->tokenStore->load();
        $refreshToken = (string)($stored['refresh_token'] ?? '');
        if ($refreshToken === '' || !isset($stored['client_id'], $stored['token_endpoint'])) {
            return false;
        }

        $tokens = $this->tokenRequest($stored, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'resource' => self::RESOURCE,
        ]);
        if (isset($tokens['error'])) {
            return false;
        }

        $this->storeTokens($tokens, false);

        return true;
    }

    /**
     * Connection state for the configuration page.
     *
     * @return array{connected:bool,email:string,expires_at:int,can_refresh:bool}
     */
    public function status(): array
    {
        $stored = $this->tokenStore->load();

        return [
            'connected' => ($stored['access_token'] ?? '') !== '',
            'email' => (string)($stored['email'] ?? ''),
            'expires_at' => (int)($stored['expires_at'] ?? 0),
            'can_refresh' => ($stored['refresh_token'] ?? '') !== '',
        ];
    }

    public function isConnected(): bool
    {
        return $this->accessToken() !== null;
    }

    /**
     * Forgets the tokens and the registered client.
     */
    public function disconnect(): void
    {
        $this->tokenStore->clear();
    }

    /**
     * Authorization server metadata.
     *
     * @return array<string,mixed>
     */
    private function metadata(): array
    {
        $data = $this->request('GET', self::METADATA_URL, []);
        if (isset($data['error'])) {
            return $data;
        }
        if (!isset($data['authorization_endpoint'], $data['token_endpoint'], $data['registration_endpoint'])) {
            return ['error' => 'Higgsfield returned incomplete OAuth metadata.'];
        }

        return $data;
    }

    /**
     * The registered OAuth client for this redirect URI, registering one when needed.
     *
     * @param string $redirectUri
     * @param array<string,mixed> $metadata
     * @return array<string,mixed> client_id, or error
     */
    private function client(string $redirectUri, array $metadata): array
    {
        $stored = $this->tokenStore->load();
        if (isset($stored['client_id']) && ($stored['redirect_uri'] ?? '') === $redirectUri) {
            $this->tokenStore->save(['token_endpoint' => (string)$metadata['token_endpoint']]);
            return ['client_id' => (string)$stored['client_id']];
        }

        $client = $this->request('POST', (string)$metadata['registration_endpoint'], [
            'json' => [
                'client_name' => self::CLIENT_NAME,
                'redirect_uris' => [$redirectUri],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'token_endpoint_auth_method' => 'client_secret_post',
                'scope' => self::SCOPE,
            ],
        ]);
        if (isset($client['error'])) {
            return $client;
        }
        if (!isset($client['client_id'])) {
            return ['error' => 'Higgsfield did not register the shop as OAuth client.'];
        }

        // A new client invalidates tokens issued to the previous one.
        $this->tokenStore->clear();
        $this->tokenStore->save([
            'client_id' => (string)$client['client_id'],
            'client_secret' => (string)($client['client_secret'] ?? ''),
            'redirect_uri' => $redirectUri,
            'token_endpoint' => (string)$metadata['token_endpoint'],
        ]);

        return ['client_id' => (string)$client['client_id']];
    }

    /**
     * Calls the token endpoint with the client credentials added.
     *
     * @param array<string,mixed> $stored
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function tokenRequest(array $stored, array $params): array
    {
        $params['client_id'] = (string)$stored['client_id'];
        if (($stored['client_secret'] ?? '') !== '') {
            $params['client_secret'] = (string)$stored['client_secret'];
        }

        $tokens = $this->request('POST', (string)$stored['token_endpoint'], ['form_params' => $params]);
        if (!isset($tokens['error']) && !isset($tokens['access_token'])) {
            return ['error' => 'Higgsfield returned no access token.'];
        }

        return $tokens;
    }

    /**
     * Saves a token response; the account e-mail is read from the ID token when present.
     *
     * @param array<string,mixed> $tokens
     * @param bool $initial
     * @return void
     */
    private function storeTokens(array $tokens, bool $initial): void
    {
        $values = [
            'access_token' => (string)$tokens['access_token'],
            'expires_at' => isset($tokens['expires_in']) ? time() + (int)$tokens['expires_in'] : 0,
        ];
        if (isset($tokens['refresh_token'])) {
            $values['refresh_token'] = (string)$tokens['refresh_token'];
        } elseif ($initial) {
            $values['refresh_token'] = null;
        }

        $email = $this->emailFromIdToken((string)($tokens['id_token'] ?? ''));
        if ($email !== '') {
            $values['email'] = $email;
        }

        $this->tokenStore->save($values);
    }

    /**
     * E-mail claim of an ID token, only for display; the token comes straight from the token endpoint.
     *
     * @param string $idToken
     * @return string
     */
    private function emailFromIdToken(string $idToken): string
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return '';
        }

        try {
            $claims = $this->json->unserialize((string)base64_decode(strtr($parts[1], '-_', '+/'), true));
        } catch (\InvalidArgumentException) {
            return '';
        }

        return is_array($claims) && is_string($claims['email'] ?? null) ? $claims['email'] : '';
    }

    /**
     * JSON response of an OAuth endpoint, or a readable error.
     *
     * @param string $method
     * @param string $url
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, array $options): array
    {
        $options += [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => self::TIMEOUT,
            'connect_timeout' => 10,
            'http_errors' => false,
        ];

        try {
            $response = $this->httpClient->request($method, $url, $options);
        } catch (GuzzleException $e) {
            $this->errorLogger->addLog('Higgsfield OAuth', $method . ' ' . $url . ': ' . $e->getMessage());
            return ['error' => 'Higgsfield could not be reached.'];
        }

        $status = $response->getStatusCode();
        try {
            $data = $this->json->unserialize((string)$response->getBody());
        } catch (\InvalidArgumentException) {
            $data = null;
        }

        if ($status >= 200 && $status < 300 && is_array($data)) {
            return $data;
        }

        $code = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : '';
        $this->errorLogger->addLog('Higgsfield OAuth', $method . ' ' . $url . ' HTTP ' . $status . ' ' . $code);

        return ['error' => 'Higgsfield refused the connection (HTTP ' . $status . ($code !== '' ? ', ' . $code : '')
            . ').'];
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
