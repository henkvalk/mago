# Privacy mode: decision record (issue #97)

The decisions behind privacy mode, kept in-repo so a developer does not need the GitHub issue
thread to understand why the code is the way it is. The mechanism itself is in `README.md`, the
legal grounding in `legal.md`. Decided September 2026 (Haimanti, in consult with Marvin); the
consolidated spec lives on issue #97.

## The two original questions, answered

- **Config toggle?** No. Privacy mode is the only mode, hard-wired. Art. 25(2) GDPR plus EDPB
  4/2019 (paragraphs 44 and 94): the protective state must be the default, and a controller may
  only deploy software with data protection built in. Mago is open source, merchants bring their
  own API keys, the maintainer is not the processor, so the default is the only compliance lever
  the product has; a toggle would undermine it. See `legal.md` §7 for the full argument
  and the hard gates any future "full data" mode would need.
- **Where is the line?** Do not send direct identifiers (strip them); tokenise only bare linkable
  ids so the assistant can still refer to a row across turns; aggregates, catalog, CMS and
  configuration flow freely. Not-sending is preferred over masking: tokenised data with a
  server-side map is still pseudonymised personal data (recital 26 GDPR, EDPB 01/2025), while
  absent data cannot leak (`legal.md` §6 and §8).

## The nine decisions

| # | Decision | As built |
|---|---|---|
| 1 | Persist tokenised, not raw, in `mago_message` (storage limitation, erasure win); display sinks rehydrate from the vault | Done: typed message and conversation title stored scrubbed on every ingress path (Stream, WebApi); history view, stream and WebApi responses rehydrate |
| 2 | Fail-closed: a tool that declares nothing has every field stripped | Done in 2.0.0 (V1 shipped lenient for unclassified tools as an interim state) |
| 3 | Silent input-scrub classes: email, BSN, IBAN, VAT, phone (NL-anchored); non-blocking client-side hint for FP-prone patterns | Done: `PiiHeuristic` with checksums (mod-97, elfproef), hint under the chat input |
| 4 | Streaming rehydration handles tokens split across chunks; the admin's browser sees cleartext | Done, deviation: server-side carry buffer instead of the planned client-side vault; functionally equivalent and simpler, the browser still receives cleartext |
| 5 | Unresolved token on display shows a neutral label; on a write it refuses | Done: "[earlier record]" on display; writes refuse unresolved tokens and admin URL tokens; personal-class tokens write after a warned confirmation (#114) |
| 6 | Dev tripwire: log-only in developer mode, throw only under a test flag, skip in production | Done: `EgressTripwire` on every provider egress, `throwOnHit` via di.xml |
| 7 | Integration and sink tests ride the existing e2e workflow; WireMock-journal assertion scoped per turn | Done: `privacy-mode.spec.ts` (journal + stored-copy asserts) plus a global canary mapping that fails any spec leaking the canary |
| 8 | Classification becomes a required method on the @api interfaces; accepted major bump 1.1.0 to 2.0.0 | Done in 2.0.0: `getFieldClassification()` on `ActionInterface` and `ToolInterface`, all first-party tools classified, registry and opt-in interface removed |
| 9 | Docs state the honest best-effort line and that a provider DPA is still required | Done: `README.md` residual-risks section |

## The egress line per data category

- **Never to the LLM (strip)**: customer names, email addresses, phone numbers, street addresses,
  review nicknames and review free text; BSN-like values and payment data under any mode.
- **Tokenise (linkable ids, Breyer C-582/14)**: customer/order/review/invoice/shipment/creditmemo
  ids and order increment numbers; admin URLs (they embed the admin secret key, token type `url`).
- **Free (non-personal)**: adequate-cell-size aggregates, catalog and product data, stock, CMS
  content, store configuration, url rewrites, cron/cache/indexer state, documentation.
- **Coarse fields stay public deliberately**: `city`/`country` on a customer lookup are kept while
  the identifiers beside them are stripped, so "which customers are in X" keeps working without
  the row being linkable.

## Deviations from the original spec, and why

- **Token grammar**: literal `[type_N]` instead of the planned salted sentinel. Compensating
  control: a defang pass neutralises token lookalikes in tool output before real tokens are
  minted, and forged or foreign tokens cannot resolve (vault is conversation-scoped).
- **Sensitive-class write refusal** (added after the security gate, narrowed in #114): `url`
  tokens never rehydrate into a write, resolvable or not, because an admin URL embeds the admin
  secret key. Heuristic-class tokens (name, email, IBAN, BSN, VAT, phone) originally refused too,
  which blocked legitimate writes such as a contact person in a CMS block or a form value read by
  `read_fields` and written back. They now rehydrate into confirmed writes; the confirmation card
  shows the real value and a "may write personal data" warning. The rehydration-oracle chain
  (prompt injection steering vaulted PII into public content) is mitigated by that card: every
  write needs the admin's approval with the value in plain sight. Id-class tokens rehydrate as
  before.
- **Vault-conceal pass**: values the vault already tokenised (an order number echoed inside an
  ack message) are re-concealed in any kept string; the heuristic alone cannot catch them
  because bare ids have no signature. Values shorter than six characters are excluded
  (false-positive risk), which is why short-id ack messages are classified STRIP instead.

## API surface changes in 2.0.0 (decision 8)

For the record; the module had no public installations or third-party tools at the time, so no
separate migration guide exists.

- `Api\Skill\ActionInterface` gained required `getFieldClassification(): array`.
- `Api\Tool\ToolInterface` gained required `getFieldClassification(string $action = ''): array`;
  `AbstractSkill` implements it by delegating to its actions.
- `Api\Skill\FieldClassifierInterface` (the 1.x opt-in) and
  `Service\Privacy\PiiClassificationRegistry` were removed; `PrivacyFilter::filter()` now takes
  the classification map instead of an action name, lenient mode is gone.
- `Api\ConversationRepositoryInterface` gained `updateTitle(int $conversationId, string $title)`.
- `Api\ChatServiceInterface::executeConfirmedTools()` gained `?int $conversationId = null`; pass
  it on every confirm round-trip so the privacy vault binds on the fresh request.

## Residual risks (documented, not solvable in code)

Names in free-form prose and admin-pasted PII have no signature: best-effort scrub, non-blocking
hint, honest docs. The admin typing customer data into the chat is exactly the breach pattern the
Dutch AP warns about (`legal.md` §4); the provider DPA remains the merchant's own duty.

## Related tickets

- #106 ConfigReader denylist to allowlist (config values egress wildcard-public behind the
  denylist today).
- #107 admin secret key in URLs: closed for AdminNavigator via `url` tokenisation; the stripped
  `admin_url` fields plus UI-side re-attach remain the pattern elsewhere.
- #108 conversation/vault retention and right-to-erasure.
- page_form (PR #67): must adopt field classification from day one when it lands
  (`legal.md` §10 point 4).
