<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\WebApi;

/**
 * Indexer management REST API interface
 * @api
 */
interface IndexerManagementInterface
{
    /**
     * Reindex all indexers
     *
     * @return string JSON response
     */
    public function reindexAll(): string;
}
