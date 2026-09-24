<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Configuration;

use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Indexer\Model\Indexer\Collection;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use MagoAssistant\Mago\Service\Skills\Configuration\IndexerManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IndexerManagerTest extends TestCase
{
    private const INDEXERS = [
        'cataloginventory_stock' => 'Stock',
        'catalog_product_price' => 'Product Price',
    ];

    #[Test]
    public function itOffersTheLiveIndexerIdsAsAnEnum(): void
    {
        $schema = $this->manager()->getParameterSchema();
        $property = $schema['properties']['indexer_id'];

        self::assertSame(['cataloginventory_stock', 'catalog_product_price'], $property['enum']);
        self::assertStringContainsString('cataloginventory_stock (Stock)', $property['description']);
        self::assertSame(['action'], $schema['required']);
    }

    #[Test]
    public function itLeavesTheIdFreeWhenNoIndexerIsRegistered(): void
    {
        $property = $this->manager([])->getParameterSchema()['properties']['indexer_id'];

        self::assertArrayNotHasKey('enum', $property);
    }

    #[Test]
    public function itDoesNotRefuseReadsEmptyIdsOrKnownIds(): void
    {
        $manager = $this->manager();

        self::assertNull($manager->findRefusal(['action' => 'status', 'indexer_id' => 'nonsense']));
        self::assertNull($manager->findRefusal(['action' => 'reindex', 'indexer_id' => '']));
        self::assertNull($manager->findRefusal(['action' => 'reindex', 'indexer_id' => 'cataloginventory_stock']));
        self::assertNull($manager->findRefusal(['action' => 'set_mode', 'indexer_id' => 'catalog_product_price']));
    }

    #[Test]
    public function itRefusesAnUnknownIdBeforeConfirmationAndNamesTheValidOnes(): void
    {
        $refusal = $this->manager()
            ->findRefusal(['action' => 'reindex', 'indexer_id' => 'cataloginventory_stock_stock']);

        self::assertSame(
            'Unknown indexer: cataloginventory_stock_stock. Valid IDs: cataloginventory_stock, catalog_product_price',
            $refusal['error']
        );
    }

    #[Test]
    public function itCapsTheEchoOfARunawayId(): void
    {
        $runaway = str_repeat('aw_', 3000);

        $refusal = $this->manager()->findRefusal(['action' => 'set_mode', 'indexer_id' => $runaway]);

        $expectedStart = 'Unknown indexer: ' . substr($runaway, 0, 80) . '…. Valid IDs:';
        self::assertStringStartsWith($expectedStart, $refusal['error']);
        self::assertLessThan(200, strlen($refusal['error']));
    }

    #[Test]
    public function itRefusesToReindexAnUnknownIdAtExecutionToo(): void
    {
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('get')->willThrowException(new \InvalidArgumentException('nonsense indexer does not exist'));

        $result = $this->manager(self::INDEXERS, $registry)
            ->execute(['action' => 'reindex', 'indexer_id' => 'nonsense']);

        self::assertSame(
            'Unknown indexer: nonsense. Valid IDs: cataloginventory_stock, catalog_product_price',
            $result['error']
        );
    }

    #[Test]
    public function itReindexesAKnownId(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getTitle')->willReturn('Stock');
        $indexer->expects(self::once())->method('reindexAll');
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('get')->with('cataloginventory_stock')->willReturn($indexer);

        $result = $this->manager(self::INDEXERS, $registry)
            ->execute(['action' => 'reindex', 'indexer_id' => 'cataloginventory_stock']);

        self::assertTrue($result['success']);
        self::assertSame('Indexer "Stock" has been reindexed', $result['message']);
    }

    /**
     * @param array<string,string> $indexers Title by ID
     */
    private function manager(array $indexers = self::INDEXERS, ?IndexerRegistry $registry = null): IndexerManager
    {
        $items = [];
        foreach ($indexers as $id => $title) {
            $indexer = $this->createMock(IndexerInterface::class);
            $indexer->method('getId')->willReturn($id);
            $indexer->method('getTitle')->willReturn($title);
            $items[] = $indexer;
        }
        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn($items);
        /** @var CollectionFactory&MockObject $factory */
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new IndexerManager($factory, $registry ?? $this->createMock(IndexerRegistry::class));
    }
}
