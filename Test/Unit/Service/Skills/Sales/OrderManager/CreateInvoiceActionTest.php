<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager;

use MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\CreateInvoiceAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CreateInvoiceActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    /**
     * getImpacts() reads only its parameters, never a constructor dependency.
     */
    private function action(): CreateInvoiceAction
    {
        return (new \ReflectionClass(CreateInvoiceAction::class))->newInstanceWithoutConstructor();
    }

    #[Test]
    public function itIsAnIrreversibleAction(): void
    {
        self::assertInstanceOf(IrreversibleActionInterface::class, $this->action());
    }

    #[Test]
    public function itWarnsThatAnInvoiceCannotBeUndoneAndNamesTheOrder(): void
    {
        $impacts = $this->action()->getImpacts(['order_number' => '000000549'], self::ADMIN_USER_ID);

        self::assertStringContainsString('000000549', $impacts[0]);
        self::assertStringContainsString('credit memo', $impacts[0]);
    }

    #[Test]
    public function captureDefaultsToOnAndIsWarned(): void
    {
        $impacts = $this->action()->getImpacts(['order_number' => '000000549'], self::ADMIN_USER_ID);

        self::assertStringContainsString('charged now', implode("\n", $impacts));
    }

    #[Test]
    public function anOfflineInvoiceIsStillIrreversibleButDoesNotChargeTheCustomer(): void
    {
        $impacts = $this->action()->getImpacts(
            ['order_number' => '000000549', 'capture' => false],
            self::ADMIN_USER_ID
        );

        self::assertStringContainsString('cannot be deleted', implode("\n", $impacts));
        self::assertStringNotContainsString('charged now', implode("\n", $impacts));
    }
}
