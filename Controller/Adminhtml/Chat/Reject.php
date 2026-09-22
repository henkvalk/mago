<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\ConversationRepositoryInterface;
use MagoAssistant\Mago\Logger\ErrorLogger;

class Reject extends Action implements HttpPostActionInterface
{
    use FormKeyJsonValidation;

    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::assistant_write';

    public function __construct(
        Context $context,
        private readonly ConversationRepositoryInterface $conversationRepository,
        private readonly JsonFactory $jsonFactory,
        private readonly Json $json,
        private readonly ErrorLogger $errorLogger,
        private readonly FormKey $formKey
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $rawBody = $this->getRequest()->getContent();
            $postData = $this->json->unserialize($rawBody);
            $messageId = (int)($postData['message_id'] ?? 0);

            if (!$messageId) {
                return $result->setData(['error' => 'message_id is required']);
            }

            $user = $this->_auth->getUser();
            $adminUserId = $user ? (int)$user->getId() : 0;
            if (!$adminUserId) {
                return $result->setData(['error' => 'Not authorized']);
            }

            $message = $this->conversationRepository->getMessageForUser($messageId, $adminUserId);
            $this->conversationRepository->resolveConfirmation(
                $messageId,
                false,
                $adminUserId,
                $this->asSkipped($message['tool_calls'] ?? null)
            );

            $conversationId = (int)$message['conversation_id'];

            $this->conversationRepository->addMessage(
                $conversationId,
                'assistant',
                'The action was rejected by the user. No changes were made.'
            );

            return $result->setData(['success' => true]);
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('Reject Controller', $e->getMessage());
            return $result->setData(['error' => $e->getMessage()]);
        }
    }

    /**
     * The rejected calls marked as never run, for the stored row.
     *
     * A reloaded conversation has to be able to tell "was asked and declined" from "was asked and
     * is still waiting", and the row itself is all it has to go on.
     *
     * @param string|array<int, array<string, mixed>>|null $toolCalls
     * @return array<int, array<string, mixed>>|null
     */
    private function asSkipped(string|array|null $toolCalls): ?array
    {
        if (is_string($toolCalls)) {
            try {
                $toolCalls = $this->json->unserialize($toolCalls);
            } catch (\Throwable $e) {
                return null;
            }
        }
        if (!is_array($toolCalls)) {
            return null;
        }

        foreach ($toolCalls as $index => $toolCall) {
            if (is_array($toolCall)) {
                $toolCalls[$index]['status'] = 'skipped';
            }
        }

        return $toolCalls;
    }
}
