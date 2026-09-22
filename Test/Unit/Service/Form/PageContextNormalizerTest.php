<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Form;

use MagoAssistant\Mago\Service\Form\FormPolicy;
use MagoAssistant\Mago\Service\Form\PageContextNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageContextNormalizerTest extends TestCase
{
    #[Test]
    public function itCarriesTheBrowsersTruncationFlagThrough(): void
    {
        $context = $this->normalize(['truncated' => ['fields' => true]]);

        $this->assertTrue($context->isFieldListTruncated);
    }

    #[Test]
    public function itTreatsAnUntruncatedSnapshotAsComplete(): void
    {
        $context = $this->normalize(['truncated' => ['fields' => false]]);

        $this->assertFalse($context->isFieldListTruncated);
    }

    /**
     * An older browser build, or a payload from anywhere else, simply has no flag. That must read
     * as "not truncated" rather than throwing or defaulting to a warning on every turn.
     */
    #[Test]
    public function itTreatsAMissingTruncationFlagAsComplete(): void
    {
        $context = $this->normalize([]);

        $this->assertFalse($context->isFieldListTruncated);
    }

    #[Test]
    public function itIgnoresATruncationFlagThatIsNotTheExpectedShape(): void
    {
        $context = $this->normalize(['truncated' => 'yes']);

        $this->assertFalse($context->isFieldListTruncated);
    }

    #[Test]
    public function itOnlyTreatsABooleanTrueAsTruncated(): void
    {
        $context = $this->normalize(['truncated' => ['fields' => 'true']]);

        $this->assertFalse($context->isFieldListTruncated);
    }

    private function normalize(array $extra): \MagoAssistant\Mago\Model\Form\PageContext
    {
        $context = (new PageContextNormalizer(new FormPolicy()))->normalize([
            'hasForm' => true,
            'route' => '/admin/catalog/product/edit/id/1/',
            'namespace' => 'product_form',
            'entityType' => 'product',
            'entityId' => '1',
            'fields' => [],
        ] + $extra);

        $this->assertNotNull($context, 'the payload should normalize to a context');

        return $context;
    }
}
