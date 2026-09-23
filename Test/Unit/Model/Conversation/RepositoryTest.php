<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Conversation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Model\Conversation\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    private AdapterInterface $connection;
    private Repository $repository;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->repository = new Repository($resource, $this->createMock(Json::class));
    }

    #[Test]
    public function deleteScrubsUsageLogPayloadsForTheOwnerBeforeRemovingTheConversation(): void
    {
        $order = [];
        $this->connection->expects(self::once())
            ->method('update')
            ->with(
                'mago_usage_log',
                ['request_payload' => null, 'response_payload' => null],
                ['conversation_id = ?' => 42, 'admin_user_id = ?' => 7]
            )
            ->willReturnCallback(function () use (&$order): int {
                $order[] = 'scrub';
                return 1;
            });
        $this->connection->expects(self::once())
            ->method('delete')
            ->with('mago_conversation', ['entity_id = ?' => 42, 'admin_user_id = ?' => 7])
            ->willReturnCallback(function () use (&$order): int {
                $order[] = 'delete';
                return 1;
            });

        $this->repository->delete(42, 7);

        self::assertSame(
            ['scrub', 'delete'],
            $order,
            'payloads must be scrubbed while conversation_id still links them, i.e. before the delete'
        );
    }

    #[Test]
    public function deleteWithoutAnOwnerScrubsAndDeletesByConversationOnly(): void
    {
        $this->connection->expects(self::once())
            ->method('update')
            ->with(
                'mago_usage_log',
                ['request_payload' => null, 'response_payload' => null],
                ['conversation_id = ?' => 42]
            );
        $this->connection->expects(self::once())
            ->method('delete')
            ->with('mago_conversation', ['entity_id = ?' => 42]);

        $this->repository->delete(42);
    }
}
