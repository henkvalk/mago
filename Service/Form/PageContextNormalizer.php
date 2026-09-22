<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Form;

use MagoAssistant\Mago\Block\Adminhtml\ChatPanel;
use MagoAssistant\Mago\Model\Form\PageContext;

/**
 * Turns the page_context the browser posts alongside a chat message into a PageContext, or drops
 * it. form-bridge.js already caps field count, value length, option count and total byte size on
 * the client, but the payload crosses a trust boundary here: a forged request, a tampered browser
 * console, or a future bridge bug could all send something those caps do not cover. This is the
 * one place that re-applies the same limits (the constants ChatPanel already publishes to the
 * client) against data this side cannot assume is well-formed, and it never throws — malformed
 * input is silently dropped rather than failing the chat turn.
 *
 * FormPolicy is applied here too, before a single field is normalized: form-bridge.js already
 * refuses to snapshot a denied form, but a forged or tampered request bypasses that entirely, so
 * this is the check that actually keeps personal data out of the feature. isDenied() lets a
 * caller (Stream, Confirm) tell a denied form apart from a page that genuinely has none open.
 */
class PageContextNormalizer
{
    private const FIELD_TYPE_PASSWORD = 'password';
    private const SECRET_PATH_PATTERN = '/password|secret|token|api_key|apikey|private_key/i';

    private readonly \stdClass $unsupportedValue;

    public function __construct(
        private readonly FormPolicy $formPolicy
    ) {
        $this->unsupportedValue = new \stdClass();
    }

    public function normalize(mixed $rawPageContext): ?PageContext
    {
        if (!$this->isCandidatePayload($rawPageContext)) {
            return null;
        }

        $route = $this->toStringValue($rawPageContext['route'] ?? null);
        $namespace = $this->toStringValue($rawPageContext['namespace'] ?? null);
        $entityType = $this->toStringValue($rawPageContext['entityType'] ?? null);

        if ($route === '' || $namespace === '' || $entityType === '' || $this->formPolicy->isDenied($namespace, $route)) {
            return null;
        }

        $fields = $this->normalizeFields($rawPageContext['fields'] ?? null);

        return new PageContext(
            route: $route,
            namespace: $namespace,
            entityType: $entityType,
            entityId: $this->toStringValue($rawPageContext['entityId'] ?? ''),
            isNewEntity: (bool)($rawPageContext['isNewEntity'] ?? false),
            storeId: $this->toNullableStringValue($rawPageContext['storeId'] ?? null),
            fields: $fields,
            fieldCount: count($fields),
            isFieldListTruncated: $this->isFieldListTruncated($rawPageContext)
        );
    }

    /**
     * The browser sets truncated.fields when the form had more fields than its cap allowed.
     *
     * @param array<string,mixed> $rawPageContext
     */
    private function isFieldListTruncated(array $rawPageContext): bool
    {
        $truncated = $rawPageContext['truncated'] ?? null;

        return is_array($truncated) && ($truncated['fields'] ?? false) === true;
    }

    /**
     * True when the raw payload reports a denied form - the one distinction Stream and Confirm
     * need beyond normalize()'s own null result, since a denied form and a page with no form open
     * both normalize to null but call for a different message to the administrator.
     *
     * form-bridge.js already applies FormPolicy itself and, on a denied form, sends nothing but
     * its own "denied" flag - no namespace, no route, nothing that would identify the form - so
     * that flag alone is trusted here for that ordinary, well-behaved case. A forged or tampered
     * request is the other case this checks: one that still carries a namespace and route (and,
     * unlike a genuine client, field data alongside them) is re-checked against FormPolicy
     * independently, the same way normalize() already does, rather than trusting a "denied" flag
     * that request never bothered to set.
     */
    public function isDenied(mixed $rawPageContext): bool
    {
        if (!$this->isObjectPayload($rawPageContext)) {
            return false;
        }

        if (($rawPageContext['denied'] ?? false) === true) {
            return true;
        }

        if (!$this->isCandidatePayload($rawPageContext)) {
            return false;
        }

        $route = $this->toStringValue($rawPageContext['route'] ?? null);
        $namespace = $this->toStringValue($rawPageContext['namespace'] ?? null);

        return $this->formPolicy->isDenied($namespace, $route);
    }

    private function isCandidatePayload(mixed $value): bool
    {
        return $this->isObjectPayload($value) && ($value['hasForm'] ?? false) === true;
    }

    private function isObjectPayload(mixed $value): bool
    {
        return is_array($value) && $value !== [] && !array_is_list($value);
    }

    private function toStringValue(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    private function toNullableStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string)$value : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeFields(mixed $rawFields): array
    {
        if (!is_array($rawFields)) {
            return [];
        }

        $fields = [];
        foreach ($rawFields as $rawField) {
            if (count($fields) >= ChatPanel::FORM_FIELD_CAP) {
                break;
            }

            $field = $this->normalizeField($rawField);
            if ($field !== null) {
                $fields[] = $field;
            }
        }

        return $this->capToByteSize($fields);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function normalizeField(mixed $rawField): ?array
    {
        if (!is_array($rawField)) {
            return null;
        }

        $path = $this->toStringValue($rawField['path'] ?? null);
        $type = $this->toStringValue($rawField['type'] ?? '');
        $value = $this->normalizeFieldValue($rawField['value'] ?? null);

        if ($path === '' || $value === $this->unsupportedValue) {
            return null;
        }

        if ($this->isSecretField($path, $type)) {
            return $this->redactedField($path, $type, $rawField);
        }

        return [
            'path' => $path,
            'label' => $this->toStringValue($rawField['label'] ?? ''),
            'type' => $type,
            'value' => $this->capValueLength($value),
            'options' => $this->normalizeOptions($rawField['options'] ?? null),
            'required' => (bool)($rawField['required'] ?? false),
            'disabled' => (bool)($rawField['disabled'] ?? false),
            'usesDefaultValue' => (bool)($rawField['usesDefaultValue'] ?? false),
        ];
    }

    /**
     * A secret is never carried, whatever the client sent: not to the model, not into the
     * conversation history, not to the provider. The field itself stays visible (path, label,
     * type) so it can still be described and written to. form-bridge.js redacts the same way
     * before sending; this is the server's own check for a client that did not.
     */
    private function isSecretField(string $path, string $type): bool
    {
        return $type === self::FIELD_TYPE_PASSWORD || preg_match(self::SECRET_PATH_PATTERN, $path) === 1;
    }

    /**
     * @param array<string,mixed> $rawField
     * @return array<string,mixed>
     */
    private function redactedField(string $path, string $type, array $rawField): array
    {
        return [
            'path' => $path,
            'label' => $this->toStringValue($rawField['label'] ?? ''),
            'type' => $type,
            'value' => null,
            'redacted' => true,
            'options' => null,
            'required' => (bool)($rawField['required'] ?? false),
            'disabled' => (bool)($rawField['disabled'] ?? false),
            'usesDefaultValue' => (bool)($rawField['usesDefaultValue'] ?? false),
        ];
    }

    /**
     * A multi-value field (the product form's category_ids and website_ids, any multiselect) holds
     * a list of scalars; it is carried as the same comma-separated string write_fields accepts for
     * it, so the model reads and writes such a field in one format. Anything else that is not a
     * scalar (or null) is not a field value this feature understands, reported as the
     * unsupportedValue sentinel (never false, which an unchecked checkbox legitimately holds) so
     * the field is left out rather than carried with a value nobody can interpret.
     */
    private function normalizeFieldValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (!is_array($value) || !array_is_list($value)) {
            return $this->unsupportedValue;
        }

        $scalars = array_filter($value, static fn (mixed $item): bool => is_scalar($item));
        if (count($scalars) !== count($value)) {
            return $this->unsupportedValue;
        }

        return implode(',', array_map(static fn (mixed $item): string => (string)$item, $scalars));
    }

    private function capValueLength(mixed $value): mixed
    {
        if (!is_string($value) || mb_strlen($value) <= ChatPanel::FORM_VALUE_LENGTH_CAP) {
            return $value;
        }

        return mb_substr($value, 0, ChatPanel::FORM_VALUE_LENGTH_CAP);
    }

    /**
     * @return array<int,array<string,mixed>>|null
     */
    private function normalizeOptions(mixed $rawOptions): ?array
    {
        if (!is_array($rawOptions)) {
            return null;
        }

        $options = [];
        foreach ($rawOptions as $rawOption) {
            if (count($options) >= ChatPanel::FORM_OPTION_CAP) {
                break;
            }

            if (!is_array($rawOption) || !isset($rawOption['value']) || !is_scalar($rawOption['value'])) {
                continue;
            }

            $options[] = [
                'value' => $rawOption['value'],
                'label' => $this->toStringValue($rawOption['label'] ?? ''),
            ];
        }

        return $options;
    }

    /**
     * Option lists are what make a snapshot large (a product form carries dozens of selects, some
     * with the full option cap each), while the field paths are what every page_form action needs.
     * So an over-sized snapshot first sheds option lists, largest first, and only drops whole
     * fields once none are left: dropping from the tail straight away silently loses whichever
     * fields happen to register last (category_ids on the product form), which then read as "not
     * a field on this form".
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array<string,mixed>>
     */
    private function capToByteSize(array $fields): array
    {
        while ($fields !== [] && strlen((string)json_encode($fields)) > ChatPanel::FORM_BYTE_CAP) {
            $index = $this->indexOfLargestOptionList($fields);
            if ($index === null) {
                array_pop($fields);
                continue;
            }
            $fields[$index]['options'] = [];
        }

        return $fields;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     */
    private function indexOfLargestOptionList(array $fields): ?int
    {
        $largestIndex = null;
        $largestCount = 0;
        foreach ($fields as $index => $field) {
            $count = count($field['options'] ?? []);
            if ($count > $largestCount) {
                $largestCount = $count;
                $largestIndex = $index;
            }
        }

        return $largestIndex;
    }
}
