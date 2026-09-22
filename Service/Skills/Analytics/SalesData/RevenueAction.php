<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\SalesData;

use Magento\Directory\Model\Currency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Sales\Model\Order;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Skills\PeriodParser;

class RevenueAction implements ActionInterface
{
    private const EXCLUDED_STATES = [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT, Order::STATE_CANCELED];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PeriodParser $periodParser,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'revenue_summary';
    }

    public function getDescription(): string
    {
        return 'Total revenue, order count, AOV for a period. Revenue is net of tax, shipping and refunds, '
            . 'in the currency given by "currency", matching the admin dashboard';
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
        return 'Always state amounts in the returned "currency", never assume dollars.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $period = $params['period'] ?? '30days';
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('sales_order');
        [$from, $to] = $this->periodParser->parse($period);
        $netSalesAmount = $this->netSalesAmountInGlobalCurrency($connection);

        $select = $connection->select()
            ->from($table, [
                'total_revenue' => new Expression("SUM({$netSalesAmount})"),
                'order_count' => new Expression('COUNT(*)'),
                'avg_order_value' => new Expression("AVG({$netSalesAmount})"),
                'total_items' => new Expression('SUM(total_item_count)'),
            ])
            ->where('created_at >= ?', $from)
            ->where('created_at <= ?', $to)
            ->where('state NOT IN (?)', self::EXCLUDED_STATES);

        $result = $connection->fetchRow($select);

        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'currency' => $this->globalCurrencyCode(),
            'total_revenue' => round((float)($result['total_revenue'] ?? 0), 2),
            'order_count' => (int)($result['order_count'] ?? 0),
            'average_order_value' => round((float)($result['avg_order_value'] ?? 0), 2),
            'total_items_sold' => (int)($result['total_items'] ?? 0),
        ];
    }

    private function netSalesAmountInGlobalCurrency(AdapterInterface $connection): string
    {
        return sprintf(
            '(%s - %s - %s - (%s - %s - %s)) * base_to_global_rate',
            $connection->getIfNullSql('base_total_invoiced'),
            $connection->getIfNullSql('base_tax_invoiced'),
            $connection->getIfNullSql('base_shipping_invoiced'),
            $connection->getIfNullSql('base_total_refunded'),
            $connection->getIfNullSql('base_tax_refunded'),
            $connection->getIfNullSql('base_shipping_refunded')
        );
    }

    private function globalCurrencyCode(): string
    {
        return (string)$this->scopeConfig->getValue(Currency::XML_PATH_CURRENCY_BASE);
    }
}
