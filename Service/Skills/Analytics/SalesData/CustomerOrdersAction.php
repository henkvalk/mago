<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\SalesData;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Skills\PeriodParser;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class CustomerOrdersAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly PeriodParser $periodParser,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'customer_orders';
    }

    public function getDescription(): string
    {
        return 'Find orders for a specific customer by customer_id';
    }

    public function getParameterSchema(): array
    {
        return [
            'customer_id' => [
                'type' => 'integer',
                'description' => 'Customer entity_id to look up orders for',
            ],
            'period' => [
                'type' => 'string',
                'description' => 'Time period: "today", "yesterday", "7days", "30days", "this_month", "last_month", "this_year", "YYYY-MM" for a specific month, or "YYYY-MM-DD:YYYY-MM-DD" for a custom range',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of results to return (default: 10)',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $customerId = (int)($params['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return ['error' => 'customer_id parameter is required for customer_orders'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $period = $params['period'] ?? '30days';
        $limit = (int)($params['limit'] ?? 10);
        [$from, $to] = $this->periodParser->parse($period);

        $searchParams = $this->apiClient->buildSearchCriteria(
            [
                ['field' => 'customer_id', 'value' => (string)$customerId, 'condition_type' => 'eq'],
                ['field' => 'created_at', 'value' => $from, 'condition_type' => 'gteq'],
                ['field' => 'created_at', 'value' => $to, 'condition_type' => 'lteq'],
            ],
            $limit,
            1,
            [['field' => 'created_at', 'direction' => 'DESC']]
        );
        $result = $this->apiClient->get('orders', $searchParams, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $orders = [];
        foreach ($result['items'] ?? [] as $order) {
            $entityId = (int)($order['entity_id'] ?? 0);
            $orders[] = [
                'entity_id' => $entityId,
                'order_number' => $order['increment_id'] ?? '',
                'total' => round((float)($order['grand_total'] ?? 0), 2),
                'status' => $order['status'] ?? '',
                'items' => (int)($order['total_item_count'] ?? 0),
                'date' => $order['created_at'] ?? '',
                'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
            ];
        }

        return [
            'customer_id' => $customerId,
            'period' => $period,
            'total_orders' => count($orders),
            'orders' => $orders,
        ];
    }
}
