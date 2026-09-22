<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Conversation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Model\Form\PageLocation;

/**
 * Stores the admin page a user message was sent from, next to the message. Kept apart from
 * ConversationRepositoryInterface on purpose: that interface is @api, so growing addMessage()
 * would break every implementation outside this module, while this is a detail of the
 * navigation note nobody else needs to implement.
 */
class PageLocationRecorder
{
    private const TABLE = 'mago_message';
    private const COLUMN_PAGE_CONTEXT = 'page_context';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Json $json
    ) {
    }

    public function record(int $messageId, PageLocation $pageLocation): void
    {
        $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName(self::TABLE),
            [self::COLUMN_PAGE_CONTEXT => $this->json->serialize($pageLocation->toArray())],
            ['entity_id = ?' => $messageId]
        );
    }
}
