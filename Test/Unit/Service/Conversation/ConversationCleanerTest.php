<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Conversation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
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
        $this->connection->expects(self::never())->method('delete');

        self::assertSame(0, $this->cleaner->clean());
    }

    #[Test]
    public function itDeletesConversationsOlderThanTheRetentionWindow(): void
    {
        $this->config->method('getHistoryRetentionDays')->willReturn(90);

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
}
