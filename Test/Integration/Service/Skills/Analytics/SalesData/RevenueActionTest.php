<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Analytics\SalesData;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use MagoAssistant\Mago\Service\Skills\Analytics\SalesData\RevenueAction;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The numbers are pinned to what the admin dashboard (Lifetime Sales / Average Order) reports for
 * the same orders: net of tax, shipping and refunds, in the global currency.
 */
final class RevenueActionTest extends TestCase
{
    private const PERIOD = '2001-01-05:2001-01-05';
    private const CREATED_AT = '2001-01-05 12:00:00';
    private const ADMIN_USER_ID = 1;

    private SalesOrderFixture $orders;
    private RevenueAction $action;

    protected function setUp(): void
    {
        $objectManager = MagentoObjectManager::get();
        $this->orders = new SalesOrderFixture($objectManager->get(ResourceConnection::class), self::CREATED_AT);
        $this->action = $objectManager->get(RevenueAction::class);
        $this->orders->begin();
    }

    protected function tearDown(): void
    {
        $this->orders->rollBack();
    }

    #[Test]
    public function itReportsNetRevenueLikeTheAdminDashboard(): void
    {
        $this->orders->invoicedOrder(29.00, 2.39, 5.00);
        $this->orders->refundedOrder(32.00, 2.64, 5.00);

        $result = $this->summary();

        self::assertSame(29.00, $result['total_revenue']);
        self::assertSame(2, $result['order_count']);
        self::assertSame(14.50, $result['average_order_value']);
    }

    #[Test]
    public function itIgnoresCanceledAndUnpaidOrders(): void
    {
        $this->orders->invoicedOrder(40.00, 0.0, 0.0);
        $this->orders->canceledOrder(100.00);
        $this->orders->unpaidOrder(100.00, Order::STATE_NEW);
        $this->orders->unpaidOrder(100.00, Order::STATE_PENDING_PAYMENT);

        $result = $this->summary();

        self::assertSame(40.00, $result['total_revenue']);
        self::assertSame(1, $result['order_count']);
        self::assertSame(40.00, $result['average_order_value']);
    }

    #[Test]
    public function itConvertsOrdersToTheGlobalCurrency(): void
    {
        $this->orders->invoicedOrder(10.00, 0.0, 0.0, 2.0);

        $result = $this->summary();

        self::assertSame(20.00, $result['total_revenue']);
        self::assertSame(20.00, $result['average_order_value']);
    }

    #[Test]
    public function itNamesTheCurrencyOfTheAmounts(): void
    {
        $this->orders->invoicedOrder(10.00, 0.0, 0.0);

        $result = $this->summary();

        self::assertSame(SalesOrderFixture::CURRENCY, $result['currency']);
    }

    #[Test]
    public function itReturnsZeroesWhenThereAreNoOrders(): void
    {
        $result = $this->summary();

        self::assertSame(0.0, $result['total_revenue']);
        self::assertSame(0, $result['order_count']);
        self::assertSame(0.0, $result['average_order_value']);
    }

    private function summary(): array
    {
        return $this->action->execute(['period' => self::PERIOD], self::ADMIN_USER_ID);
    }
}
