<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Form\PageForm;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Model\Form\PageContext;
use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Every page_form action, read or write, reads the same request-scoped PageContext and shares the
 * same response when no form is open, so that shape lives here once rather than in each action.
 */
abstract class AbstractPageFormAction implements ActionInterface
{
    /**
     * The fields of noFormOpenResult() and deniedFormResult(), which every action can return. Each
     * action adds these after its own classification, so its own rule for a key wins; left out,
     * the privacy filter drops them and the model sees an empty result instead of "no form open" or
     * "this form is denied". The message is a fixed sentence written here, never data from the form.
     */
    protected const NO_FORM_CLASSIFICATION = [
        'form_open' => [PiiClass::PUBLIC],
        'denied' => [PiiClass::PUBLIC],
        'message' => [PiiClass::PUBLIC],
    ];

    public function __construct(
        private readonly PageContextHolder $pageContextHolder
    ) {
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    /**
     * A write action never executes before confirmation, so on a first turn the model has never
     * seen any instructions injected here (JIT instructions are only injected after a tool has
     * executed). Anything the model must know to call an action correctly therefore belongs in its
     * description and parameter descriptions instead.
     */
    public function getInstructions(): string
    {
        return '';
    }

    protected function getPageContext(): ?PageContext
    {
        return $this->pageContextHolder->get();
    }

    /**
     * No form open is a normal condition (the chat panel is available on every admin page,
     * including the dashboard), not an error, so this carries no "error" key. A denied form is a
     * distinct, explained refusal instead (deniedFormResult()): the administrator needs to know
     * why nothing came back, not read the exact same message as an empty dashboard.
     */
    protected function noFormOpenResult(): array
    {
        if ($this->pageContextHolder->isDenied()) {
            return $this->deniedFormResult();
        }

        return [
            'form_open' => false,
            'message' => 'No form is currently open on this admin page. Ask the administrator to '
                . 'navigate to the page you want to inspect, or use cms_data for CMS pages that '
                . 'are not open right now.',
        ];
    }

    /**
     * customer_data is named explicitly so the model does not read this as customer data being
     * off limits generally - only this form, which may carry personal data the assistant keeps
     * out of the feature entirely (FormPolicy), is refused.
     *
     * protected, not private: WriteFieldsAction's navigate-then-act path reuses this exact result
     * (same message, same shape) when the *target* of a navigation is denied, rather than only the
     * form already open - one denial message for one notion of "denied", not a second one.
     */
    protected function deniedFormResult(): array
    {
        return [
            'form_open' => false,
            'denied' => true,
            'message' => 'This admin form cannot be read or written to by the assistant because it '
                . 'may contain personal data (customer, order or admin user details). Ask about '
                . 'customers through customer_data instead.',
        ];
    }
}
