<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\CatalogRule\Model\Rule\Condition\Combine;
use Magento\CatalogRule\Model\Rule\Condition\Product;
use Magento\CatalogRule\Model\RuleFactory;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as CustomerGroupCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use MaggyAssistant\Base\Api\Skill\ActionInterface;
use MaggyAssistant\Base\Service\Url\SecureAdminUrl;

class CreateRuleAction implements ActionInterface
{
    public function __construct(
        private readonly CatalogRuleRepositoryInterface $catalogRuleRepository,
        private readonly RuleFactory $ruleFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerGroupCollectionFactory $customerGroupCollectionFactory,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'create_rule';
    }

    public function getDescription(): string
    {
        return 'Create a new catalog price rule';
    }

    public function getParameterSchema(): array
    {
        return [
            'name' => [
                'type' => 'string',
                'description' => 'Rule name (required)',
            ],
            'discount_type' => [
                'type' => 'string',
                'description' => 'Discount type: "percent", "fixed", "to_percent", or "to_fixed"',
            ],
            'discount_amount' => [
                'type' => 'number',
                'description' => 'Discount amount',
            ],
            'from_date' => [
                'type' => 'string',
                'description' => 'Start date (YYYY-MM-DD format, optional)',
            ],
            'to_date' => [
                'type' => 'string',
                'description' => 'End date (YYYY-MM-DD format, optional)',
            ],
            'website_ids' => [
                'type' => 'array',
                'description' => 'Website IDs to apply rule to (default: all websites)',
            ],
            'customer_group_ids' => [
                'type' => 'array',
                'description' => 'Customer group IDs (default: all groups)',
            ],
            'category_ids' => [
                'type' => 'array',
                'description' => 'Category IDs to restrict the rule to (optional)',
            ],
            'priority' => [
                'type' => 'integer',
                'description' => 'Rule priority / sort order (default: 0)',
            ],
            'stop_further_rules' => [
                'type' => 'boolean',
                'description' => 'Stop processing subsequent rules (default: false)',
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
        return 'After creating a rule, remind the user that apply_rules must be run for changes to take effect. '
            . 'Discount types: "percent" = X% off, "fixed" = X amount off, '
            . '"to_percent" = set price to X% of original, "to_fixed" = set price to X.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $name = $params['name'] ?? '';
        if (empty($name)) {
            return ['error' => 'name parameter is required for create_rule'];
        }

        $discountType = $params['discount_type'] ?? '';
        if (empty($discountType)) {
            return ['error' => 'discount_type parameter is required for create_rule'];
        }

        $discountAmount = (float)($params['discount_amount'] ?? 0);
        if ($discountAmount <= 0) {
            return ['error' => 'discount_amount must be greater than 0'];
        }

        $discountTypeMap = [
            'percent' => 'by_percent',
            'fixed' => 'by_fixed',
            'to_percent' => 'to_percent',
            'to_fixed' => 'to_fixed',
        ];

        $simpleAction = $discountTypeMap[$discountType] ?? null;
        if (!$simpleAction) {
            return ['error' => 'Invalid discount_type. Use: percent, fixed, to_percent, or to_fixed'];
        }

        try {
            $websiteIds = $params['website_ids'] ?? $this->getAllWebsiteIds();
            $customerGroupIds = $params['customer_group_ids'] ?? $this->getAllCustomerGroupIds();

            $rule = $this->ruleFactory->create();
            $rule->setData('name', $name);
            $rule->setData('is_active', 1);
            $rule->setData('simple_action', $simpleAction);
            $rule->setData('discount_amount', $discountAmount);
            $rule->setData('website_ids', $websiteIds);
            $rule->setData('customer_group_ids', $customerGroupIds);
            $rule->setData('sort_order', (int)($params['priority'] ?? 0));
            $rule->setData('stop_rules_processing', !empty($params['stop_further_rules']));

            if (!empty($params['from_date'])) {
                $rule->setData('from_date', $params['from_date']);
            }
            if (!empty($params['to_date'])) {
                $rule->setData('to_date', $params['to_date']);
            }

            $categoryIds = $params['category_ids'] ?? [];
            if (!empty($categoryIds)) {
                $conditions = [
                    'type' => Combine::class,
                    'aggregator' => 'all',
                    'value' => '1',
                    'conditions' => [
                        [
                            'type' => Product::class,
                            'attribute' => 'category_ids',
                            'operator' => '()',
                            'value' => implode(',', $categoryIds),
                        ],
                    ],
                ];
                $rule->getConditions()->loadArray($conditions);
            }

            $this->catalogRuleRepository->save($rule);
            $ruleId = (int)$rule->getId();

            return [
                'success' => true,
                'rule_id' => $ruleId,
                'name' => $name,
                'message' => 'Catalog price rule "' . $name . '" created successfully. '
                    . 'Run apply_rules to activate the changes.',
                'admin_url' => $this->secureAdminUrl->getUrl(
                    'catalog_rule/promo_catalog/edit',
                    ['id' => $ruleId]
                ),
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to create catalog price rule: ' . $e->getMessage()];
        }
    }

    private function getAllWebsiteIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $ids[] = (int)$website->getId();
        }
        return $ids;
    }

    private function getAllCustomerGroupIds(): array
    {
        $ids = [];
        $collection = $this->customerGroupCollectionFactory->create();
        foreach ($collection as $group) {
            $ids[] = (int)$group->getId();
        }
        return $ids;
    }
}
