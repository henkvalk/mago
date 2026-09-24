<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Form\PageForm;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Skills\Form\PageForm\DescribeFormAction;
use MagoAssistant\Mago\Service\Skills\Form\PageForm\ReadFieldsAction;
use MagoAssistant\Mago\Service\Skills\Form\PageForm\WriteFieldsAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every page_form action answers a denied or missing form with the same shared result, so each one
 * has to let that result through the privacy filter: stripped, the model gets {} and cannot tell the
 * administrator why nothing came back.
 */
final class NoFormOpenClassificationTest extends TestCase
{
    /**
     * @return array<string, array{class-string<ActionInterface>}>
     */
    public static function pageFormActions(): array
    {
        return [
            'describe_form' => [DescribeFormAction::class],
            'read_fields' => [ReadFieldsAction::class],
            'write_fields' => [WriteFieldsAction::class],
        ];
    }

    /**
     * @param class-string<ActionInterface> $actionClass
     */
    #[Test]
    #[DataProvider('pageFormActions')]
    public function itTellsTheModelTheFormIsDenied(string $actionClass): void
    {
        $deniedResult = $this->resultOnPage(true);

        $filtered = $this->filterFor($actionClass, $deniedResult);

        self::assertFalse($filtered['form_open']);
        self::assertTrue($filtered['denied']);
        self::assertStringContainsString('customer_data', $filtered['message']);
    }

    /**
     * @param class-string<ActionInterface> $actionClass
     */
    #[Test]
    #[DataProvider('pageFormActions')]
    public function itTellsTheModelNoFormIsOpen(string $actionClass): void
    {
        $noFormResult = $this->resultOnPage(false);

        $filtered = $this->filterFor($actionClass, $noFormResult);

        self::assertFalse($filtered['form_open']);
        self::assertStringContainsString('No form is currently open', $filtered['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function resultOnPage(bool $isDenied): array
    {
        $holder = new PageContextHolder();
        $holder->set(null, $isDenied);

        return (new DescribeFormAction($holder))->execute([], 1);
    }

    /**
     * @param class-string<ActionInterface> $actionClass
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function filterFor(string $actionClass, array $result): array
    {
        $action = (new \ReflectionClass($actionClass))->newInstanceWithoutConstructor();

        return (new PrivacyFilter(new ConversationVault(), new PiiHeuristic()))
            ->filter($action->getFieldClassification(), $result);
    }
}
