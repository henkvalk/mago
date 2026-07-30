<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use MaggyAssistant\Base\Api\Skill\ActionInterface;
use MaggyAssistant\Base\Service\Url\SecureAdminUrl;

class DeactivateRuleAction implements ActionInterface
{
    public function __construct(
        private readonly CatalogRuleRepositoryInterface $catalogRuleRepository,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'deactivate_rule';
    }

    public function getDescription(): string
    {
        return 'Deactivate a catalog price rule';
    }

    public function getParameterSchema(): array
    {
        return [
            'rule_id' => [
                'type' => 'integer',
                'description' => 'The catalog price rule ID to deactivate',
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
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $ruleId = (int)($params['rule_id'] ?? 0);
        if (!$ruleId) {
            return ['error' => 'rule_id parameter is required for deactivate_rule'];
        }

        try {
            $rule = $this->catalogRuleRepository->get($ruleId);
            $ruleName = $rule->getData('name') ?? '';

            $rule->setData('is_active', 0);
            $this->catalogRuleRepository->save($rule);

            return [
                'success' => true,
                'rule_id' => $ruleId,
                'name' => $ruleName,
                'message' => 'Catalog price rule "' . $ruleName . '" has been deactivated. '
                    . 'Run apply_rules to apply the changes to product prices.',
                'admin_url' => $this->secureAdminUrl->getUrl(
                    'catalog_rule/promo_catalog/edit',
                    ['id' => $ruleId]
                ),
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to deactivate catalog price rule: ' . $e->getMessage()];
        }
    }
}
