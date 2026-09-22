<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Form;

use MagoAssistant\Mago\Model\Form\PageContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageContextTest extends TestCase
{
    #[Test]
    public function itReportsTheFieldCountOnAFormItCanSeeInFull(): void
    {
        $line = $this->context(fieldCount: 12, truncated: false)->toPromptLine();

        $this->assertStringContainsString('12 field(s) visible', $line);
    }

    #[Test]
    public function itSaysNothingAboutTruncationWhenTheWholeFormFits(): void
    {
        $line = $this->context(fieldCount: 12, truncated: false)->toPromptLine();

        $this->assertStringNotContainsString('cut short', $line);
    }

    /**
     * Without this the count reads as the whole form, which is what let the assistant tell an
     * administrator the Description field did not exist when it had simply been cut from the list.
     */
    #[Test]
    public function itWarnsThatFieldsAreMissingWhenTheListWasCutShort(): void
    {
        $line = $this->context(fieldCount: 600, truncated: true)->toPromptLine();

        $this->assertStringContainsString('cut short', $line);
    }

    #[Test]
    public function itTellsTheModelNotToClaimAFieldIsMissingWhenTheListWasCutShort(): void
    {
        $line = $this->context(fieldCount: 600, truncated: true)->toPromptLine();

        $this->assertStringContainsString('do not tell the administrator a field is missing', $line);
    }

    #[Test]
    public function itStillNamesThePageAndEntityWhenTheListWasCutShort(): void
    {
        $line = $this->context(fieldCount: 600, truncated: true)->toPromptLine();

        $this->assertStringContainsString('/admin/catalog/product/edit/id/1/', $line);
    }

    /**
     * Several skills can answer "update the description"; only page_form puts a value on the page.
     * Left to pick by name the model drafts prose about a form it is already looking at and changes
     * nothing, which is what happened before this hint existed.
     */
    #[Test]
    public function itPrefersTheFormToolWhileAFormIsOpen(): void
    {
        $line = $this->context(fieldCount: 12, truncated: false)->toPromptLine();

        $this->assertStringContainsString('prefer page_form', $line);
    }

    #[Test]
    public function itSaysThatATextOnlyToolLeavesTheFormUntouched(): void
    {
        $line = $this->context(fieldCount: 12, truncated: false)->toPromptLine();

        $this->assertStringContainsString('leaves the form untouched', $line);
    }

    #[Test]
    public function itKeepsTheToolPreferenceWhenTheFieldListWasCutShort(): void
    {
        $line = $this->context(fieldCount: 600, truncated: true)->toPromptLine();

        $this->assertStringContainsString('cut short', $line);
        $this->assertStringContainsString('prefer page_form', $line);
    }

    private function context(int $fieldCount, bool $truncated): PageContext
    {
        return new PageContext(
            route: '/admin/catalog/product/edit/id/1/',
            namespace: 'product_form',
            entityType: 'product',
            entityId: '1',
            isNewEntity: false,
            storeId: null,
            fields: [],
            fieldCount: $fieldCount,
            isFieldListTruncated: $truncated
        );
    }
}
