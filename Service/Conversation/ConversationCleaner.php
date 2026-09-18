<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Conversation;

use Magento\Framework\App\ResourceConnection;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepositoryInterface;

/**
 * Retention purge for conversation history (issue #108, GDPR art. 5(1)(e)/17). Conversations not
 * touched within the retention window are deleted; the mago_message and mago_pii_token foreign keys
 * cascade, so the stored messages and the per-conversation PII vault go with them in the same
 * statement. Deleting by updated_at, not created_at, so a long but still-used conversation survives.
 */
class ConversationCleaner
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ConfigRepositoryInterface $configRepository
    ) {
    }

    /**
     * @return int Number of conversations deleted
     */
    public function clean(): int
    {
        $days = $this->configRepository->getHistoryRetentionDays();
        if ($days === 0) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_conversation');
        $cutoff = (new \DateTimeImmutable(sprintf('-%d days', $days), new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        return $connection->delete($table, ['updated_at < ?' => $cutoff]);
    }
}
