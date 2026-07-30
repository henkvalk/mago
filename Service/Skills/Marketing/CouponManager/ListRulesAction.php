<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Marketing\CouponManager;

use MaggyAssistant\Base\Api\Skill\ActionInterface;
use MaggyAssistant\Base\Service\Api\InternalApiClient;
use MaggyAssistant\Base\Service\Url\SecureAdminUrl;

class ListRulesAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'list_rules';
    }

    public function getDescription(): string
    {
        return 'List cart price rules, optionally filtered by status';
    }

    public function getParameterSchema(): array
    {
        return [
            'status' => [
                'type' => 'string',
                'description' => 'Filter by status: "active" or "inactive" (default: "active")',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $status = $params['status'] ?? 'active';
        $isActive = $status === 'active' ? '1' : '0';

        $searchParams = $this->apiClient->buildSearchCriteria(
            [['field' => 'is_active', 'value' => $isActive, 'condition_type' => 'eq']],
            100,
            1,
            [['field' => 'sort_order', 'direction' => 'ASC']]
        );

        $result = $this->apiClient->get('salesRules/search', $searchParams, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $discountTypeMap = [
            'by_percent' => 'percent',
            'by_fixed' => 'fixed',
            'cart_fixed' => 'fixed_cart',
        ];

        $couponTypeMap = [
            1 => 'no_coupon',
            2 => 'specific_coupon',
            3 => 'auto_generated',
        ];

        $rules = [];
        foreach ($result['items'] ?? [] as $item) {
            $ruleId = (int)($item['rule_id'] ?? 0);
            $simpleAction = $item['simple_action'] ?? '';
            $couponType = (int)($item['coupon_type'] ?? 1);

            $rules[] = [
                'rule_id' => $ruleId,
                'name' => $item['name'] ?? '',
                'is_active' => (bool)($item['is_active'] ?? false),
                'discount_amount' => round((float)($item['discount_amount'] ?? 0), 2),
                'discount_type' => $discountTypeMap[$simpleAction] ?? $simpleAction,
                'from_date' => $item['from_date'] ?? null,
                'to_date' => $item['to_date'] ?? null,
                'coupon_type' => $couponTypeMap[$couponType] ?? 'unknown',
                'uses_per_coupon' => (int)($item['uses_per_coupon'] ?? 0),
                'times_used' => (int)($item['times_used'] ?? 0),
                'admin_url' => $this->secureAdminUrl->getUrl(
                    'sales_rule/promo_quote/edit',
                    ['id' => $ruleId]
                ),
            ];
        }

        return [
            'total_count' => (int)($result['total_count'] ?? 0),
            'rules' => $rules,
        ];
    }
}
