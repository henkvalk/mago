<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Sales\OrderManager;

use MaggyAssistant\Base\Api\Skill\ActionInterface;
use MaggyAssistant\Base\Service\Api\InternalApiClient;
use MaggyAssistant\Base\Service\Url\SecureAdminUrl;

class CreateCreditmemoAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly OrderResolver $orderResolver
    ) {
    }

    public function getName(): string
    {
        return 'create_creditmemo';
    }

    public function getDescription(): string
    {
        return 'Create a credit memo (refund) for an order';
    }

    public function getParameterSchema(): array
    {
        return [
            'order_number' => [
                'type' => 'string',
                'description' => 'Order increment ID (e.g. "000000549")',
            ],
            'notify_customer' => [
                'type' => 'boolean',
                'description' => 'Whether to notify the customer (default: false)',
            ],
            'adjustment_positive' => [
                'type' => 'number',
                'description' => 'Extra refund amount to add',
            ],
            'adjustment_negative' => [
                'type' => 'number',
                'description' => 'Amount to withhold from the refund',
            ],
            'comment' => [
                'type' => 'string',
                'description' => 'Optional comment to add to the credit memo',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getInstructions(): string
    {
        return 'The order must be invoiced before a credit memo can be created. '
            . 'By default, a full credit memo (all items) is created.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $orderNumber = $params['order_number'] ?? '';

        if (empty($orderNumber)) {
            return ['error' => 'order_number parameter is required'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $order = $this->orderResolver->resolve($orderNumber, $adminUserId);
        if (isset($order['error'])) {
            return $order;
        }

        $entityId = $order['entity_id'];
        $notify = $params['notify_customer'] ?? false;

        $body = [
            'notify' => (bool)$notify,
        ];

        $adjustmentPositive = $params['adjustment_positive'] ?? null;
        if ($adjustmentPositive !== null) {
            $body['adjustment_positive'] = (float)$adjustmentPositive;
        }

        $adjustmentNegative = $params['adjustment_negative'] ?? null;
        if ($adjustmentNegative !== null) {
            $body['adjustment_negative'] = (float)$adjustmentNegative;
        }

        $comment = $params['comment'] ?? '';
        if (!empty($comment)) {
            $body['comment'] = [
                'comment' => $comment,
            ];
        }

        $result = $this->apiClient->post('order/' . $entityId . '/refund', $body, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $creditmemoId = is_numeric($result) ? (int)$result : ($result['id'] ?? $result);

        return [
            'success' => true,
            'message' => 'Credit memo created for order #' . $order['increment_id'],
            'creditmemo_id' => $creditmemoId,
            'order_number' => $order['increment_id'],
            'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
        ];
    }
}
