<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Form\PageForm;

/**
 * The two read actions (describe_form, read_fields) share everything AbstractPageFormAction
 * already provides; the only thing they add is that neither one has a side effect.
 */
abstract class AbstractPageFormReadAction extends AbstractPageFormAction
{
    public function isReadOnly(): bool
    {
        return true;
    }
}
