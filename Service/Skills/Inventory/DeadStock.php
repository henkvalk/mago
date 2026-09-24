<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Inventory;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class DeadStock implements ToolInterface
{
    public const ACL = 'Magento_Reports::report_products';

    private const BASE_CURRENCY_PATH = 'currency/options/base';
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly DeadStockReport $report,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'dead_stock';
    }

    public function getDescription(): string
    {
        return 'Find dead stock among products that hold a quantity. action "obsolete": in stock and '
            . 'no net sales in the last N months (obsolete inventory). action "slow_moving": sold in '
            . 'the last N months, but the stock on hand lasts at least min_days_of_cover days at that '
            . 'sales rate (slow-moving inventory). Only products created before the period count, '
            . 'because Magento keeps no receipt date. Returns totals, the products with the highest '
            . 'stock value and a link to download the full list as CSV. Does not change stock or '
            . 'prices and does not look at inventory sources separately.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => DeadStockReport::TYPES,
                    'description' => '"obsolete" for unsold stock, "slow_moving" for stock that sells too slowly.',
                ],
                'months' => [
                    'type' => 'integer',
                    'minimum' => DeadStockReport::MIN_MONTHS,
                    'maximum' => DeadStockReport::MAX_MONTHS,
                    'description' => 'Length of the sales period in months. Default 6.',
                ],
                'min_days_of_cover' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'slow_moving only: how many days the stock must last at the '
                        . 'current sales rate to count as slow-moving. Default 180.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'description' => 'How many products to list in the answer. Default 20. The CSV always holds all.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function getMagentoAcl(array $input = []): string
    {
        return self::ACL;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return true;
    }

    public function getFieldClassification(string $action = ''): array
    {
        return [
            'action' => [PiiClass::PUBLIC],
            'months' => [PiiClass::PUBLIC],
            'since' => [PiiClass::PUBLIC],
            'min_days_of_cover' => [PiiClass::PUBLIC],
            'currency' => [PiiClass::PUBLIC],
            'product_count' => [PiiClass::PUBLIC],
            'total_qty' => [PiiClass::PUBLIC],
            'total_stock_value' => [PiiClass::PUBLIC],
            'shown' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'product_name' => [PiiClass::PUBLIC],
            'qty' => [PiiClass::PUBLIC],
            'units_sold' => [PiiClass::PUBLIC],
            'last_sold_at' => [PiiClass::PUBLIC],
            'created_at' => [PiiClass::PUBLIC],
            'days_of_cover' => [PiiClass::PUBLIC],
            'unit_value' => [PiiClass::PUBLIC],
            'value_basis' => [PiiClass::PUBLIC],
            'stock_value' => [PiiClass::PUBLIC],
            'export_url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return 'Lead with product_count, total_qty and total_stock_value in the currency given, then '
            . 'a table of the listed products. stock_value uses cost when value_basis is "cost", '
            . 'otherwise the regular price; say so when both occur. last_sold_at null means never sold. '
            . 'When product_count exceeds shown, say the table is the top by stock value. Offer the '
            . 'CSV download by writing the export_url token exactly as it came back, as a markdown link.';
    }

    public function execute(array $params): array
    {
        $action = (string)($params['action'] ?? '');
        if (!in_array($action, DeadStockReport::TYPES, true)) {
            return ['error' => 'dead_stock needs action "obsolete" or "slow_moving"'];
        }

        $months = max(DeadStockReport::MIN_MONTHS, min(
            DeadStockReport::MAX_MONTHS,
            (int)($params['months'] ?? DeadStockReport::DEFAULT_MONTHS)
        ));
        $minDaysOfCover = max(1, (int)($params['min_days_of_cover'] ?? DeadStockReport::DEFAULT_MIN_DAYS_OF_COVER));
        $limit = max(1, min(self::MAX_LIMIT, (int)($params['limit'] ?? self::DEFAULT_LIMIT)));

        $rows = $this->report->build($action, $months, $minDaysOfCover);
        $shown = array_slice($rows, 0, $limit);

        $urlParams = ['type' => $action, 'months' => $months];
        $result = [
            'action' => $action,
            'months' => $months,
            'since' => $this->report->since($months)->format('Y-m-d'),
        ];
        if ($action === DeadStockReport::SLOW_MOVING) {
            $result['min_days_of_cover'] = $minDaysOfCover;
            $urlParams['min_days_of_cover'] = $minDaysOfCover;
        }

        return $result + [
            'currency' => (string)$this->scopeConfig->getValue(self::BASE_CURRENCY_PATH),
            'product_count' => count($rows),
            'total_qty' => array_sum(array_column($rows, 'qty')),
            'total_stock_value' => round(array_sum(array_column($rows, 'stock_value')), 2),
            'shown' => count($shown),
            'products' => array_map(static function (array $row): array {
                unset($row['product_id']);
                return $row;
            }, $shown),
            'export_url' => $this->secureAdminUrl->getUrl('mago/deadstock/export', $urlParams),
        ];
    }
}
