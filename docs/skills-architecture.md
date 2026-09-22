# Skills Architecture

> **Status:** Draft — `MagoAssistant_Mago` v1.0.0
> **Last updated:** 2026-09-07

## Table of Contents

1. [What is a Skill](#what-is-a-skill)
2. [Architecture](#architecture)
3. [Built-in Skills](#built-in-skills)
4. [Slash Commands](#slash-commands)
5. [ACL & Permissions](#acl--permissions)
6. [Building Custom Skills](#building-custom-skills)
7. [Examples](#examples)
8. [MCP Compatibility](#mcp-compatibility)
9. [Configuration](#configuration)
10. [Data & Privacy](#data--privacy)

---

## What is a Skill

A **Skill** is a self-contained capability that the Admin Assistant can invoke during a conversation. Skills give the AI access to Magento data and actions — reading sales figures, updating CMS content, generating product descriptions — while enforcing ACL permissions and requiring user confirmation for write operations.

### Skill vs Tool

In the current codebase, skills are implemented as **Tools** (`ToolInterface`). The terms are used interchangeably, but there is a conceptual distinction:

| | Tool | Skill |
|---|---|---|
| **Scope** | Single atomic operation | Can group multiple tools under one domain |
| **Example** | `config_reader` — reads a config path | "Store Configuration" — reads + writes config |
| **Granularity** | Fine-grained, one action | Coarse-grained, a capability area |
| **User perspective** | Implementation detail | Something the assistant "can do" |

In practice, a skill maps to one or more tools registered in the `ToolRegistry`. Third-party developers register tools; the assistant surfaces them as skills to the admin user.

---

## Architecture

### Core Interfaces

#### `ToolInterface`

```
MagoAssistant\Mago\Api\Tool\ToolInterface
```

Every tool implements this contract:

```php
interface ToolInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getParameterSchema(): array;
    public function execute(array $params): array;
    public function isReadOnly(): bool;
    public function isReadOnlyAction(array $input): bool;
    public function getInstructions(): string;
    public function getMagentoAcl(array $input = []): string;
}
```

| Method | Purpose |
|--------|---------|
| `getName()` | Unique identifier, e.g. `sales_data` |
| `getDescription()` | Short description sent to the LLM with every request so it knows the tool exists |
| `getParameterSchema()` | JSON Schema defining accepted parameters |
| `execute(array $params)` | Runs the tool logic against Magento, returns structured data |
| `isReadOnly()` | `true` = all actions are read-only; `false` = tool has at least one write action |
| `isReadOnlyAction(array $input)` | Checks if a specific invocation is read-only based on input parameters. For tools with mixed read/write sub-actions (e.g. `cms_data`), this checks the actual action. |
| `getInstructions()` | Detailed usage instructions injected only when the tool is invoked (JIT). Keeps the base prompt lean. |
| `getMagentoAcl(array $input)` | Native Magento ACL resource for a specific invocation (e.g. `Magento_Backend::cache` for a cache `status` read, `Magento_Backend::flush_cache_storage` for `flush`). Mixed tools return the resource matching the action in `$input`; empty or unknown input must resolve to the most restrictive resource (fail closed). Checked in addition to the assistant skill permissions. Return empty string if not needed. |

#### Just-in-Time Instructions

Tool descriptions (`getDescription()`) are sent with every request so the LLM knows which tools exist. Detailed instructions — edge cases, formatting rules, domain-specific guidance — go in `getInstructions()` instead.

The `ChatService` injects a tool's instructions into the conversation **only when that tool is actually called** (once per tool per conversation). This keeps the base prompt lean regardless of how many tools are registered.

```
Request 1:  system prompt + 20 tool descriptions (short)     → LLM picks sales_data
Request 2:  + sales_data instructions (detailed)             → LLM executes with full context
            + sales_data result
```

**For tool authors:** Keep `getDescription()` under ~100 tokens — just enough for the LLM to know when to pick the tool. Put formatting rules, edge cases, and domain knowledge in `getInstructions()`.

For `AbstractSkill`-based tools, override `getBaseInstructions()` for skill-level instructions. Individual actions implement `getInstructions()` via `ActionInterface`. The `AbstractSkill` aggregates both automatically.

#### `ToolRegistry`

```
MagoAssistant\Mago\Service\Tool\ToolRegistry
```

Central registry that collects all tools via DI injection:

```php
class ToolRegistry
{
    public function __construct(?PermissionChecker $permissionChecker = null, array $tools = []); // ToolInterface[] injected via di.xml
    public function getEnabledTools(?int $adminUserId = null): array;              // Tools the admin may invoke
    public function getToolDefinitions(?int $adminUserId = null): array;           // Specs for the AI provider, as the admin may use them
    public function getToolDefinition(ToolInterface $tool, ?int $adminUserId): array; // One spec, narrowed to the admin's grant
    public function getTool(string $name, ?int $adminUserId = null): ?ToolInterface; // Retrieve by name (enabled tools only)
    public function isCallAllowed(ToolInterface $tool, array $input, ?int $adminUserId): bool;
    public function hasWriteAccess(ToolInterface $tool, ?int $adminUserId): bool;
}
```

Parameter schemas and the derived read-only action lists are memoized per tool for the lifetime of the registry (one request), so a tool's `getParameterSchema()` is built once no matter how often the registry consults it.

#### `IrreversibleActionInterface` / `IrreversibleToolInterface`

A write that has no reverse (a delete, an order cancellation, a refund) implements
`MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface` instead of the plain
`ActionInterface`. Its one extra method, `getImpacts(array $params, int $adminUserId): string[]`,
describes what the call will do to the store in plain words, one consequence per line, reading
the current state where that helps ("Order #100 (currently processing) is canceled ..."). It must
never change anything.

`AbstractSkill` implements the matching `IrreversibleToolInterface` by delegating to the action
named in the input, so a skill needs no extra code. `ChatService` asks the tool before it sends a
confirmation and adds `irreversible: true` plus the `impacts` to that tool in the `confirm` event;
the chat panel then shows the "cannot be undone" card with an acknowledgement checkbox instead of
a plain Allow button. Built-in irreversible actions: `order_manager` `cancel` and
`create_creditmemo`, `url_rewrite_manager` `delete`.

A `confirm` event also carries each tool call's `id`. With several writes in one turn the panel
shows a tick list; the confirm request then sends the ticked ids as `tool_call_ids`, and
`executeConfirmedTools()` answers every unticked call with `{"skipped": true, ...}` without
running it. A tool that returns an `error` key is reported to the panel with a `tool_status` of
`failed` and the error as `message`.

#### `ActionScopedToolInterface`

```
MagoAssistant\Mago\Api\Tool\ActionScopedToolInterface
```

Optional extension of `ToolInterface` for mixed read/write tools. It adds `getDescriptionForActions(array $actionNames)` and `getParameterSchemaForActions(array $actionNames)`, which the registry calls for admins holding only a `read` grant so the provider sees a description and schema that mention nothing but the read actions. `AbstractSkill` implements it for free; hand-written mixed tools (`cache_manager`, `indexer_manager`) implement it themselves. A mixed tool that only implements `ToolInterface` still works, but is narrowed by the action enum alone.

#### `ChatService`

```
MagoAssistant\Mago\Service\Ai\ChatService
```

Orchestrates the conversation loop:

1. Prepend the system prompt and the current store scope summary (see [Store Scope Awareness](#store-scope-awareness))
2. Send messages + tool definitions to the AI provider
3. If the AI requests a **read-only** tool → execute automatically, append result, loop
4. If the AI requests a **write** tool → pause, return `pending_confirmation: true`
5. On user confirmation → execute the write tool via `executeConfirmedTools()`
6. Loop continues until the AI produces a final text response or `max_tool_iterations` is reached

```
┌──────────┐     messages + tools      ┌──────────────┐
│  Admin    │ ──────────────────────── │  AI Provider  │
│  User     │                          │ (Claude/GPT)  │
└──────────┘                          └──────────────┘
      │                                       │
      │                                       │ tool_call
      │                                       ▼
      │                              ┌─────────────────┐
      │                              │  ToolRegistry    │
      │                              │  ┌─────────────┐ │
      │      read-only? ──── yes ──▶ │  │ execute()   │ │ ── result back to AI ──▶ loop
      │                              │  └─────────────┘ │
      │                              └─────────────────┘
      │                                       │
      │               write? ── yes ──▶ pause + confirm
      │                                       │
      ◀───────── pending_confirmation ────────┘
      │
      │  confirm / reject
      │
      ▼
   execute write tool ──▶ result back to AI ──▶ continue
```

### Response Limits

> **Status: Truncation implemented.** Execution timeouts are still planned.

Tool output is truncated by the `ChatService` to prevent context window exhaustion. A single CMS page or large product set can easily produce thousands of tokens — without limits, one tool call can crowd out the rest of the conversation.

| Limit | Default | Configurable |
|-------|---------|--------------|
| Max response size per tool | 4,000 tokens (estimated as 4 bytes per token on the JSON output) | `mago/tools/max_response_tokens` |
| Execution timeout (planned) | 5 seconds (read), 10 seconds (write) | `mago/tools/execution_timeout` |

When a tool result exceeds the limit, the result sent to the LLM is replaced by an envelope: `_truncated: true`, an `output` field with the first part of the JSON (cut multibyte-safe, may stop mid-value), `total_bytes`/`returned_bytes`, and a `note` instructing the model not to retry the same call but to narrow the query. The cap applies to all three execution paths (plain, streaming, confirmed writes); the debug log still records the full result before truncation.

### Store Scope Awareness

```
MagoAssistant\Mago\Service\Store\StoreScopeContext
```

Magento configuration and content live on three levels — default (global), website and store view — and a deeper level overrides the one above it. Before this existed the assistant silently acted on whatever scope a tool defaulted to (issue #38): config went to the default scope, CMS pages created through the internal REST API landed on the default store view only, and product content was saved globally.

`StoreScopeContext` reads the website / store group / store view layout from the `StoreManager` and serves two purposes:

1. **Per-request verification.** `ChatService` appends a `[Store scope]` section to the system prompt of **every** request. It lists every website, store group and store view with id, code and name, flags the defaults, and states the scope rules: work out which scope the request targets, ask before a scope-sensitive write when the user did not name a scope and the change could differ per store view, default reads to the default scope, and always state the scope used. On a single-store-view installation the section tells the assistant to use the default scope and never ask. The section is rebuilt on every request, so a store view added mid-conversation is picked up on the next message; a failure to load the stores is logged and the chat continues without the section.
2. **Tool-side validation.** Scope-sensitive tools take the same object to validate the scope they were handed and to describe it back to the user, so a guessed or stale id never reaches Magento:

| Tool | Scope handling |
|------|----------------|
| `config_reader` | Validates `scope`/`scope_id` against existing websites and store views. A default-scope read on a multi-store installation also returns `overrides`: every website or store view whose effective value differs from what it inherits (a website override is reported once, not per store view), plus a `note` telling the model to mention them. |
| `config_writer` | Validates `scope`/`scope_id` before writing; the result and message carry a `scope_label` such as `store view "Luma" (id 2, code "luma")`. Its instructions tell the model to ask which scope the user means when the setting could differ per store view. |
| `cms_data` (`create_page`, `create_block`) | New `store_id` parameter: `0` (default) creates the entity for **all store views**, a store view id restricts it to that view. Implemented by running the internal REST call under `/rest/{store code}/V1/` (`all` for 0), because `PageInterface`/`BlockInterface` expose no store field. Previously new pages and blocks were tied to the default store view. |
| `content_generator` | New `store_id` parameter: `0` reads and saves the default (global) values, a store view id reads and saves a store-view-specific version, e.g. a translation. The same id must be used for the generate call and the save call. Earlier versions saved at the admin's current store view (the default store view) instead of globally, so a default-scope save also returns `overridden_in`: store views that still carry their own value and therefore do not show the new text. |

`InternalApiClient::get()/post()/put()/delete()` accept an optional store code for this; without one they keep calling `/rest/V1/`, which Magento serves in its default store view.

**For tool authors:** inject `StoreScopeContext` when a tool reads or writes anything that Magento stores per scope. Use `validateScope()` for config-style `scope`/`scope_id` pairs, `getRestStoreCode()` when the write goes through the internal REST API, and `describeScope()`/`describeStoreTarget()` to put a human-readable scope label in the result so the assistant can repeat it to the user.

### Resource Links (Planned frontend rendering)

> **Status: Partially implemented** — Tools can return `_links` arrays and the AI renders them as markdown links (which works). Dedicated frontend rendering of `_links` is not yet built.

Tools can include navigable admin links in their response by adding a `_links` array.

```php
public function execute(array $params): array
{
    return [
        'order_id' => '100004521',
        'status' => 'processing',
        'grand_total' => 149.95,
        '_links' => [
            [
                'label' => 'Order #100004521',
                'url' => 'sales/order/view/order_id/100004521'
            ]
        ]
    ];
}
```

The `_links` convention is optional — tools work fine without it. But it significantly improves the UX by turning data into actionable navigation. The URL is relative to the admin base URL; the frontend prepends the admin path.

### Client Directives (`client_directive`)

A tool result may also carry a `client_directive` key — a payload meant for the browser itself,
not for the AI provider. `ChatService` treats it as opaque transport: it does not interpret the
value, it only forwards it, untouched, as a `form_apply` SSE event (on both the streaming and
confirmation request paths) and strips the key before the result reaches the provider or gets
persisted to `mago_message`. This is what lets a tool make the browser *do* something — apply a
staged value, navigate somewhere — as a side effect of its own result, without `ChatService` ever
needing to know what that something is.

```php
public function execute(array $params, int $adminUserId): array
{
    return [
        'staged' => true,
        'client_directive' => [
            'type' => 'form_write',
            // whatever shape the frontend code that reacts to this directive expects
        ],
    ];
}
```

`page_form` is the first tool to use this channel, with two directive types
(`form_write`, `form_navigate`) that `view/adminhtml/web/js/chat-panel.js` and
`view/adminhtml/web/js/form-bridge.js` know how to apply. See
[`docs/form-access.md`](form-access.md#client_directive-how-a-tool-result-reaches-the-browser) for
the concrete payload shapes and how the browser handles each one. Like `_links`, this is a
convention a tool opts into by including the key — nothing about the base `ToolInterface` contract
requires it.

### Provider Layer

Providers are not this module's concern. `MageOS_AiBase` owns them — credentials, models,
endpoints and the wire format of every backend it supports — and hands back a
`MageOS\AiBase\Api\AiClientInterface` that speaks `chat()`, `streamChat()` and `complete()`.

Two classes bridge that to the rest of this module:

| Class | Responsibility |
|---|---|
| `Service\Ai\RequestFactory` | Turns the conversation arrays the panel and the conversation store speak into a `ChatRequestInterface`, tools included |
| `Service\Ai\Client` | Resolves the configured service row, applies `max_tokens`, and maps responses and stream chunks back to the array shape `ChatService` returns |

`ChatService` therefore never sees a provider. Streaming yields `StreamChunkInterface` values with
tool calls already complete and their arguments decoded, so there is no SSE parsing or partial-JSON
stitching anywhere in this module.

Which service a store runs on is `mago/api/ai_service`, an id pointing at a row configured under
*Stores > Configuration > Mage-OS > AI Configuration*. Empty means the first usable one.

---

## Built-in Skills

The module ships with 10 tools grouped into 4 skill areas:

### Store Analytics

| Tool | Class | Read-only | Description |
|------|-------|-----------|-------------|
| `sales_data` | `Service\Skills\Analytics\SalesData` | Yes | Revenue summaries, top products, recent orders, order counts by status. Supports period filters (`today`, `7days`, `30days`, custom date ranges). |
| `product_data` | `Service\Skills\Analytics\ProductData` | Yes | Product search, lookup by SKU, inventory counts, low-stock alerts. |
| `customer_data` | `Service\Skills\Analytics\CustomerData` | Yes | Customer counts, recent signups, top spenders. **Never returns PII** — only aggregates and IDs. |

### Store Configuration

| Tool | Class | Read-only | Description |
|------|-------|-----------|-------------|
| `config_reader` | `Service\Skills\Configuration\ConfigReader` | Yes | Reads Magento system configuration by path and scope. Validates the scope against existing websites/store views and lists per-scope overrides of a default value. Blocks sensitive paths (keys, secrets, passwords, tokens, payment config). |
| `config_writer` | `Service\Skills\Configuration\ConfigWriter` | No | Writes Magento system configuration on a validated default/website/store view scope. Same blocked-path protections. Requires user confirmation. |
| `cache_manager` | `Service\Skills\Configuration\CacheManager` | No | Flush all caches, flush specific cache types, or view cache status. Requires confirmation for flush actions. |
| `indexer_manager` | `Service\Skills\Configuration\IndexerManager` | No | Reindex specific indexers or all, check indexer status, change indexer mode (realtime/schedule). Requires confirmation. |

### Content Management

| Tool | Class | Read-only | Description |
|------|-------|-----------|-------------|
| `cms_data` | `Service\Skills\Content\CmsData` | Mixed | List, read, create and update CMS pages and blocks. Read actions (list/get) execute automatically; write actions (create/update) require confirmation. New pages and blocks target all store views or one store view via `store_id`. Uses `isReadOnlyAction()` for per-action granularity. |
| `content_generator` | `Service\Skills\Content\ContentGenerator` | No | AI-powered product description, meta, and short description generation. Two-phase: first call returns product context, second call saves confirmed content. Works on the default scope or on one store view via `store_id`. |

### Navigation

| Tool | Class | Read-only | Description |
|------|-------|-----------|-------------|
| `admin_navigator` | `Service\Skills\Navigation\AdminNavigator` | Yes | Searches admin pages by keyword and returns direct URLs. Used for navigating to specific admin sections. |

---

## Slash Commands

Typing `/` in the chat input opens an autocomplete menu with two kinds of entries:

1. **Commands** — `/cache flush`, `/index status`, … These run directly against Magento through the
   assistant's tools, without a round-trip to the AI provider. They work even when no provider is
   configured and answer in a deterministic Markdown format.
2. **Skills** — `/cache_manager`, `/sales_data`, … Selecting one expands to a prompt
   ("Use the cache_manager skill to ") that the admin completes and sends to the assistant.

Both lists are filtered by what the admin types next and by what the admin is allowed to do.

### Built-in Commands

| Command | Does | Tool action |
|---------|------|-------------|
| `/cache flush` | Flushes all caches, including the cache storage | `cache_manager.flush` |
| `/cache clean <type> [type...]` | Cleans the given cache types, e.g. `config full_page` | `cache_manager.flush_type` per type |
| `/cache status` | Lists all cache types and whether they are enabled | `cache_manager.status` |
| `/index list` | Lists all indexers with their ID and mode | `indexer_manager.status` |
| `/index status` | Shows the status of all indexers and how many need a reindex | `indexer_manager.status` |
| `/index reindex [indexer_id...]` | Reindexes all indexers, or only the given indexer IDs | `indexer_manager.reindex_all` / `reindex` per ID |
| `/help` | Lists the commands available to the current admin | — |

A command without a subcommand (`/cache`) or with an unknown one prints its usage. Command
and subcommand names are case-insensitive. A slash message that matches no registered command
(`/revenue today`) is sent to the assistant as a normal prompt.

### How a Command Runs

```
Admin types "/cache clean config"
        │
        ▼
Controller\Adminhtml\Chat\Stream ── CommandRunner::isCommand() ── yes ──▶ CommandRunner::run()
        │                                                                       │
        │  persists the user message and the reply                              ▼
        │  like a normal turn; streams the reply as one "text" chunk    CacheCommand::execute('clean', ['config'])
        │                                                                       │
        ▼                                                                       ▼
   SSE: conversation → tool_status → text → done            ChatServiceInterface::executeConfirmedTools()
                                                              ├─ skill permission (PermissionChecker)
                                                              ├─ native Magento ACL (getMagentoAcl)
                                                              └─ cache_manager->execute([...])
```

The admin typed the exact action, so a write command needs no confirmation card. Everything else
is enforced exactly as for an AI-initiated tool call:

- `MagoAssistant_Mago::assistant_write` is required for write subcommands (`flush`, `clean`, `reindex`)
- the skill grant on the underlying tool decides which subcommands exist for the admin
  (a read grant on `cache_manager` shows only `/cache status`)
- the tool's native Magento ACL (`Magento_Backend::flush_cache_storage`, `Magento_Indexer::invalidate`, …)
  is checked on execution and returned as an error when missing

### Registering Custom Commands

Implement `MagoAssistant\Mago\Api\Command\CommandInterface` and add it to the
`CommandRegistry` via `di.xml`:

```xml
<type name="MagoAssistant\Mago\Service\Command\CommandRegistry">
    <arguments>
        <argument name="commands" xsi:type="array">
            <item name="server" xsi:type="object">Vendor\HostingIntegration\Command\ServerCommand</item>
        </argument>
    </arguments>
</type>
```

When the command wraps one of the assistant's tools, extend
`MagoAssistant\Mago\Service\Command\AbstractToolCommand`: declare `getToolName()`, list the
subcommands with `getSubcommands()` (name, argument hint, description, read/write) and map each
subcommand to a tool input in `execute()` through `runTool()`. Permission filtering, the
`tool_status` events and error rendering come for free; `renderTable()` formats tabular results.

`CacheCommand` and `IndexCommand` are the reference implementations.

---

## ACL & Permissions

### Resource Tree

```
Magento_Backend::admin
├── Magento_Backend::stores
│   └── Magento_Backend::stores_settings
│       └── Magento_Config::config
│           └── MagoAssistant_Mago::config          # Module configuration access
│
└── MagoAssistant_Mago::assistant                    # Parent resource
    ├── MagoAssistant_Mago::assistant_read           # Read operations
    └── MagoAssistant_Mago::assistant_write          # Write operations
```

### How It Works

1. **Every tool is gated by the assistant resources** (`assistant_read` for read actions, `assistant_write` for write actions), enforced per invocation by the tool path; tools with a native Magento counterpart additionally declare a per-action resource via `getMagentoAcl(array $input)`.
2. **Read tools** require `assistant_read` — analytics queries, config reading.
3. **Write tools** require `assistant_write` — config changes, CMS updates, content generation.
4. **ACL is checked before execution**, not just at the API level. Even if the AI requests a tool, it won't execute if the admin user's role lacks the required resource.
5. **Admin roles** in Magento's `System > Permissions > User Roles` control which skills are available per user. An admin with only `assistant_read` will never see write tools offered by the AI — they are excluded from the tool definitions sent to the provider.

### Per-Role Behavior

| Role has | Tools available | Write confirmation |
|----------|----------------|-------------------|
| `assistant_read` | ConfigReader, SalesData, ProductData, CustomerData, AdminNavigator | N/A |
| `assistant_read` + `assistant_write` | All 10 tools | Required for write tools |
| None | Chat only, no tools | N/A |

### Per-User Skill Permissions

On top of role ACL, the **Skills** admin screen (Mago Assistant → Skills) stores a per-admin-user permission per skill in the `mago_skill_permission` table: `disabled`, `read`, or `write`. `PermissionChecker` resolves these; when a user has no row for a skill, the role ACL above is the fallback. Unknown values in a row are treated as `disabled` (fail closed).

Enforcement happens at three points, all keyed on the acting admin user id, which the chat controllers pass through `ChatService` into `ToolRegistry`:

1. **Advertising** — `ToolRegistry::getToolDefinitions($adminUserId)` excludes skills the user may not use at all. Availability requires the grant to cover the skill's least-privileged side: `read` suffices for read-only and mixed skills, write-only skills require `write`.
2. **Action filtering** — for a user with only a `read` grant, a mixed skill (e.g. `cms_data`) stays available but its advertised definition is narrowed to its read-only actions: the `action` enum, and for `ActionScopedToolInterface` tools also the description and the write-only parameters. The model is never told about actions the user cannot invoke.
3. **Execution** — `ToolRegistry::isCallAllowed($tool, $input, $adminUserId)` re-checks every invocation per action (`isReadOnlyAction($input)`), including tool calls executed via the Confirm flow. A missing user id routes through the ACL fallback rather than allowing everything.

Should the model nevertheless emit a write action the user may not perform, `ChatService` does not start the confirmation round-trip: the call is executed straight away, `executeTool()` returns the "Access denied" error as the tool result, and the loop continues so the model can answer within the same turn. The same holds for a native Magento ACL denial. JIT tool instructions (`getInstructions()`) are only injected after a call that was actually permitted.

The chat panel's slash-command legend is fed from `ToolRegistry::getEnabledTools($adminUserId)` with the same narrowed definitions, so an admin only sees the skills (and actions) they can invoke; a mixed skill is badged `read` when the admin lacks a write grant.

Per-user rows only apply to genuine admin users. Integration-token ids live in a different table than `admin_user`, so a colliding id must never select another admin's permission rows or conversations. The REST endpoints (`Model/WebApi/ChatManagement.php`) therefore require an admin user token: any other user type (`USER_TYPE_INTEGRATION`, customer, guest) receives an authorization error before any conversation or tool work happens.

`getAllTools()` / `getToolByName()` remain unfiltered — they serve the Skills admin UI and JIT instruction lookup, not tool access.

---

## Building Custom Skills

Third-party modules (hosting providers, PSPs, marketplace integrations) can register their own tools.

### Step 1: Implement `ToolInterface`

```php
<?php

declare(strict_types=1);

namespace Vendor\HostingIntegration\Service\Tool;

use MagoAssistant\Mago\Api\Tool\ToolInterface;

class ServerStatus implements ToolInterface
{
    public function getName(): string
    {
        return 'server_status';
    }

    public function getDescription(): string
    {
        return 'Get current server performance metrics including CPU, memory, disk usage, and PHP worker status.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'metric' => [
                    'type' => 'string',
                    'enum' => ['overview', 'php_workers', 'disk', 'database'],
                    'description' => 'Specific metric category to retrieve'
                ]
            ],
            'required' => ['metric']
        ];
    }

    public function execute(array $params): array
    {
        // Your implementation — call hosting API, read server stats, etc.
        return [
            'cpu_usage' => 42.5,
            'memory_usage' => 68.2,
            'disk_usage' => 55.0,
            'php_workers_active' => 12,
            'php_workers_total' => 20
        ];
    }

    public function isReadOnly(): bool
    {
        return true; // No side effects
    }

    public function isReadOnlyAction(array $input): bool
    {
        return $this->isReadOnly();
    }

    public function getInstructions(): string
    {
        return ''; // Return detailed instructions here if needed (injected JIT)
    }

    public function getMagentoAcl(array $input = []): string
    {
        return ''; // Return e.g. 'Magento_Backend::cache' for native ACL checks;
                   // mixed tools can switch on $input['action'] per invocation
    }
}
```

### Step 2: Register via `di.xml`

```xml
<!-- Vendor/HostingIntegration/etc/di.xml -->
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">

    <type name="MagoAssistant\Mago\Service\Tool\ToolRegistry">
        <arguments>
            <argument name="tools" xsi:type="array">
                <item name="server_status" xsi:type="object">
                    Vendor\HostingIntegration\Service\Tool\ServerStatus
                </item>
            </argument>
        </arguments>
    </type>
</config>
```

That's it. The `ToolRegistry` picks up the new tool, includes it in AI provider calls, and handles execution within the existing conversation loop.

### Step 3: Add ACL resource (optional but recommended)

```xml
<!-- Vendor/HostingIntegration/etc/acl.xml -->
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Acl/etc/acl.xsd">
    <acl>
        <resources>
            <resource id="Magento_Backend::admin">
                <resource id="MagoAssistant_Mago::assistant">
                    <resource id="Vendor_HostingIntegration::server_status"
                              title="Hosting - Server Status" sortOrder="100"/>
                </resource>
            </resource>
        </resources>
    </acl>
</config>
```

### Step 4: Add config toggle (optional)

Add a `system.xml` field under the Admin Assistant tools section so store admins can enable/disable your tool independently.

### Guidelines for Tool Authors

- **Keep `getDescription()` short** (~100 tokens). The LLM sees every tool's description on every request. Be specific about what the tool does, but save the detail for `getInstructions()`.
- **Put domain knowledge in `getInstructions()`** — formatting rules, edge cases, enum explanations, example queries. These are only injected when your tool is called, so they don't pollute the base prompt.
- **`getParameterSchema()` must be valid JSON Schema** — the LLM uses this to construct the call. Include `description` on each property.
- **Return structured data** — arrays with clear keys. Avoid returning raw HTML or unstructured text.
- **Keep responses under 4,000 tokens** — output beyond the limit is truncated. If your tool can return large datasets, support pagination or filtering via parameters.
- **Include `_links` for navigable records** — if your tool returns orders, products, or other admin-viewable entities, add a `_links` array so the admin can click through to the relevant page.
- **Set `isReadOnly()` correctly** — if your tool has any side effects (writes, API calls that change state), return `false`. This triggers the user confirmation flow.
- **Never return secrets** — API keys, passwords, tokens should never appear in tool output. They would be sent to the LLM.
- **Handle errors gracefully** — throw exceptions with clear messages. The ChatService catches them and reports to the user.

---

## Examples

### Hypernode Hosting Skill

A hosting provider like Hypernode could register multiple tools as a single skill area:

```xml
<type name="MagoAssistant\Mago\Service\Tool\ToolRegistry">
    <arguments>
        <argument name="tools" xsi:type="array">
            <item name="hypernode_server_status" xsi:type="object">
                Hypernode\MagentoIntegration\Service\Tool\ServerStatus
            </item>
            <item name="hypernode_php_config" xsi:type="object">
                Hypernode\MagentoIntegration\Service\Tool\PhpConfig
            </item>
            <item name="hypernode_varnish_status" xsi:type="object">
                Hypernode\MagentoIntegration\Service\Tool\VarnishStatus
            </item>
            <item name="hypernode_cron_status" xsi:type="object">
                Hypernode\MagentoIntegration\Service\Tool\CronStatus
            </item>
        </argument>
    </arguments>
</type>
```

This enables conversations like:

> **Admin:** "My site feels slow today, what's going on?"
> **Assistant:** Let me check your server metrics.
> *[calls `hypernode_server_status`]*
> Your CPU usage is at 94% and 18 of 20 PHP workers are active. Let me check what's causing the load.
> *[calls `hypernode_cron_status`]*
> The `catalog_product_flat_reindex` cron job has been running for 47 minutes. This is consuming most of your PHP workers. You could wait for it to complete, or I can check if there's a stuck index process.

### Mollie Payments Skill

A PSP like Mollie could expose payment insights:

```xml
<type name="MagoAssistant\Mago\Service\Tool\ToolRegistry">
    <arguments>
        <argument name="tools" xsi:type="array">
            <item name="mollie_payment_methods" xsi:type="object">
                Mollie\Payment\Service\Tool\PaymentMethods
            </item>
            <item name="mollie_transaction_status" xsi:type="object">
                Mollie\Payment\Service\Tool\TransactionStatus
            </item>
            <item name="mollie_settlement_report" xsi:type="object">
                Mollie\Payment\Service\Tool\SettlementReport
            </item>
        </argument>
    </arguments>
</type>
```

Example conversation:

> **Admin:** "A customer says their iDEAL payment failed but we received an order. What happened?"
> **Assistant:** Let me look up the order and check the payment status.
> *[calls `sales_data` with `recent_orders`]*
> *[calls `mollie_transaction_status`]*
> Order #100004521 has status "processing" in Magento but the Mollie transaction `tr_abc123` shows status `expired`. This means the payment webhook hasn't been received yet. The payment was initiated but not completed. I'd recommend checking the webhook URL configuration and resyncing this order's payment status.

---

## MCP Compatibility

### Current State

The module implements its own tool protocol via `ToolInterface`. This is conceptually similar to the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) but is not MCP-compliant.

### Mapping to MCP

The architecture was designed to be adaptable to MCP:

| Mago Assistant | MCP Equivalent |
|----------------|----------------|
| `ToolInterface` | MCP Tool |
| `ToolRegistry` | MCP Server (tool provider) |
| `getParameterSchema()` | MCP `inputSchema` |
| `execute()` | MCP tool handler |
| `getDescription()` | MCP tool description |
| `isReadOnly()` | MCP `readOnlyHint` annotation |

### Future: MCP Server Mode

A future version could expose the `ToolRegistry` as an MCP server, allowing external AI clients (Claude Desktop, Cursor, other MCP-compatible tools) to use Magento skills directly:

```
┌─────────────────┐         MCP (stdio/SSE)        ┌────────────────────┐
│  External AI     │ ◀──────────────────────────── │  Magento MCP       │
│  (Claude Desktop)│                                │  Server            │
└─────────────────┘                                │  ┌──────────────┐  │
                                                    │  │ ToolRegistry │  │
                                                    │  └──────────────┘  │
                                                    └────────────────────┘
```

This would require:

1. An MCP server transport layer (SSE endpoint or stdio bridge)
2. Authentication via Magento admin tokens or integration tokens
3. ACL mapping from MCP client identity to Magento admin roles
4. A manifest endpoint exposing available tools in MCP format

The existing `ToolInterface` methods map 1:1 to MCP tool definitions, making this a transport-layer change rather than an architectural rewrite.

---

## Configuration

### Admin UI Location

`Stores > Configuration > MagoAssistant > Admin Assistant`

### Sections

#### General
| Path | Description | Default |
|------|-------------|---------|
| `mago/general/enabled` | Enable/disable the module | No |

#### AI Provider
| Path | Description | Default |
|------|-------------|---------|
| `mago/api/ai_service` | Row id of the `MageOS_AiBase` service to run on; empty means the first usable one | — |
| `mago/api/max_tokens` | Maximum response tokens | `4096` |
| `mago/api/streaming` | Enable SSE streaming | Yes |

#### Chat Behavior
| Path | Description | Default |
|------|-------------|---------|
| `mago/chat/system_prompt` | System instruction sent with every request | — |
| `mago/chat/max_tool_iterations` | Max tool execution loops per message | `10` |

#### Tools
| Path | Description | Default |
|------|-------------|---------|
| `mago/tools/max_response_tokens` | Estimated token cap per tool result before truncation | `4000` |

#### Internal API
| Path | Description | Default |
|------|-------------|---------|
| `mago/api/internal_url` | Internal URL for REST API calls (Docker/proxy setups) | — (uses store base URL) |
| `mago/api/internal_ssl_verify` | Verify the TLS certificate on internal REST calls (disable when the certificate cannot match the internal URL host) | `1` |

#### Debug & Logging
| Path | Description | Default |
|------|-------------|---------|
| `mago/debug/debug` | Debug log, and full request/response payloads in the usage log | No |
| `mago/debug/payload_retention_days` | Days before a daily cron removes stored payloads from the usage log (0 keeps forever); token statistics are never deleted | `30` |

#### Per-Tool Toggles (Not implemented)

> **Status: Not planned** — Per-tool config toggles are superseded by the DB-based `PermissionChecker` system which provides per-user granularity. No additional config UI needed.

~~Third-party tools can add their own toggles under the same section.~~

---

## Data & Privacy

### What Goes to the LLM

| Data type | Sent to LLM | Notes |
|-----------|-------------|-------|
| Admin user messages | Yes | The conversation itself |
| Tool definitions (names, descriptions, schemas) | Yes | So the LLM knows what tools are available |
| Tool execution results | Yes | The LLM needs results to formulate its response |
| Sales aggregates (revenue, counts, AOV) | Yes | Via `sales_data` tool |
| Product catalog data (SKU, name, price, status) | Yes | Via `product_data` tool |
| CMS content (page/block HTML) | Yes | Via `cms_data` tool |
| Config values (non-sensitive paths) | Yes | Via `config_reader` tool |

### What Never Goes to the LLM

| Data type | Protection mechanism |
|-----------|---------------------|
| API keys, secrets, passwords, tokens | Blocked path patterns in `ConfigReader` and `ConfigWriter` |
| Payment configuration (`payment/*`) | Hardcoded path block in config tools |
| Encrypted config values | Blocked by sensitive path detection |
| Customer PII (names, emails, addresses) | `CustomerData` only returns aggregates and IDs |
| Admin passwords | Never exposed via any tool |
| Database credentials | Blocked by sensitive path detection |

### Sensitive Path Blocking

The `ConfigReader` and `ConfigWriter` tools block any config path containing:

- `key`
- `secret`
- `password`
- `token`
- `credential`
- `private`
- `encrypt`
- Any path under `payment/*`

### Data Flow

```
Admin types message
       │
       ▼
┌──────────────┐
│ ChatService   │ ── adds system prompt + tool definitions
│               │ ── sends to AI provider API
└──────────────┘
       │
       ▼
┌──────────────┐
│ AI Provider   │ ── processes on provider's infrastructure
│ (Anthropic/   │ ── returns text + tool calls
│  OpenAI)      │
└──────────────┘
       │
       ▼
┌──────────────┐
│ Tool          │ ── executes against Magento database/APIs
│ Execution     │ ── result sent back to AI provider for next iteration
└──────────────┘
       │
       ▼
  Response displayed to admin
```

### Recommendations for Store Owners

- **Review the system prompt** — it's sent with every request. Don't include credentials or internal URLs.
- **Disable unused tools** — if you don't need CMS editing via the assistant, disable `cms_data`.
- **Use ACL roles** — give catalog managers `assistant_read` only. Reserve `assistant_write` for senior admins.
- **Audit conversations** — conversations are stored in `mago_conversation` and `mago_message` tables. Review periodically.
- **Usage log** — `mago_usage_log` always records token counts and skill names for accounting. The full request/response payloads are only stored while Debug Mode is on, and a daily cron removes stored payloads older than `mago/debug/payload_retention_days` (default 30 days).
- **Be aware of AI provider data policies** — messages and tool results are processed by the selected AI provider (Anthropic or OpenAI). Review their data retention and usage policies.
