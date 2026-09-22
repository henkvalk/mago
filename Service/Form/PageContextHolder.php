<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Form;

use MagoAssistant\Mago\Model\Form\PageContext;

/**
 * Carries the current request's normalized page context from the controller, where the client
 * payload arrives, to ChatService, where the system prompt is built, and to the page_form actions.
 * Magento shares one instance of a non-virtual type across a single request by default, which is
 * what makes this safe: the controller sets it once, and later collaborators read the same
 * instance in the same request.
 *
 * $isDenied is carried alongside the context, rather than folded into a null PageContext, so a
 * page_form action can tell a denied form apart from a page that genuinely has no form open at
 * all - the administrator needs the reason, not just the same "no form open" result either way.
 */
class PageContextHolder
{
    private ?PageContext $pageContext = null;
    private bool $isDenied = false;

    public function set(?PageContext $pageContext, bool $isDenied = false): void
    {
        $this->pageContext = $pageContext;
        $this->isDenied = $isDenied;
    }

    public function get(): ?PageContext
    {
        return $this->pageContext;
    }

    public function isDenied(): bool
    {
        return $this->isDenied;
    }
}
