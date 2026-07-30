<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Sales\OrderManager;

use MaggyAssistant\Base\Api\Skill\ActionInterface;
use MaggyAssistant\Base\Service\Api\InternalApiClient;
use MaggyAssistant\Base\Service\Url\SecureAdminUrl;

class AddCommentAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly OrderResolver $orderResolver
    ) {
    }

    public function getName(): string
    {
        return 'add_comment';
    }

    public function getDescription(): string
    {
        return 'Add a comment or note to an order';
    }

    public function getParameterSchema(): array
    {
        return [
            'order_number' => [
                'type' => 'string',
                'description' => 'Order increment ID (e.g. "000000549")',
            ],
            'comment' => [
                'type' => 'string',
                'description' => 'Comment text to add to the order',
            ],
            'notify_customer' => [
                'type' => 'boolean',
                'description' => 'Whether to notify the customer (default: false)',
            ],
            'visible_on_front' => [
                'type' => 'boolean',
                'description' => 'Whether the comment is visible on the storefront (default: false)',
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
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $orderNumber = $params['order_number'] ?? '';
        $comment = $params['comment'] ?? '';

        if (empty($orderNumber)) {
            return ['error' => 'order_number parameter is required'];
        }

        if (empty($comment)) {
            return ['error' => 'comment parameter is required'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $order = $this->orderResolver->resolve($orderNumber, $adminUserId);
        if (isset($order['error'])) {
            return $order;
        }

        $entityId = $order['entity_id'];
        $notifyCustomer = !empty($params['notify_customer']);
        $visibleOnFront = !empty($params['visible_on_front']);

        $body = [
            'statusHistory' => [
                'comment' => $comment,
                'is_customer_notified' => $notifyCustomer ? 1 : 0,
                'is_visible_on_front' => $visibleOnFront ? 1 : 0,
            ],
        ];

        $result = $this->apiClient->post('orders/' . $entityId . '/comments', $body, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        return [
            'success' => true,
            'message' => 'Comment added to order #' . $order['increment_id'],
            'order_number' => $order['increment_id'],
            'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
        ];
    }
}
