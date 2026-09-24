<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Ai;

use MageOS\AiBase\Api\AiClientInterface;
use MagoAssistant\Mago\Api\Tool\ValidatingToolInterface;
use MagoAssistant\Mago\Api\ChatServiceInterface;
use MagoAssistant\Mago\Api\Tool\IrreversibleToolInterface;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepository;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Logger\ErrorLogger;
use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Service\Usage\UsageLogger;

class ChatService implements ChatServiceInterface
{
    private const BYTES_PER_TOKEN_ESTIMATE = 4;

    /** Marker that opens the store scope section so it is never injected twice */
    private const STORE_SCOPE_MARKER = '[Store scope]';

    /**
     * A tool result carrying this key gets its value forwarded to the panel as a form_apply SSE
     * event and stripped from what the provider and the conversation history see. ChatService does
     * not know what the value means; task 006 defines the shape a tool may put there.
     */
    private const CLIENT_DIRECTIVE_KEY = 'client_directive';

    private const FORM_APPLY_EVENT = 'form_apply';

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly Client $client,
        private readonly ToolRegistry $toolRegistry,
        private readonly DebugLogger $debugLogger,
        private readonly ErrorLogger $errorLogger,
        private readonly UsageLogger $usageLogger,
        private readonly AuthorizationInterface $authorization,
        private readonly StoreScopeContext $storeScopeContext,
        private readonly AnswerWidgets $answerWidgets,
        private readonly PageContextHolder $pageContextHolder,
        private readonly PrivacyService $privacyService
    ) {
    }

    /**
     * Some models (gpt-4o in particular) answer a tool result with an empty completion, or announce
     * the next step in prose without calling the tool. One follow-up turn recovers both cases.
     */
    private const EMPTY_TURN_NUDGE = [
        'role' => 'user',
        'content' => 'Your previous reply was empty. If a next tool call is required to finish the task, '
            . 'make it now. Otherwise summarise the result for the user.',
    ];

    public function processMessage(array $messages, ?int $conversationId = null, ?int $adminUserId = null): array
    {
        try {
            $client = $this->client->resolve();
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('ChatService', $e->getMessage());
            return ['content' => 'An error occurred: ' . $e->getMessage(), 'tool_calls' => []];
        }

        $tools = $this->toolRegistry->getToolDefinitions($adminUserId);
        $maxIterations = $this->configRepository->getMaxToolIterations();

        if ($conversationId !== null) {
            $this->privacyService->beginConversation($conversationId);
        }
        $messages = $this->privacyService->scrubMessages($this->prependSystemMessage($messages));
        $instructedTools = [];
        $nudged = false;

        for ($i = 0; $i < $maxIterations; $i++) {
            try {
                $response = $this->client->chat($client, $messages, $tools);
            } catch (\Throwable $e) {
                $this->errorLogger->addLog('ChatService', $e->getMessage());
                return ['content' => 'An error occurred: ' . $e->getMessage(), 'tool_calls' => []];
            }

            $this->logUsage($response, $adminUserId, $conversationId, $client, $messages);

            if (empty($response['tool_calls'])) {
                if ($this->needsNudge($response, $messages, $nudged)) {
                    $messages[] = self::EMPTY_TURN_NUDGE;
                    $nudged = true;
                    continue;
                }
                return $response;
            }

            // Check if any tool call requires confirmation (permitted write action).
            // Denied write actions skip the confirm round-trip: they fall through to
            // executeTool(), which returns the denial as a tool result.
            foreach ($response['tool_calls'] as $toolCall) {
                if ($this->requiresConfirmation($toolCall, $adminUserId)) {
                    return [
                        'content' => $response['content'],
                        'tool_calls' => $response['tool_calls'],
                        'pending_confirmation' => true,
                    ];
                }
            }

            // Execute read-only tool calls and continue the loop
            $messages[] = [
                'role' => 'assistant',
                'content' => $response['content'],
                'tool_calls' => $response['tool_calls'],
            ];

            foreach ($response['tool_calls'] as $toolCall) {
                $result = $this->executeTool($toolCall, $adminUserId);
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => json_encode(
                        $this->withoutClientDirective($result),
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                ];

                $this->injectToolInstructions($toolCall, $adminUserId, $messages, $instructedTools);
            }
        }

        return ['content' => 'Maximum tool iterations reached.', 'tool_calls' => []];
    }

    public function processMessageStreaming(array $messages, callable $onChunk, ?int $conversationId = null, ?int $adminUserId = null): array
    {
        $client = $this->client->resolve();
        $tools = $this->toolRegistry->getToolDefinitions($adminUserId);
        $maxIterations = $this->configRepository->getMaxToolIterations();

        if ($conversationId !== null) {
            $this->privacyService->beginConversation($conversationId);
        }
        $messages = $this->privacyService->scrubMessages($this->prependSystemMessage($messages));
        $instructedTools = [];
        $nudged = false;
        $allToolCalls = [];

        // Rehydrate the text the admin sees, holding a token that splits across chunks. Everything
        // stored and replayed to the provider stays tokenised; only this display copy is rehydrated.
        /** @var string $carry */
        $carry = '';
        $flushCarry = function () use ($onChunk, &$carry): void {
            if ($carry !== '') {
                $onChunk('text', ['text' => $this->privacyService->displayText($carry)]);
                $carry = '';
            }
        };
        $streamOut = function (string $event, array $data) use ($onChunk, &$carry, $flushCarry): void {
            if ($event !== 'text') {
                $flushCarry();
                $onChunk($event, $data);

                return;
            }
            [$emit, $carry] = $this->privacyService->rehydrateStreamDelta($carry, (string)($data['text'] ?? ''));
            if ($emit !== '') {
                $onChunk('text', ['text' => $emit]);
            }
        };

        for ($i = 0; $i < $maxIterations; $i++) {
            try {
                $response = $this->client->stream($client, $messages, $tools, $streamOut);
                $flushCarry();
            } catch (\Throwable $e) {
                $this->errorLogger->addLog('ChatService Stream', $e->getMessage());
                throw $e;
            }

            $this->logUsage($response, $adminUserId, $conversationId, $client, $messages);

            if (empty($response['tool_calls'])) {
                if ($this->needsNudge($response, $messages, $nudged)) {
                    $messages[] = self::EMPTY_TURN_NUDGE;
                    $nudged = true;
                    continue;
                }
                if (!empty($allToolCalls)) {
                    $response['executed_tool_calls'] = $allToolCalls;
                }
                return $response;
            }

            // A write the action already knows it would refuse (no form open, denied form, unknown
            // field) is answered as a tool result right away rather than put to the administrator
            // to confirm first; the confirmation prompt is only shown for writes that can happen.
            $refusals = $this->findRefusals($response['tool_calls'], $adminUserId);

            // Check for permitted write actions needing confirmation (denied ones are
            // executed below and answered with the denial as a tool result)
            foreach ($response['tool_calls'] as $toolCall) {
                if (isset($refusals[$toolCall['id']])) {
                    continue;
                }
                if ($this->requiresConfirmation($toolCall, $adminUserId)) {
                    // Send confirm event with tool details so frontend can show what will happen.
                    // The same details go back on the calls themselves: the stored row is what a
                    // reloaded conversation rebuilds its card from, and the raw call carries
                    // neither the description nor the impact list the card is made of.
                    $confirmTools = [];
                    $describedCalls = [];
                    foreach ($response['tool_calls'] as $tc) {
                        $t = $this->requiresConfirmation($tc, $adminUserId)
                            ? $this->toolRegistry->getTool($tc['name'], $adminUserId)
                            : null;
                        if ($t === null) {
                            $describedCalls[] = $tc;
                            continue;
                        }
                        // Display copy only (#97 decision 5): the admin must see the real values
                        // they are approving, not opaque tokens; the persisted tool_calls stay
                        // tokenised and are re-checked on the confirm round-trip. describeRisk()
                        // reads the same copy, because looking an impact up by mago://order_1 finds
                        // nothing and the card then loses its list for precisely the irreversible
                        // actions it exists to spell out.
                        $shownInput = $this->privacyService->rehydrateArguments(
                            $this->inputForAction($t, $tc['input'] ?? [])
                        );
                        $details = [
                            'id' => (string)($tc['id'] ?? ''),
                            'name' => $tc['name'],
                            'description' => $t->getDescription(),
                            'input' => $shownInput,
                        ] + $this->describeRisk($t, $shownInput, $adminUserId);
                        if ($this->privacyService->containsPersonalToken($tc['input'] ?? [])) {
                            $details['sensitive'] = true;
                        }
                        $confirmTools[] = $details;
                        $describedCalls[] = $tc + $details;
                    }
                    $onChunk('confirm', ['tools' => $confirmTools]);
                    return [
                        'content' => $response['content'],
                        'tool_calls' => $describedCalls,
                        'pending_confirmation' => true,
                    ];
                }
            }

            // Execute read-only tools and loop
            $messages[] = [
                'role' => 'assistant',
                'content' => $response['content'],
                'tool_calls' => $response['tool_calls'],
            ];

            foreach ($response['tool_calls'] as $toolCall) {
                // A denied call never runs, so do not announce it as running
                $reportStatus = !$this->isToolCallDenied($toolCall, $adminUserId);
                $runningMessage = $this->getToolStatusMessage(
                    $toolCall['name'],
                    $toolCall['input']['action'] ?? '',
                    $toolCall['input'] ?? []
                );
                if ($reportStatus) {
                    $onChunk('tool_status', [
                        'name' => $toolCall['name'],
                        'status' => 'running',
                        'message' => $runningMessage,
                    ]);
                }

                $startedAt = microtime(true);
                $result = $refusals[$toolCall['id']] ?? $this->executeTool($toolCall, $adminUserId);
                $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
                $this->emitClientDirective($result, $onChunk);
                $status = $this->statusAfter($toolCall['name'], $result, $elapsedMs);

                if ($reportStatus) {
                    $onChunk('tool_status', $status);
                }

                $allToolCalls[] = $reportStatus
                    ? $this->withStatus($toolCall, $runningMessage, $status, $elapsedMs)
                    : $toolCall;

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => json_encode(
                        $this->withoutClientDirective($result),
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                ];

                $this->injectToolInstructions($toolCall, $adminUserId, $messages, $instructedTools);
            }
        }

        return ['content' => 'Maximum tool iterations reached.', 'tool_calls' => []];
    }

    /**
     * @param array<int,array<string,mixed>> $toolCalls
     * @return array<string,array<string,mixed>> Refusal results keyed by tool call id
     */
    private function findRefusals(array $toolCalls, ?int $adminUserId): array
    {
        $refusals = [];
        foreach ($toolCalls as $toolCall) {
            $tool = $this->toolRegistry->getTool($toolCall['name'], $adminUserId);
            if (!$tool instanceof ValidatingToolInterface || $tool->isReadOnlyAction($toolCall['input'] ?? [])) {
                continue;
            }
            $refusal = $tool->findRefusal($toolCall['input'] ?? []);
            if ($refusal !== null) {
                $refusals[$toolCall['id']] = $refusal;
            }
        }

        return $refusals;
    }

    /**
     * Execute the confirmed write actions
     *
     * With a bulk confirmation the user can leave calls unticked; those are answered with a
     * "skipped" tool result so the model knows they did not run, and nothing is executed for them.
     *
     * @param array $toolCalls
     * @param int|null $adminUserId
     * @param callable|null $onChunk
     * @param string[]|null $selectedIds
     * @return array Results keyed by tool call ID
     */
    public function executeConfirmedTools(
        array $toolCalls,
        ?int $adminUserId = null,
        ?callable $onChunk = null,
        ?array $selectedIds = null,
        ?int $conversationId = null
    ): array {
        // The confirm round-trip is a fresh request: without binding the vault here the persisted
        // tokenised arguments cannot rehydrate (every confirmed write would be refused) and tokens
        // minted while filtering the results would collide with earlier turns' persisted ones.
        if ($conversationId !== null) {
            $this->privacyService->beginConversation($conversationId);
        }
        $results = [];
        foreach ($toolCalls as $toolCall) {
            if ($selectedIds !== null && !in_array((string)($toolCall['id'] ?? ''), $selectedIds, true)) {
                $results[$toolCall['id']] = [
                    'skipped' => true,
                    'reason' => 'The user chose not to run this action.',
                ];
                continue;
            }

            $reportStatus = $onChunk !== null && !$this->isToolCallDenied($toolCall, $adminUserId);
            if ($reportStatus) {
                $this->reportToolStatus($onChunk, [
                    'name' => $toolCall['name'],
                    'status' => 'running',
                    'message' => $this->getToolStatusMessage(
                        $toolCall['name'],
                        $toolCall['input']['action'] ?? '',
                        $toolCall['input'] ?? []
                    ),
                ]);
            }

            $startedAt = microtime(true);
            $result = $this->executeTool($toolCall, $adminUserId);
            $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
            $this->emitClientDirective($result, $onChunk);
            $results[$toolCall['id']] = $this->withoutClientDirective($result);

            if ($reportStatus) {
                $this->reportToolStatus(
                    $onChunk,
                    $this->statusAfter($toolCall['name'], $results[$toolCall['id']], $elapsedMs)
                );
            }
        }
        return $results;
    }

    /**
     * The tool call as it should be stored: what ran, and how it ended.
     *
     * A reloaded conversation has only this row to work from, so the line the live stream drew
     * while the tool ran is kept with the call rather than rebuilt from the tool name alone.
     *
     * @param array<string, mixed> $toolCall
     * @param string $runningMessage
     * @param array<string, string> $status
     * @param int $elapsedMs
     * @return array<string, mixed>
     */
    private function withStatus(array $toolCall, string $runningMessage, array $status, int $elapsedMs): array
    {
        $toolCall['status'] = $status['status'];
        $toolCall['status_message'] = $runningMessage;
        $toolCall['status_duration_ms'] = $elapsedMs;
        if (isset($status['message'])) {
            $toolCall['status_error'] = $status['message'];
        }

        return $toolCall;
    }

    /**
     * The tool_status event that closes a call: "done", or "failed" with the tool's own error text
     *
     * @param string $toolName
     * @param array<string, mixed> $result
     * @param int $elapsedMs
     * @return array<string, mixed>
     */
    private function statusAfter(string $toolName, array $result, int $elapsedMs): array
    {
        if (isset($result['error'])) {
            return [
                'name' => $toolName,
                'status' => 'failed',
                'message' => (string)$result['error'],
                'duration_ms' => $elapsedMs,
            ];
        }

        return ['name' => $toolName, 'status' => 'done', 'duration_ms' => $elapsedMs];
    }

    /**
     * Irreversibility flag and impact list for the confirmation card, empty for a reversible write
     *
     * @param ToolInterface $tool
     * @param array<string, mixed> $input
     * @param int|null $adminUserId
     * @return array{irreversible?: bool, impacts?: string[]}
     */
    private function describeRisk(ToolInterface $tool, array $input, ?int $adminUserId): array
    {
        if (!$tool instanceof IrreversibleToolInterface || !$tool->isIrreversibleAction($input)) {
            return [];
        }

        try {
            $impacts = $tool->getImpacts($input, (int)$adminUserId);
        } catch (\Throwable $e) {
            // The card still warns without the list; a broken impact lookup must not block the ask
            $this->errorLogger->addLog('Tool Impacts', ['tool' => $tool->getName(), 'error' => $e->getMessage()]);
            $impacts = [];
        }

        return ['irreversible' => true, 'impacts' => array_values(array_map('strval', $impacts))];
    }

    private function logUsage(
        array $response,
        ?int $adminUserId,
        ?int $conversationId,
        AiClientInterface $client,
        ?array $messages = null
    ): void {
        $inputTokens = (int)($response['usage']['input_tokens'] ?? 0);
        $outputTokens = (int)($response['usage']['output_tokens'] ?? 0);

        if ($inputTokens === 0 && $outputTokens === 0) {
            return;
        }

        try {
            $this->usageLogger->log(
                $adminUserId ?? 0,
                $conversationId,
                $client->getServiceCode(),
                $client->getModel(),
                $inputTokens,
                $outputTokens,
                array_column($response['tool_calls'] ?? [], 'name'),
                $messages,
                [
                    'content' => $response['content'] ?? '',
                    'tool_calls' => $response['tool_calls'] ?? [],
                ]
            );
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('UsageLogger', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $status
     */
    private function reportToolStatus(?callable $onChunk, array $status): void
    {
        if ($onChunk === null) {
            return;
        }

        $onChunk('tool_status', $status);
    }

    /**
     * Forwards a tool result's client_directive, untouched, to the panel. Neither the presence of
     * this key nor its shape is domain knowledge ChatService holds; only json_encode-ability of the
     * value into the onChunk('type', array $data) contract is checked.
     */
    private function emitClientDirective(array $result, ?callable $onChunk): void
    {
        if ($onChunk === null || !is_array($result[self::CLIENT_DIRECTIVE_KEY] ?? null)) {
            return;
        }

        $onChunk(self::FORM_APPLY_EVENT, $result[self::CLIENT_DIRECTIVE_KEY]);
    }

    /**
     * A client_directive is transport to the browser, not something the provider should reason
     * about or the conversation history should keep replaying on every later turn.
     */
    private function withoutClientDirective(array $result): array
    {
        unset($result[self::CLIENT_DIRECTIVE_KEY]);

        return $result;
    }

    /**
     * The part of a tool result that only the browser reads, if the tool sent one
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>|null
     */
    private function clientDirectiveOf(array $result): ?array
    {
        return is_array($result[self::CLIENT_DIRECTIVE_KEY] ?? null) ? $result[self::CLIENT_DIRECTIVE_KEY] : null;
    }

    private function executeTool(array $toolCall, ?int $adminUserId = null): array
    {
        $tool = $this->toolRegistry->getTool($toolCall['name'], $adminUserId);
        if (!$tool) {
            return ['error' => 'Tool not found: ' . $toolCall['name']];
        }

        $denial = $this->getDenialReason($tool, $toolCall['input'] ?? [], $adminUserId);
        if ($denial !== null) {
            return ['error' => $denial];
        }

        // The tool's own declaration of how the invoked action's output crosses to the LLM (#97).
        $classes = $tool->getFieldClassification((string)($toolCall['input']['action'] ?? ''));

        try {
            if ($this->configRepository->isDebugEnabled()) {
                $this->debugLogger->addLog('Tool Execute', [
                    'tool' => $toolCall['name'],
                    'input' => $toolCall['input'] ?? [],
                ]);
            }
            $input = $toolCall['input'] ?? [];
            // An admin URL token never rehydrates into a write, resolvable or not: it embeds the
            // admin secret key. Masked personal values do rehydrate (#114); the confirmation card
            // shows them in plain text with a warning instead. Checked BEFORE rehydration.
            if (!$tool->isReadOnlyAction($input) && $this->privacyService->containsSensitiveToken($input)) {
                return ['error' => 'This action would write an admin URL into data. Ask the '
                    . 'administrator to enter it directly on the form or in the request.'];
            }
            // The model only ever saw tokens for scrubbed values, so swap them back to real values on
            // every execution path (read, stream, confirm); the persistent vault resolves tokens from
            // earlier turns and across the confirm round-trip. A token the vault cannot resolve
            // (forged, or minted in another conversation) must never reach a write: it would persist
            // "[order_1]" verbatim into real data. Refuse instead.
            $input = $this->privacyService->rehydrateArguments($input);
            if (!$tool->isReadOnlyAction($input) && $this->privacyService->containsToken($input)) {
                return ['error' => 'This action refers to a value that is masked for privacy. Ask the '
                    . 'administrator to enter it directly on the form or in the request.'];
            }
            if ($adminUserId !== null) {
                $input['_admin_user_id'] = $adminUserId;
            }
            $result = $tool->execute($input);
            // Privacy filter runs here, before the result is capped and sent to the LLM. The
            // client_directive bypasses it: it only ever goes to the browser, which checks the real
            // entity id against the open form and stages the real values, so a token there makes
            // every form write fail the identity check.
            $directive = $this->clientDirectiveOf($result);
            $result = $this->privacyService->filterToolResult($classes, $this->withoutClientDirective($result));
            if ($this->configRepository->isDebugEnabled()) {
                $this->debugLogger->addLog('Tool Result', ['tool' => $toolCall['name'], 'result' => $result]);
            }
            return $this->capToolResult($this->withClientDirective($result, $directive), $toolCall['name']);
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('Tool Error', [
                'tool' => $toolCall['name'],
                'error' => $e->getMessage(),
            ]);
            // The exception message can embed a rehydrated argument, so it goes through the filter
            // (its "error" envelope is re-scrubbed) rather than straight to the LLM.
            return $this->privacyService->filterToolResult($classes, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Cap tool output so one large result cannot crowd out the conversation context
     *
     * @param array<string, mixed> $result
     * @param string $toolName
     * @return array<string, mixed>
     */
    private function capToolResult(array $result, string $toolName): array
    {
        // A client_directive is stripped again before the tool message is built, so it never
        // spends context: it neither counts towards the cap nor may be truncated away with the
        // rest, or a confirmed write would be staged nowhere with nothing said about it.
        $directive = $this->clientDirectiveOf($result);
        $result = $this->withoutClientDirective($result);

        $maxBytes = $this->configRepository->getMaxResponseTokens() * self::BYTES_PER_TOKEN_ESTIMATE;
        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) <= $maxBytes) {
            return $this->withClientDirective($result, $directive);
        }

        if ($this->configRepository->isDebugEnabled()) {
            $this->debugLogger->addLog('Tool Result truncated', [
                'tool' => $toolName,
                'bytes' => strlen($json),
                'max_bytes' => $maxBytes,
            ]);
        }

        $output = mb_strcut($json, 0, $maxBytes);

        return $this->withClientDirective([
            '_truncated' => true,
            'output' => $output,
            'total_bytes' => strlen($json),
            'returned_bytes' => strlen($output),
            'note' => 'Tool output exceeded the configured limit and was truncated. The output field holds the '
                . 'beginning of the JSON result and may stop mid-value. Do not retry the same call; ask the user '
                . 'to narrow the query (filters, pagination, fewer fields) or use a more specific action.',
        ], $directive);
    }

    /**
     * Put back what only the browser reads, after the model's copy has been measured and capped
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed>|null $directive
     * @return array<string, mixed>
     */
    private function withClientDirective(array $result, ?array $directive): array
    {
        if ($directive === null) {
            return $result;
        }

        $result[self::CLIENT_DIRECTIVE_KEY] = $directive;

        return $result;
    }

    /**
     * Whether a tool call is a write action the user is permitted to perform, and
     * therefore needs the merchant's confirmation before execution. Read actions
     * and denied calls return false: both are executed directly (a denied call
     * yields the denial as its tool result instead of a confirm round-trip).
     *
     * @param array<string, mixed> $toolCall
     * @param int|null $adminUserId
     * @return bool
     */
    private function requiresConfirmation(array $toolCall, ?int $adminUserId): bool
    {
        $tool = $this->toolRegistry->getTool($toolCall['name'], $adminUserId);
        if (!$tool) {
            return false;
        }
        $input = $toolCall['input'] ?? [];
        if ($tool->isReadOnlyAction($input)) {
            return false;
        }
        return $this->getDenialReason($tool, $input, $adminUserId) === null;
    }

    /**
     * Whether executeTool() would refuse this call: unknown/unavailable tool or a denied invocation
     *
     * @param array<string, mixed> $toolCall
     * @param int|null $adminUserId
     * @return bool
     */
    private function isToolCallDenied(array $toolCall, ?int $adminUserId): bool
    {
        $tool = $this->toolRegistry->getTool($toolCall['name'], $adminUserId);

        return !$tool || $this->getDenialReason($tool, $toolCall['input'] ?? [], $adminUserId) !== null;
    }

    /**
     * Why the admin user may not perform this invocation, or null when allowed.
     * Checks the assistant skill permission first, then the tool's native Magento ACL.
     *
     * @param ToolInterface $tool
     * @param array<string, mixed> $input
     * @param int|null $adminUserId
     * @return string|null
     */
    private function getDenialReason(ToolInterface $tool, array $input, ?int $adminUserId): ?string
    {
        if (!$this->toolRegistry->isCallAllowed($tool, $input, $adminUserId)) {
            return sprintf(
                'Access denied: your skill permissions do not allow this action with the %s tool',
                $tool->getName()
            );
        }

        $magentoAcl = $tool->getMagentoAcl($input);
        if ($magentoAcl && !$this->authorization->isAllowed($magentoAcl)) {
            return sprintf(
                'Access denied: you do not have the required Magento permission (%s) to use the %s tool',
                $magentoAcl,
                $tool->getName()
            );
        }

        return null;
    }

    /**
     * Inject tool instructions once per tool per conversation (JIT).
     * Skipped when the call was denied: the tool did not run, so its usage
     * instructions would only add prompt text the model cannot act on.
     *
     * @param array<string, mixed> $toolCall
     * @param int|null $adminUserId
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, bool> $instructedTools
     */
    private function injectToolInstructions(
        array $toolCall,
        ?int $adminUserId,
        array &$messages,
        array &$instructedTools
    ): void {
        $toolName = (string)$toolCall['name'];
        if (isset($instructedTools[$toolName])) {
            return;
        }

        if ($this->isToolCallDenied($toolCall, $adminUserId)) {
            return;
        }

        $tool = $this->toolRegistry->getTool($toolName, $adminUserId);
        if ($tool === null) {
            return;
        }

        $instructions = $tool->getInstructions();
        if ($instructions) {
            $messages[] = [
                'role' => 'system',
                'content' => "[Instructions for {$toolName}]\n{$instructions}",
            ];
            if ($this->configRepository->isDebugEnabled()) {
                $this->debugLogger->addLog('JIT Instructions', ['tool' => $toolName]);
            }
        }
        $instructedTools[$toolName] = true;
    }

    private function getToolStatusMessage(string $toolName, string $action, array $input): string
    {
        $messages = [
            'sales_data.revenue_summary' => 'Calculating revenue...',
            'sales_data.recent_orders' => 'Fetching recent orders...',
            'sales_data.lookup_order' => 'Looking up order...',
            'sales_data.get_order_details' => 'Fetching order details...',
            'product_data.search' => 'Searching products...',
            'product_data.low_stock' => 'Checking low stock...',
            'product_data.get_by_sku' => 'Fetching product...',
            'dead_stock.obsolete' => 'Finding obsolete inventory...',
            'dead_stock.slow_moving' => 'Finding slow-moving inventory...',
            'customer_data.lookup_customer' => 'Searching for customer...',
            'customer_data.recent_customers' => 'Fetching recent customers...',
            'cms_data.create_page' => 'Creating CMS page...',
            'cms_data.update_page' => 'Updating CMS page...',
            'cms_data.list_pages' => 'Listing CMS pages...',
            'cms_data.create_block' => 'Creating CMS block...',
            'cms_data.update_block' => 'Updating CMS block...',
            'cms_data.list_blocks' => 'Listing CMS blocks...',
            'config_reader' => 'Reading configuration...',
            'config_writer' => 'Updating configuration...',
            'cache_manager.flush' => 'Flushing cache...',
            'cache_manager.flush_type' => 'Cleaning cache type...',
            'cache_manager.status' => 'Checking cache status...',
            'indexer_manager.reindex' => 'Reindexing...',
            'indexer_manager.reindex_all' => 'Reindexing all indexers...',
            'indexer_manager.status' => 'Checking indexer status...',
            'order_manager.create_shipment' => 'Creating shipment...',
            'order_manager.create_invoice' => 'Creating invoice...',
            'order_manager.create_creditmemo' => 'Creating credit memo...',
            'order_manager.add_comment' => 'Adding order comment...',
            'order_manager.cancel' => 'Cancelling order...',
            'order_manager.hold' => 'Holding order...',
            'order_manager.unhold' => 'Removing hold from order...',
            'page_form.describe_form' => 'Reading the form on screen...',
            'page_form.read_fields' => 'Reading field values from the form on screen...',
            'page_form.write_fields' => 'Staging field changes on the form on screen...',
        ];

        $key = $action ? "{$toolName}.{$action}" : $toolName;

        // Try exact match first
        if (isset($messages[$key])) {
            $msg = $messages[$key];

            // Add context from input
            if ($action === 'lookup_order' && !empty($input['order_number'])) {
                return 'Looking up order #' . $input['order_number'] . '...';
            }
            if ($action === 'get_by_sku' && !empty($input['query'])) {
                return 'Fetching product ' . $input['query'] . '...';
            }
            if ($action === 'lookup_customer' && !empty($input['search'])) {
                return 'Searching for customer ' . $input['search'] . '...';
            }

            return $msg;
        }

        // Try tool-level match (no action)
        if (isset($messages[$toolName])) {
            return $messages[$toolName];
        }

        return 'Running ' . $toolName . '...';
    }

    /**
     * Put the configured system prompt first and make sure the store scope summary and the answer
     * widget guide are in it.
     *
     * The scope summary is rebuilt on every request, so the assistant always checks a request
     * against the current website / store view layout before it decides whether an action belongs
     * on the default scope or on a specific website or store view. The widget guide tells it which
     * ```mago blocks the panel can render; it is skipped when answer widgets are switched off.
     */
    private function prependSystemMessage(array $messages): array
    {
        $systemPrompt = $this->configRepository->getSystemPrompt();
        $pageContextLine = $this->pageContextHolder->get()?->toPromptLine();
        $firstSystemIndex = null;
        $hasStoreScope = false;
        $hasWidgetGuide = false;
        foreach ($messages as $index => $msg) {
            if (($msg['role'] ?? '') !== 'system') {
                continue;
            }
            $firstSystemIndex ??= (int)$index;
            $content = (string)($msg['content'] ?? '');
            if (str_contains($content, self::STORE_SCOPE_MARKER)) {
                $hasStoreScope = true;
            }
            if (str_contains($content, AnswerWidgets::MARKER)) {
                $hasWidgetGuide = true;
            }
        }

        $sections = [];
        if (!$hasStoreScope) {
            $sections[] = $this->getStoreScopeSection();
        }
        if (!$hasWidgetGuide && $this->configRepository->isAnswerWidgetsEnabled()) {
            $sections[] = $this->answerWidgets->toPromptSection();
        }
        $extra = implode("\n\n", array_filter($sections, static fn (string $section): bool => $section !== ''));

        if ($firstSystemIndex === null) {
            $content = $systemPrompt;
            if ($pageContextLine !== null) {
                $content .= "\n\n" . $pageContextLine;
            }
            if ($extra !== '') {
                $content .= "\n\n" . $extra;
            }
            array_unshift($messages, ['role' => 'system', 'content' => $content]);

            return $messages;
        }

        if ($pageContextLine !== null) {
            $messages[$firstSystemIndex]['content'] = rtrim((string)$messages[$firstSystemIndex]['content'])
                . "\n\n" . $pageContextLine;
        }

        if ($extra !== '') {
            array_splice($messages, $firstSystemIndex + 1, 0, [['role' => 'system', 'content' => $extra]]);
        }

        return $messages;
    }

    /**
     * An empty completion straight after a tool result is worth exactly one retry.
     */
    private function needsNudge(array $response, array $messages, bool $nudged): bool
    {
        if ($nudged || trim((string)($response['content'] ?? '')) !== '') {
            return false;
        }
        $last = end($messages);

        return is_array($last) && ($last['role'] ?? '') === 'tool';
    }

    /**
     * Store layout and scope rules; a failure to load the stores must never take the chat down.
     */
    private function getStoreScopeSection(): string
    {
        try {
            return $this->storeScopeContext->toPromptSection();
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('StoreScopeContext', $e->getMessage());
            return '';
        }
    }

    /**
     * The parameters the chosen action actually takes. A skill offers one flat schema for all its
     * actions, and the model fills in every key it is shown, so a status change arrives carrying
     * capture, carrier_code and the credit memo adjustments. On the confirmation card that is worse
     * than noise: the admin is asked to approve a status change while reading "capture: true".
     *
     * @param array<array-key,mixed> $input
     * @return array<array-key,mixed>
     */
    private function inputForAction(object $tool, array $input): array
    {
        $action = (string)($input['action'] ?? '');
        if ($action === '' || !method_exists($tool, 'getParameterSchemaForActions')) {
            return $input;
        }

        $schema = $tool->getParameterSchemaForActions([$action]);
        $known = array_keys($schema['properties'] ?? []);
        if ($known === []) {
            return $input;
        }

        return array_filter(
            $input,
            static fn (mixed $value, string|int $key): bool => in_array((string)$key, $known, true),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
