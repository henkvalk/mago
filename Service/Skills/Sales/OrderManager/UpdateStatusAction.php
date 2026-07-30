<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Sales\OrderManager;

use MaggyAssistant\Base\Api\Skill\ActionInterface;
use MaggyAssistant\Base\Service\Api\InternalApiClient;
use MaggyAssistant\Base\Service\Url\SecureAdminUrl;

class UpdateStatusAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly OrderResolver $orderResolver
    ) {
    }

    public function getName(): string
    {
        return 'update_status';
    }

    public function getDescription(): string
    {
        return 'Update the status of an order';
    }

    public function getParameterSchema(): array
    {
        return [
            'order_number' => [
                'type' => 'string',
                'description' => 'Order increment ID (e.g. "000000549")',
            ],
            'status' => [
                'type' => 'string',
                'description' => 'New order status (e.g. "processing", "complete", "holded")',
            ],
            'comment' => [
                'type' => 'string',
                'description' => 'Optional comment to add with the status change',
            ],
            'notify_customer' => [
                'type' => 'boolean',
                'description' => 'Whether to notify the customer (default: false)',
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
        return 'Not all status transitions are valid. Magento will reject invalid transitions via the API. '
            . 'Common statuses: pending, processing, complete, holded, canceled, closed.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $orderNumber = $params['order_number'] ?? '';
        $newStatus = $params['status'] ?? '';

        if (empty($orderNumber)) {
            return ['error' => 'order_number parameter is required'];
        }

        if (empty($newStatus)) {
            return ['error' => 'status parameter is required'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $order = $this->orderResolver->resolve($orderNumber, $adminUserId);
        if (isset($order['error'])) {
            return $order;
        }

        $entityId = $order['entity_id'];
        $oldStatus = $order['status'];
        $notifyCustomer = !empty($params['notify_customer']);
        $comment = $params['comment'] ?? '';

        $body = [
            'statusHistory' => [
                'comment' => $comment ?: 'Status updated to ' . $newStatus,
                'status' => $newStatus,
                'is_customer_notified' => $notifyCustomer ? 1 : 0,
                'is_visible_on_front' => 0,
            ],
        ];

        $result = $this->apiClient->post('orders/' . $entityId . '/comments', $body, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        return [
            'success' => true,
            'message' => 'Order #' . $order['increment_id'] . ' status updated from "' . $oldStatus . '" to "' . $newStatus . '"',
            'order_number' => $order['increment_id'],
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
        ];
    }
}
