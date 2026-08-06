<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Model\Ai\Claude;

use Magento\Framework\Phrase;

enum SupportedModel: string
{
    case Opus5 = 'claude-opus-5';
    case Sonnet5 = 'claude-sonnet-5';
    case Haiku45 = 'claude-haiku-4-5';

    public function label(): Phrase
    {
        return match ($this) {
            self::Opus5 => __('Claude Opus 5 (most capable)'),
            self::Sonnet5 => __('Claude Sonnet 5 (balanced)'),
            self::Haiku45 => __('Claude Haiku 4.5 (fastest, cheapest)'),
        };
    }

    public function canUseTemperature(): bool
    {
        return match ($this) {
            self::Opus5, self::Sonnet5 => false,
            self::Haiku45 => true,
        };
    }

    public static function canModelUseTemperature(string $model): bool
    {
        return self::tryFrom($model)?->canUseTemperature() ?? false;
    }
}
