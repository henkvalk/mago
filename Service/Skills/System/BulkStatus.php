<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\System;

use MagoAssistant\Mago\Api\Tool\ToolInterface;

class BulkStatus implements ToolInterface
{
    public function getName(): string
    {
        return 'bulk_status';
    }

    public function getDescription(): string
    {
        return 'Check the status of a background (bulk) operation by its ID.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulk_uuid' => [
                    'type' => 'string',
                    'description' => 'The ID of the background operation, as returned when it was started',
                ],
            ],
            'required' => ['bulk_uuid'],
        ];
    }

    public function execute(array $params): array
    {
        return [];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return true;
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Logging::system_magento_logging_bulk_operations';
    }
}
