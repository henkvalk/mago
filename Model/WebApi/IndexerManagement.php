<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\WebApi;

use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\StateInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use Magento\Indexer\Model\Processor\MakeSharedIndexValid;
use MagoAssistant\Mago\Api\WebApi\IndexerManagementInterface;

class IndexerManagement implements IndexerManagementInterface
{
    private const RESULT_REBUILT = 'rebuilt';
    private const RESULT_SHARED = 'shared';
    private const RESULT_LOCKED = 'locked';

    public function __construct(
        private readonly CollectionFactory $indexerCollectionFactory,
        private readonly ConfigInterface $config,
        private readonly MakeSharedIndexValid $makeSharedIndexValid,
        private readonly Json $json
    ) {
    }

    public function reindexAll(): string
    {
        $result = [];
        $rebuiltSharedIndexes = [];

        foreach ($this->getIndexers() as $indexer) {
            $indexerId = $indexer->getId();
            if ($indexer->getStatus() === StateInterface::STATUS_WORKING) {
                $result[] = [
                    'id' => $indexerId,
                    'title' => $indexer->getTitle(),
                    'result' => self::RESULT_LOCKED,
                ];
                continue;
            }

            $sharedIndex = $this->getSharedIndex($indexerId);
            if ($sharedIndex && in_array($sharedIndex, $rebuiltSharedIndexes)) {
                $result[] = [
                    'id' => $indexerId,
                    'title' => $indexer->getTitle(),
                    'result' => self::RESULT_SHARED,
                ];
                continue;
            }

            $indexer->reindexAll();
            if ($sharedIndex && $this->makeSharedIndexValid->execute($sharedIndex)) {
                $rebuiltSharedIndexes[] = $sharedIndex;
            }

            $result[] = [
                'id' => $indexerId,
                'title' => $indexer->getTitle(),
                'result' => self::RESULT_REBUILT,
            ];
        }

        return (string)$this->json->serialize(['indexers' => $result]);
    }

    /**
     * @return IndexerInterface[]
     */
    private function getIndexers(): array
    {
        return $this->indexerCollectionFactory->create()->getItems();
    }

    private function getSharedIndex(string $indexerId): string
    {
        return (string)($this->config->getIndexer($indexerId)['shared_index'] ?? '');
    }
}
