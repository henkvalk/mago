# Privacy mode (issue #97)

Privacy mode keeps directly identifying customer data out of what the assistant sends to the LLM
provider. It is the only mode (no toggle). Companion documents in this folder: `decisions.md` (the
decision record, its rationale and the 2.0.0 API surface changes), `legal.md` (the GDPR/AI Act
research it rests on).

**Legal preference: not-sending over masking (#97 §8).** Tokenised data with a server-side map is
still pseudonymised personal data (Recital 26, EDPB 01/2025); absent data cannot leak. So direct
identifiers are **stripped** (never sent). Only a **bare linkable id** is **tokenised**, purely so the
assistant can refer to a row across turns (`[customer_N]`, `[order_N]`, `[review_N]`).

## Classification is required (2.0.0)

Every tool declares how its own output crosses to the LLM:

- `Api\Skill\ActionInterface::getFieldClassification(): array` on every skill action.
- `Api\Tool\ToolInterface::getFieldClassification(string $action = ''): array` on every tool;
  `AbstractSkill` implements it by delegating to the named action, flat tools ignore `$action`.

The map is `field => [PiiClass, tokenType?]`, covering every key the result can contain at any
depth. **Undeclared is never public**: a field the map does not name is stripped, so a tool that
declares nothing leaks nothing, and a brand-new or third-party tool that forgets to classify fails
closed instead of leaking. `PiiClass::ANY` (`'*'`) classifies keys that cannot be enumerated
(dynamic config paths, status codes used as keys); declaring it is an explicit assertion about all
of them.

### Cookbook: the four canonical shapes

```php
// 1. A lookup returning customer PII: strip identifiers, tokenise the bare id, keep coarse fields.
public function getFieldClassification(): array
{
    return [
        'entity_id' => [PiiClass::TOKENISE, 'customer'],
        'name' => [PiiClass::STRIP],
        'email' => [PiiClass::STRIP],
        'telephone' => [PiiClass::STRIP],
        'country' => [PiiClass::PUBLIC],
        'city' => [PiiClass::PUBLIC],
    ];
}

// 2. A list of linkable ids: tokenise the ids, counts and dates are public.
return [
    'customer_id' => [PiiClass::TOKENISE, 'customer'],
    'period' => [PiiClass::PUBLIC],
    'total_new' => [PiiClass::PUBLIC],
];

// 3. A PII-free tool: enumerate the public fields by name (nothing is public by omission).
return [
    'sku' => [PiiClass::PUBLIC],
    'name' => [PiiClass::PUBLIC],
    'price' => [PiiClass::PUBLIC],
];

// 4. Output whose keys cannot be enumerated: assert the whole surface deliberately.
return [PiiClass::ANY => [PiiClass::PUBLIC]];
```

Do not declare `error` (envelope, always kept and heuristic-rescrubbed). Do not declare `admin_url`
(always stripped centrally, it embeds the admin secret key; the panel re-attaches deep links
UI-side). A field carrying a full backend URL under another name is `[PiiClass::TOKENISE, 'url']`
(AdminNavigator does this): the provider sees `[url_N]`, display rehydration hands the admin the
real link. An explicit `'message' => [PiiClass::STRIP]` beats the envelope allowance when an ack
message would embed something the vault cannot conceal (for example a bare review id).

## The egress paths and their defenses

| Path | Defense |
|---|---|
| Tool output | `PrivacyFilter` at `ChatService::executeTool()`'s return: declared public passes, tokenise becomes a stable vault token, everything else is stripped. Kept strings are defanged (forged token lookalikes neutralised), vault-concealed (a value the vault already tokenised, like an echoed order number, becomes its token) and heuristic-rescrubbed. |
| The admin's typed message | Scrubbed at the wire boundary (`scrubMessages`) and **persisted tokenised** (decision 1); the conversation title derives from the scrubbed text. History replay and the custom system prompt go through the same scrub. |
| Model-generated tool-call arguments | Rehydrated to real values before the tool runs, on every path (read, stream, confirm; the confirm round-trip re-binds the vault via the conversation id). Two write guards: an admin URL token never rehydrates into a write, resolvable or not (it embeds the admin secret key); and a token the vault cannot resolve is refused. Personal-class tokens (name, email, IBAN, BSN, VAT, phone) and id-class tokens (order, customer, review, ...) rehydrate into confirmed writes; the confirmation card shows the admin the rehydrated values, and warns when a write carries personal data (#114). |
| The model's reply | Stored tokenised; the streamed copy and the history view rehydrate for display, and a token the vault cannot resolve shows a neutral label instead (decision 5). |
| The wire itself | `EgressTripwire` (decision 6): in developer mode every outbound payload is scanned once more, independently of the paths above; a hit is logged (classes and position, never values). Under the test flag it throws, so a filter regression fails a test run loudly. Production skips the scan. |

The typed-input hint in the chat panel warns non-blockingly when a message looks like it carries
personal data (decision 3); the certain classes (email, IBAN, BSN, VAT, NL phone) are tokenised
server-side regardless. Debug logging of the raw request body is gated behind the debug flag and
masked by class (irreversibly, no vault) so the log never holds what decision 1 keeps out of the
message store. The panel renders model output HTML-escaped before markdown parsing, so injected
raw HTML in a reply shows as text instead of executing in the admin session.

## Files (`Service/Privacy/`)

| File | Role |
|---|---|
| `PiiClass.php` | The classes `public` / `tokenise` / `strip` and the `ANY` wildcard. |
| `ConversationVault.php` | Reversible per-conversation token map; stable, typed tokens; conceals known values in kept text. |
| `PrivacyFilter.php` | Choke-point filter working off the tool's own declaration; fail-closed. |
| `PiiHeuristic.php` | Detects email / IBAN / BSN / VAT / Dutch phone in free text (checksums), for the paths with no declared field to classify; also powers the tripwire's independent detection. |
| `EgressTripwire.php` | Last-line outbound scan (decision 6). |
| `PrivacyService.php` | Request-scoped facade ChatService uses: filter results, scrub messages and single texts, rehydrate arguments, display rehydration, begin the conversation vault. |

## Verification

- `PrivacyFilterTest` proves the real PII tool shapes strip/tokenise correctly, fail-closed
  semantics, envelope handling, defang and conceal.
- `FieldClassificationCoverageTest` walks every concrete class under `Service/Skills` and validates
  the shape of its declaration, so a malformed map fails the build.
- `ChatServiceTest` carries the end-to-end canary (customer PII never reaches the provider payload)
  and the write-path token tests.
- `EgressTripwireTest` proves the tripwire's log/throw/skip behavior.

```bash
# from the Magento root
vendor/bin/phpunit -c vendor/mago-assistant/mago/phpunit.xml.dist
```

## Residual risks (state honestly to merchants)

Names in free-form prose and admin-pasted PII have no signature and are only mitigated (best-effort
scrub, non-blocking hint, honest docs), not closed. No directly identifying data leaves from the
shop's structured records; free text the admin types is filtered best-effort. Avoid pasting customer
PII, and the provider DPA is still required for any residual personal data.

## Related (separate tickets)

- page_form field classification (once PR #67 lands in main).
- ConfigReader allowlist (#106), admin_url secret key (#107), retention and right-to-erasure (#108).
