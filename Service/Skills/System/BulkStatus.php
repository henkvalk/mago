<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\System;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class BulkStatus extends AbstractSkill
{
    public function getName(): string
    {
        return 'bulk_status';
    }

    protected function getBaseDescription(): string
    {
        return 'Check the status of a background (bulk) operation by its ID.';
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Logging::system_magento_logging_bulk_operations';
    }

    protected function getBaseInstructions(): string
    {
        return '';
    }
}
