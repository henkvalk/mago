<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\WebApi;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\ChatServiceInterface;
use MagoAssistant\Mago\Api\ConversationRepositoryInterface;
use MagoAssistant\Mago\Api\WebApi\ChatManagementInterface;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Ai\ChatService;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;

class ChatManagement implements ChatManagementInterface
{
    public function __construct(
        private readonly ChatServiceInterface $chatService,
        private readonly ConversationRepositoryInterface $conversationRepository,
        private readonly UserContextInterface $userContext,
        private readonly Json $json,
        private readonly ErrorLogger $errorLogger,
        private readonly PrivacyService $privacyService
    ) {
    }

    public function sendMessage(string $message, ?int $conversationId = null): string
    {
        try {
            $adminUserId = $this->requireAdminUserId();

            $isNewConversation = !$conversationId;
            if (!$conversationId) {
                $conversationId = $this->conversationRepository->create($adminUserId);
            } else {
                // Reject posting into another admin's conversation
                $this->conversationRepository->getByIdForUser($conversationId, $adminUserId);
            }

            // Same treatment as the Stream controller (#97 decision 1): the stored copy is
            // tokenised, the title derives from the scrubbed text.
            $this->privacyService->beginConversation($conversationId);
            $message = $this->privacyService->scrubText($message);
            if ($isNewConversation) {
                $this->conversationRepository->updateTitle(
                    $conversationId,
                    $this->privacyService->safeTitle($message)
                );
            }
            $this->conversationRepository->addMessage($conversationId, 'user', $message);

            $messages = $this->conversationRepository->getMessages($conversationId);
            $formattedMessages = $this->formatMessagesForAi($messages);

            $response = $this->chatService->processMessage($formattedMessages, $conversationId, $adminUserId);

            $pendingConfirmation = !empty($response['pending_confirmation']);
            $messageId = $this->conversationRepository->addMessage(
                $conversationId,
                'assistant',
                $response['content'] ?? '',
                $response['tool_calls'] ?? null,
                $pendingConfirmation
            );

            // Display copies for the caller (#97 decision 5); stored copies stay tokenised, and the
            // canonical tool_calls stay tokenised too so a confirm re-reads them from the store.
            $toolCallsDisplay = [];
            foreach (($response['tool_calls'] ?? []) as $tc) {
                $tc['input'] = $this->privacyService->rehydrateArguments($tc['input'] ?? []);
                $toolCallsDisplay[] = $tc;
            }

            return $this->toJson([
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'content' => $this->privacyService->displayText((string)($response['content'] ?? '')),
                'tool_calls' => $response['tool_calls'] ?? [],
                'tool_calls_display' => $toolCallsDisplay,
                'pending_confirmation' => $pendingConfirmation,
            ]);
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('ChatManagement::sendMessage', $e->getMessage());
            return $this->toJson(['error' => $e->getMessage()]);
        }
    }

    public function getConversations(): string
    {
        try {
            $adminUserId = $this->requireAdminUserId();
            $conversations = $this->conversationRepository->getListByUser($adminUserId);
            return $this->toJson(['conversations' => $conversations]);
        } catch (\Throwable $e) {
            return $this->toJson(['error' => $e->getMessage()]);
        }
    }

    public function getConversation(int $conversationId): string
    {
        try {
            $adminUserId = $this->requireAdminUserId();
            $conversation = $this->conversationRepository->getByIdForUser($conversationId, $adminUserId);
            $messages = $this->conversationRepository->getMessages($conversationId);

            // Stored copies are tokenised; rehydrate for the caller like the panel's Load does
            // (#97 decision 5). The list endpoint keeps tokenised titles: rehydrating would need a
            // vault bind per row, and a token in a list title is privacy-safe.
            $this->privacyService->beginConversation($conversationId);
            $conversation['title'] = $this->privacyService->displayText((string)($conversation['title'] ?? ''));
            foreach ($messages as $index => $message) {
                if (isset($message['content']) && is_string($message['content'])) {
                    $messages[$index]['content'] = $this->privacyService->displayText($message['content']);
                }
            }
            $conversation['messages'] = $messages;

            return $this->toJson($conversation);
        } catch (\Throwable $e) {
            return $this->toJson(['error' => $e->getMessage()]);
        }
    }

    public function deleteConversation(int $conversationId): string
    {
        try {
            $adminUserId = $this->requireAdminUserId();
            $this->conversationRepository->delete($conversationId, $adminUserId);
            return $this->toJson(['success' => true]);
        } catch (\Throwable $e) {
            return $this->toJson(['error' => $e->getMessage()]);
        }
    }

    public function confirmAction(int $messageId): string
    {
        try {
            $adminUserId = $this->requireAdminUserId();
            $message = $this->conversationRepository->getMessageForUser($messageId, $adminUserId);
            if (empty($message['pending_confirmation'])) {
                return $this->toJson(['error' => 'No pending confirmation for this message']);
            }

            $toolCalls = $message['tool_calls'] ?? [];
            if (is_string($toolCalls)) {
                $toolCalls = $this->json->unserialize($toolCalls);
            }

            /** @var ChatService $chatService */
            $chatService = $this->chatService;
            $conversationId = (int)$message['conversation_id'];
            $results = $chatService->executeConfirmedTools($toolCalls, $adminUserId, null, null, $conversationId);

            $this->conversationRepository->resolveConfirmation($messageId, true, $adminUserId);
            foreach ($results as $toolCallId => $result) {
                // Store the tool result against its call id (cast: a numeric id arrives as an int key).
                $this->conversationRepository->addMessage(
                    $conversationId,
                    'tool',
                    $this->toJson($result),
                    null,
                    false,
                    (string)$toolCallId
                );
            }

            // Get follow-up response from AI
            $messages = $this->conversationRepository->getMessages($conversationId);
            $formattedMessages = $this->formatMessagesForAi($messages);
            $response = $this->chatService->processMessage($formattedMessages, $conversationId, $adminUserId);

            $responseMessageId = $this->conversationRepository->addMessage(
                $conversationId,
                'assistant',
                $response['content'] ?? ''
            );

            return $this->toJson([
                'success' => true,
                'message_id' => $responseMessageId,
                'content' => $this->privacyService->displayText((string)($response['content'] ?? '')),
                'tool_results' => $results,
            ]);
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('ChatManagement::confirmAction', $e->getMessage());
            return $this->toJson(['error' => $e->getMessage()]);
        }
    }

    public function rejectAction(int $messageId): string
    {
        try {
            $adminUserId = $this->requireAdminUserId();
            $message = $this->conversationRepository->getMessageForUser($messageId, $adminUserId);
            $this->conversationRepository->resolveConfirmation($messageId, false, $adminUserId);

            $conversationId = (int)$message['conversation_id'];

            $this->conversationRepository->addMessage(
                $conversationId,
                'assistant',
                'The action was rejected by the user. No changes were made.'
            );

            return $this->toJson(['success' => true, 'message' => 'Action rejected']);
        } catch (\Throwable $e) {
            return $this->toJson(['error' => $e->getMessage()]);
        }
    }

    /**
     * Non-admin user types (integration tokens) get ids from other tables that can
     * collide with admin_user ids, so they must not select per-user skill permissions
     * or own conversations. These endpoints therefore require an admin token.
     *
     * @return int
     * @throws AuthorizationException
     */
    private function requireAdminUserId(): int
    {
        $adminUserId = (int)$this->userContext->getUserId();
        if ((int)$this->userContext->getUserType() !== UserContextInterface::USER_TYPE_ADMIN || !$adminUserId) {
            throw new AuthorizationException(__('This endpoint requires an admin user token.'));
        }

        return $adminUserId;
    }

    private function formatMessagesForAi(array $messages): array
    {
        $formatted = [];
        foreach ($messages as $msg) {
            $entry = [
                'role' => $msg['role'],
                'content' => $msg['content'] ?? '',
            ];

            if (!empty($msg['tool_calls'])) {
                $toolCalls = $msg['tool_calls'];
                if (is_string($toolCalls)) {
                    try {
                        $toolCalls = json_decode($toolCalls, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\Throwable $e) {
                        $toolCalls = [];
                    }
                }
                $entry['tool_calls'] = $toolCalls;
            }

            $formatted[] = $entry;
        }
        return $formatted;
    }
    /**
     * @param array<string, mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return (string)$this->json->serialize($payload);
    }
}
