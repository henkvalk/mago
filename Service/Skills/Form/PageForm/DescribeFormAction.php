<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Form\PageForm;

use MagoAssistant\Mago\Model\Form\PageContext;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * Describes the form currently open in the browser without any field values, so the model can see
 * what is on screen (and learn the form's identity) without spending tokens on a whole product
 * form's worth of data. read_fields is the companion action that returns values, for fields this
 * one already named.
 */
class DescribeFormAction extends AbstractPageFormReadAction
{
    public function getName(): string
    {
        return 'describe_form';
    }

    public function getDescription(): string
    {
        return 'List the fields (path, label, type) of the admin form currently open in the '
            . 'browser, and the form identity (namespace, entity type, entity id, store scope), '
            . 'without field values. Optionally filter by a substring of the label or path.';
    }

    public function getParameterSchema(): array
    {
        return [
            'filter' => [
                'type' => 'string',
                'description' => 'Optional case-insensitive substring to match against a field '
                    . 'label or path, to narrow down which fields are returned on a form with many '
                    . 'fields.',
            ],
        ];
    }

    public function execute(array $params, int $adminUserId): array
    {
        $pageContext = $this->getPageContext();
        if ($pageContext === null) {
            return $this->noFormOpenResult();
        }

        $filter = mb_strtolower(trim((string)($params['filter'] ?? '')));
        $fields = $this->filterFields($pageContext, $filter);

        return [
            'namespace' => $pageContext->namespace,
            'entity_type' => $pageContext->entityType,
            'entity_id' => $pageContext->entityId,
            'is_new_entity' => $pageContext->isNewEntity,
            'store_id' => $pageContext->storeId,
            'total_fields' => $pageContext->fieldCount,
            'returned_fields' => count($fields),
            'fields' => array_map($this->toFieldSummary(...), $fields),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function filterFields(PageContext $pageContext, string $filter): array
    {
        if ($filter === '') {
            return $pageContext->fields;
        }

        return array_values(array_filter(
            $pageContext->fields,
            fn (array $field): bool => str_contains(mb_strtolower((string)$field['path']), $filter)
                || str_contains(mb_strtolower((string)$field['label']), $filter)
        ));
    }

    /**
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private function toFieldSummary(array $field): array
    {
        return [
            'path' => $field['path'],
            'label' => $field['label'],
            'type' => $field['type'],
        ];
    }
    public function getFieldClassification(): array
    {
        return [
            'namespace' => [PiiClass::PUBLIC],
            'entity_type' => [PiiClass::PUBLIC],
            'entity_id' => [PiiClass::TOKENISE, 'entity'],
            'is_new_entity' => [PiiClass::PUBLIC],
            'store_id' => [PiiClass::PUBLIC],
            'total_fields' => [PiiClass::PUBLIC],
            'returned_fields' => [PiiClass::PUBLIC],
            'fields' => [PiiClass::PUBLIC],
            'path' => [PiiClass::PUBLIC],
            'label' => [PiiClass::PUBLIC],
            'type' => [PiiClass::PUBLIC],
        ] + $this->noFormOpenFieldClassification();
    }

}
