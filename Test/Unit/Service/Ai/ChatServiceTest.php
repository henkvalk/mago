<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Ai;

use MageOS\AiBase\Api\AiClientInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Api\Config\RepositoryInterface;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Ai\AnswerWidgets;
use MagoAssistant\Mago\Service\Ai\ChatService;
use MagoAssistant\Mago\Service\Ai\Client;
use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Skills\PermissionChecker;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Service\Usage\UsageLogger;
use MagoAssistant\Mago\Test\Unit\Fakes\BuildsStoreLayouts;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeIrreversibleAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeSkill;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChatServiceTest extends TestCase
{
    use BuildsStoreLayouts;

    private const SYSTEM_PROMPT = 'You are a Magento store assistant.';

    private const ADMIN_ID = 7;

    /** 100 tokens is 400 bytes of tool output, small enough to cross in a test and never in life */
    private const MAX_RESPONSE_TOKENS = 100;

    /** @var array<int, array<string, mixed>> Messages the provider received on the last call */
    private array $sentMessages = [];

    /** @var list<array<string, mixed>> Messages the provider received per chat() call */
    private array $requests = [];

    /** @var list<array<string, mixed>> Canned provider responses, consumed in order */
    private array $responses = [];

    /** @var array<string, string> skill name => read|write|disabled */
    private array $grants = [];

    private ChatService $chatService;

    protected function setUp(): void
    {
        $this->grants = ['cms_data' => 'read'];
        $this->chatService = $this->buildChatService();
    }

    /**
     * The service under test: a cms_data skill with one read and one write action, plus whatever
     * extra skills a test needs (an irreversible order action, a tool that fails, ...).
     *
     * @param FakeSkill[] $extraSkills
     */
    private function buildChatService(array $extraSkills = []): ChatService
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);

        $cmsData = new FakeSkill('cms_data', $authorization, [
            'list_pages' => new FakeAction('list_pages', true, [], 'Always mention the page count.'),
            'update_page' => new FakeAction('update_page', false, ['content' => ['type' => 'string']]),
            'check_links' => new FakeAction('check_links', true, [], '', ['error' => 'Link checker is offline']),
        ]);

        $checker = $this->createMock(PermissionChecker::class);
        $checker->method('isAllowed')->willReturnCallback(
            fn (int $adminUserId, string $skill, string $action): bool => match ($this->grants[$skill] ?? 'disabled') {
                'write' => true,
                'read' => $action === 'read',
                default => false,
            }
        );

        $client = $this->createMock(Client::class);
        $client->method('resolve')->willReturn($this->createMock(AiClientInterface::class));
        $client->method('chat')->willReturnCallback(function (AiClientInterface $c, array $messages): array {
            $this->requests[] = $messages;
            return array_shift($this->responses) ?? ['content' => 'done', 'tool_calls' => []];
        });
        $client->method('stream')->willReturnCallback(function (AiClientInterface $c, array $messages): array {
            $this->requests[] = $messages;
            return array_shift($this->responses) ?? ['content' => 'done', 'tool_calls' => []];
        });

        $json = new Json();
        return new ChatService(
            (new FakeConfigRepository())->withMaxToolIterations(5)->withMaxResponseTokens(4000),
            $client,
            new ToolRegistry($checker, array_merge([$cmsData], $extraSkills)),
            new DebugLogger(new FakeLogger(), $json),
            new ErrorLogger(new FakeLogger(), $json),
            $this->createMock(UsageLogger::class),
            $authorization,
            new StoreScopeContext($this->singleStoreManager()),
            new AnswerWidgets(),
            new PageContextHolder()
        );
    }

    /**
     * An order_manager skill whose "cancel" action cannot be undone
     */
    private function orderManagerSkill(?\Throwable $impactsFailure = null): FakeSkill
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);

        return new FakeSkill('order_manager', $authorization, [
            'cancel' => new FakeIrreversibleAction(
                'cancel',
                ['Order #100 is canceled and cannot be reopened.', 'Reserved stock returns to inventory.'],
                $impactsFailure
            ),
        ]);
    }

    #[Test]
    public function confirmationNamesEachToolCallAndMarksIrreversibleActions(): void
    {
        $this->grants = ['cms_data' => 'write', 'order_manager' => 'write'];
        $service = $this->buildChatService([$this->orderManagerSkill()]);
        $this->responses = [[
            'content' => '',
            'tool_calls' => [
                ['id' => 'call_1', 'name' => 'cms_data', 'input' => ['action' => 'update_page', 'content' => 'x']],
                ['id' => 'call_2', 'name' => 'order_manager', 'input' => ['action' => 'cancel', 'order_number' => '100']],
            ],
        ]];
        $confirm = null;
        $onChunk = static function (string $type, array $data) use (&$confirm): void {
            if ($type === 'confirm') {
                $confirm = $data;
            }
        };

        $service->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertNotNull($confirm);
        self::assertSame(['call_1', 'call_2'], array_column($confirm['tools'], 'id'));
        self::assertArrayNotHasKey('irreversible', $confirm['tools'][0]);
        self::assertTrue($confirm['tools'][1]['irreversible']);
        self::assertSame(
            ['Order #100 is canceled and cannot be reopened.', 'Reserved stock returns to inventory.'],
            $confirm['tools'][1]['impacts']
        );
    }

    #[Test]
    public function aBrokenImpactLookupStillAsksWithAnEmptyImpactList(): void
    {
        $this->grants = ['order_manager' => 'write'];
        $service = $this->buildChatService([$this->orderManagerSkill(new \RuntimeException('orders API down'))]);
        $this->responses = [[
            'content' => '',
            'tool_calls' => [['id' => 'call_2', 'name' => 'order_manager', 'input' => ['action' => 'cancel']]],
        ]];
        $confirm = null;
        $onChunk = static function (string $type, array $data) use (&$confirm): void {
            if ($type === 'confirm') {
                $confirm = $data;
            }
        };

        $service->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertTrue($confirm['tools'][0]['irreversible']);
        self::assertSame([], $confirm['tools'][0]['impacts']);
    }

    #[Test]
    public function executeConfirmedToolsSkipsTheCallsTheUserLeftUnticked(): void
    {
        $this->grants = ['cms_data' => 'write'];
        $this->chatService = $this->buildChatService();
        $events = [];
        $onChunk = static function (string $type, array $data) use (&$events): void {
            $events[] = $type . ':' . ($data['status'] ?? '');
        };

        $results = $this->chatService->executeConfirmedTools(
            [
                ['id' => 'call_1', 'name' => 'cms_data', 'input' => ['action' => 'update_page', 'content' => 'a']],
                ['id' => 'call_2', 'name' => 'cms_data', 'input' => ['action' => 'update_page', 'content' => 'b']],
            ],
            self::ADMIN_ID,
            $onChunk,
            ['call_2']
        );

        fwrite(STDERR, var_export($results, true));
        self::assertTrue($results['call_1']['skipped']);
        self::assertSame('update_page', $results['call_2']['executed']);
        self::assertSame(['tool_status:running', 'tool_status:done'], $events);
    }

    #[Test]
    public function executeConfirmedToolsRunsEverythingWhenNoSelectionIsGiven(): void
    {
        $this->grants = ['cms_data' => 'write'];
        $this->chatService = $this->buildChatService();

        $results = $this->chatService->executeConfirmedTools([
            ['id' => 'call_1', 'name' => 'cms_data', 'input' => ['action' => 'update_page']],
            ['id' => 'call_2', 'name' => 'cms_data', 'input' => ['action' => 'update_page']],
        ], self::ADMIN_ID);

        self::assertSame('update_page', $results['call_1']['executed']);
        self::assertSame('update_page', $results['call_2']['executed']);
    }

    #[Test]
    public function aToolThatAnswersWithAnErrorIsAnnouncedAsFailed(): void
    {
        $this->responses = [$this->toolCallResponse('check_links')];
        $events = [];
        $onChunk = static function (string $type, array $data) use (&$events): void {
            $events[] = $type . ':' . ($data['status'] ?? '') . ($data['status'] === 'failed' ? ':' . $data['message'] : '');
        };

        $this->chatService->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertSame(['tool_status:running', 'tool_status:failed:Link checker is offline'], $events);
    }

    #[Test]
    public function deniedWriteActionIsAnsweredImmediatelyInsteadOfAskingForConfirmation(): void
    {
        $this->responses = [
            $this->toolCallResponse('update_page', ['content' => 'x']),
            ['content' => 'Sorry, I cannot do that.', 'tool_calls' => []],
        ];

        $result = $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertArrayNotHasKey('pending_confirmation', $result);
        self::assertSame('Sorry, I cannot do that.', $result['content']);
        self::assertCount(2, $this->requests);

        $toolResult = $this->lastMessageOfRole($this->requests[1], 'tool');
        self::assertStringContainsString('Access denied', $toolResult['content']);
        self::assertStringContainsString('cms_data', $toolResult['content']);
    }

    #[Test]
    public function permittedWriteActionStillRequiresConfirmation(): void
    {
        $this->grants = ['cms_data' => 'write'];
        $this->responses = [$this->toolCallResponse('update_page', ['content' => 'x'])];

        $result = $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertTrue($result['pending_confirmation']);
        self::assertCount(1, $this->requests);
    }

    #[Test]
    public function instructionsAreNotInjectedForADeniedCall(): void
    {
        $this->responses = [$this->toolCallResponse('update_page', ['content' => 'x'])];

        $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        self::assertNull($this->instructionMessage($this->requests[1]));
    }

    #[Test]
    public function instructionsAreInjectedAfterAnExecutedCall(): void
    {
        $this->responses = [$this->toolCallResponse('list_pages')];

        $this->chatService->processMessage([$this->userMessage()], null, self::ADMIN_ID);

        $instruction = $this->instructionMessage($this->requests[1]);
        self::assertNotNull($instruction);
        self::assertStringContainsString('Always mention the page count.', $instruction['content']);
    }

    #[Test]
    public function streamingDoesNotAnnounceADeniedCallAsRunningNorAskForConfirmation(): void
    {
        $this->responses = [
            $this->toolCallResponse('update_page', ['content' => 'x']),
            ['content' => 'Sorry, I cannot do that.', 'tool_calls' => []],
        ];
        $events = [];
        $onChunk = static function (string $type, array $data) use (&$events): void {
            $events[] = $type;
        };

        $result = $this->chatService->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertArrayNotHasKey('pending_confirmation', $result);
        self::assertNotContains('confirm', $events);
        self::assertNotContains('tool_status', $events);
        self::assertStringContainsString('Access denied', $this->lastMessageOfRole($this->requests[1], 'tool')['content']);
    }

    #[Test]
    public function streamingAnnouncesAnExecutedReadCall(): void
    {
        $this->responses = [$this->toolCallResponse('list_pages')];
        $events = [];
        $onChunk = static function (string $type, array $data) use (&$events): void {
            $events[] = $type . ':' . ($data['status'] ?? '');
        };

        $this->chatService->processMessageStreaming([$this->userMessage()], $onChunk, null, self::ADMIN_ID);

        self::assertSame(['tool_status:running', 'tool_status:done'], $events);
    }

    #[Test]
    public function itAppendsTheStoreScopeToTheSystemPromptOfEveryRequest(): void
    {
        $service = $this->serviceWith($this->multiStoreManager());

        $service->processMessage([['role' => 'user', 'content' => 'Change the store name']]);

        self::assertSame('system', $this->sentMessages[0]['role']);
        self::assertStringStartsWith(self::SYSTEM_PROMPT . "\n\n[Store scope]", $this->sentMessages[0]['content']);
        self::assertStringContainsString('Store view "Luma" (id 2, code "luma")', $this->sentMessages[0]['content']);
        self::assertSame('user', $this->sentMessages[1]['role']);
    }

    #[Test]
    public function itAddsTheStoreScopeRightAfterACallerSuppliedSystemMessage(): void
    {
        $service = $this->serviceWith($this->multiStoreManager());

        $service->processMessage([
            ['role' => 'system', 'content' => 'Custom prompt'],
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        self::assertSame(['system', 'system', 'user'], array_column($this->sentMessages, 'role'));
        self::assertSame('Custom prompt', $this->sentMessages[0]['content']);
        self::assertStringStartsWith('[Store scope]', $this->sentMessages[1]['content']);
    }

    #[Test]
    public function itNeverInjectsTheStoreScopeTwice(): void
    {
        $service = $this->serviceWith($this->multiStoreManager());

        $service->processMessage([
            ['role' => 'system', 'content' => "Custom prompt\n\n[Store scope] already here"],
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        self::assertSame(['system', 'user'], array_column($this->sentMessages, 'role'));
    }

    #[Test]
    public function itTellsTheAssistantNotToAskOnASingleStoreView(): void
    {
        $service = $this->serviceWith($this->singleStoreManager());

        $service->processMessage([['role' => 'user', 'content' => 'Hi']]);

        self::assertStringContainsString(
            'never ask the user which store view to use',
            $this->sentMessages[0]['content']
        );
    }

    #[Test]
    public function itKeepsChattingWhenTheStoreLayoutCannotBeLoaded(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willThrowException(new \RuntimeException('stores table gone'));
        $errorLogger = $this->createMock(ErrorLogger::class);
        $errorLogger->expects(self::once())->method('addLog')->with('StoreScopeContext', 'stores table gone');

        $response = $this->serviceWith($storeManager, $errorLogger)
            ->processMessage([['role' => 'user', 'content' => 'Hi']]);

        self::assertSame('ok', $response['content']);
        self::assertSame(self::SYSTEM_PROMPT, $this->sentMessages[0]['content']);
    }

    #[Test]
    public function itAppendsTheWidgetGuideAfterTheStoreScopeWhenAnswerWidgetsAreOn(): void
    {
        $service = $this->serviceWith($this->singleStoreManager(), null, true);

        $service->processMessage([['role' => 'user', 'content' => 'How did we do this week?']]);

        $content = $this->sentMessages[0]['content'];
        self::assertStringStartsWith(self::SYSTEM_PROMPT . "\n\n[Store scope]", $content);
        self::assertStringContainsString("\n\n[Answer widgets]", $content);
        self::assertStringContainsString('"type":"rankedBars"', $content);
        self::assertSame('user', $this->sentMessages[1]['role']);
    }

    #[Test]
    public function itLeavesTheWidgetGuideOutWhenAnswerWidgetsAreOff(): void
    {
        $service = $this->serviceWith($this->singleStoreManager());

        $service->processMessage([['role' => 'user', 'content' => 'Hi']]);

        self::assertStringNotContainsString('[Answer widgets]', $this->sentMessages[0]['content']);
    }

    #[Test]
    public function itNeverInjectsTheWidgetGuideTwice(): void
    {
        $service = $this->serviceWith($this->singleStoreManager(), null, true);

        $service->processMessage([
            ['role' => 'system', 'content' => "Custom prompt\n\n[Store scope] here\n\n[Answer widgets] here"],
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        self::assertSame(['system', 'user'], array_column($this->sentMessages, 'role'));
    }

    private function serviceWith(
        StoreManagerInterface $storeManager,
        ?ErrorLogger $errorLogger = null,
        bool $answerWidgets = false
    ): ChatService {
        $configRepository = $this->createMock(RepositoryInterface::class);
        $configRepository->method('getSystemPrompt')->willReturn(self::SYSTEM_PROMPT);
        $configRepository->method('getMaxToolIterations')->willReturn(1);
        $configRepository->method('isAnswerWidgetsEnabled')->willReturn($answerWidgets);

        $client = $this->createMock(Client::class);
        $client->method('resolve')->willReturn($this->createMock(AiClientInterface::class));
        $client->method('chat')->willReturnCallback(function (AiClientInterface $aiClient, array $messages): array {
            $this->sentMessages = $messages;
            return ['content' => 'ok', 'tool_calls' => []];
        });

        return new ChatService(
            $configRepository,
            $client,
            new ToolRegistry(null, []),
            $this->createMock(DebugLogger::class),
            $errorLogger ?? $this->createMock(ErrorLogger::class),
            $this->createMock(UsageLogger::class),
            $this->createMock(AuthorizationInterface::class),
            new StoreScopeContext($storeManager),
            new AnswerWidgets(),
            new PageContextHolder()
        );
    }

    #[Test]
    public function itStagesTheFormEvenWhenTheToolResultIsTooBigToKeep(): void
    {
        $directive = ['action' => 'form_write', 'changes' => [['path' => 'description', 'value' => 'Nieuwe tekst']]];
        $service = $this->serviceWithTool((new FakeTool('page_form', ['write_fields'], []))->withResult([
            'staged' => true,
            'fields' => array_fill(0, 40, str_repeat('a', 500)),
            'client_directive' => $directive,
        ]));

        $events = [];
        $results = $service->executeConfirmedTools(
            [['id' => 'call_1', 'name' => 'page_form', 'input' => ['action' => 'write_fields']]],
            null,
            function (string $event, array $data) use (&$events): void {
                $events[] = [$event, $data];
            }
        );

        self::assertContains(['form_apply', $directive], $events);
        self::assertTrue($results['call_1']['_truncated']);
        self::assertArrayNotHasKey('client_directive', $results['call_1']);
    }

    #[Test]
    public function itDoesNotSpendTheResponseBudgetOnWhatOnlyTheBrowserSees(): void
    {
        $directive = ['action' => 'form_write', 'changes' => array_fill(0, 40, [
            'path' => 'description',
            'value' => str_repeat('a', 500),
        ])];
        $service = $this->serviceWithTool((new FakeTool('page_form', ['write_fields'], []))->withResult([
            'staged' => true,
            'client_directive' => $directive,
        ]));

        $results = $service->executeConfirmedTools(
            [['id' => 'call_1', 'name' => 'page_form', 'input' => ['action' => 'write_fields']]],
            null,
            function (string $event, array $data): void {
            }
        );

        self::assertSame(['staged' => true], $results['call_1']);
    }

    private function serviceWithTool(FakeTool $tool): ChatService
    {
        return new ChatService(
            (new FakeConfigRepository())->withMaxResponseTokens(self::MAX_RESPONSE_TOKENS),
            $this->createMock(Client::class),
            new ToolRegistry(null, [$tool]),
            $this->createMock(DebugLogger::class),
            $this->createMock(ErrorLogger::class),
            $this->createMock(UsageLogger::class),
            $this->createMock(AuthorizationInterface::class),
            new StoreScopeContext($this->createMock(StoreManagerInterface::class)),
            new AnswerWidgets(),
            new PageContextHolder()
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function toolCallResponse(string $action, array $input = []): array
    {
        return [
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_1',
                'name' => 'cms_data',
                'input' => ['action' => $action] + $input,
            ]],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function userMessage(): array
    {
        return ['role' => 'user', 'content' => 'Update the home page'];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function lastMessageOfRole(array $messages, string $role): array
    {
        $matching = array_values(array_filter($messages, static fn (array $m): bool => ($m['role'] ?? '') === $role));
        self::assertNotEmpty($matching, "No message with role {$role}");

        return end($matching);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>|null
     */
    private function instructionMessage(array $messages): ?array
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'system' && str_starts_with($message['content'], '[Instructions for')) {
                return $message;
            }
        }

        return null;
    }
}
