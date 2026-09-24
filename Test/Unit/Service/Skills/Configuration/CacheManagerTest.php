<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Configuration;

use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\DataObject;
use MagoAssistant\Mago\Service\Skills\Configuration\CacheManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CacheManagerTest extends TestCase
{
    private const TYPES = [
        'config' => 'Configuration',
        'full_page' => 'Page Cache',
    ];

    #[Test]
    public function itOffersTheLiveCacheTypeIdsAsAnEnum(): void
    {
        $property = $this->manager($this->typeList())->getParameterSchema()['properties']['cache_type'];

        self::assertSame(['config', 'full_page'], $property['enum']);
        self::assertStringContainsString('full_page (Page Cache)', $property['description']);
    }

    #[Test]
    public function itDoesNotRefuseOtherActionsEmptyIdsOrKnownIds(): void
    {
        $manager = $this->manager($this->typeList());

        self::assertNull($manager->findRefusal(['action' => 'flush', 'cache_type' => 'nonsense']));
        self::assertNull($manager->findRefusal(['action' => 'flush_type', 'cache_type' => '']));
        self::assertNull($manager->findRefusal(['action' => 'flush_type', 'cache_type' => 'full_page']));
    }

    #[Test]
    public function itRefusesAnUnknownIdBeforeConfirmation(): void
    {
        $refusal = $this->manager($this->typeList())->findRefusal(['action' => 'flush_type', 'cache_type' => 'page']);

        self::assertSame('Unknown cache type: page. Valid IDs: config, full_page', $refusal['error']);
    }

    #[Test]
    public function itDoesNotFlushAnUnknownIdAtExecution(): void
    {
        $typeList = $this->typeList();
        $typeList->expects(self::never())->method('cleanType');

        $result = $this->manager($typeList)->execute(['action' => 'flush_type', 'cache_type' => 'pages']);

        self::assertSame('Unknown cache type: pages. Valid IDs: config, full_page', $result['error']);
    }

    #[Test]
    public function itFlushesAKnownId(): void
    {
        $typeList = $this->typeList();
        $typeList->expects(self::once())->method('cleanType')->with('full_page');

        $result = $this->manager($typeList)->execute(['action' => 'flush_type', 'cache_type' => 'full_page']);

        self::assertTrue($result['success']);
    }

    /**
     * @return TypeListInterface&MockObject
     */
    private function typeList(array $types = self::TYPES): TypeListInterface
    {
        $items = [];
        foreach ($types as $id => $label) {
            $items[$id] = new DataObject(['id' => $id, 'cache_type' => $label, 'status' => 1]);
        }
        $typeList = $this->createMock(TypeListInterface::class);
        $typeList->method('getTypes')->willReturn($items);

        return $typeList;
    }

    private function manager(TypeListInterface $typeList): CacheManager
    {
        return new CacheManager($typeList, $this->createMock(Pool::class));
    }
}
