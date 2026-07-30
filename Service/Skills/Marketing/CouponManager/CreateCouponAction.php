<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Marketing\CouponManager;

use MaggyAssistant\Base\Api\Skill\ActionInterface;
use MaggyAssistant\Base\Service\Api\InternalApiClient;
use MaggyAssistant\Base\Service\Url\SecureAdminUrl;

class CreateCouponAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'create_coupon';
    }

    public function getDescription(): string
    {
        return 'Generate coupon code(s) for an existing cart price rule';
    }

    public function getParameterSchema(): array
    {
        return [
            'rule_id' => [
                'type' => 'integer',
                'description' => 'The cart price rule ID to generate coupons for',
            ],
            'quantity' => [
                'type' => 'integer',
                'description' => 'Number of coupon codes to generate (default: 1)',
            ],
            'length' => [
                'type' => 'integer',
                'description' => 'Length of generated coupon codes (default: 8)',
            ],
            'prefix' => [
                'type' => 'string',
                'description' => 'Prefix for generated coupon codes (optional)',
            ],
            'suffix' => [
                'type' => 'string',
                'description' => 'Suffix for generated coupon codes (optional)',
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
        return 'The rule must have coupon_type "specific_coupon" or "auto_generated" for coupon generation to work. '
            . 'If the rule has coupon_type "no_coupon", inform the user that this rule does not support coupon codes.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $ruleId = (int)($params['rule_id'] ?? 0);
        if (!$ruleId) {
            return ['error' => 'rule_id parameter is required for create_coupon'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        // Verify rule exists and supports coupons
        $rule = $this->apiClient->get('salesRules/' . $ruleId, [], $adminUserId);
        if (isset($rule['error'])) {
            return $rule;
        }

        $couponType = (int)($rule['coupon_type'] ?? 1);
        if ($couponType === 1) {
            return ['error' => 'Rule "' . ($rule['name'] ?? $ruleId) . '" does not use coupon codes (coupon_type is "no_coupon"). '
                . 'Change the rule to use specific coupons first.'];
        }

        $quantity = (int)($params['quantity'] ?? 1);
        $length = (int)($params['length'] ?? 8);

        if ($quantity < 1) {
            $quantity = 1;
        }
        if ($length < 4) {
            $length = 8;
        }

        $couponSpec = [
            'couponSpec' => [
                'rule_id' => $ruleId,
                'quantity' => $quantity,
                'length' => $length,
                'format' => 'alphanum',
            ],
        ];

        if (!empty($params['prefix'])) {
            $couponSpec['couponSpec']['prefix'] = $params['prefix'];
        }
        if (!empty($params['suffix'])) {
            $couponSpec['couponSpec']['suffix'] = $params['suffix'];
        }

        $result = $this->apiClient->post('coupons/generate', $couponSpec, $adminUserId);

        if (isset($result['error'])) {
            return ['error' => 'Failed to generate coupons: ' . $result['error']];
        }

        return [
            'success' => true,
            'rule_id' => $ruleId,
            'rule_name' => $rule['name'] ?? '',
            'codes' => $result,
            'quantity_generated' => is_array($result) ? count($result) : 0,
            'admin_url' => $this->secureAdminUrl->getUrl(
                'sales_rule/promo_quote/edit',
                ['id' => $ruleId]
            ),
        ];
    }
}
