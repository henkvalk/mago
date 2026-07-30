<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Marketing;

use MaggyAssistant\Base\Service\Skills\AbstractSkill;

class CatalogPriceRules extends AbstractSkill
{
    public function getName(): string
    {
        return 'catalog_price_rules';
    }

    protected function getBaseDescription(): string
    {
        return 'Manage catalog price rules: automatic discounts applied to product prices without coupon codes.';
    }

    public function getMagentoAcl(): string
    {
        return 'Magento_CatalogRule::promo_catalog';
    }

    protected function getBaseInstructions(): string
    {
        return 'Catalog price rules differ from cart price rules: they modify the displayed product price '
            . 'on category/product pages, while cart price rules apply at checkout. '
            . 'After creating or deactivating a rule, always suggest running apply_rules to activate changes. '
            . 'Include admin_url links in results.';
    }
}
