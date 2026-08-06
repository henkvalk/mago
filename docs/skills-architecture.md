# Skills Architecture

> **Status:** Draft — `MaggyAssistant_Base` v1.0.0
> **Last updated:** 2026-07-26

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
MaggyAssistant\Base\Api\Tool\ToolInterface
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
    public function getRequiredAcl(): string;
    public function getInstructions(): string;
    public function getMagentoAcl(): string;
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
| `getRequiredAcl()` | MaggyAssistant ACL resource string (e.g. `assistant_read` or `assistant_write`) |
| `getInstructions()` | Detailed usage instructions injected only when the tool is invoked (JIT). Keeps the base prompt lean. |
| `getMagentoAcl()` | Native Magento ACL resource (e.g. `Magento_Backend::cache`). Checked in addition to `getRequiredAcl()`. Return empty string if not needed. |

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
MaggyAssistant\Base\Service\Tool\ToolRegistry
```

Central registry that collects all tools via DI injection:

```php
class ToolRegistry
{
    public function __construct(array $tools = []);       // ToolInterface[] injected via di.xml
    public function getEnabledTools(): array;             // Filters by config toggle
    public function getToolDefinitions(): array;          // Returns specs for AI provider
    public function getTool(string $name): ToolInterface; // Retrieve by name
}
```

#### `ChatService`

```
MaggyAssistant\Base\Service\Ai\ChatService
```

Orchestrates the conversation loop:

1. Send messages + tool definitions to the AI provider
2. If the AI requests a **read-only** tool → execute automatically, append result, loop
3. If the AI requests a **write** tool → pause, return `pending_confirmation: true`
4. On user confirmation → execute the write tool via `executeConfirmedTools()`
5. Loop continues until the AI produces a final text response or `max_tool_iterations` is reached

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

### Response Limits (Planned)

> **Status: Planned** — Truncation logic is not yet implemented in ChatService. Tool output is currently returned in full.

Tool output should be truncated by the `ChatService` to prevent context window exhaustion. A single CMS page or large product set can easily produce thousands of tokens — without limits, one tool call can crowd out the rest of the conversation.

| Limit | Default | Configurable |
|-------|---------|--------------|
| Max response size per tool | 4,000 tokens | `maggy/tools/max_response_tokens` |
| Execution timeout | 5 seconds (read), 10 seconds (write) | `maggy/tools/execution_timeout` |

When implemented, the `ChatService` will append a `_truncated: true` flag so the LLM knows the data is incomplete and can ask the user to narrow the query.

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

### Provider Layer

The AI provider is abstracted behind `ProviderInterface`:

```
MaggyAssistant\Base\Api\Ai\ProviderInterface
```

```php
interface ProviderInterface
{
    public function chat(array $messages, array $tools = [], array $options = []): array;
    public function stream(array $messages, array $tools = [], array $options = [], callable $onChunk = null): array;
    public function getProviderName(): string;
}
```

Built-in providers: **Claude** (`Model/Ai/Provider/Claude.php`) and **OpenAI** (`Model/Ai/Provider/OpenAi.php`). The `ProviderFactory` selects the active provider based on configuration.

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
| `config_reader` | `Service\Skills\Configuration\ConfigReader` | Yes | Reads Magento system configuration by path and scope. Blocks sensitive paths (keys, secrets, passwords, tokens, payment config). |
| `config_writer` | `Service\Skills\Configuration\ConfigWriter` | No | Writes Magento system configuration. Same blocked-path protections. Requires user confirmation. |
| `cache_manager` | `Service\Skills\Configuration\CacheManager` | No | Flush all caches, flush specific cache types, or view cache status. Requires confirmation for flush actions. |
| `indexer_manager` | `Service\Skills\Configuration\IndexerManager` | No | Reindex specific indexers or all, check indexer status, change indexer mode (realtime/schedule). Requires confirmation. |

### Content Management

| Tool | Class | Read-only | Description |
|------|-------|-----------|-------------|
| `cms_data` | `Service\Skills\Content\CmsData` | Mixed | List, read, and update CMS pages and blocks. Read actions (list/get) execute automatically; write actions (update) require confirmation. Uses `isReadOnlyAction()` for per-action granularity. |
| `content_generator` | `Service\Skills\Content\ContentGenerator` | No | AI-powered product description, meta, and short description generation. Two-phase: first call returns product context, second call saves confirmed content. |

### Navigation

| Tool | Class | Read-only | Description |
|------|-------|-----------|-------------|
| `admin_navigator` | `Service\Skills\Navigation\AdminNavigator` | Yes | Searches admin pages by keyword and returns direct URLs. Used for navigating to specific admin sections. |

---

## Slash Commands

Slash commands give admins a discoverable way to see what the assistant can do. Typing `/` in the chat input shows an autocomplete list of available commands. Each command expands to a pre-built prompt that invokes the right tools with sensible defaults.

This serves two purposes:
1. **Discoverability** — new users immediately see what's possible without guessing
2. **Consistency** — common tasks always use the same prompt structure, producing reliable results

### Built-in Commands

| Command | Expands to | Tools used |
|---------|-----------|------------|
| `/revenue` | "Show me a revenue summary for the last 30 days including top products and order count." | `sales_data` |
| `/revenue today` | "Show me today's revenue summary including top products and order count." | `sales_data` |
| `/low-stock` | "Show me products with stock below 5 units." | `product_data` |
| `/low-stock 20` | "Show me products with stock below 20 units." | `product_data` |
| `/orders` | "Show me the 10 most recent orders with their status." | `sales_data` |
| `/customers` | "Show me customer statistics: total count, new signups this month, and top spenders." | `customer_data` |
| `/config <path>` | "Read the Magento config value at `<path>` for the default scope." | `config_reader` |
| `/cms-pages` | "List all CMS pages with their status and URL key." | `cms_data` |
| `/describe <sku>` | "Generate a product description for SKU `<sku>`." | `content_generator`, `product_data` |
| `/health` | "Give me a store health check: orders today, low stock count, and any disabled products." | `sales_data`, `product_data` |

### Command with Arguments

Commands accept optional arguments after the command name. The argument replaces a placeholder in the expanded prompt:

```
/revenue this_year       → revenue summary for this year
/low-stock 50            → products with stock below 50
/config general/locale   → read the locale config path
/describe SKU-12345      → generate description for specific SKU
```

If no argument is provided, the command uses its default value.

### Registering Custom Commands (Planned)

> **Status: Planned** — `CommandRegistry` via DI is not yet implemented. Slash commands are currently hardcoded from `ToolRegistry::getAllTools()` in the frontend.

Third-party modules will register commands via `di.xml`, similar to tools:

```xml
<type name="MaggyAssistant\Base\Service\Chat\CommandRegistry">
    <arguments>
        <argument name="commands" xsi:type="array">
            <item name="server" xsi:type="array">
                <item name="label" xsi:type="string">Server status</item>
                <item name="description" xsi:type="string">Check server performance metrics</item>
                <item name="prompt" xsi:type="string">Show me current server performance: CPU, memory, disk usage, and PHP worker status.</item>
                <item name="acl" xsi:type="string">Vendor_HostingIntegration::server_status</item>
            </item>
            <item name="payments" xsi:type="array">
                <item name="label" xsi:type="string">Payment overview</item>
                <item name="description" xsi:type="string">Today's payment method breakdown</item>
                <item name="prompt" xsi:type="string">Show me today's orders grouped by payment method with success/failure rates.</item>
                <item name="acl" xsi:type="string">Vendor_Payments::overview</item>
            </item>
        </argument>
    </arguments>
</type>
```

Commands inherit ACL from their config — if the admin doesn't have the required role, the command doesn't appear in autocomplete. The `prompt` field is what gets sent to the LLM; the admin can edit it before sending.

### UX Behavior

- **Autocomplete:** Typing `/` opens a dropdown with all available commands, filtered by what the admin types next
- **Preview:** Each command shows its `label` and `description` in the dropdown
- **Editable:** The expanded prompt appears in the input field — the admin can modify it before sending
- **ACL-filtered:** Commands are only shown if the admin has the required permissions

---

## ACL & Permissions

### Resource Tree

```
Magento_Backend::admin
├── Magento_Backend::stores
│   └── Magento_Backend::stores_settings
│       └── Magento_Config::config
│           └── MaggyAssistant_Base::config          # Module configuration access
│
└── MaggyAssistant_Base::assistant                    # Parent resource
    ├── MaggyAssistant_Base::assistant_read           # Read operations
    └── MaggyAssistant_Base::assistant_write          # Write operations
```

### How It Works

1. **Every tool declares its required ACL** via `getRequiredAcl()`.
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

---

## Building Custom Skills

Third-party modules (hosting providers, PSPs, marketplace integrations) can register their own tools.

### Step 1: Implement `ToolInterface`

```php
<?php

declare(strict_types=1);

namespace Vendor\HostingIntegration\Service\Tool;

use MaggyAssistant\Base\Api\Tool\ToolInterface;

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

    public function getRequiredAcl(): string
    {
        return 'Vendor_HostingIntegration::server_status';
    }

    public function getInstructions(): string
    {
        return ''; // Return detailed instructions here if needed (injected JIT)
    }

    public function getMagentoAcl(): string
    {
        return ''; // Return e.g. 'Magento_Backend::cache' for native ACL checks
    }
}
```

### Step 2: Register via `di.xml`

```xml
<!-- Vendor/HostingIntegration/etc/di.xml -->
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">

    <type name="MaggyAssistant\Base\Service\Tool\ToolRegistry">
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
                <resource id="MaggyAssistant_Base::assistant">
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
<type name="MaggyAssistant\Base\Service\Tool\ToolRegistry">
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
<type name="MaggyAssistant\Base\Service\Tool\ToolRegistry">
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

| Maggy Assistant | MCP Equivalent |
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

`Stores > Configuration > MaggyAssistant > Admin Assistant`

### Sections

#### General
| Path | Description | Default |
|------|-------------|---------|
| `maggy/general/enabled` | Enable/disable the module | No |

#### AI Provider
| Path | Description | Default |
|------|-------------|---------|
| `maggy/api/provider` | AI provider (`claude` or `openai`) | `claude` |
| `maggy/api/claude_api_key` | Claude API key (encrypted) | — |
| `maggy/api/claude_model` | Claude model | `claude-opus-5` |
| `maggy/api/openai_api_key` | OpenAI API key (encrypted) | — |
| `maggy/api/openai_model` | OpenAI model | — |
| `maggy/api/max_tokens` | Maximum response tokens | `4096` |
| `maggy/api/temperature` | Response randomness | `0.7` |
| `maggy/api/streaming` | Enable SSE streaming | Yes |

#### Chat Behavior
| Path | Description | Default |
|------|-------------|---------|
| `maggy/chat/system_prompt` | System instruction sent with every request | — |
| `maggy/chat/max_tool_iterations` | Max tool execution loops per message | `10` |

#### Internal API
| Path | Description | Default |
|------|-------------|---------|
| `maggy/api/internal_url` | Internal URL for REST API calls (Docker/proxy setups) | — (uses store base URL) |

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
- **Audit conversations** — conversations are stored in `maggy_conversation` and `maggy_message` tables. Review periodically.
- **Be aware of AI provider data policies** — messages and tool results are processed by the selected AI provider (Anthropic or OpenAI). Review their data retention and usage policies.
