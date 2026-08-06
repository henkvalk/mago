<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Model\Config\Source;

use MaggyAssistant\Base\Model\Ai\Claude\SupportedModel;
use Magento\Framework\Data\OptionSourceInterface;

class ClaudeModel implements OptionSourceInterface
{
    /**
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return array_map(
            fn(SupportedModel $model): array => ['value' => $model->value, 'label' => $model->label()],
            SupportedModel::cases()
        );
    }
}
