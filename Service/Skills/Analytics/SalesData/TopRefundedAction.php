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

class TopRefundedAction implements ActionInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PeriodParser $periodParser
    ) {
    }

    public function getName(): string
    {
        return 'top_refunded';
    }

    public function getDescription(): string
    {
        return 'Most refunded/returned products by credit memo data';
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
        return 'Magento_Sales::creditmemo';
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
        $creditmemoTable = $this->resourceConnection->getTableName('sales_creditmemo');
        $creditmemoItemTable = $this->resourceConnection->getTableName('sales_creditmemo_item');
        [$from, $to] = $this->periodParser->parse($period);

        $select = $connection->select()
            ->from(['ci' => $creditmemoItemTable], [
                'sku' => 'ci.sku',
                'name' => 'ci.name',
                'qty_refunded' => new Expression('SUM(ci.qty)'),
                'refund_total' => new Expression('SUM(ci.row_total)'),
                'creditmemo_count' => new Expression('COUNT(DISTINCT ci.parent_id)'),
            ])
            ->join(['c' => $creditmemoTable], 'c.entity_id = ci.parent_id', [])
            ->where('c.created_at >= ?', $from)
            ->where('c.created_at <= ?', $to)
            ->where('ci.qty > 0')
            ->group('ci.sku')
            ->order('qty_refunded DESC')
            ->limit($limit);

        $rows = $connection->fetchAll($select);
        $products = [];
        foreach ($rows as $row) {
            $products[] = [
                'sku' => $row['sku'],
                'name' => $row['name'],
                'qty_refunded' => (int)$row['qty_refunded'],
                'refund_total' => round((float)$row['refund_total'], 2),
                'creditmemo_count' => (int)$row['creditmemo_count'],
            ];
        }

        return ['period' => $period, 'top_refunded' => $products];
    }
}
