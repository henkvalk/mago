<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\Assert;

final class MagentoObjectManager
{
    private const AREA = 'adminhtml';
    private static ?ObjectManagerInterface $objectManager = null;

    public static function get(): ObjectManagerInterface
    {
        return self::$objectManager ??= self::boot();
    }

    private static function boot(): ObjectManagerInterface
    {
        $bootstrapFile = dirname(__DIR__, 5) . '/app/bootstrap.php';

        if (!is_file($bootstrapFile)) {
            Assert::markTestSkipped('Integration tests need the module mounted inside a Magento install.');
        }

        require_once $bootstrapFile;
        $objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $objectManager->get(State::class)->setAreaCode(self::AREA);

        return $objectManager;
    }
}
