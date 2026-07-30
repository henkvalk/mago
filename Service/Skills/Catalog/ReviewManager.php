<?php
/**
 * Copyright © Maggy Assistant
 */
declare(strict_types=1);

namespace MaggyAssistant\Base\Service\Skills\Catalog;

use MaggyAssistant\Base\Service\Skills\AbstractSkill;

class ReviewManager extends AbstractSkill
{
    public function getName(): string
    {
        return 'review_manager';
    }

    protected function getBaseDescription(): string
    {
        return 'Manage product reviews: list pending/approved, approve, reject, get stats.';
    }

    public function getMagentoAcl(): string
    {
        return 'Magento_Review::reviews_all';
    }

    public function getRequiredAcl(): string
    {
        return 'MaggyAssistant_Base::assistant_write';
    }

    protected function getBaseInstructions(): string
    {
        return 'All review results include admin_url – always include these as markdown links in your response. '
            . 'Do NOT call admin_navigator separately for reviews, the URLs are already in the data.';
    }
}
