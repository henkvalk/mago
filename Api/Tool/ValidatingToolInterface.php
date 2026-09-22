<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Tool;

/**
 * A tool that can refuse a proposed write before the confirmation prompt. Kept apart from
 * ToolInterface so existing tools outside this module need not implement it.
 * @api
 */
interface ValidatingToolInterface
{
    /**
     * @return array|null The tool result to record instead of asking for confirmation, or null
     */
    public function findRefusal(array $input): ?array;
}
