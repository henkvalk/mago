<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Command;

/**
 * /index — the chat counterpart of bin/magento indexer:info, indexer:status and indexer:reindex
 */
class IndexCommand extends AbstractToolCommand
{
    private const STATUS_LABELS = [
        'valid' => 'Ready',
        'invalid' => 'Reindex required',
        'working' => 'Processing',
    ];

    public function getName(): string
    {
        return 'index';
    }

    public function getDescription(): string
    {
        return 'List, check or rebuild the Magento indexers';
    }

    public function getSubcommands(): array
    {
        return [
            'list' => [
                'args' => '',
                'description' => 'List all indexers with their ID and mode',
                'readOnly' => true,
            ],
            'status' => [
                'args' => '',
                'description' => 'Show the status of all indexers',
                'readOnly' => true,
            ],
            'reindex' => [
                'args' => '[indexer_id...]',
                'description' => 'Reindex all indexers, or only the given indexer IDs',
                'readOnly' => false,
            ],
        ];
    }

    public function execute(string $subcommand, array $args, int $adminUserId, callable $onChunk): string
    {
        return match ($subcommand) {
            'list' => $this->list($adminUserId, $onChunk),
            'status' => $this->status($adminUserId, $onChunk),
            'reindex' => $this->reindex($args, $adminUserId, $onChunk),
            default => $this->renderError('Unknown subcommand: ' . $subcommand),
        };
    }

    protected function getToolName(): string
    {
        return 'indexer_manager';
    }

    private function list(int $adminUserId, callable $onChunk): string
    {
        $indexers = $this->fetchIndexers($adminUserId, $onChunk);
        if (is_string($indexers)) {
            return $indexers;
        }

        $rows = [];
        foreach ($indexers as $indexer) {
            $rows[] = [
                '`' . (string)($indexer['id'] ?? '') . '`',
                (string)($indexer['title'] ?? ''),
                $this->modeLabel((string)($indexer['mode'] ?? '')),
            ];
        }

        return sprintf("**%d indexers**\n\n", count($rows)) . $this->renderTable(['ID', 'Title', 'Mode'], $rows);
    }

    private function status(int $adminUserId, callable $onChunk): string
    {
        $indexers = $this->fetchIndexers($adminUserId, $onChunk);
        if (is_string($indexers)) {
            return $indexers;
        }

        $rows = [];
        $needsReindex = 0;
        foreach ($indexers as $indexer) {
            $status = (string)($indexer['status'] ?? '');
            if ($status === 'invalid') {
                $needsReindex++;
            }
            $rows[] = [
                (string)($indexer['title'] ?? ''),
                self::STATUS_LABELS[$status] ?? ucfirst($status),
                $this->modeLabel((string)($indexer['mode'] ?? '')),
                (string)($indexer['updated'] ?? ''),
            ];
        }

        $summary = $needsReindex === 0
            ? '**All indexers are up to date.**'
            : sprintf(
                '**%d indexer%s need%s a reindex.** Run `/index reindex` to rebuild them.',
                $needsReindex,
                $needsReindex === 1 ? '' : 's',
                $needsReindex === 1 ? 's' : ''
            );

        return $summary . "\n\n" . $this->renderTable(['Indexer', 'Status', 'Mode', 'Updated'], $rows);
    }

    /**
     * Reindex the given indexers one by one, or queue a background rebuild when no ID is given
     *
     * @param string[] $args
     * @param int $adminUserId
     * @param callable $onChunk
     * @return string
     */
    private function reindex(array $args, int $adminUserId, callable $onChunk): string
    {
        if ($args !== []) {
            return $this->reindexByIds(array_values(array_unique($args)), $adminUserId, $onChunk);
        }

        return $this->reindexAll($adminUserId, $onChunk);
    }

    private function reindexAll(int $adminUserId, callable $onChunk): string
    {
        $result = $this->runTool(['action' => 'reindex_all'], $adminUserId, $onChunk);
        if (isset($result['error'])) {
            return $this->renderError((string)$result['error']);
        }

        return '**' . ($result['message'] ?? 'Reindex of all indexers queued') . '.**';
    }

    /**
     * One reindex call per ID, so an unknown ID does not stop the others
     *
     * @param string[] $indexerIds
     * @param int $adminUserId
     * @param callable $onChunk
     * @return string
     */
    private function reindexByIds(array $indexerIds, int $adminUserId, callable $onChunk): string
    {
        $lines = [];
        foreach ($indexerIds as $indexerId) {
            $result = $this->runTool(['action' => 'reindex', 'indexer_id' => $indexerId], $adminUserId, $onChunk);
            $lines[] = isset($result['error'])
                ? $this->renderError((string)$result['error'])
                : '**' . (string)($result['message'] ?? 'Indexer "' . $indexerId . '" reindexed') . '.**';
        }

        return implode("\n\n", $lines);
    }

    /**
     * Indexer rows from the status action, or the rendered error when it fails
     *
     * @return array<int, array<string, mixed>>|string
     */
    private function fetchIndexers(int $adminUserId, callable $onChunk): array|string
    {
        $result = $this->runTool(['action' => 'status'], $adminUserId, $onChunk);
        if (isset($result['error'])) {
            return $this->renderError((string)$result['error']);
        }
        $indexers = $result['indexers'] ?? [];
        if (!is_array($indexers) || $indexers === []) {
            return 'No indexers found.';
        }

        return $indexers;
    }

    private function modeLabel(string $mode): string
    {
        return $mode === 'schedule' ? 'Update by Schedule' : 'Update on Save';
    }
}
