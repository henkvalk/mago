<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Form\PageForm;

use MagoAssistant\Mago\Model\Form\PageContext;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Returns the current values of named fields on the form open in the browser, including edits the
 * administrator has made but not yet saved. Field paths come from describe_form; an unknown path is
 * reported explicitly rather than silently returning an empty value, so the model never mistakes
 * "wrong path" for "field is blank".
 */
class ReadFieldsAction extends AbstractPageFormReadAction
{
    public function getName(): string
    {
        return 'read_fields';
    }

    public function getDescription(): string
    {
        return 'Get the current values of one or more named fields on the admin form currently '
            . 'open in the browser, identified by the paths returned from describe_form.';
    }

    public function getParameterSchema(): array
    {
        return [
            'field_paths' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'One or more field paths, as returned by describe_form, whose '
                    . 'current values to return.',
            ],
        ];
    }

    public function execute(array $params, int $adminUserId): array
    {
        $pageContext = $this->getPageContext();
        if ($pageContext === null) {
            return $this->noFormOpenResult();
        }

        $requestedPaths = array_filter((array)($params['field_paths'] ?? []), 'is_string');
        if ($requestedPaths === []) {
            return ['error' => 'field_paths parameter is required'];
        }

        return [
            'namespace' => $pageContext->namespace,
            'fields' => array_map(
                fn (string $path): array => $this->readField($pageContext, $path),
                array_values($requestedPaths)
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readField(PageContext $pageContext, string $path): array
    {
        $field = $this->findField($pageContext, $path);
        if ($field === null) {
            return [
                'path' => $path,
                'found' => false,
                'message' => '"' . $path . '" is not a field on this form. Call describe_form to '
                    . 'see the available field paths.',
            ];
        }

        return [
            'path' => $field['path'],
            'label' => $field['label'],
            'value' => $field['value'],
            'found' => true,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findField(PageContext $pageContext, string $path): ?array
    {
        foreach ($pageContext->fields as $field) {
            if ($field['path'] === $path) {
                return $field;
            }
        }

        return null;
    }
    public function getFieldClassification(): array
    {
        // Structure only. A form field's value is whatever the admin has open, and on a customer or
        // order form that is a name, an address or a phone number, so the value itself never
        // crosses; the model still learns which fields exist and which were found.
        return [
            'namespace' => [PiiClass::PUBLIC],
            'found' => [PiiClass::PUBLIC],
            'fields' => [PiiClass::PUBLIC],
            'path' => [PiiClass::PUBLIC],
            'label' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
            'value' => [PiiClass::STRIP],
        ] + $this->noFormOpenFieldClassification();
    }

}
