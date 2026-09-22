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

class TopProductsAction implements ActionInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PeriodParser $periodParser
    ) {
    }

    public function getName(): string
    {
        return 'top_products';
    }

    public function getDescription(): string
    {
        return 'Best-selling products';
    }

    public function getParameterSchema(): array
    {
        return [
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
        $period = $params['period'] ?? '30days';
        $limit = (int)($params['limit'] ?? 10);
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $itemTable = $this->resourceConnection->getTableName('sales_order_item');
        [$from, $to] = $this->periodParser->parse($period);

        $select = $connection->select()
            ->from(['oi' => $itemTable], [
                'sku' => 'oi.sku',
                'name' => 'oi.name',
                'qty_ordered' => new Expression('SUM(oi.qty_ordered)'),
                'revenue' => new Expression('SUM(oi.row_total)'),
            ])
            ->join(['o' => $orderTable], 'o.entity_id = oi.order_id', [])
            ->where('o.created_at >= ?', $from)
            ->where('o.created_at <= ?', $to)
            ->where('o.state NOT IN (?)', ['canceled', 'closed'])
            ->where('oi.parent_item_id IS NULL')
            ->group('oi.sku')
            ->order('qty_ordered DESC')
            ->limit($limit);

        $rows = $connection->fetchAll($select);
        $products = [];
        foreach ($rows as $row) {
            $products[] = [
                'sku' => $row['sku'],
                'name' => $row['name'],
                'qty_ordered' => (int)$row['qty_ordered'],
                'revenue' => round((float)$row['revenue'], 2),
            ];
        }

        return ['period' => $period, 'top_products' => $products];
    }
}
