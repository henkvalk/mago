<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\SalesData;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Skills\PeriodParser;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class SearchOrdersAction implements ActionInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PeriodParser $periodParser,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'search_orders';
    }

    public function getDescription(): string
    {
        return 'Find orders containing a specific product by name or SKU';
    }

    public function getParameterSchema(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'Product name or SKU to search for in order items',
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
        return 'Magento_Sales::sales';
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
        $query = $params['query'] ?? '';
        if (empty($query)) {
            return ['error' => 'query parameter is required for search_orders'];
        }

        $period = $params['period'] ?? '30days';
        $limit = (int)($params['limit'] ?? 10);
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $itemTable = $this->resourceConnection->getTableName('sales_order_item');
        [$from, $to] = $this->periodParser->parse($period);

        $select = $connection->select()
            ->from(['oi' => $itemTable], [
                'sku' => 'oi.sku',
                'product_name' => 'oi.name',
                'qty_ordered' => 'oi.qty_ordered',
                'row_total' => 'oi.row_total',
            ])
            ->join(['o' => $orderTable], 'o.entity_id = oi.order_id', [
                'entity_id' => 'o.entity_id',
                'order_number' => 'o.increment_id',
                'order_total' => 'o.grand_total',
                'status' => 'o.status',
                'customer' => new Expression("TRIM(CONCAT(COALESCE(o.customer_firstname, ''), ' ', COALESCE(o.customer_lastname, '')))"),
                'date' => 'o.created_at',
            ])
            ->where('o.created_at >= ?', $from)
            ->where('o.created_at <= ?', $to)
            ->where('o.state NOT IN (?)', ['canceled', 'closed'])
            ->where('oi.parent_item_id IS NULL')
            ->where(
                'oi.name LIKE ' . $connection->quote('%' . $query . '%')
                . ' OR oi.sku LIKE ' . $connection->quote('%' . $query . '%')
            )
            ->order('o.created_at DESC')
            ->limit($limit);

        $rows = $connection->fetchAll($select);
        $orders = [];
        foreach ($rows as $row) {
            $orders[] = [
                'entity_id' => (int)$row['entity_id'],
                'order_number' => $row['order_number'],
                'order_total' => round((float)$row['order_total'], 2),
                'status' => $row['status'],
                'customer' => $row['customer'],
                'date' => $row['date'],
                'product_sku' => $row['sku'],
                'product_name' => $row['product_name'],
                'qty' => (int)$row['qty_ordered'],
                'line_total' => round((float)$row['row_total'], 2),
                'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => (int)$row['entity_id']]),
            ];
        }

        return [
            'query' => $query,
            'period' => $period,
            'results_count' => count($orders),
            'orders' => $orders,
        ];
    }
}
