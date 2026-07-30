<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Model\Rule\Job;
use MaggyAssistant\Base\Api\Skill\ActionInterface;

class ApplyRulesAction implements ActionInterface
{
    public function __construct(
        private readonly Job $ruleJob
    ) {
    }

    public function getName(): string
    {
        return 'apply_rules';
    }

    public function getDescription(): string
    {
        return 'Apply all catalog price rules to recalculate product prices';
    }

    public function getParameterSchema(): array
    {
        return [];
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
        return 'This action triggers a full recalculation of all catalog price rules. '
            . 'It may take a while on stores with large catalogs. '
            . 'Warn the user that price changes may not be visible immediately.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        try {
            $this->ruleJob->applyAll();

            return [
                'success' => true,
                'message' => 'Catalog price rules have been applied. '
                    . 'Note: changes may take a few minutes to be fully reflected on the storefront, '
                    . 'especially on stores with large product catalogs.',
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to apply catalog price rules: ' . $e->getMessage()];
        }
    }
}
