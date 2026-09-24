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
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;
use MagoAssistant\Mago\Api\ConversationRepositoryInterface;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Model\Conversation\PageLocationRecorder;
use MagoAssistant\Mago\Service\Ai\Client;
use MagoAssistant\Mago\Service\Command\CommandRunner;
use MagoAssistant\Mago\Service\Conversation\NavigationNoteInjector;
use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Form\PageContextNormalizer;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;

class Stream extends Action implements HttpPostActionInterface
{
    use FormKeyJsonValidation;

    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::assistant_read';

    public function __construct(
        Context $context,
        private readonly ChatServiceInterface $chatService,
        private readonly ConversationRepositoryInterface $conversationRepository,
        private readonly ConfigRepository $configRepository,
        private readonly Client $client,
        private readonly Json $json,
        private readonly ErrorLogger $errorLogger,
        private readonly DebugLogger $debugLogger,
        private readonly FormKey $formKey,
        private readonly CommandRunner $commandRunner,
        private readonly PageContextNormalizer $pageContextNormalizer,
        private readonly PageContextHolder $pageContextHolder,
        private readonly NavigationNoteInjector $navigationNoteInjector,
        private readonly PageLocationRecorder $pageLocationRecorder,
        private readonly PrivacyService $privacyService
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

        // Disable all output buffering for SSE
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_implicit_flush(true);

        try {
            $rawBody = $this->getRequest()->getContent();
            $postData = (array)$this->json->unserialize($rawBody);

            $rawPageContext = $postData['page_context'] ?? null;
            $pageContext = $this->pageContextNormalizer->normalize($rawPageContext);
            $this->pageContextHolder->set($pageContext, $this->pageContextNormalizer->isDenied($rawPageContext));

            // Debug-gated AND masked: the raw typed message is exactly what decision 1 keeps out of
            // persistence, and this log has no conversation to tokenise into, so values are masked
            // irreversibly by class. The page context is still summarised so the entry stays readable.
            if ($this->configRepository->isDebugEnabled()) {
                $this->debugLogger->addLog('Stream Request', [
                    'masked_request' => $this->maskedPostData($this->redactedPostData($postData)),
                ]);
            }

            $message = $postData['message'] ?? '';
            $conversationId = !empty($postData['conversation_id']) ? (int)$postData['conversation_id'] : null;

            if (!$message) {
                $this->sendSse('error', ['error' => 'Message is required']);
                $this->sendSse('done', []);
                $this->terminateResponse();
            }

            $user = $this->_auth->getUser();
            if (!$user) {
                $this->debugLogger->addLog('Stream', 'No admin user in session');
                $this->sendSse('error', ['error' => 'Admin user session not found']);
                $this->sendSse('done', []);
                $this->terminateResponse();
            }

            if (!$this->configRepository->isEnabled()) {
                $this->sendSse('error', ['error' => 'The assistant is currently disabled. Enable it in Stores > Configuration > Mago Assistant.']);
                $this->sendSse('done', []);
                $this->terminateResponse();
            }

            $adminUserId = (int)$user->getId();
            $adminName = $user->getFirstName() ?: $user->getUserName();

            // Slash commands run against Magento directly and need no AI provider
            if ($this->commandRunner->isCommand($message)) {
                $this->runCommand($message, $conversationId, $adminUserId, $adminName);
            }

            // Before the conversation row exists: an unconfigured store would otherwise persist the
            // question and then fail, leaving a conversation nobody ever got an answer to.
            try {
                $this->client->resolve();
            } catch (\Throwable $e) {
                $this->sendSse('error', ['error' => $e->getMessage()]);
                $this->sendSse('done', []);
                $this->terminateResponse();
            }

            $isNewConversation = $conversationId === null;
            $conversationId = $this->resolveConversation($conversationId, $adminUserId);

            // The stored copy is tokenised, not raw (#97 decision 1). The vault is bound first so the
            // tokens persist and later turns and the history view resolve them; the title is derived
            // from the scrubbed text for the same reason.
            $this->privacyService->beginConversation($conversationId);
            $message = $this->privacyService->scrubText($message);
            if ($isNewConversation) {
                $this->conversationRepository->updateTitle($conversationId, $this->privacyService->safeTitle($message));
            }
            $messageId = $this->conversationRepository->addMessage($conversationId, 'user', $message);
            if ($pageContext !== null) {
                $this->pageLocationRecorder->record($messageId, $pageContext->toLocation());
            }

            $messages = $this->navigationNoteInjector->annotate($this->conversationRepository->getMessages($conversationId));
            $formattedMessages = [];

            // Collect all tool response IDs to validate tool_call chains
            $toolResponseIds = [];
            foreach ($messages as $msg) {
                if (($msg['role'] ?? '') === 'tool' && !empty($msg['tool_call_id'])) {
                    $toolResponseIds[$msg['tool_call_id']] = true;
                }
            }

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
                    // Only include tool_calls if all responses exist (prevents API errors)
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
                        // Tool calls without responses (rejected/abandoned confirmation) —
                        // skip this message entirely if it has no text content
                        if (empty(trim($content))) {
                            continue;
                        }
                        $entry = ['role' => $role, 'content' => $content];
                    }
                } elseif ($role === 'tool') {
                    // Only include tool responses if they have matching tool_calls already included
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

            $this->sendSse('conversation', [
                'conversation_id' => $conversationId,
                'admin_user' => $adminName,
            ]);

            $this->debugLogger->addLog('Stream', [
                'conversation_id' => $conversationId,
                'message_count' => count($formattedMessages),
                'admin_user' => $adminName,
            ]);

            $result = $this->chatService->processMessageStreaming(
                $formattedMessages,
                function (string $type, array $data) {
                    $this->sendSse($type, $data);
                },
                $conversationId,
                $adminUserId
            );

            $this->debugLogger->addLog('Stream Result', [
                'content_length' => strlen($result['content'] ?? ''),
                'tool_calls_count' => count($result['tool_calls'] ?? []),
            ]);

            $content = $result['content'] ?? '';
            $pendingConfirmation = !empty($result['pending_confirmation']);

            $toolCalls = $result['tool_calls'] ?? null;
            if (empty($toolCalls) && !empty($result['executed_tool_calls'])) {
                $toolCalls = $result['executed_tool_calls'];
            }

            $messageId = $this->conversationRepository->addMessage(
                $conversationId,
                'assistant',
                $content,
                $toolCalls,
                $pendingConfirmation
            );

            $this->sendSse('done', [
                'message_id' => $messageId,
                'conversation_id' => $conversationId,
                'pending_confirmation' => $pendingConfirmation,
            ], true);
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('Stream Controller', $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->sendSse('error', ['error' => $e->getMessage()]);
            $this->sendSse('done', [], true);
        }

        $this->terminateResponse();
    }

    /**
     * Answer a slash command: persist the exchange like a normal turn, then stream the reply as one
     * text chunk. The command's tool_status events pass straight through to the panel.
     */
    private function runCommand(string $message, ?int $conversationId, int $adminUserId, string $adminName): never
    {
        $isNewConversation = $conversationId === null;
        $conversationId = $this->resolveConversation($conversationId, $adminUserId);

        // The command itself runs on the raw text (a token would corrupt its arguments); only the
        // stored copy is tokenised (#97 decision 1).
        $this->privacyService->beginConversation($conversationId);
        $storedMessage = $this->privacyService->scrubText($message);
        if ($isNewConversation) {
            $this->conversationRepository->updateTitle($conversationId, $this->privacyService->safeTitle($storedMessage));
        }
        $this->conversationRepository->addMessage($conversationId, 'user', $storedMessage);

        $this->sendSse('conversation', [
            'conversation_id' => $conversationId,
            'admin_user' => $adminName,
        ]);

        $content = $this->commandRunner->run(
            $message,
            $adminUserId,
            function (string $type, array $data) {
                $this->sendSse($type, $data);
            }
        );
        if ($this->configRepository->isDebugEnabled()) {
            $this->debugLogger->addLog('Slash Command', [
                'message' => $this->privacyService->maskText($message),
                'content_length' => strlen($content),
            ]);
        }

        $this->sendSse('text', ['text' => $content]);
        $messageId = $this->conversationRepository->addMessage($conversationId, 'assistant', $content);
        $this->sendSse('done', [
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'pending_confirmation' => false,
        ], true);
        $this->terminateResponse();
    }

    /**
     * Existing conversation of this admin, or a new one (titled by the caller after scrubbing)
     */
    private function resolveConversation(?int $conversationId, int $adminUserId): int
    {
        if (!$conversationId) {
            // Created with the default title; the caller sets the real one from the scrubbed message
            // once the vault is bound (the id has to exist before text can be tokenised into it).
            return $this->conversationRepository->create($adminUserId);
        }

        // Reject posting into another admin's conversation
        $this->conversationRepository->getByIdForUser($conversationId, $adminUserId);

        return $conversationId;
    }

    /**
     * A full form snapshot can be hundreds of kilobytes of field labels and values per message;
     * logging it verbatim would put untrusted client data into the debug log untouched. Only the
     * namespace, entity id and field count are worth keeping here.
     *
     * @param array<array-key, mixed> $postData
     * @return array<array-key, mixed>
     */
    private function redactedPostData(array $postData): array
    {
        if (!isset($postData['page_context'])) {
            return $postData;
        }

        $postData['page_context'] = $this->summarizePageContext($postData['page_context']);

        return $postData;
    }

    /**
     * Mask every string in the request, leaving its structure as is. Masking the serialized body
     * instead would log it as one escaped JSON string, which nobody reading the log can scan.
     *
     * @param array<array-key, mixed> $postData
     * @return array<array-key, mixed>
     */
    private function maskedPostData(array $postData): array
    {
        array_walk_recursive($postData, function (mixed &$value): void {
            if (is_string($value)) {
                $value = $this->privacyService->maskText($value);
            }
        });

        return $postData;
    }

    private function summarizePageContext(mixed $pageContext): mixed
    {
        if (!is_array($pageContext) || array_is_list($pageContext)) {
            return $pageContext;
        }

        return [
            'namespace' => $pageContext['namespace'] ?? null,
            'entityId' => $pageContext['entityId'] ?? null,
            'fieldCount' => is_array($pageContext['fields'] ?? null)
                ? count($pageContext['fields'])
                : ($pageContext['fieldCount'] ?? null),
        ];
    }

    private function sendSse(string $event, array $data, bool $pad = false): void
    {
        // phpcs:ignore Magento2.Security.LanguageConstruct.DirectOutput
        $payload = "event: {$event}\ndata: " . json_encode($data) . "\n\n";
        if ($pad || $event === 'tool_status') {
            // Add SSE comment padding to push data through network/proxy buffers (4KB)
            $payload .= str_repeat(": \n", max(0, (int)ceil((8192 - strlen($payload)) / 3)));
        }
        echo $payload;
        flush();
    }

    /**
     * Terminate response to prevent Magento from sending its own HTML response.
     * SSE requires direct output, Magento's response object would override our headers.
     *
     * @SuppressWarnings("PHPMD.ExitExpression")
     */
    private function terminateResponse(): never
    {
        // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
        exit(0);
    }
}
