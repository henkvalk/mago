<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager;

use MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class CancelAction implements IrreversibleActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly OrderResolver $orderResolver
    ) {
    }

    public function getName(): string
    {
        return 'cancel';
    }

    public function getDescription(): string
    {
        return 'Cancel an order';
    }

    public function getParameterSchema(): array
    {
        return [
            'order_number' => [
                'type' => 'string',
                'description' => 'Order increment ID (e.g. "000000549")',
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

    public function getFieldClassification(): array
    {
        // The ack's ids are linkable (tokenised); the message may embed the order number, which the
        // filter's vault-conceal pass covers.
        return [
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'success' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
            'order_number' => [PiiClass::TOKENISE, 'order'],
        ];
    }

    public function getInstructions(): string
    {
        return 'Not all orders can be canceled (e.g. already shipped or completed orders). '
            . 'Magento will return an error if the order cannot be canceled.';
    }

    public function getImpacts(array $params, int $adminUserId): array
    {
        $orderNumber = (string)($params['order_number'] ?? '');
        $label = $orderNumber !== '' ? 'Order #' . $orderNumber : 'The order';
        if ($orderNumber !== '' && $adminUserId) {
            $order = $this->orderResolver->resolve($orderNumber, $adminUserId);
            $status = isset($order['error']) ? '' : (string)($order['status'] ?? '');
            if ($status !== '') {
                $label .= ' (currently "' . $status . '")';
            }
        }

        return [
            $label . ' is canceled and cannot be reopened; the customer would have to order again.',
            'Reserved stock returns to inventory.',
            'Nothing is refunded automatically: a paid order still needs a credit memo.',
        ];
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

        $result = $this->apiClient->post('orders/' . $entityId . '/cancel', [], $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        // The REST endpoint answers with a boolean; false means Magento declined the cancellation.
        if (($result['result'] ?? null) === false) {
            return [
                'error' => 'Order #' . $order['increment_id'] . ' could not be canceled. Magento declined it '
                    . '— the order may already be invoiced, shipped, or completed.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Order #' . $order['increment_id'] . ' has been canceled',
            'order_number' => $order['increment_id'],
            'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
        ];
    }
}
