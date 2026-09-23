<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Conversation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\ConversationRepositoryInterface;

class Repository implements ConversationRepositoryInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Json $json
    ) {
    }

    public function create(int $adminUserId, string $title = 'New Chat'): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_conversation');

        $connection->insert($table, [
            'admin_user_id' => $adminUserId,
            'title' => $title,
        ]);

        return (int)$connection->lastInsertId($table);
    }

    public function updateTitle(int $conversationId, string $title): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_conversation');

        $connection->update($table, ['title' => $title], ['entity_id = ?' => $conversationId]);
    }

    public function getById(int $conversationId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_conversation');

        $select = $connection->select()->from($table)->where('entity_id = ?', $conversationId);
        $row = $connection->fetchRow($select);

        if (!$row) {
            throw new \InvalidArgumentException('Conversation not found: ' . $conversationId);
        }

        return $row;
    }

    public function getByIdForUser(int $conversationId, int $adminUserId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_conversation');

        $select = $connection->select()
            ->from($table)
            ->where('entity_id = ?', $conversationId)
            ->where('admin_user_id = ?', $adminUserId);
        $row = $connection->fetchRow($select);

        if (!$row) {
            // Same message for missing and foreign rows, so the id space cannot be probed
            throw new \InvalidArgumentException('Conversation not found: ' . $conversationId);
        }

        return $row;
    }

    public function getListByUser(int $adminUserId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_conversation');

        $select = $connection->select()
            ->from($table)
            ->where('admin_user_id = ?', $adminUserId)
            ->order('updated_at DESC');

        return $connection->fetchAll($select);
    }

    public function delete(int $conversationId, ?int $adminUserId = null): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_conversation');

        $where = ['entity_id = ?' => $conversationId];
        if ($adminUserId !== null) {
            $where['admin_user_id = ?'] = $adminUserId;
        }

        // mago_usage_log.conversation_id is ON DELETE SET NULL, so its debug payloads (the full
        // prompt, including conversation text) would outlive the deleted conversation and become
        // unreachable once conversation_id is nulled. Scrub them under the same ownership guard
        // while the row is still linked; the token counts stay for usage accounting.
        $usageWhere = ['conversation_id = ?' => $conversationId];
        if ($adminUserId !== null) {
            $usageWhere['admin_user_id = ?'] = $adminUserId;
        }
        $connection->update(
            $this->resourceConnection->getTableName('mago_usage_log'),
            ['request_payload' => null, 'response_payload' => null],
            $usageWhere
        );

        $connection->delete($table, $where);
    }

    public function addMessage(
        int $conversationId,
        string $role,
        string $content,
        ?array $toolCalls = null,
        bool $pendingConfirmation = false,
        ?string $toolCallId = null
    ): int {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_message');

        $data = [
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => $content,
            'pending_confirmation' => $pendingConfirmation ? 1 : 0,
        ];

        if ($toolCalls !== null) {
            $data['tool_calls'] = $this->json->serialize($toolCalls);
        }

        if ($toolCallId !== null) {
            $data['tool_call_id'] = $toolCallId;
        }

        $connection->insert($table, $data);
        $messageId = (int)$connection->lastInsertId($table);

        // Touch conversation updated_at
        $convTable = $this->resourceConnection->getTableName('mago_conversation');
        $connection->update($convTable, ['updated_at' => new Expression('NOW()')], ['entity_id = ?' => $conversationId]);

        return $messageId;
    }

    public function getMessages(int $conversationId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_message');

        $select = $connection->select()
            ->from($table)
            ->where('conversation_id = ?', $conversationId)
            ->order('created_at ASC');

        return $connection->fetchAll($select);
    }

    public function getMessageById(int $messageId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_message');

        $select = $connection->select()->from($table)->where('entity_id = ?', $messageId);
        $row = $connection->fetchRow($select);

        if (!$row) {
            throw new \InvalidArgumentException('Message not found: ' . $messageId);
        }

        return $row;
    }

    public function getMessageForUser(int $messageId, int $adminUserId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $messageTable = $this->resourceConnection->getTableName('mago_message');
        $conversationTable = $this->resourceConnection->getTableName('mago_conversation');

        $select = $connection->select()
            ->from(['m' => $messageTable])
            ->join(['c' => $conversationTable], 'c.entity_id = m.conversation_id', [])
            ->where('m.entity_id = ?', $messageId)
            ->where('c.admin_user_id = ?', $adminUserId);
        $row = $connection->fetchRow($select);

        if (!$row) {
            // Same message for missing and foreign rows, so the id space cannot be probed
            throw new \InvalidArgumentException('Message not found: ' . $messageId);
        }

        return $row;
    }

    public function resolveConfirmation(
        int $messageId,
        bool $confirmed,
        ?int $adminUserId = null,
        ?array $toolCalls = null
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_message');

        $where = ['entity_id = ?' => $messageId];
        if ($adminUserId !== null) {
            $conversationTable = $this->resourceConnection->getTableName('mago_conversation');
            $where[] = $connection->quoteInto(
                'conversation_id IN (SELECT entity_id FROM ' . $conversationTable . ' WHERE admin_user_id = ?)',
                $adminUserId
            );
        }

        // The answered row is what a reloaded conversation rebuilds its result card from, so the
        // outcome is written back onto the calls it already holds rather than only onto the reply.
        $values = ['pending_confirmation' => 0];
        if ($toolCalls !== null) {
            $values['tool_calls'] = $this->json->serialize($toolCalls);
        }

        $connection->update($table, $values, $where);
    }
}
