<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Form;

/**
 * The normalized, trusted description of the admin page the administrator was looking at when a
 * chat message was sent. Everything here has already passed through PageContextNormalizer, so
 * unlike the raw client payload it carries no un-typed, unbounded or non-scalar data.
 */
class PageContext
{
    /**
     * @param array<int,array<string,mixed>> $fields Normalized field snapshots (path/label/type/value/...)
     */
    public function __construct(
        public readonly string $route,
        public readonly string $namespace,
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly bool $isNewEntity,
        public readonly ?string $storeId,
        public readonly array $fields,
        public readonly int $fieldCount,
        public readonly bool $isFieldListTruncated = false
    ) {
    }

    /**
     * A short, value-free line for the system prompt: page label, route, entity type, entity id,
     * store scope and field count. Field values and labels are deliberately left out; they cost
     * tokens and belong in a dedicated read tool rather than every turn of every conversation.
     *
     * A truncated list says so. Without it the count reads as the whole form, and a field missing
     * only because the list was cut is indistinguishable from one the form does not have - which
     * invites telling the administrator a field does not exist when it is simply not in view.
     */
    public function toPromptLine(): string
    {
        return sprintf(
            'The administrator is currently on the %s page (%s), viewing %s%s, with %d field(s) visible.%s',
            $this->label(),
            $this->route,
            $this->entityDescriptor(),
            $this->storeDescriptor(),
            $this->fieldCount,
            $this->truncationWarning() . $this->toolPreference()
        );
    }

    /**
     * Which tool to reach for while a form is open.
     *
     * Several skills can answer "update the description": one drafts copy, another puts a value on
     * the page. Without this the model picks by name and hands the administrator prose about a form
     * it is already looking at, having changed nothing. Only page_form stages a value, so while a
     * form is open it is the one that finishes the job - drafting first with another tool is fine,
     * staging the result through page_form is what makes it land.
     */
    private function toolPreference(): string
    {
        return ' Because a form is open, prefer page_form for anything that is one of its fields:'
            . ' it is the only tool that puts a value on the page. A tool that just returns text'
            . ' leaves the form untouched, so draft with it if you like, then stage the result'
            . ' with page_form rather than replying with the text alone.';
    }

    private function truncationWarning(): string
    {
        if (!$this->isFieldListTruncated) {
            return '';
        }

        return ' That list was cut short, so the form has fields you cannot see: do not tell the'
            . ' administrator a field is missing, say you cannot see all of them and ask which one'
            . ' they mean.';
    }

    public function toLocation(): PageLocation
    {
        return new PageLocation(
            route: $this->route,
            namespace: $this->namespace,
            entityType: $this->entityType,
            entityId: $this->entityId,
            isNewEntity: $this->isNewEntity,
            storeId: $this->storeId
        );
    }

    private function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->entityType));
    }

    private function entityDescriptor(): string
    {
        return $this->toLocation()->entityDescriptor();
    }

    private function storeDescriptor(): string
    {
        return $this->toLocation()->storeDescriptor();
    }
}
