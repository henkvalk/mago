<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Marketing;

use MaggyAssistant\Base\Service\Skills\AbstractSkill;

class CouponManager extends AbstractSkill
{
    public function getName(): string
    {
        return 'coupon_manager';
    }

    protected function getBaseDescription(): string
    {
        return 'Manage cart price rules and coupon codes: list, create, and deactivate discount rules.';
    }

    public function getMagentoAcl(): string
    {
        return 'Magento_SalesRule::quote';
    }

    protected function getBaseInstructions(): string
    {
        return 'When creating rules, always confirm the discount type and amount with the user. '
            . 'Include admin_url links in results so the user can review rules in the admin panel.';
    }
}
