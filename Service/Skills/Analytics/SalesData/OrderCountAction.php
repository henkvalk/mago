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

class OrderCountAction implements ActionInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PeriodParser $periodParser
    ) {
    }

    public function getName(): string
    {
        return 'order_count';
    }

    public function getDescription(): string
    {
        return 'Count orders by status for a period';
    }

    public function getParameterSchema(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Time period: "today", "yesterday", "7days", "30days", "this_month", "last_month", "this_year", "YYYY-MM" for a specific month, or "YYYY-MM-DD:YYYY-MM-DD" for a custom range',
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
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order');
        [$from, $to] = $this->periodParser->parse($period);

        $select = $connection->select()
            ->from($table, [
                'status',
                'count' => new Expression('COUNT(*)'),
            ])
            ->where('created_at >= ?', $from)
            ->where('created_at <= ?', $to)
            ->group('status');

        $rows = $connection->fetchAll($select);
        $statuses = [];
        $total = 0;
        foreach ($rows as $row) {
            $statuses[$row['status']] = (int)$row['count'];
            $total += (int)$row['count'];
        }

        return ['period' => $period, 'total' => $total, 'by_status' => $statuses];
    }
}
