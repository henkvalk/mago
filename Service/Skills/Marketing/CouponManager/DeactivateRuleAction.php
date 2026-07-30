<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Marketing\CouponManager;

use MaggyAssistant\Base\Api\Skill\ActionInterface;
use MaggyAssistant\Base\Service\Api\InternalApiClient;
use MaggyAssistant\Base\Service\Url\SecureAdminUrl;

class DeactivateRuleAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'deactivate_rule';
    }

    public function getDescription(): string
    {
        return 'Deactivate a cart price rule';
    }

    public function getParameterSchema(): array
    {
        return [
            'rule_id' => [
                'type' => 'integer',
                'description' => 'The cart price rule ID to deactivate',
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
        $ruleId = (int)($params['rule_id'] ?? 0);
        if (!$ruleId) {
            return ['error' => 'rule_id parameter is required for deactivate_rule'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        // First get the rule to confirm it exists
        $rule = $this->apiClient->get('salesRules/' . $ruleId, [], $adminUserId);
        if (isset($rule['error'])) {
            return $rule;
        }

        $ruleName = $rule['name'] ?? '';

        // Deactivate by setting is_active to false
        $result = $this->apiClient->put('salesRules/' . $ruleId, [
            'rule' => [
                'rule_id' => $ruleId,
                'is_active' => false,
            ],
        ], $adminUserId);

        if (isset($result['error'])) {
            return ['error' => 'Failed to deactivate rule: ' . $result['error']];
        }

        return [
            'success' => true,
            'message' => 'Rule "' . $ruleName . '" (ID: ' . $ruleId . ') has been deactivated',
            'admin_url' => $this->secureAdminUrl->getUrl(
                'sales_rule/promo_quote/edit',
                ['id' => $ruleId]
            ),
        ];
    }
}
