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
     * Rebuild every indexer
     *
     * @return string JSON response
     */
    public function reindexAll(): string;
}
