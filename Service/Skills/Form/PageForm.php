<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Form;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class PageForm extends AbstractSkill
{
    public function getName(): string
    {
        return 'page_form';
    }

    protected function getBaseDescription(): string
    {
        return 'Read the fields of the admin form currently open in the browser, including any '
            . 'unsaved edits the administrator has made, and stage new field values for the '
            . 'administrator to confirm. Reading only sees the page on screen right now; writing can '
            . 'also send the browser to another entity\'s form, or to the New form of a product, '
            . 'category, CMS page or CMS block to create one, and stage the values there.';
    }
}
