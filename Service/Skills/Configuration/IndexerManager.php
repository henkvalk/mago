<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Configuration;

use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use MagoAssistant\Mago\Api\Tool\ActionScopedToolInterface;
use MagoAssistant\Mago\Api\Tool\ValidatingToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class IndexerManager implements ActionScopedToolInterface, ValidatingToolInterface
{
    use ValidatesIdArgument;

    private const ACTION_DESCRIPTIONS = [
        'status' => 'list all indexers with status',
        'reindex' => 'reindex a specific indexer by ID',
        'reindex_all' => 'reindex all indexers',
        'set_mode' => 'set indexer mode to "realtime" or "schedule"',
    ];

    public function __construct(
        private readonly CollectionFactory $indexerCollectionFactory,
        private readonly IndexerRegistry $indexerRegistry
    ) {
    }

    public function getName(): string
    {
        return 'indexer_manager';
    }

    public function getDescription(): string
    {
        return $this->getDescriptionForActions(array_keys(self::ACTION_DESCRIPTIONS));
    }

    public function getDescriptionForActions(array $actionNames): string
    {
        $parts = [];
        foreach (self::ACTION_DESCRIPTIONS as $name => $description) {
            if (in_array($name, $actionNames, true)) {
                $parts[] = '"' . $name . '" (' . $description . ')';
            }
        }

        return 'Manage Magento indexers. Actions: ' . implode(', ', $parts) . '.';
    }

    public function getParameterSchema(): array
    {
        return $this->getParameterSchemaForActions(array_keys(self::ACTION_DESCRIPTIONS));
    }

    public function getParameterSchemaForActions(array $actionNames): array
    {
        $properties = [
            'action' => [
                'type' => 'string',
                'description' => 'The action to perform',
                'enum' => array_values(array_intersect(array_keys(self::ACTION_DESCRIPTIONS), $actionNames)),
            ],
        ];
        if (array_intersect(['reindex', 'set_mode'], $actionNames) !== []) {
            $properties['indexer_id'] = $this->idProperty(
                'Indexer ID for reindex/set_mode actions',
                $this->indexerTitles()
            );
        }
        if (in_array('set_mode', $actionNames, true)) {
            $properties['mode'] = [
                'type' => 'string',
                'description' => 'Indexer mode for set_mode action',
                'enum' => ['realtime', 'schedule'],
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => ['action'],
        ];
    }

    public function execute(array $params): array
    {
        $action = $params['action'] ?? '';

        return match ($action) {
            'status' => $this->getStatus(),
            'reindex' => $this->reindex($params['indexer_id'] ?? ''),
            'reindex_all' => $this->reindexAll(),
            'set_mode' => $this->setMode($params['indexer_id'] ?? '', $params['mode'] ?? ''),
            default => ['error' => 'Unknown action: ' . $action],
        };
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function isReadOnlyAction(array $input): bool
    {
        // status only reads indexer state; reindex/reindex_all/set_mode mutate. Unknown actions fail closed to write.
        return ($input['action'] ?? '') === 'status';
    }

    public function findRefusal(array $input): ?array
    {
        if (!in_array($input['action'] ?? '', ['reindex', 'set_mode'], true)) {
            return null;
        }
        $indexerId = (string)($input['indexer_id'] ?? '');
        if ($indexerId === '') {
            return null;
        }

        return $this->refusalForId('indexer', $indexerId, $this->indexerTitles());
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getFieldClassification(string $action = ''): array
    {
        return [
            'message' => [PiiClass::PUBLIC],
            'id' => [PiiClass::PUBLIC],
            'title' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'mode' => [PiiClass::PUBLIC],
            'updated' => [PiiClass::PUBLIC],
            'success' => [PiiClass::PUBLIC],
            'reindexed' => [PiiClass::PUBLIC],
            'errors' => [PiiClass::PUBLIC],
        ];
    }

    public function getMagentoAcl(array $input = []): string
    {
        // Mirrors module-indexer acl.xml: index (read, the Index Management grid),
        // invalidate (closest native resource to triggering a rebuild; Magento has
        // no dedicated reindex resource), changeMode for set_mode. Unknown actions
        // fail closed to the mode-change resource.
        return match ($input['action'] ?? '') {
            'status' => 'Magento_Indexer::index',
            'reindex', 'reindex_all' => 'Magento_Indexer::invalidate',
            default => 'Magento_Indexer::changeMode',
        };
    }

    /**
     * Every registered indexer's title by ID
     *
     * @return array<string,string>
     */
    private function indexerTitles(): array
    {
        $titles = [];
        foreach ($this->indexerCollectionFactory->create()->getItems() as $indexer) {
            $titles[(string)$indexer->getId()] = (string)$indexer->getTitle();
        }

        return $titles;
    }

    private function getStatus(): array
    {
        $collection = $this->indexerCollectionFactory->create();
        $result = [];
        foreach ($collection->getItems() as $indexer) {
            $result[] = [
                'id' => $indexer->getId(),
                'title' => $indexer->getTitle(),
                'status' => $indexer->getStatus(),
                'mode' => $indexer->isScheduled() ? 'schedule' : 'realtime',
                'updated' => $indexer->getUpdated(),
            ];
        }
        return ['indexers' => $result];
    }

    private function reindex(string $indexerId): array
    {
        if (!$indexerId) {
            return ['error' => 'indexer_id parameter is required for reindex action'];
        }

        try {
            $indexer = $this->indexerRegistry->get($indexerId);
        } catch (\Exception $e) {
            return $this->refuseUnknownId('indexer', $indexerId, $this->indexerTitles());
        }

        $indexer->reindexAll();

        return [
            'success' => true,
            'message' => sprintf('Indexer "%s" has been reindexed', $indexer->getTitle()),
        ];
    }

    private function reindexAll(): array
    {
        $collection = $this->indexerCollectionFactory->create();
        $reindexed = [];
        $errors = [];

        foreach ($collection->getItems() as $indexer) {
            try {
                $indexer->reindexAll();
                $reindexed[] = $indexer->getId();
            } catch (\Exception $e) {
                $errors[] = $indexer->getId() . ': ' . $e->getMessage();
            }
        }

        $result = [
            'success' => empty($errors),
            'message' => sprintf('%d indexers reindexed', count($reindexed)),
            'reindexed' => $reindexed,
        ];
        if ($errors) {
            $result['errors'] = $errors;
        }
        return $result;
    }

    private function setMode(string $indexerId, string $mode): array
    {
        if (!$indexerId) {
            return ['error' => 'indexer_id parameter is required for set_mode action'];
        }
        if (!in_array($mode, ['realtime', 'schedule'])) {
            return ['error' => 'mode must be "realtime" or "schedule"'];
        }

        try {
            $indexer = $this->indexerRegistry->get($indexerId);
        } catch (\Exception $e) {
            return $this->refuseUnknownId('indexer', $indexerId, $this->indexerTitles());
        }

        $indexer->setScheduled($mode === 'schedule');

        return [
            'success' => true,
            'message' => sprintf('Indexer "%s" mode set to "%s"', $indexer->getTitle(), $mode),
        ];
    }
}
