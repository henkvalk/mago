<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Conversation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepositoryInterface;
use MagoAssistant\Mago\Service\Conversation\ConversationCleaner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationCleanerTest extends TestCase
{
    private ConfigRepositoryInterface $config;
    private AdapterInterface $connection;
    private ConversationCleaner $cleaner;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigRepositoryInterface::class);
        $this->connection = $this->createMock(AdapterInterface::class);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->cleaner = new ConversationCleaner($resource, $this->config);
    }

    #[Test]
    public function itDeletesNothingAndDoesNotTouchTheDbWhenRetentionIsZero(): void
    {
        $this->config->method('getHistoryRetentionDays')->willReturn(0);
        $this->connection->expects(self::never())->method('update');
        $this->connection->expects(self::never())->method('delete');

        self::assertSame(0, $this->cleaner->clean());
    }

    #[Test]
    public function itDeletesConversationsOlderThanTheRetentionWindow(): void
    {
        $this->config->method('getHistoryRetentionDays')->willReturn(90);
        $this->connection->method('select')->willReturn($this->selectStub());
        $this->connection->method('update')->willReturn(0);

        $this->connection->expects(self::once())
            ->method('delete')
            ->with(
                'mago_conversation',
                self::callback(function (array $where): bool {
                    // Cutoff is a UTC datetime string keyed on updated_at, roughly 90 days back.
                    if (!isset($where['updated_at < ?'])) {
                        return false;
                    }
                    $cutoff = strtotime($where['updated_at < ?'] . ' UTC');
                    $expected = strtotime('-90 days');
                    return $cutoff !== false && abs($cutoff - $expected) < 86400;
                })
            )
            ->willReturn(7);

        self::assertSame(7, $this->cleaner->clean());
    }

    #[Test]
    public function itScrubsUsageLogDebugPayloadsForThePurgedConversationsBeforeDeleting(): void
    {
        $this->config->method('getHistoryRetentionDays')->willReturn(90);

        // The scrub selects the entity_ids of the conversations that are about to be purged.
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')
            ->with('mago_conversation', 'entity_id')->willReturnSelf();
        $select->expects(self::once())->method('where')
            ->with('updated_at < ?', self::callback('is_string'))->willReturnSelf();
        $this->connection->method('select')->willReturn($select);

        $order = [];
        $this->connection->expects(self::once())
            ->method('update')
            ->with(
                'mago_usage_log',
                ['request_payload' => null, 'response_payload' => null],
                ['conversation_id IN (?)' => $select]
            )
            ->willReturnCallback(function () use (&$order): int {
                $order[] = 'scrub';
                return 3;
            });
        $this->connection->expects(self::once())
            ->method('delete')
            ->willReturnCallback(function () use (&$order): int {
                $order[] = 'delete';
                return 7;
            });

        self::assertSame(7, $this->cleaner->clean());
        self::assertSame(
            ['scrub', 'delete'],
            $order,
            'payloads must be scrubbed while conversation_id still links them, i.e. before the delete'
        );
    }

    private function selectStub(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }
}
