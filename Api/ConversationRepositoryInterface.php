<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api;

/**
 * Conversation repository interface
 * @api
 */
interface ConversationRepositoryInterface
{
    /**
     * @param int $adminUserId
     * @param string $title
     * @return int Conversation ID
     */
    public function create(int $adminUserId, string $title = 'New Chat'): int;

    /**
     * @param int $conversationId
     * @return array
     * @deprecated Not ownership-scoped; use getByIdForUser() to avoid cross-user conversation IDOR.
     * @see self::getByIdForUser()
     */
    public function getById(int $conversationId): array;

    /**
     * Load a conversation only when it belongs to the given admin user
     *
     * @param int $conversationId
     * @param int $adminUserId
     * @return array
     * @throws \InvalidArgumentException when the conversation does not exist or is not owned by the user
     */
    public function getByIdForUser(int $conversationId, int $adminUserId): array;

    /**
     * @param int $adminUserId
     * @return array
     */
    public function getListByUser(int $adminUserId): array;

    /**
     * @param int $conversationId
     * @param int|null $adminUserId when given, only deletes a conversation owned by this admin user
     * @return void
     */
    public function delete(int $conversationId, ?int $adminUserId = null): void;

    /**
     * @param int $conversationId
     * @param string $role user|assistant|system|tool
     * @param string $content
     * @param array|null $toolCalls
     * @param bool $pendingConfirmation
     * @return int Message ID
     */
    public function addMessage(
        int $conversationId,
        string $role,
        string $content,
        ?array $toolCalls = null,
        bool $pendingConfirmation = false,
        ?string $toolCallId = null
    ): int;

    /**
     * @param int $conversationId
     * @return array
     */
    public function getMessages(int $conversationId): array;

    /**
     * @param int $messageId
     * @return array
     * @deprecated Not ownership-scoped; use getMessageForUser() to avoid the IDOR closed in issue #41.
     * @see self::getMessageForUser()
     */
    public function getMessageById(int $messageId): array;

    /**
     * Load a message only when its conversation belongs to the given admin user
     *
     * @param int $messageId
     * @param int $adminUserId
     * @return array
     * @throws \InvalidArgumentException when the message does not exist or is not owned by the user
     */
    public function getMessageForUser(int $messageId, int $adminUserId): array;

    /**
     * @param int $messageId
     * @param bool $confirmed
     * @param int|null $adminUserId when given, only resolves messages owned by this admin user
     * @return void
     */
    public function resolveConfirmation(
        int $messageId,
        bool $confirmed,
        ?int $adminUserId = null,
        ?array $toolCalls = null
    ): void;
}
