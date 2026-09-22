<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Skill;

/**
 * A write action that can tell, before the administrator is asked to confirm it, that it would
 * refuse anyway. Being asked to approve something that was never going to happen erodes trust in
 * the confirmation card, so such a refusal is returned as the tool result straight away instead.
 * @api
 */
interface ValidatingActionInterface
{
    /**
     * @param array $params The tool input as the model proposed it
     * @return array|null The result execute() would return for this refusal, or null when the
     *                    action would go ahead
     */
    public function findRefusal(array $params): ?array;
}
