---
name: mago-skill
description: Scaffold, register and verify a new Mago Assistant skill (a tool the admin chat can call) as its own Magento module under package-source/. Use when asked to create, add or build a Mago skill, tool or capability, e.g. "maak een Mago-skill die ...", "add a tool to the assistant", "new skill like vies".
---

# Create a Mago Assistant skill

A Mago skill is a separate Magento module with one class implementing
`MagoAssistant\Mago\Api\Tool\ToolInterface` and one `di.xml` entry that appends it to
`MagoAssistant\Mago\Service\Tool\ToolRegistry`. Nothing inside `vendor/mago-assistant/mago` is
edited. Reference implementation: https://github.com/mago-assistant/vies

This skill is meant for Claude Code in a Magento project that has `mago-assistant/mago` installed.
Copy this directory to `.claude/skills/mago-skill/` in the project root and run the commands below
from that root.

## 1. Pin down the skill before writing code

Settle these with the user if the request does not make them clear:

| Item | Example | Notes |
| --- | --- | --- |
| Tool name | `vies_vat_check` | snake_case, prefixed with the module, unique in the registry |
| Module | `MagoAssistant_Vies` | Vendor default `MagoAssistant` |
| Composer name | `mago-assistant/magento2-vies` | Vendor default `mago-assistant` |
| Read or write | read | Write means the admin confirms every call |
| Irreversible | no | Deletes, sends, refunds: implement `IrreversibleToolInterface` |
| Magento ACL | `Magento_Sales::actions_view` | Resource an admin needs for the same action in the backend |
| Output fields | `valid`, `name`, ... | Each needs a privacy class, see step 4 |

## 2. Scaffold the module

Location: `package-source/<composer-vendor>/magento2-<slug>/`. If the project's root `composer.json`
has no path repository for that directory yet, add one:

```bash
composer config repositories.local-packages '{"type": "path", "url": "package-source/*/*"}'
```

```
package-source/mago-assistant/magento2-<slug>/
  composer.json
  registration.php
  etc/module.xml
  etc/di.xml
  Service/Tool/<ClassName>.php
  README.md
```

Copy the files from `templates/` in this skill directory and replace the placeholders:

| Placeholder | Example |
| --- | --- |
| `{{Vendor}}` / `{{vendor}}` | `MagoAssistant` / `mago-assistant` |
| `{{Module}}` / `{{slug}}` | `Vies` / `vies` |
| `{{ClassName}}` | `VatCheck` |
| `{{tool_name}}` | `vies_vat_check` |
| `{{description}}` | One line for `composer.json` |

Add every module the tool reads from (e.g. `Magento_Sales`) to `<sequence>` in `module.xml` and to
`require` in `composer.json`.

## 3. Write the tool class

The eight methods, and what goes wrong with each:

- `getName()`: must equal the `di.xml` item name.
- `getDescription()`: the model picks tools by this text. Say what the tool does and what it does
  not. Never promise more than `execute()` returns.
- `getParameterSchema()`: JSON Schema, `type: object`. The model fills in every parameter it is
  shown, so only offer parameters that are always safe to receive. Removing a parameter works
  better than a description asking the model not to use it.
- `getMagentoAcl(array $input = [])`: an empty `$input` must return the most restrictive resource
  the tool can reach (fail closed). Return `''` only when the Mago skill permission is enough.
- `isReadOnly()` / `isReadOnlyAction(array $input)`: `false` triggers a confirmation in the panel.
  A mixed tool returns per action.
- `getFieldClassification(string $action = '')`: a whitelist. See step 4.
- `getInstructions()`: reaches the model only after the first call. Use it for how to present the
  result, never for how to call the tool.
- `execute(array $params)`: return an array. Catch external failures and return
  `['error' => '...']` instead of throwing, so the model can report it.

Optional interfaces in `MagoAssistant\Mago\Api\Tool`:

- `IrreversibleToolInterface`: `isIrreversibleAction()` + `getImpacts()`, shows an impact list and
  an acknowledgement checkbox instead of a plain Allow button.
- `ValidatingToolInterface`: `findRefusal()` rejects a proposed write before the confirmation.
- `ActionScopedToolInterface`: for tools with an `action` parameter whose read and write actions
  must be exposed separately per permission.

Use Magento repositories and `SearchCriteriaBuilder`, never raw SQL, and inject
`Magento\Framework\HTTP\Client\Curl` for external HTTP with an explicit timeout.

## 4. Classify every returned field

`getFieldClassification()` maps each key of the `execute()` result to a `PiiClass` rule from
`MagoAssistant\Mago\Service\Privacy\PiiClass`:

- `[PiiClass::PUBLIC]`: sent as is. Only for data that is not personal.
- `[PiiClass::TOKENISE, '<type>']`: model sees `mago://<type>_1`, the admin sees the real value.
  Types in use: `name`, `email`, `phone`, `address`, `customer`, `order`, `invoice`, `shipment`,
  `creditmemo`, `url`, `entity`, `review`, `reviewtitle`, `reviewtext`, `nickname`.
- `[PiiClass::STRIP]`: never sent.
- Key `PiiClass::ANY` (`'*'`): rule for all keys not named, only for dynamic keys.

How `PrivacyFilter` applies the map:

- Keys are matched by name at every depth, not by path: `name` covers `name` in every nested row.
- Nested arrays are walked, so a wrapper key (`rows`, `last_24h`) needs no rule of its own. A list
  of scalars inherits the rule of the key it sits under.
- A scalar with no rule is stripped silently. When the assistant answers as if data is missing,
  check this map first.
- `error` always crosses, so a failure can be explained. `admin_url` is always tokenised.
- An explicit `STRIP` on a key drops the whole subtree under it.

## 5. Install and verify

```bash
composer require {{vendor}}/magento2-{{slug}}:@dev
bin/magento module:enable {{Vendor}}_{{Module}}
bin/magento setup:upgrade
bin/magento cache:flush
```

Then check, in this order, and report each result:

1. `php -l` on every PHP file in the module.
2. `php .claude/skills/mago-skill/scripts/verify-tool.php <tool_name> '<json params>'` shows
   the tool is registered, prints its definition, runs `execute()` and lists returned fields that
   `getFieldClassification()` does not declare. Any listed field is a bug.
3. In the admin chat panel, ask a question the skill should answer and confirm the model calls it.

Never test a "module X disabled" case by running `module:disable` plus `setup:upgrade` on a shared
database: declarative schema drops the tables of disabled modules, and re-enabling recreates them
empty while their data patches stay marked as applied. Use a throwaway database or a unit test.

Access: an admin uses the skill when they hold `MagoAssistant_Mago::assistant_read` (or
`assistant_write` for writes), unless a row in `mago_skill_permission` for that admin and tool
name says otherwise.

## 6. README

Write `README.md` for the module: what it does, two example questions for the chat panel, install
commands, and the privacy classes of the returned fields.
