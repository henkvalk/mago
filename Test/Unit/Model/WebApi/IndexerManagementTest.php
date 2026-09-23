<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\WebApi;

use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\StateInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use Magento\Indexer\Model\Processor\MakeSharedIndexValid;
use MagoAssistant\Mago\Api\Data\IndexerResultInterface;
use MagoAssistant\Mago\Api\Data\IndexerResultInterfaceFactory;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Model\Data\IndexerResult;
use MagoAssistant\Mago\Model\WebApi\IndexerManagement;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class IndexerManagementTest extends TestCase
{
    private FakeLogger $logger;
    private MakeSharedIndexValid&MockObject $makeSharedIndexValid;

    protected function setUp(): void
    {
        $this->logger = new FakeLogger();
        $this->makeSharedIndexValid = $this->createMock(MakeSharedIndexValid::class);
    }

    #[Test]
    public function aFailingIndexerDoesNotStopTheOthers(): void
    {
        $search = $this->indexer('catalogsearch_fulltext');
        $search->method('reindexAll')->willThrowException(new \RuntimeException('OpenSearch is down'));
        $price = $this->indexer('catalog_product_price');
        $price->expects(self::once())->method('reindexAll');

        $result = $this->management([$search, $price])->reindexAll();

        self::assertSame(
            ['catalogsearch_fulltext' => 'failed', 'catalog_product_price' => 'rebuilt'],
            $this->results($result)
        );
        self::assertSame(
            ['IndexerManagement::rebuild catalogsearch_fulltext: OpenSearch is down'],
            $this->logger->getMessages()
        );
    }

    #[Test]
    public function aFailingSharedIndexValidationDoesNotStopTheOthers(): void
    {
        $stock = $this->indexer('cataloginventory_stock');
        $price = $this->indexer('catalog_product_price');
        $price->expects(self::once())->method('reindexAll');
        $this->makeSharedIndexValid->method('execute')->willThrowException(new \RuntimeException('Deadlock'));

        $result = $this->management([$stock, $price], ['cataloginventory_stock' => 'inventory'])->reindexAll();

        self::assertSame(
            ['cataloginventory_stock' => 'failed', 'catalog_product_price' => 'rebuilt'],
            $this->results($result)
        );
    }

    private function indexer(string $id): IndexerInterface&MockObject
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getId')->willReturn($id);
        $indexer->method('getTitle')->willReturn($id);
        $indexer->method('getStatus')->willReturn(StateInterface::STATUS_VALID);

        return $indexer;
    }

    /**
     * @param IndexerInterface[] $indexers
     * @param array<string, string> $sharedIndexes
     */
    private function management(array $indexers, array $sharedIndexes = []): IndexerManagement
    {
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($indexers);
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getIndexer')->willReturnCallback(
            static fn (string $id): array => ['shared_index' => $sharedIndexes[$id] ?? null]
        );
        $resultFactory = $this->createMock(IndexerResultInterfaceFactory::class);
        $resultFactory->method('create')->willReturnCallback(
            static fn (): IndexerResult => new IndexerResult()
        );

        return new IndexerManagement(
            $collectionFactory,
            $config,
            $this->makeSharedIndexValid,
            $resultFactory,
            new ErrorLogger($this->logger, new Json())
        );
    }

    /**
     * @param IndexerResultInterface[] $results
     * @return array<string, string>
     */
    private function results(array $results): array
    {
        $byId = [];
        foreach ($results as $result) {
            $byId[$result->getId()] = $result->getResult();
        }

        return $byId;
    }
}
