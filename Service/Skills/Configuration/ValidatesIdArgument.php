<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Configuration;

/**
 * For tools whose target is one of a known, small set of IDs (indexers, cache types): the schema
 * offers the live IDs as an enum so the model cannot invent one, and an unknown ID is refused with
 * the valid list before any confirmation card, its echo capped so a runaway argument is not
 * stored, displayed and re-sent in full.
 */
trait ValidatesIdArgument
{
    /**
     * Schema property for the ID: an enum of the live IDs, each named in the description.
     *
     * @param string $description
     * @param array<string,string> $labels Human label by ID
     * @return array<string,mixed>
     */
    private function idProperty(string $description, array $labels): array
    {
        $property = ['type' => 'string', 'description' => $description];
        if ($labels === []) {
            return $property;
        }

        $named = [];
        foreach ($labels as $id => $label) {
            $named[] = $label === '' ? (string)$id : $id . ' (' . $label . ')';
        }
        $property['enum'] = array_map('strval', array_keys($labels));
        $property['description'] .= '. One of: ' . implode(', ', $named);

        return $property;
    }

    /**
     * The refusal for an unknown ID, or null when it is known.
     *
     * @param string $kind
     * @param string $id
     * @param array<string,string> $labels Human label by ID
     * @return array{error: string}|null
     */
    private function refusalForId(string $kind, string $id, array $labels): ?array
    {
        return isset($labels[$id]) ? null : $this->refuseUnknownId($kind, $id, $labels);
    }

    /**
     * The error result for an unknown ID, naming the valid ones; a runaway ID is cut short.
     *
     * @param string $kind
     * @param string $id
     * @param array<string,string> $labels Human label by ID
     * @return array{error: string}
     */
    private function refuseUnknownId(string $kind, string $id, array $labels): array
    {
        // Longest echo of an unknown ID; a runaway argument must not travel on in full.
        $maxLength = 80;
        if (mb_strlen($id) > $maxLength) {
            $id = mb_substr($id, 0, $maxLength) . '…';
        }
        $valid = implode(', ', array_map('strval', array_keys($labels)));

        return ['error' => sprintf('Unknown %s: %s. Valid IDs: %s', $kind, $id, $valid !== '' ? $valid : '(none)')];
    }
}
