<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Privacy;

/**
 * The privacy-mode entry point ChatService uses (issue #97). One request-scoped instance holds the
 * conversation vault, so a value tokenised anywhere in the request reads back as the same token.
 */
class PrivacyService
{
    public function __construct(
        private readonly PrivacyFilter $filter,
        private readonly ConversationVault $vault,
        private readonly PiiHeuristic $heuristic
    ) {
    }

    /**
     * Bind the vault to the current conversation so tokens persist and resolve across turns and the
     * confirmed-write round-trip. Called once at the start of a chat request.
     */
    public function beginConversation(int $conversationId): void
    {
        $this->vault->beginConversation($conversationId);
    }

    /**
     * Filter a tool result before it reaches the LLM. $classes is the executed tool's own
     * declaration (ToolInterface::getFieldClassification() for the invoked action).
     *
     * @param array<string,array{0:string,1?:string}> $classes
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function filterToolResult(array $classes, array $result): array
    {
        return $this->filter->filter($classes, $result);
    }

    /**
     * Scrub free text in the outbound messages before they reach the LLM: the typed message, replayed
     * history and the custom system prompt. This is the one place that catches PII the admin types,
     * which no field classification can. Idempotent, so already-filtered tool results are untouched.
     *
     * @param array<int,array<string,mixed>> $messages
     * @return array<int,array<string,mixed>>
     */
    public function scrubMessages(array $messages): array
    {
        foreach ($messages as $index => $message) {
            if (is_string($message['content'] ?? null) && $message['content'] !== '') {
                $messages[$index]['content'] = $this->heuristic->tokeniseFreeText($message['content'], $this->vault);
            }
        }

        return $messages;
    }

    /**
     * Scrub one piece of free text into the bound vault. Used where a single string is persisted or
     * egresses outside the message array (the typed message before storage, a conversation title), so
     * the stored copy is tokenised per #97 decision 1. Idempotent like scrubMessages.
     */
    public function scrubText(string $text): string
    {
        return $this->heuristic->tokeniseFreeText($text, $this->vault);
    }

    /**
     * A conversation title from already-scrubbed text: truncated without cutting a vault token in
     * half (a partial token can neither rehydrate nor be neutralised on display).
     */
    public function safeTitle(string $scrubbedText, int $length = 50): string
    {
        return (string)preg_replace('/\[[a-z]*(?:_\d*)?$/', '', mb_substr($scrubbedText, 0, $length));
    }

    /**
     * Rehydrate tokens in tool-call arguments before the tool runs. The model only ever saw tokens
     * for scrubbed values, so it passes e.g. search="[email_1]"; the tool must receive the real
     * address or its lookup finds nothing. Applied on every execution path (read, stream, confirm).
     *
     * @param array<array-key,mixed> $input
     * @return array<array-key,mixed>
     */
    public function rehydrateArguments(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->rehydrateArguments($value);
            } elseif (is_string($value)) {
                $input[$key] = $this->vault->rehydrate($value);
            }
        }

        return $input;
    }

    /**
     * Token types that never rehydrate into a write, resolvable or not: an admin URL embeds the admin
     * secret key, and nothing an administrator asks for needs it in stored data.
     */
    private const WRITE_REFUSED_TYPES = '/(?:\[|mago:\/\/)url_\d+\]?/';

    /**
     * Heuristic-minted personal values. They may be written (#114: a contact person in a CMS block is
     * a legitimate write), but the confirmation card shows the real value with a warning, so the
     * administrator decides with it in plain sight rather than approving an opaque token.
     */
    private const PERSONAL_TYPES = '/(?:\[|mago:\/\/)(?:name|email|iban|vat|bsn|phone)_\d+\]?/';

    /**
     * True when any argument carries a token of a write-refused class (see WRITE_REFUSED_TYPES).
     * Checked on the raw arguments BEFORE rehydration, so a resolvable token still refuses.
     *
     * @param array<array-key,mixed> $input
     */
    public function containsSensitiveToken(array $input): bool
    {
        return $this->matchesAnywhere($input, self::WRITE_REFUSED_TYPES);
    }

    /**
     * True when any argument carries a masked personal value (see PERSONAL_TYPES). Checked on the
     * raw, still tokenised arguments.
     *
     * @param array<array-key,mixed> $input
     */
    public function containsPersonalToken(array $input): bool
    {
        return $this->matchesAnywhere($input, self::PERSONAL_TYPES);
    }

    /**
     * @param array<array-key,mixed> $input
     */
    private function matchesAnywhere(array $input, string $pattern): bool
    {
        foreach ($input as $value) {
            if (is_array($value) && $this->matchesAnywhere($value, $pattern)) {
                return true;
            }
            if (is_string($value) && preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask detected personal values with their class label, without touching the vault. For sinks
     * that must never hold personal data but have no conversation to tokenise into (debug logs).
     */
    public function maskText(string $text): string
    {
        return $this->heuristic->mask($text);
    }

    /**
     * True when any argument still carries a vault token. A write is refused rather than run with one,
     * so a masked value is never written verbatim as "[order_1]" into real data, nor moved into a
     * write by prompt injection.
     *
     * @param array<array-key,mixed> $input
     */
    public function containsToken(array $input): bool
    {
        foreach ($input as $value) {
            if (is_array($value) && $this->containsToken($value)) {
                return true;
            }
            if (is_string($value) && preg_match('/(?:\[[a-z]+_\d+\]|mago:\/\/[a-z]+_\d+)/', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Swap vault tokens in the model's reply back to real values for the admin. Safe on any string,
     * a no-op when the reply carries no tokens.
     */
    public function rehydrate(string $text): string
    {
        return $this->vault->rehydrate($text);
    }

    /**
     * Rehydrate for a display sink. A token the vault cannot resolve (deleted vault rows, another
     * conversation's token, a forgery) becomes a neutral label instead of raw token grammar the
     * admin never typed (#97 decision 5); write sinks refuse unresolved tokens instead.
     */
    public function displayText(string $text): string
    {
        return (string)preg_replace(
            '/(?:\[[a-z]+_\d+\]|mago:\/\/[a-z]+_\d+)/',
            '[earlier record]',
            $this->vault->rehydrate($text)
        );
    }

    /**
     * Rehydrate a streamed text delta for the admin. A token can split across SSE chunks, so a
     * trailing partial token is held back as the returned carry and prepended to the next delta; the
     * rest is rehydrated now. Both shapes have to be recognised half-written: the bracket form and
     * the "mago://" form a url token wears so markdown link syntax leaves it alone. The stored
     * message stays tokenised; only the displayed copy is restored.
     *
     * @return array{0:string,1:string} [text to emit now, carry for the next delta]
     */
    public function rehydrateStreamDelta(string $carry, string $delta): array
    {
        $text = $carry . $delta;
        $newCarry = '';
        // Every branch has to consume at least one character, or the pattern matches the empty
        // string at the end of any delta and nothing is ever emitted.
        if (preg_match('/(?:\[[a-z]*(?:_\d*)?|m(?:a(?:g(?:o(?::(?:\/(?:\/[a-z]*(?:_\d*)?)?)?)?)?)?)?)$/', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int)$m[0][1];
            $newCarry = substr($text, $offset);
            $text = substr($text, 0, $offset);
        }

        return [$this->displayText($text), $newCarry];
    }
}
