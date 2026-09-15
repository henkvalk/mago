<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Integration\Api\UserTokenIssuerInterface;
use Magento\Integration\Model\CustomUserContext;
use Magento\Integration\Model\UserToken\UserTokenParametersFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepositoryInterface;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Logger\ErrorLogger;

class InternalApiClient
{
    private array $tokens = [];

    public function __construct(
        private readonly UserTokenIssuerInterface $tokenIssuer,
        private readonly UserTokenParametersFactory $tokenParametersFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ConfigRepositoryInterface $configRepository,
        private readonly InternalRequestOptions $requestOptions,
        private readonly Json $json,
        private readonly DebugLogger $debugLogger,
        private readonly ErrorLogger $errorLogger,
        private readonly CleartextTokenWarning $cleartextTokenWarning
    ) {
    }

    /**
     * @param string $endpoint Path after /V1/
     * @param array<string, mixed> $params Query parameters
     * @param int $adminUserId
     * @param string|null $storeCode Store view code to run the call in ("all" for every store view);
     *                               null keeps Magento's default store view
     * @return array<string, mixed>
     */
    public function get(string $endpoint, array $params, int $adminUserId, ?string $storeCode = null): array
    {
        $url = $this->buildUrl($endpoint, $storeCode);
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        return $this->request(InternalRequestOptions::METHOD_GET, $url, null, $adminUserId);
    }

    /**
     * @param string $endpoint Path after /V1/
     * @param array<string, mixed> $body
     * @param int $adminUserId
     * @param string|null $storeCode See get()
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $body, int $adminUserId, ?string $storeCode = null): array
    {
        return $this->request(
            InternalRequestOptions::METHOD_POST,
            $this->buildUrl($endpoint, $storeCode),
            $body,
            $adminUserId
        );
    }

    /**
     * Queue the call on async.operations.all instead of running it; returns the bulk_uuid
     *
     * @param string $endpoint Path after /V1/
     * @param array<string, mixed> $body
     * @param int $adminUserId
     * @param string|null $storeCode See get()
     * @return array<string, mixed>
     */
    public function postAsync(string $endpoint, array $body, int $adminUserId, ?string $storeCode = null): array
    {
        return $this->request(
            InternalRequestOptions::METHOD_POST,
            $this->buildUrl($endpoint, $storeCode, true),
            $body,
            $adminUserId
        );
    }

    /**
     * @param string $endpoint Path after /V1/
     * @param array<string, mixed> $body
     * @param int $adminUserId
     * @param string|null $storeCode See get()
     * @return array<string, mixed>
     */
    public function put(string $endpoint, array $body, int $adminUserId, ?string $storeCode = null): array
    {
        return $this->request(
            InternalRequestOptions::METHOD_PUT,
            $this->buildUrl($endpoint, $storeCode),
            $body,
            $adminUserId
        );
    }

    /**
     * @param string $endpoint Path after /V1/
     * @param int $adminUserId
     * @param string|null $storeCode See get()
     * @return array<string, mixed>
     */
    public function delete(string $endpoint, int $adminUserId, ?string $storeCode = null): array
    {
        return $this->request(
            InternalRequestOptions::METHOD_DELETE,
            $this->buildUrl($endpoint, $storeCode),
            null,
            $adminUserId
        );
    }

    /**
     * Build searchCriteria query params from filter arrays
     *
     * @param array $filters [['field' => 'name', 'value' => '%test%', 'condition_type' => 'like'], ...]
     * @param int $pageSize
     * @param int $currentPage
     * @param array|null $sortOrders [['field' => 'created_at', 'direction' => 'DESC'], ...]
     * @return array Query params ready for http_build_query
     */
    public function buildSearchCriteria(
        array $filters = [],
        int $pageSize = 100,
        int $currentPage = 1,
        ?array $sortOrders = null
    ): array {
        $params = [
            'searchCriteria[pageSize]' => $pageSize,
            'searchCriteria[currentPage]' => $currentPage,
        ];

        foreach ($filters as $groupIndex => $filter) {
            $prefix = "searchCriteria[filter_groups][$groupIndex][filters][0]";
            $params[$prefix . '[field]'] = $filter['field'];
            $params[$prefix . '[value]'] = $filter['value'];
            if (isset($filter['condition_type'])) {
                $params[$prefix . '[conditionType]'] = $filter['condition_type'];
            }
        }

        if ($sortOrders) {
            foreach ($sortOrders as $index => $sort) {
                $prefix = "searchCriteria[sortOrders][$index]";
                $params[$prefix . '[field]'] = $sort['field'];
                $params[$prefix . '[direction]'] = $sort['direction'] ?? 'ASC';
            }
        }

        return $params;
    }

    private function getToken(int $adminUserId): string
    {
        if (isset($this->tokens[$adminUserId])) {
            return $this->tokens[$adminUserId];
        }

        $context = new CustomUserContext($adminUserId, UserContextInterface::USER_TYPE_ADMIN);
        $params = $this->tokenParametersFactory->create();
        $token = $this->tokenIssuer->create($context, $params);

        $this->tokens[$adminUserId] = $token;
        return $token;
    }

    /**
     * Without a store code Magento serves /rest/V1/ in its default store view, which is also the
     * store view it assigns to anything created through that URL. A store code in the path
     * (/rest/{code}/V1/) runs the call in that store view; "all" runs it in the admin store, so
     * created entities belong to all store views.
     */
    private function buildUrl(string $endpoint, ?string $storeCode = null, bool $async = false): string
    {
        $endpoint = ltrim($endpoint, '/');
        $storeSegment = $storeCode !== null && $storeCode !== '' ? rawurlencode($storeCode) . '/' : '';
        $asyncSegment = $async ? 'async/' : '';
        return $this->getInternalBaseUrl() . '/rest/' . $storeSegment . $asyncSegment . 'V1/' . $endpoint;
    }

    private function getInternalBaseUrl(): string
    {
        $configured = $this->configRepository->getInternalUrl();
        if ($configured) {
            return rtrim($configured, '/');
        }

        return rtrim(
            $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB),
            '/'
        );
    }

    private function request(string $method, string $url, ?array $body, int $adminUserId): array
    {
        $token = $this->getToken($adminUserId);
        $this->cleartextTokenWarning->warnIfNeeded($url);

        $this->debugLogger->addLog('InternalAPI Request', [
            'method' => $method,
            'url' => $url,
            'admin_user_id' => $adminUserId,
            'body' => $body ? $this->json->serialize($body) : null,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, $this->requestOptions->build(
            $method,
            $url,
            $body,
            $token,
            $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB)
        ));
        $responseBody = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            $this->errorLogger->addLog('InternalAPI curl error', $curlError);
            return ['error' => 'Internal API request failed: ' . $curlError];
        }

        $logData = [
            'status' => $statusCode,
            'body_length' => strlen((string)$responseBody),
        ];
        if ($statusCode >= 400) {
            $logData['body'] = substr((string)$responseBody, 0, 1000);
        }
        $this->debugLogger->addLog('InternalAPI Response', $logData);

        if ($statusCode >= 300 && $statusCode < 400) {
            $this->errorLogger->addLog('InternalAPI redirect detected', [
                'status' => $statusCode,
                'url' => $url,
                'body' => substr((string)$responseBody, 0, 200),
            ]);
            return ['error' => 'Internal API returned a redirect (HTTP ' . $statusCode . '). Check base URL configuration.'];
        }

        if ($statusCode === 401) {
            unset($this->tokens[$adminUserId]);
            return ['error' => 'Authentication failed'];
        }

        if ($statusCode === 403) {
            return ['error' => 'You do not have permission to access this data'];
        }

        if ($statusCode === 404) {
            return ['error' => 'Resource not found'];
        }

        try {
            $decoded = $this->json->unserialize((string)$responseBody);
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('InternalAPI parse error', [
                'status' => $statusCode,
                'body' => substr((string)$responseBody, 0, 500),
            ]);
            return ['error' => 'Failed to parse API response'];
        }

        if ($statusCode >= 400) {
            $message = $decoded['message'] ?? ('API error (HTTP ' . $statusCode . ')');
            return ['error' => $message];
        }

        // Some endpoints return a scalar (e.g. an ID or bool) instead of an object
        return is_array($decoded) ? $decoded : ['result' => $decoded];
    }
}
