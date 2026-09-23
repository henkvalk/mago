<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\System;

use InvalidArgumentException;
use Magento\Framework\Bulk\OperationInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;

class BulkStatus implements ToolInterface
{
    private const STATUS_LABELS = [
        OperationInterface::STATUS_TYPE_COMPLETE => 'complete',
        OperationInterface::STATUS_TYPE_RETRIABLY_FAILED => 'failed',
        OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED => 'failed',
        OperationInterface::STATUS_TYPE_OPEN => 'in progress',
        OperationInterface::STATUS_TYPE_REJECTED => 'rejected',
    ];

    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly Json $json
    ) {
    }

    public function getName(): string
    {
        return 'bulk_status';
    }

    public function getDescription(): string
    {
        return 'Check how a job that was queued earlier is doing, such as a reindex.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulk_uuid' => [
                    'type' => 'string',
                    'description' => 'The operation ID from the reply that queued the job, '
                        . 'e.g. "d96f5d12-cca9-47bf-910a-804a4d1d0276".',
                ],
            ],
            'required' => ['bulk_uuid'],
        ];
    }

    public function execute(array $params): array
    {
        $bulkUuid = (string)($params['bulk_uuid'] ?? '');
        if (!$bulkUuid) {
            return ['error' => 'bulk_uuid parameter is required'];
        }

        $response = $this->apiClient->get(
            'bulk/' . rawurlencode($bulkUuid) . '/detailed-status',
            [],
            (int)($params['_admin_user_id'] ?? 0)
        );
        if (isset($response['error'])) {
            return $response;
        }

        $operations = [];
        foreach ($response['operations_list'] as $operation) {
            $operations[] = [
                'status' => self::STATUS_LABELS[$operation['status']] ?? 'unknown',
                'message' => $operation['result_message'] ?? null,
                'result' => $this->getResult($operation),
            ];
        }

        return [
            'bulk_uuid' => $bulkUuid,
            'description' => $response['description'],
            'started_at' => $response['start_time'],
            'operations' => $operations,
        ];
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

    /**
     * What the operation's service returned; absent while the job is still queued
     *
     * @param array<string, mixed> $operation
     * @return mixed
     */
    private function getResult(array $operation): mixed
    {
        $result = $operation['result_serialized_data'] ?? null;
        if (!$result) {
            return null;
        }

        try {
            return $this->json->unserialize($result);
        } catch (InvalidArgumentException $e) {
            return $result;
        }
    }
}
