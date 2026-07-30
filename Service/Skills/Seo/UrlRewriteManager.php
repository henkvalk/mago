<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Seo;

use MaggyAssistant\Base\Service\Skills\AbstractSkill;

class UrlRewriteManager extends AbstractSkill
{
    public function getName(): string
    {
        return 'url_rewrite_manager';
    }

    protected function getBaseDescription(): string
    {
        return 'Manage URL rewrites and redirects: search existing rewrites, create redirects, and delete custom rewrites.';
    }

    public function getMagentoAcl(): string
    {
        return 'Magento_UrlRewrite::urlrewrite';
    }

    protected function getBaseInstructions(): string
    {
        return 'URL rewrites control how URLs map to Magento entities. '
            . 'Types: "custom" (user-created), "product", "category", "cms-page" (auto-generated). '
            . 'Only delete "custom" type rewrites — auto-generated ones regenerate via indexer. '
            . 'Redirect types: 0 = internal rewrite (no redirect), 301 = permanent, 302 = temporary. '
            . 'Always use 301 for permanent URL changes, 302 for temporary campaigns. '
            . 'All rewrite results include admin_url – always include these as markdown links in your response.';
    }
}
