<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Sales\OrderManager;

use MaggyAssistant\Base\Service\Api\InternalApiClient;

class OrderResolver
{
    public function __construct(
        private readonly InternalApiClient $apiClient
    ) {
    }

    /**
     * Resolve increment_id to entity_id and order metadata
     *
     * @return array{entity_id: int, status: string, increment_id: string}|array{error: string}
     */
    public function resolve(string $orderNumber, int $adminUserId): array
    {
        $searchParams = $this->apiClient->buildSearchCriteria(
            [['field' => 'increment_id', 'value' => $orderNumber, 'condition_type' => 'eq']],
            1
        );
        $result = $this->apiClient->get('orders', $searchParams, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $items = $result['items'] ?? [];
        if (empty($items)) {
            return ['error' => 'Order not found: ' . $orderNumber];
        }

        $order = reset($items);
        return [
            'entity_id' => (int)$order['entity_id'],
            'status' => $order['status'] ?? '',
            'increment_id' => $order['increment_id'] ?? '',
        ];
    }
}
