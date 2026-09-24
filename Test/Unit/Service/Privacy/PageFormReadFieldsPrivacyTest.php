<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Privacy;

use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Skills\Form\PageForm\ReadFieldsAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #114: read_fields values cross so the model can work with existing content, but only after
 * the PII heuristic. Denied forms (customer, address, order) never produce a snapshot, so they are
 * not this filter's concern.
 */
class PageFormReadFieldsPrivacyTest extends TestCase
{
    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function filter(array $result, ConversationVault $vault = new ConversationVault()): array
    {
        $classes = (new ReadFieldsAction($this->createStub(PageContextHolder::class)))->getFieldClassification();

        return (new PrivacyFilter($vault, new PiiHeuristic()))->filter($classes, $result);
    }

    #[Test]
    public function aPlainFieldValueReachesTheModel(): void
    {
        $out = $this->filter([
            'namespace' => 'product_form',
            'fields' => [[
                'path' => 'product.description',
                'label' => 'Description',
                'value' => '<p>Carbon brake lever for Honda CRF 250.</p>',
                'found' => true,
            ]],
        ]);

        self::assertSame('<p>Carbon brake lever for Honda CRF 250.</p>', $out['fields'][0]['value']);
        self::assertSame('product_form', $out['namespace']);
    }

    #[Test]
    public function recognisablePiiInAFieldValueIsTokenised(): void
    {
        $out = $this->filter([
            'namespace' => 'cms_block_form',
            'fields' => [[
                'path' => 'content',
                'label' => 'Content',
                'value' => 'Questions? Mail jan@example.com or call 06 12345678.',
                'found' => true,
            ]],
        ]);

        $json = (string)json_encode($out);
        self::assertStringNotContainsString('jan@example.com', $json);
        self::assertStringNotContainsString('12345678', $json);
        self::assertStringContainsString('mago://email_', $out['fields'][0]['value']);
    }

    #[Test]
    public function aMultiselectValueIsKeptElementByElementThroughTheHeuristic(): void
    {
        $out = $this->filter([
            'namespace' => 'product_form',
            'fields' => [[
                'path' => 'product.category_ids',
                'label' => 'Categories',
                'value' => ['12', 'owner@example.com'],
                'found' => true,
            ]],
        ]);

        self::assertSame('12', $out['fields'][0]['value'][0]);
        self::assertStringStartsWith('mago://email_', $out['fields'][0]['value'][1]);
    }

    #[Test]
    public function anUnknownPathStillExplainsItself(): void
    {
        $out = $this->filter([
            'namespace' => 'product_form',
            'fields' => [[
                'path' => 'product.nope',
                'found' => false,
                'message' => '"product.nope" is not a field on this form.',
            ]],
        ]);

        self::assertFalse($out['fields'][0]['found']);
        self::assertSame('"product.nope" is not a field on this form.', $out['fields'][0]['message']);
    }
}
