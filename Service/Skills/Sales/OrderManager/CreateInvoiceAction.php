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

class CreateInvoiceAction implements IrreversibleActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly OrderResolver $orderResolver
    ) {
    }

    public function getName(): string
    {
        return 'create_invoice';
    }

    public function getDescription(): string
    {
        return 'Create an invoice for an order';
    }

    public function getParameterSchema(): array
    {
        return [
            'order_number' => [
                'type' => 'string',
                'description' => 'Order increment ID (e.g. "000000549")',
            ],
            'capture' => [
                'type' => 'boolean',
                'description' => 'Whether to capture payment online (default: true)',
            ],
            'notify_customer' => [
                'type' => 'boolean',
                'description' => 'Whether to notify the customer (default: false)',
            ],
            'comment' => [
                'type' => 'string',
                'description' => 'Optional comment to add to the invoice',
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
        // The ack's order number is a linkable id (tokenised); the message may embed it too, which
        // the filter's vault-conceal pass covers.
        return [
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'success' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
            'invoice_id' => [PiiClass::TOKENISE, 'invoice'],
            'order_number' => [PiiClass::TOKENISE, 'order'],
        ];
    }

    public function getInstructions(): string
    {
        return 'Creating an invoice with capture=true will trigger online payment capture if the payment method supports it. '
            . 'For offline payment methods (bank transfer, check/MO), capture is always offline.';
    }

    public function getImpacts(array $params, int $adminUserId): array
    {
        $orderNumber = trim((string)($params['order_number'] ?? ''));
        $orderLabel = $orderNumber !== '' ? ' for order #' . $orderNumber : '';

        $impacts = ['Creates an invoice' . $orderLabel . '; an invoice cannot be deleted, only reversed with a credit memo.'];
        if ((bool)($params['capture'] ?? true)) {
            $impacts[] = 'Captures payment online where the payment method supports it — the customer is charged now.';
        }

        return $impacts;
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
        $capture = $params['capture'] ?? true;
        $notify = $params['notify_customer'] ?? false;

        $body = [
            'capture' => (bool)$capture,
            'notify' => (bool)$notify,
        ];

        $comment = $params['comment'] ?? '';
        if (!empty($comment)) {
            $body['comment'] = [
                'comment' => $comment,
            ];
        }

        $result = $this->apiClient->post('order/' . $entityId . '/invoice', $body, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $invoiceId = $result['result'] ?? $result['id'] ?? null;

        return [
            'success' => true,
            'message' => 'Invoice created for order #' . $order['increment_id'],
            'invoice_id' => $invoiceId,
            'order_number' => $order['increment_id'],
            'admin_url' => $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]),
        ];
    }
}
