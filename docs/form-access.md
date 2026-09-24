# Form Access (`page_form`)

> **Status:** Implemented — `MagoAssistant_Mago`
> **Last updated:** 2026-08-13

`page_form` lets the assistant read and stage changes on whatever admin form is open in the
browser right now — a product edit page, a CMS page, a CMS block, and any other admin screen built
on a Magento UI component form, including a third-party one this module has never heard of. It is
one of the built-in skills listed in the [README](../README.md#built-in-skills) and follows the
same tool/action contract described in [`docs/skills-architecture.md`](skills-architecture.md).

## For administrators

### What the assistant can see

Ask the assistant about "this form" or "the page I'm on" and it can:

- List every field's path, label and type (`describe_form`), optionally filtered by a substring
  of the label or path.
- Read the current value of one or more named fields (`read_fields`).
- Stage new values for one or more fields (`write_fields`), always behind the confirmation prompt
  every write skill uses.

Field values come from the live UI component in the browser, not a fresh database read, so **the
assistant sees edits you have already typed but not yet saved** — exactly what you'd see on
screen. Nothing about this is stored anywhere but the conversation until you save it yourself.

If no form is open (the dashboard, a grid, a report), `describe_form` and `read_fields` say so
plainly and suggest `cms_data` instead for a CMS page that isn't open right now. `page_form` only
ever acts on the single form on screen; `cms_data` is for pages you haven't navigated to.

### Changes are staged, never saved

`write_fields` **never writes to the database**. It only sets the same field values you would set
by typing into the form, through the exact UI component the page already uses. The assistant then
reports how many fields it staged, highlights each changed field on the page, and scrolls to and
focuses the first one. Nothing is written to Magento until **you** click the page's own Save
button — Magento's own validation and ACL checks run exactly as they would if you had typed the
value yourself. Reject the assistant's proposal and it stages nothing at all.

If you ask for a change on a page that is not currently open, and the assistant recognizes the
entity type (product, CMS page, CMS block, category, customer, order, invoice, shipment or credit
memo), it navigates the browser there for you and stages the change once that page has finished
loading — still just staged, still waiting on your Save.

### Which forms are off limits

The assistant never reads or writes to a form that may carry personal data, no matter what you ask
it: **customer edit, customer address, order view/create, admin user edit, and newsletter
subscriber forms are refused outright**, both in the browser (so the data is never even collected)
and again on the server (so a tampered request can't bypass the browser's own refusal). Ask about
customers through `customer_data` instead — it only ever returns aggregates and ids, never PII.

A few Magento admin screens (the customer edit page, in this install) don't register a UI
component form the assistant's detection can see at all, so those are excluded architecturally
before the deny list is ever consulted; the deny list is what additionally covers a screen that
does register a form under a name it should never expose.

This also covers asking the assistant to change a customer or order field while neither is open:
the same deny list is checked against where that request would navigate your browser to, before it
is ever sent there, so the assistant refuses up front instead of navigating you to a page it was
never going to be able to write to anyway.

Also out of scope, by design, not as a bug to fix:

- **Grids and listings** — nothing to stage a value onto.
- **System configuration** (`Stores > Configuration`) — that's what `config_reader` /
  `config_writer` are for.
- **Nested records inside a form** — a product's image gallery, configurable variations, bundle
  options, or a customer's individual address rows. `page_form` sees the top-level form's own
  fields only.
- **File and image uploads** — there is no field type this skill can stage a file into.

### What the confirmation prompt means

Like every write skill, `write_fields` pauses and shows a **Confirm / Reject** prompt before
touching anything. For a field write specifically, the prompt lists each field by its label with
its current value and the proposed one (for example, "Product Name: `Old Name` → `New Name`"),
capped at ten lines with an "...and N more fields" note for a larger batch, so you can actually
review it rather than scroll past a wall of text.

- **Confirm** stages the listed changes into the fields on the form that is open in your browser
  right now — not necessarily the one that was open when the assistant proposed the change. If you
  navigated to a different entity, a different store view, or away from the form entirely in the
  meantime, the assistant refuses to touch anything and tells you the page changed instead of
  guessing.
- **Reject** discards the proposal. Nothing is sent to the browser and nothing is staged.

### Where field data ends up

A value `read_fields` returns goes straight into the tool result, the same as any other tool's
output: it is sent to your configured AI provider, and retained both in the stored conversation
(`mago_message`) and in the usage log (`mago_usage_log`, which persists the whole request and
response for every turn). A value `write_fields` stages is proposed by the model itself, as that
tool call's own arguments — it is retained as part of the assistant's own stored message the same
way, and is sent back to the provider again as conversation history on every later turn. Treat
field values you read or write through the assistant like any other conversation content — not as
something more private just because it came from a form field.

With privacy mode on, a `read_fields` value still crosses, but first passes the PII heuristic: an
email address, IBAN, phone number, BSN or VAT number in it is replaced by a token before it reaches
the provider. A name or street without such a signature is not recognised. Customer, address and
order forms are on the deny list and never send field values at all.

The one thing that does *not* follow this path is the extra context `write_fields` adds purely for
the confirmation prompt — a field's current value and label, attached to `client_directive` by the
server so the browser can show "Label: `old` → `new`". `client_directive` is stripped before a tool
result reaches the provider or gets persisted (see the developer section below), so that specific
"before" value and label exist only in the browser, never in `mago_message` or
`mago_usage_log`.

### The Skills grid is not a security boundary here

The admin **Skills** grid lets you disable a skill, or restrict it to read-only, per admin user.
That setting is real and stored, but it is **not currently enforced on the chat path for any
skill**, `page_form` included — `ToolRegistry::getToolDefinitions()` and `ToolRegistry::getTool()`
both look up enabled tools without an admin user id, so the per-user permission check never runs
when the assistant actually decides what it can call. This is a pre-existing gap, not something
this feature introduced, but it means the deny list above — not the Skills grid — is what actually
keeps personal data out of `page_form`.

## For developers

### `client_directive`: how a tool result reaches the browser

Any tool result may carry a `client_directive` key. `ChatService` (`Service/Ai/ChatService.php`)
treats it as opaque transport: it does not know or care what the value means, it only forwards it,
untouched, as a `form_apply` SSE event on both the streaming (`Stream.php`) and confirmation
(`Confirm.php`) request paths, and strips the key before the value ever reaches the AI provider or
gets persisted to `mago_message`. `page_form` is the first, and so far only, tool that uses this
channel.

`page_form.write_fields` (`Service/Skills/Form/PageForm/WriteFieldsAction.php`) puts two different
directive shapes there, depending on whether a form is open:

**`form_write`** — staged on the form already open in the browser:

```json
{
  "type": "form_write",
  "target": {
    "namespace": "product_form",
    "entity_type": "product",
    "entity_id": "42",
    "store_id": ""
  },
  "changes": [
    {
      "path": "product.name",
      "label": "Product Name",
      "previous_value": "Old Name",
      "value": "New Name",
      "clear_use_default": false
    }
  ]
}
```

**`form_navigate`** — issued instead when no form is open but the requested `entity_type` is one
`EntityRouteMap` (below) knows how to reach:

```json
{
  "type": "form_navigate",
  "target": {"entity_type": "product", "entity_id": "42", "store_id": ""},
  "url": "https://.../admin/catalog/product/edit/id/42/",
  "changes": [{"path": "product.name", "value": "New Name"}]
}
```

`view/adminhtml/web/js/chat-panel.js` is the only place that dispatches on a directive's `type`. A
`form_write` directive is handed to `form-bridge.js`'s `apply()`. A `form_navigate` directive is
stashed in `sessionStorage` (`SS_KEY_NAVIGATE_INTENT`, a 60s TTL) and the browser is redirected to
`url`; once the target page loads, the stored intent is replayed as an ordinary `form_write`
directive against whatever form actually registers there — the same target-matching guard runs
either way, so a wrong-entity landing is refused, not silently applied.

Before writing anything, `form-bridge.js#apply()` re-checks the directive's `target` — namespace,
`entity_id` and `store_id` — against the form actually open in the browser right now
(`isSameTarget()`). An empty value never matches, not even another empty one (a new, unsaved
entity form always reports an empty `entity_id`), so a directive proposed for one "New Product"
form can never land on the next one. This is why `write_fields` requires `form_namespace`,
`entity_id` and `store_id` as parameters the model must copy from a prior `describe_form` call,
rather than deriving them itself: the values available when `execute()` runs are whatever the
browser resent on the confirm request for the page open at that moment, which is not necessarily
the page the model had in mind when it proposed the write.

### The deny list: `FormPolicy`

`Service/Form/FormPolicy.php` is the single source of which forms `page_form` refuses. It denies by
namespace (`customer_form`, `customer_address_form`, `sales_order_view`, `sales_order_create`,
`admin_user_form`, `newsletter_subscriber_form`) or by route substring (`customer/`, `sales/order`,
`admin/user`) — matched as substrings, not exact strings, so `customer/` also denies
`customer/address/edit`. Both the client and server enforce the same list:

- `form-bridge.js` reads it from `window.MAGO_CONFIG.formDenyNamespaces` /
  `formDenyRoutes`, published by `Block\Adminhtml\ChatPanel::getJsConfig()`, and refuses to
  snapshot a denied form at all — no field, not even the form's own identity, is ever collected.
- `Service/Form/PageContextNormalizer.php` re-applies `FormPolicy` on the server, because a denied
  form's data never being collected in the browser only holds for an unmodified client; a forged
  or tampered request bypasses that entirely.
- `WriteFieldsAction::isDeniedNavigationTarget()` checks the same `FormPolicy` a third time, against
  the *target* of navigate-then-act (below), before a `form_navigate` directive is ever built - the
  one path with no open form's namespace or route to check yet, since the point of navigate-then-act
  is that nothing is open. Without this, an entity type `EntityRouteMap` can reach but `FormPolicy`
  denies (`customer`, `order` today) would send the administrator's browser there anyway, on the
  assistant's own say-so, before finding out the write itself was never going to land.

This third check is keyed on two independent values, checked together through the same
`FormPolicy::isDenied(string $namespace, string $route)` the other two calls already use - neither
alone catches everything `EntityRouteMap` can reach today:

- The resolved admin route (`customer/index/edit`, `sales/order/view`, ...) catches `customer` and
  `order` directly, both already matching a route pattern.
- The entity type's own form namespace, built as `"{$entityType}_form"` - the same convention
  `form-bridge.js`'s `applyStoredNavigateIntent()` already relies on to replay a stored navigate
  intent - catches a namespace-only denial that the route never would. Concretely: if an integrator
  denies a namespace like `cms_block_form` through the extension point below, `cms_block`'s route
  (`cms/block/edit`) matches no route pattern at all, so checking the route alone would let
  navigate-then-act bypass that denial.

Because this reuses `FormPolicy::isDenied()` rather than re-implementing the match, a pattern added
through either `di.xml` argument is enforced on the navigate-then-act path the same instant it is
enforced everywhere else - there is only ever the one list, and only ever one notion of "denied."
`Test/End-2-end/tests/write-fields-action.spec.ts` proves both entity types directly: the admin's
browser never navigates at all (asserted on `page.url()`, not merely on nothing being staged).

`FormPolicy`'s constructor takes `$additionalDeniedNamespacePatterns` / `$additionalDeniedRoutePatterns`
arguments, extended (not replaced) via `di.xml`, the same shape `PageRegistry`'s `additionalPages`
argument already uses elsewhere in this module:

```xml
<type name="MagoAssistant\Mago\Service\Form\FormPolicy">
    <arguments>
        <argument name="additionalDeniedNamespacePatterns" xsi:type="array">
            <item name="vendor_secret_form" xsi:type="string">vendor_secret_form</item>
        </argument>
    </arguments>
</type>
```

This module's own `etc/di.xml` carries exactly this block, commented out, as the example an
integrator copies. Nothing extra is denied by default: `FormPolicy`'s built-in patterns already
cover every form that carries personal data, so the module ships no active `additionalDenied...`
entry of its own.

### `EntityRouteMap`: the one place per-entity routing knowledge lives

`Service/Url/EntityRouteMap.php` maps an entity type to its admin edit route and the URL parameter
key that route expects the id under (`order` → `sales/order/view` / `order_id`, `product` →
`catalog/product/edit` / `id`, `cms_page` → `cms/page/edit` / `page_id`, and so on for `invoice`,
`shipment`, `creditmemo`, `customer`, `cms_block`, `category`). Both `AdminNavigator` and
`WriteFieldsAction`'s navigate-then-act path resolve a URL through this map rather than keeping
their own copies of it; resolving a human identifier (a SKU, an order number) down to the id this
map expects stays the model's own job, through `product_data` / `cms_data` / `sales_data` first.
`getEntityTypes()` also backs the `entity_type` enum in `AdminNavigator`'s parameter schema, so
adding an entity here makes it navigable from both places at once.

### Snapshot caps

Four caps bound what `form-bridge.js#snapshot()` collects, all defined once as constants on
`Block\Adminhtml\ChatPanel` and published to the browser through `getJsConfig()`:

| Constant | Value | Bounds |
|---|---|---|
| `FORM_FIELD_CAP` | 200 | Fields per snapshot |
| `FORM_VALUE_LENGTH_CAP` | 500 | Characters per field value |
| `FORM_OPTION_CAP` | 50 | Options per select/multiselect field |
| `FORM_BYTE_CAP` | 200,000 | Serialized snapshot size in bytes |

The client applies the per-field and per-option caps while it walks `uiRegistry`, then trims whole
fields from the end of the snapshot until the serialized payload is back under the byte cap. The
caps cross a trust boundary on the way to the server, so `PageContextNormalizer` re-applies every
one of them independently against the raw posted payload rather than trusting the client to have
already done so — a forged request, a tampered console, or a future bridge bug are all still
bounded by the same limits.

### The tool contract

`page_form` is an `AbstractSkill` with three actions (`Service/Skills/Form/PageForm/`):
`DescribeFormAction`, `ReadFieldsAction` (read-only) and `WriteFieldsAction` (write, so it always
goes through confirmation). `AbstractSkill::getParameterSchema()` flattens every action's own
parameter schema into one object for the model, keeping the **first** registration of a given
parameter name and silently dropping any later action's schema for that same name — so action
parameter names within one skill must not collide. `page_form`'s three actions don't today
(`filter`, `field_paths`, and `form_namespace`/`entity_id`/`store_id`/`changes`/`entity_type` are
all distinct), but a new action added to this skill has to pick names with that in mind.

## Why these decisions

**Discovery is client side, not server side, on purpose.** `form-bridge.js` walks Magento's own
`uiRegistry`, the same registry every UI component form (including a third-party one) already
registers itself in, rather than the server trying to know the shape of every possible admin form.
That's what makes `page_form` work on a CMS page, a product, and any future admin screen built the
ordinary Magento UI component way, without a line of per-entity code here. It also has a second
effect worth being explicit about: reading through the live component, not a database row, is the
only way to see an edit the administrator has made but not yet saved — a server-side read would
show what's in the database, not what's on screen.

**Nothing is ever persisted by this feature**, because a write skill that could bypass Magento's
own Save action would also bypass its validation and ACL checks at the moment of writing. Staging
into the same fields the administrator would type into, and leaving the actual write to the page's
own Save button, keeps every existing safeguard in the write path exactly where it already was.
