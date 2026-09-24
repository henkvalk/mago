<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class ProductMedia extends AbstractSkill
{
    public function getName(): string
    {
        return 'product_media';
    }

    protected function getBaseDescription(): string
    {
        return 'Generate new images or short videos of an existing product from its main image with Higgsfield '
            . '(needs a Higgsfield API key in the configuration). Generation runs in the background: start it, '
            . 'then check the request until it is completed.';
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Catalog::products';
    }

    protected function getBaseInstructions(): string
    {
        return <<<'TEXT'
Write the prompt in English, even when the admin writes in another language. Describe what should change
(scene, background, lighting, motion, camera) and keep the product itself as in its main image; do not ask
Higgsfield to alter the product's shape, colour, text or logo unless the admin asks for it.
Every generation costs credits, so start one generation per request of the admin and never retry a failed
one on your own.
TEXT;
    }
}
