<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Command;

use MagoAssistant\Mago\Service\Command\IndexCommand;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeChatService;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePermissionChecker;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndexCommandTest extends TestCase
{
    private const ADMIN_ID = 7;

    private const STATUS = [
        'indexers' => [
            [
                'id' => 'catalog_product_price',
                'title' => 'Product Price',
                'status' => 'valid',
                'mode' => 'schedule',
                'updated' => '2026-09-07 10:00:00',
            ],
            [
                'id' => 'catalogsearch_fulltext',
                'title' => 'Catalog Search',
                'status' => 'invalid',
                'mode' => 'realtime',
                'updated' => '2026-09-06 09:00:00',
            ],
        ],
    ];

    #[Test]
    public function listShowsIdTitleAndMode(): void
    {
        $chat = new FakeChatService(static fn (array $call): array => self::STATUS);

        $reply = $this->command($chat)->execute('list', [], self::ADMIN_ID, $this->noopChunk());

        self::assertSame([['action' => 'status']], $chat->inputs());
        self::assertSame(
            "**2 indexers**\n\n"
            . "| ID | Title | Mode |\n"
            . "|---|---|---|\n"
            . "| `catalog_product_price` | Product Price | Update by Schedule |\n"
            . "| `catalogsearch_fulltext` | Catalog Search | Update on Save |",
            $reply
        );
    }

    #[Test]
    public function statusSummarisesInvalidIndexersAndTranslatesStates(): void
    {
        $chat = new FakeChatService(static fn (array $call): array => self::STATUS);

        $reply = $this->command($chat)->execute('status', [], self::ADMIN_ID, $this->noopChunk());

        self::assertStringStartsWith('**1 indexer needs a reindex.** Run `/index reindex` to rebuild them.', $reply);
        self::assertStringContainsString(
            '| Product Price | Ready | Update by Schedule | 2026-09-07 10:00:00 |',
            $reply
        );
        self::assertStringContainsString(
            '| Catalog Search | Reindex required | Update on Save | 2026-09-06 09:00:00 |',
            $reply
        );
    }

    #[Test]
    public function statusReportsWhenEverythingIsValid(): void
    {
        $chat = new FakeChatService(static fn (array $call): array => [
            'indexers' => [['id' => 'a', 'title' => 'A', 'status' => 'valid', 'mode' => 'realtime', 'updated' => '']],
        ]);

        $reply = $this->command($chat)->execute('status', [], self::ADMIN_ID, $this->noopChunk());

        self::assertStringStartsWith('**All indexers are up to date.**', $reply);
    }

    #[Test]
    public function reindexWithoutArgumentQueuesABackgroundRebuild(): void
    {
        $chat = new FakeChatService(static fn (array $call): array => [
            'success' => true,
            'message' => 'Reindex of all indexers queued',
            'bulk_uuid' => 'b1c2d3e4',
        ]);

        $reply = $this->command($chat)->execute('reindex', [], self::ADMIN_ID, $this->noopChunk());

        self::assertSame([['action' => 'reindex_all']], $chat->inputs());
        self::assertSame(
            "**Reindex of all indexers queued.**\n\nBulk operation `b1c2d3e4`.",
            $reply
        );
    }

    #[Test]
    public function reindexWithIdsQueuesEachGivenIndexerOnce(): void
    {
        $chat = new FakeChatService(static fn (array $call): array => $call['input']['indexer_id'] === 'nope'
            ? ['error' => 'Unknown indexer: nope']
            : ['success' => true, 'message' => 'Reindex queued', 'bulk_uuid' => 'uuid-' . $call['input']['indexer_id']]);

        $reply = $this->command($chat)->execute(
            'reindex',
            ['catalog_product_price', 'nope', 'catalog_product_price', 'cataloginventory_stock'],
            self::ADMIN_ID,
            $this->noopChunk()
        );

        self::assertSame([
            ['action' => 'reindex', 'indexer_id' => 'catalog_product_price'],
            ['action' => 'reindex', 'indexer_id' => 'nope'],
            ['action' => 'reindex', 'indexer_id' => 'cataloginventory_stock'],
        ], $chat->inputs());
        self::assertSame(
            "**Reindex of `catalog_product_price` queued.**\n\nBulk operation `uuid-catalog_product_price`.\n\n"
            . "**Error:** Unknown indexer: nope\n\n"
            . "**Reindex of `cataloginventory_stock` queued.**\n\nBulk operation `uuid-cataloginventory_stock`.",
            $reply
        );
    }

    #[Test]
    public function reindexAllDenialIsReportedAsError(): void
    {
        $chat = new FakeChatService(static fn (array $call): array => ['error' => 'Access denied: nope']);

        $reply = $this->command($chat)->execute('reindex', [], self::ADMIN_ID, $this->noopChunk());

        self::assertSame('**Error:** Access denied: nope', $reply);
    }

    private function command(FakeChatService $chat): IndexCommand
    {
        $checker = (new FakePermissionChecker())
            ->withDecision('indexer_manager', 'read', true)
            ->withDecision('indexer_manager', 'write', true);
        $registry = new ToolRegistry($checker, [
            new FakeTool('indexer_manager', ['status', 'reindex', 'reindex_all'], ['status']),
        ]);

        return new IndexCommand($registry, $chat);
    }

    private function noopChunk(): callable
    {
        return static function (string $type, array $data): void {
        };
    }
}
