<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Sales\OrderManager;

use MaggyAssistant\Base\Api\Skill\ActionInterface;
use MaggyAssistant\Base\Service\Api\InternalApiClient;
use MaggyAssistant\Base\Service\Url\SecureAdminUrl;

class CreateShipmentAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly OrderResolver $orderResolver
    ) {
    }

    public function getName(): string
    {
        return 'create_shipment';
    }

    public function getDescription(): string
    {
        return 'Create a shipment for an order, optionally with tracking information';
    }

    public function getParameterSchema(): array
    {
        return [
            'order_number' => [
                'type' => 'string',
                'description' => 'Order increment ID (e.g. "000000549")',
            ],
            'tracking_number' => [
                'type' => 'string',
                'description' => 'Tracking number for the shipment',
            ],
            'carrier_code' => [
                'type' => 'string',
                'description' => 'Carrier code (e.g. "custom", "dhl", "ups", "fedex", "postnl"). Defaults to "custom"',
            ],
            'carrier_title' => [
                'type' => 'string',
                'description' => 'Carrier display name (e.g. "PostNL", "DHL")',
            ],
            'notify_customer' => [
                'type' => 'boolean',
                'description' => 'Whether to notify the customer (default: true)',
            ],
            'comment' => [
                'type' => 'string',
                'description' => 'Optional comment to add to the shipment',
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
        return 'For European carriers (PostNL, DPD, GLS), use carrier_code "custom" with the carrier_title set to the carrier name. '
            . 'Built-in Magento carrier codes: dhl, ups, fedex, usps, dhlint.';
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
        $notify = $params['notify_customer'] ?? true;
        $comment = $params['comment'] ?? '';

        $body = [
            'notify' => (bool)$notify,
        ];

        $trackingNumber = $params['tracking_number'] ?? '';
        if (!empty($trackingNumber)) {
            $carrierCode = $params['carrier_code'] ?? 'custom';
            $carrierTitle = $params['carrier_title'] ?? $carrierCode;

            $body['tracks'] = [
                [
                    'track_number' => $trackingNumber,
                    'carrier_code' => $carrierCode,
                    'title' => $carrierTitle,
                ],
            ];
        }

        if (!empty($comment)) {
            $body['comment'] = [
                'comment' => $comment,
            ];
        }

        $result = $this->apiClient->post('order/' . $entityId . '/ship', $body, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $shipmentId = is_numeric($result) ? (int)$result : ($result['id'] ?? $result);

        return [
            'success' => true,
            'message' => 'Shipment created for order #' . $order['increment_id'],
            'shipment_id' => $shipmentId,
            'order_number' => $order['increment_id'],
            'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
        ];
    }
}
