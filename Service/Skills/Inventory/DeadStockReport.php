<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Inventory;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Model\Stock;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\Order;

/**
 * Finds stocked products that did not sell (obsolete) or sell too slowly for their quantity
 * (slow-moving) within a period. Magento keeps no receipt date, so a product counts as held for the
 * whole period when it was created before the period started.
 */
class DeadStockReport
{
    public const OBSOLETE = 'obsolete';
    public const SLOW_MOVING = 'slow_moving';
    public const TYPES = [self::OBSOLETE, self::SLOW_MOVING];

    public const DEFAULT_MONTHS = 6;
    public const MIN_MONTHS = 1;
    public const MAX_MONTHS = 36;
    public const DEFAULT_MIN_DAYS_OF_COVER = 180;

    /** Product types that hold a quantity of their own. */
    private const STOCKED_TYPES = ['simple', 'virtual', 'downloadable'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductCollectionFactory $productCollectionFactory
    ) {
    }

    /**
     * Rows sorted by stock value, highest first.
     *
     * @param string $type One of self::TYPES
     * @param int $months Length of the sales period, clamped to MIN_MONTHS..MAX_MONTHS
     * @param int $minDaysOfCover Slow-moving threshold, ignored for obsolete
     * @return list<array{product_id:int,sku:string,product_name:string,qty:float,units_sold:float,
     *     last_sold_at:?string,created_at:string,days_of_cover:?int,unit_value:float,
     *     value_basis:string,stock_value:float}>
     */
    public function build(string $type, int $months, int $minDaysOfCover = self::DEFAULT_MIN_DAYS_OF_COVER): array
    {
        $months = max(self::MIN_MONTHS, min(self::MAX_MONTHS, $months));
        $since = $this->since($months);
        $periodDays = max(1, (int)(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->diff($since)->days);

        $rows = [];
        foreach ($this->resource->getConnection()->fetchAll($this->candidates($type, $since)) as $row) {
            $qty = (float)$row['qty'];
            $unitsSold = (float)$row['units_sold'];
            $daysOfCover = $unitsSold > 0 ? (int)round($qty / ($unitsSold / $periodDays)) : null;

            if ($type === self::SLOW_MOVING && $daysOfCover < $minDaysOfCover) {
                continue;
            }

            $rows[(int)$row['product_id']] = [
                'product_id' => (int)$row['product_id'],
                'sku' => (string)$row['sku'],
                'product_name' => '',
                'qty' => $qty,
                'units_sold' => $unitsSold,
                'last_sold_at' => $row['last_sold_at'] !== null ? (string)$row['last_sold_at'] : null,
                'created_at' => (string)$row['created_at'],
                'days_of_cover' => $daysOfCover,
                'unit_value' => 0.0,
                'value_basis' => 'price',
                'stock_value' => 0.0,
            ];
        }

        $this->addCatalogData($rows);
        usort($rows, static fn (array $a, array $b): int => $b['stock_value'] <=> $a['stock_value']);

        return $rows;
    }

    public function since(int $months): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify(sprintf('-%d months', $months));
    }

    private function candidates(string $type, \DateTimeImmutable $since): Select
    {
        $connection = $this->resource->getConnection();
        $sinceSql = $since->format('Y-m-d H:i:s');
        $orderItem = $this->resource->getTableName('sales_order_item');
        $order = $this->resource->getTableName('sales_order');

        // Child rows of configurable and bundle orders carry the simple product id, so they are
        // counted; the parent rows are skipped by the product type filter.
        $sold = $connection->select()
            ->from(['oi' => $orderItem], [
                'product_id',
                'units_sold' => new \Zend_Db_Expr('SUM(oi.qty_ordered - oi.qty_canceled - oi.qty_refunded)'),
            ])
            ->join(['o' => $order], 'o.entity_id = oi.order_id', [])
            ->where('o.state <> ?', Order::STATE_CANCELED)
            ->where('o.created_at >= ?', $sinceSql)
            ->where('oi.product_type IN (?)', self::STOCKED_TYPES)
            ->group('oi.product_id');

        $lastSold = $connection->select()
            ->from(['oi' => $orderItem], ['product_id', 'last_sold_at' => new \Zend_Db_Expr('MAX(o.created_at)')])
            ->join(['o' => $order], 'o.entity_id = oi.order_id', [])
            ->where('o.state <> ?', Order::STATE_CANCELED)
            ->where('oi.product_type IN (?)', self::STOCKED_TYPES)
            ->group('oi.product_id');

        $select = $connection->select()
            ->from(
                ['e' => $this->resource->getTableName('catalog_product_entity')],
                ['product_id' => 'entity_id', 'sku', 'created_at']
            )
            ->join(
                ['si' => $this->resource->getTableName('cataloginventory_stock_item')],
                $connection->quoteInto('si.product_id = e.entity_id AND si.stock_id = ?', Stock::DEFAULT_STOCK_ID),
                ['qty']
            )
            ->joinLeft(
                ['s' => $sold],
                's.product_id = e.entity_id',
                ['units_sold' => new \Zend_Db_Expr('COALESCE(s.units_sold, 0)')]
            )
            ->joinLeft(['l' => $lastSold], 'l.product_id = e.entity_id', ['last_sold_at'])
            ->where('e.type_id IN (?)', self::STOCKED_TYPES)
            ->where('si.qty > 0')
            ->where('si.manage_stock = 1 OR si.use_config_manage_stock = 1')
            ->where('e.created_at < ?', $sinceSql);

        $select->where($type === self::OBSOLETE ? 'COALESCE(s.units_sold, 0) <= 0' : 's.units_sold > 0');

        return $select;
    }

    /**
     * Adds name and value from the admin store view.
     *
     * Stock is valued at cost when a cost is set, otherwise at the regular price.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function addCatalogData(array &$rows): void
    {
        if ($rows === []) {
            return;
        }

        $collection = $this->productCollectionFactory->create()
            ->addAttributeToSelect(['name', 'price', 'cost'])
            ->addIdFilter(array_keys($rows));

        foreach ($collection as $product) {
            $id = (int)$product->getId();
            $cost = (float)$product->getData('cost');
            $unitValue = $cost > 0 ? $cost : (float)$product->getData('price');

            $rows[$id]['product_name'] = (string)$product->getName();
            $rows[$id]['unit_value'] = $unitValue;
            $rows[$id]['value_basis'] = $cost > 0 ? 'cost' : 'price';
            $rows[$id]['stock_value'] = round($unitValue * $rows[$id]['qty'], 2);
        }
    }
}
