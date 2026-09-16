<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\WebApi;

/**
 * @api
 */
interface IndexerManagementInterface
{
    /**
     * @return \MagoAssistant\Mago\Api\Data\IndexerResultInterface[]
     */
    public function reindexAll(): array;
}
