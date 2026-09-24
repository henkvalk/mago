<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Chat;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\ChatServiceInterface;
use MagoAssistant\Mago\Api\ConversationRepositoryInterface;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Conversation\NavigationNoteInjector;
use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Form\PageContextNormalizer;

class Confirm extends Action implements HttpPostActionInterface
{
    use FormKeyJsonValidation;

    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::assistant_write';

    public function __construct(
        Context $context,
        private readonly ChatServiceInterface $chatService,
        private readonly ConversationRepositoryInterface $conversationRepository,
        private readonly Json $json,
        private readonly ErrorLogger $errorLogger,
        private readonly FormKey $formKey,
        private readonly PageContextNormalizer $pageContextNormalizer,
        private readonly PageContextHolder $pageContextHolder,
        private readonly NavigationNoteInjector $navigationNoteInjector
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface|HttpResponse
    {
        /** @var HttpResponse $response */
        $response = $this->getResponse();
        $response->setHeader('Content-Type', 'text/event-stream', true);
        $response->setHeader('Cache-Control', 'no-cache', true);
        $response->setHeader('Connection', 'keep-alive', true);
        $response->setHeader('X-Accel-Buffering', 'no', true);
        $response->sendHeaders();

        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_implicit_flush(true);

        try {
            $rawBody = $this->getRequest()->getContent();
            $postData = $this->json->unserialize($rawBody);
            $messageId = (int)($postData['message_id'] ?? 0);

            $rawPageContext = $postData['page_context'] ?? null;
            $pageContext = $this->pageContextNormalizer->normalize($rawPageContext);
            $this->pageContextHolder->set($pageContext, $this->pageContextNormalizer->isDenied($rawPageContext));

            if (!$messageId) {
                $this->sendSse('error', ['error' => 'message_id is required']);
                $this->sendSse('done', []);
                $this->terminateResponse();
            }

            $user = $this->_auth->getUser();
            $adminUserId = $user ? (int)$user->getId() : 0;
            if (!$adminUserId) {
                $this->sendSse('error', ['error' => 'Not authorized']);
                $this->sendSse('done', []);
                $this->terminateResponse();
            }

            $message = $this->conversationRepository->getMessageForUser($messageId, $adminUserId);
            if (empty($message['pending_confirmation'])) {
                $this->sendSse('error', ['error' => 'No pending confirmation for this message']);
                $this->sendSse('done', []);
                $this->terminateResponse();
            }

            $toolCalls = $message['tool_calls'] ?? [];
            if (is_string($toolCalls)) {
                $toolCalls = $this->json->unserialize($toolCalls);
            }

            // A bulk confirmation sends the ticked tool call ids; without the field everything runs
            $selectedIds = isset($postData['tool_call_ids']) && is_array($postData['tool_call_ids'])
                ? array_values(array_map('strval', $postData['tool_call_ids']))
                : null;

            $conversationId = (int)$message['conversation_id'];

            // The status events carry the server's own measurement per tool, which is the number
            // the live card prints; keeping it means a reloaded card does not disagree with what
            // the admin just watched happen.
            $durations = [];
            $results = $this->chatService->executeConfirmedTools(
                $toolCalls,
                $adminUserId,
                function (string $type, array $data) use (&$durations) {
                    if ($type === 'tool_status' && isset($data['duration_ms'], $data['name'])) {
                        $durations[(string)$data['name']] = (int)$data['duration_ms'];
                    }
                    $this->sendSse($type, $data);
                },
                $selectedIds,
                $conversationId
            );

            $toolCalls = $this->withResultStatus($toolCalls, $results, $durations);
            $this->conversationRepository->resolveConfirmation($messageId, true, $adminUserId, $toolCalls);

            foreach ($results as $toolCallId => $result) {
                // PHP casts a numeric-string array key ("0", from providers like Ollama) to an int,
                // which addMessage()'s ?string $toolCallId rejects — after the write has already run.
                $this->conversationRepository->addMessage(
                    $conversationId,
                    'tool',
                    (string)$this->json->serialize($result),
                    null,
                    false,
                    (string)$toolCallId
                );
            }

            // Stream follow-up AI response
            $messages = $this->navigationNoteInjector->annotate($this->conversationRepository->getMessages($conversationId));

            // Collect tool response IDs to validate tool_call chains
            $toolResponseIds = [];
            foreach ($messages as $msg) {
                if (($msg['role'] ?? '') === 'tool' && !empty($msg['tool_call_id'])) {
                    $toolResponseIds[$msg['tool_call_id']] = true;
                }
            }

            $formattedMessages = [];
            foreach ($messages as $msg) {
                $role = $msg['role'] ?? 'user';
                $content = $msg['content'] ?? '';

                if (!empty($msg['tool_calls'])) {
                    $tc = $msg['tool_calls'];
                    if (is_string($tc)) {
                        try {
                            $tc = json_decode($tc, true, 512, JSON_THROW_ON_ERROR);
                        } catch (\Throwable $e) {
                            $tc = [];
                        }
                    }
                    $allResolved = true;
                    foreach ($tc as $call) {
                        if (!isset($toolResponseIds[$call['id'] ?? ''])) {
                            $allResolved = false;
                            break;
                        }
                    }
                    if ($allResolved && !empty($tc)) {
                        $entry = ['role' => $role, 'content' => $content, 'tool_calls' => $tc];
                    } else {
                        if (empty(trim($content))) {
                            continue;
                        }
                        $entry = ['role' => $role, 'content' => $content];
                    }
                } elseif ($role === 'tool') {
                    $toolCallId = $msg['tool_call_id'] ?? '';
                    if ($toolCallId && !isset($toolResponseIds[$toolCallId])) {
                        continue;
                    }
                    $entry = ['role' => $role, 'content' => $content];
                    if ($toolCallId) {
                        $entry['tool_call_id'] = $toolCallId;
                    }
                } else {
                    $entry = ['role' => $role, 'content' => $content];
                    if (!empty($msg['tool_call_id'])) {
                        $entry['tool_call_id'] = $msg['tool_call_id'];
                    }
                }

                $formattedMessages[] = $entry;
            }

            $result = $this->chatService->processMessageStreaming(
                $formattedMessages,
                function (string $type, array $data) {
                    $this->sendSse($type, $data);
                },
                $conversationId,
                $adminUserId
            );

            // The follow-up turn may itself be another write action (e.g. add variants right after
            // creating a configurable parent), so it needs the same persistence as a first turn or
            // the chained confirmation is lost.
            $content = $result['content'] ?? '';
            $pendingConfirmation = !empty($result['pending_confirmation']);
            // Only what this follow-up turn did of its own accord. The confirmed writes already
            // sit on the row that asked for them, and repeating them here would tag and trace the
            // same call twice in a reloaded conversation.
            $executedTools = $result['tool_calls'] ?? null;
            if (empty($executedTools)) {
                $executedTools = $result['executed_tool_calls'] ?? null;
            }
            $newMessageId = $this->conversationRepository->addMessage(
                $conversationId,
                'assistant',
                $content,
                $executedTools,
                $pendingConfirmation
            );

            $this->sendSse('done', [
                'message_id' => $newMessageId,
                'conversation_id' => $conversationId,
                'pending_confirmation' => $pendingConfirmation,
            ], true);
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('Confirm Controller', $e->getMessage());
            $this->sendSse('error', ['error' => $e->getMessage()]);
            $this->sendSse('done', [], true);
        }

        $this->terminateResponse();
    }

    /**
     * The confirmed calls stamped with how each one ended, for the stored row.
     *
     * Without this a reloaded conversation knows a write was allowed but not whether it worked,
     * and shows an approved card for a call that failed.
     *
     * @param array<int, array<string, mixed>> $toolCalls
     * @param array<string, mixed> $results Tool results keyed by tool call id
     * @param array<string, int> $durations Milliseconds per tool name, from the status events
     * @return array<int, array<string, mixed>>
     */
    private function withResultStatus(array $toolCalls, array $results, array $durations): array
    {
        foreach ($toolCalls as $index => $toolCall) {
            $result = $results[$toolCall['id'] ?? ''] ?? null;
            if (!is_array($result)) {
                continue;
            }
            if (!empty($result['skipped'])) {
                $toolCalls[$index]['status'] = 'skipped';
                continue;
            }
            if (isset($result['error'])) {
                $toolCalls[$index]['status'] = 'failed';
                $toolCalls[$index]['status_error'] = (string)$result['error'];
                continue;
            }
            $toolCalls[$index]['status'] = 'done';
            $duration = $durations[(string)($toolCall['name'] ?? '')] ?? null;
            if ($duration !== null) {
                $toolCalls[$index]['status_duration_ms'] = $duration;
            }
        }

        return $toolCalls;
    }

    private function sendSse(string $event, array $data, bool $pad = false): void
    {
        $payload = "event: {$event}\ndata: " . json_encode($data) . "\n\n";
        if ($pad || $event === 'tool_status') {
            $payload .= str_repeat(": \n", max(0, (int)ceil((8192 - strlen($payload)) / 3)));
        }
        echo $payload;
        flush();
    }

    /**
     * @SuppressWarnings("PHPMD.ExitExpression")
     */
    private function terminateResponse(): never
    {
        exit(0);
    }
}
