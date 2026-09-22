<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Config;

use MagoAssistant\Mago\Model\Config\SystemPromptBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SystemPromptBuilderTest extends TestCase
{
    private SystemPromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new SystemPromptBuilder();
    }

    #[Test]
    public function itTellsTheAssistantToSkipFillerAcknowledgements(): void
    {
        $prompt = $this->builder->build('auto', '2026-09-01');

        self::assertStringContainsString('skip filler acknowledgements', $prompt);
        self::assertStringContainsString('Sure, I will', $prompt);
    }

    #[Test]
    public function itTellsTheAssistantToLeadWithTheAnswerOrResult(): void
    {
        $prompt = $this->builder->build('auto', '2026-09-01');

        self::assertStringContainsString('lead with the answer or the result of the action', $prompt);
    }

    #[Test]
    public function itTellsTheAssistantToSkipUnaskedExtras(): void
    {
        $prompt = $this->builder->build('auto', '2026-09-01');

        self::assertStringContainsString(
            'do not add explanations, caveats, or offers of further help unless the user asks for them',
            $prompt
        );
    }

    #[Test]
    public function itIncludesTodaysDate(): void
    {
        $prompt = $this->builder->build('auto', '2026-09-01');

        self::assertStringContainsString('Today is 2026-09-01.', $prompt);
    }

    #[Test]
    public function itAsksToMatchTheUsersLanguageWhenAutoDetecting(): void
    {
        $prompt = $this->builder->build('auto', '2026-09-01');

        self::assertStringContainsString('Respond in the same language as the user.', $prompt);
    }

    #[Test]
    public function itForcesAFixedLanguageWhenOneIsConfigured(): void
    {
        $prompt = $this->builder->build('Dutch', '2026-09-01');

        self::assertStringContainsString(
            'IMPORTANT: You MUST always respond in Dutch, regardless of what language the user writes in.',
            $prompt
        );
        self::assertStringNotContainsString('Respond in the same language as the user.', $prompt);
    }
}
