<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Form\PageForm;

use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Skills\Form\PageForm\AbstractPageFormAction;
use MagoAssistant\Mago\Service\Skills\Form\PageForm\DescribeFormAction;
use MagoAssistant\Mago\Service\Skills\Form\PageForm\ReadFieldsAction;
use MagoAssistant\Mago\Service\Skills\Form\PageForm\WriteFieldsAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every page_form action can answer "no form open" or "this form is denied". Those results have to
 * reach the model through the privacy filter intact, or it sees {} and cannot tell the admin why.
 */
final class NoFormResultClassificationTest extends TestCase
{
    /**
     * @return array<string, array{class-string<AbstractPageFormAction>}>
     */
    public static function actions(): array
    {
        return [
            'describe_form' => [DescribeFormAction::class],
            'read_fields' => [ReadFieldsAction::class],
            'write_fields' => [WriteFieldsAction::class],
        ];
    }

    /**
     * @param class-string<AbstractPageFormAction> $class
     */
    #[Test]
    #[DataProvider('actions')]
    public function aDeniedFormResultSurvivesTheFilter(string $class): void
    {
        $action = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $result = (fn (): array => $this->deniedFormResult())->call($action);

        $filtered = $this->filter()->filter($action->getFieldClassification(), $result);

        self::assertFalse($filtered['form_open']);
        self::assertTrue($filtered['denied']);
        self::assertStringContainsString('customer_data', $filtered['message']);
    }

    /**
     * @param class-string<AbstractPageFormAction> $class
     */
    #[Test]
    #[DataProvider('actions')]
    public function aNoFormOpenResultSurvivesTheFilter(string $class): void
    {
        $action = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $holder = new PageContextHolder();
        // Bound to AbstractPageFormAction's scope, the only one that may set its private holder.
        $result = \Closure::bind(function () use ($holder): array {
            $this->pageContextHolder = $holder;

            return $this->noFormOpenResult();
        }, $action, AbstractPageFormAction::class)();

        $filtered = $this->filter()->filter($action->getFieldClassification(), $result);

        self::assertSame($result, $filtered);
    }

    #[Test]
    public function describeFormReportsTheOpenRecordById(): void
    {
        $classification = (new \ReflectionClass(DescribeFormAction::class))
            ->newInstanceWithoutConstructor()
            ->getFieldClassification();

        $filtered = $this->filter()->filter($classification, ['namespace' => 'product_form', 'entity_id' => '12']);

        self::assertSame('12', $filtered['entity_id']);
    }

    private function filter(): PrivacyFilter
    {
        return new PrivacyFilter(new ConversationVault(), new PiiHeuristic());
    }
}
