<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Debug;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class ProductDebug extends AbstractSkill
{
    public function getName(): string
    {
        return 'product_debug';
    }

    protected function getBaseDescription(): string
    {
        return 'Debug why a product is hidden, not visible, or missing images on the storefront.';
    }

    protected function getBaseInstructions(): string
    {
        return <<<INSTRUCTIONS
Use this tool when the user asks why a product is not visible, hidden, or missing from the storefront.
Always require a SKU. Use `diagnose` to get a full per-store-view breakdown of all visibility factors.
Use `media_gallery` when the user asks why a product has no images, or why an image is missing on the
storefront — it reports gallery image count, disabled images, and per-store-view overrides.
INSTRUCTIONS;
    }
}
