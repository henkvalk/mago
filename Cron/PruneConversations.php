<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Cron;

use MagoAssistant\Mago\Service\Conversation\ConversationCleaner;

class PruneConversations
{
    public function __construct(
        private readonly ConversationCleaner $cleaner
    ) {
    }

    public function execute(): void
    {
        $this->cleaner->clean();
    }
}
