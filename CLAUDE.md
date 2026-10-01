# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

PHP CLI tool that syncs hosts from an external **Inventory** system ([arms_inventory](https://github.com/spo0okie/arms_inventory)) into **Zabbix 7.0–7.2** via the Zabbix JSON-RPC API. There is no web UI, no package manager — the entry point is a single `php sync.php` invocation. Runs on PHP 7.4+ (the bundled `test.cmd` invokes `c:\wamp\bin\php\php7.4.33\php.exe`).

## Running the sync

```bash
php ./sync.php              # dry run — prints planned changes only
php ./sync.php real         # actually pushes changes to Zabbix
php ./sync.php verbose      # adds per-host explanation of why nothing was done
php ./sync.php real verbose # combine
```

`sync.php` also detects equipment "stacks" (multiple **in-service** `techs` items sharing model + IP) and only syncs the lowest-numbered one as master, marking the rest as disabled (`status=1`) — see `syncPlanner` below.

`explain.php <class> <id>` (CLI) / `explain.php?class=&id=&token=` (HTTP) — "explain mode": answers "will this inventory host be monitored and how" for a **single** host as JSON (verdict/errors/sets trace/actions). No Zabbix connection, no bulk caches — pipeline is initialized with `init(null, ...)` so template/group names stay names. Consumed by the ARMS integration provider. HTTP access requires `$explainToken` in config.priv.php. The `PSK` action (secrets) is stripped from the report.

Rule sets and rules may optionally carry names: set name = string top-level key in rules.priv.php, set/rule description = reserved `'desc'` key (string keys inside a set are metadata, not rules). Old unnamed rulesets work unchanged (`set#N` / `rule#M` in output).

Auxiliary scripts (not part of the daily sync loop):
- `template2service.php <templateName> <serviceID>` — copies service-support tags onto a Zabbix template and strips them from its triggers
- `triggers2services.php <templateName> <triggerName> <serviceID>` — same idea for a single trigger
- `add_user.php <login> [surname]` — idempotently grants a user restricted monitoring access: host group `<login> nodes`, user group `<login> group` (read on it + `Zabbix servers`, problem tag filter `serviceman=<surname>` on the latter), user with role `User` in that group. Doesn't edit `rules.priv.php` — only checks for the `teamLogins` → `<login> nodes` rule and prints the snippet if missing. Pure logic in `lib_userAccess.php`.
- `user_alerts.php` — provisions/updates a fixed set of Zabbix actions (email + SMS escalation) for one hardcoded `$login`. Edit the file to change the user.

## Tests

Dependency-free unit tests live in `tests/`. Run them with `php tests/run.php` (or `tests.cmd`, which pins PHP 7.4). The runner (`tests/run.php`) discovers `tests/*Test.php` files, instantiates every class whose name ends in `Test` (extending `miniTestCase`), and runs its `test*` methods; exit code is non-zero on failure. Note `test.cmd` (singular) is unrelated — it runs `sync.php`.

## Configuration

Two private files (gitignored via `*.priv.*`) must exist before any script will run:

- **`config.priv.php`** — credentials and endpoints (see `config.sample.php`). Defines `$webInventory`, `$inventoryAuth`, `$zabbixApiUrl`, `$zabbixAuth`.
- **`rules.priv.php`** — the entire sync policy as a nested PHP array (see `rules.sample.php` and the README for the DSL). This file is the primary thing that gets edited day-to-day; PHP code is rarely touched.

The rules DSL — condition types, action types, and `${inventory:*}` / `${vmware:*}` macros — is fully documented in `README.md`. Don't re-derive it; read the README when editing rules.

## Architecture

Four layers, wired together in `sync.php`:

1. **`inventoryApi` (`lib_inventoryApi.php`)** — thin wrapper over the Inventory REST API. On init it eagerly bulk-fetches all `comps` (operating systems) and `techs` (hardware) into `$cache`. Everything downstream reads from this cache; there are no per-host inventory lookups during the sync loop.

2. **`zabbixApi` (`lib_zabbixApi.php`)** — Zabbix JSON-RPC client. On init it bulk-caches `hosts`, `templates`, and `groups`. Two key methods drive the sync:
   - `applyPipelineActions($zHost, $actions, $new=false)` — given a Zabbix host (or `[]` for create) and the pipeline's accumulated actions, builds a `stdClass` diff containing only the fields that need to change. Returns an empty object if nothing changed. Note `host` (technical name) and `address` (interface address) are separate actions; `address` falls back to `host` when absent, and an `ip`/`dns` set inside an `interfaces` template beats both.
   - `setHost($diff)` — dispatches `host.create` or `host.update` based on whether `$diff->hostid` is set, then separately POSTs `usermacro.create` / `usermacro.update` for the macro deltas that were stashed on the diff.

3. **`rulesPipeline` (`lib_rulesPipeline.php`)** — the policy engine. `init()` resolves all template/group names referenced in the rules into Zabbix IDs upfront (it `die()`s with `HALT:` if a name doesn't exist). The core method is `pipeHost($iHost)`:
   - Iterates **rule sets** (top-level array entries in `rules.priv.php`).
   - In each set, finds the **first rule whose conditions all match**, takes its actions, and moves on. Sets are independent — every set contributes.
   - Actions accumulate across sets via `array_merge_recursive`, then `prepareTemplates` / `prepareGroups` / `prepareTags` resolve names → IDs and compute set-difference for `ofTemplates`/`ofGroups` (the "remove if in this set but not explicitly added" semantics).
   - `replaceInventoryMacros` walks the accumulated actions and substitutes `${inventory:fqdn}`, `${vmware:uuid}`, etc. before returning.

4. **`syncPlanner` (`lib_syncPlanner.php`)** — decides which inventory record owns which Zabbix host.
   - `buildProcessedItems($entries, $log)` — collapses equipment stacks (`techs` sharing `model|ip`). Disabled records are excluded from grouping: a warehouse unit drags stale model+IP along, so it must not become master and disable the device that replaced it.
   - `claimHost($hostid)` — the collision guard for phase 2: one Zabbix host serves exactly one inventory record, first claim wins. A host is never handed over between records — its history belongs to one physical device, and a replacement device (possibly on different templates) must get its own host.

The main loop in `sync.php` then:
- Calls `findZabbixHostid($iHost)` which dispatches to `findCompsZabbixHostid` or `findTechsZabbixHostid` (lookup priority: `{$INVENTORY_*}` macros → FQDN → IP). The comps path has explicit guarding against sandbox-clone FQDN collisions.
- Decides create vs. update vs. skip based on whether `actions` contains `create`/`update`.
- Prints the diff via `printDiff` (a switch over known field types: tags, interfaces, templates, macros…) and either skips (dry run) or calls `setHost`.

### Extending conditions and macros

The pipeline uses naming-convention dispatch — adding a new condition or macro is a single-file change in `lib_rulesPipeline.php`:

- **New condition `foo`**: add `public static function conditionFoo($params, $iHost): bool`. The dispatcher in `checkSingleCondition` does `ucfirst(strtolower($type))` → `'condition'.$type`. If the method doesn't exist the script dies with `HALT: no checker [...]`.
- **New macro `${inventory:foo}`**: add a `macroInventoryFoo` static method, then register it in the `$inventoryMacros` map at the top of the class.
- **New macro modifier `${...|foo}`**: add `public static function macroModFoo($value)` — the dispatcher in `replaceInventoryMacros` does `'macroMod'.ucfirst(strtolower($mod))`. Existing: `|translit` (Cyrillic→Latin), `|normalize` (strip chars invalid for a Zabbix technical name). Modifiers apply left-to-right; there is deliberately NO implicit sanitization — an invalid `host` value is caught by `pipeHost` and reported as a per-host error instead.
- **New action**: actions are *not* convention-dispatched — they're a fixed switch in `zabbixApi::applyPipelineActions`. Adding one means editing that method and (if the diff should pretty-print) `rulesPipeline::printDiff`.

### Things that will trip you up

- The Zabbix client uses **Bearer token auth** (`Authorization: Bearer ...`). The `$zabbixAuth` value is the API token, not a username/password. Inventory uses Basic auth with a base64'd `user:pass` string.
- `req()` returns `null` and prints the raw request/response to stdout on any non-array `result`. There's no exception path — callers check for null/empty.
- `die("HALT: ...")` is used liberally for any unrecoverable config error (missing template, unknown condition type, malformed rule). When something exits silently with `HALT:`, the rule set references a name that isn't in Zabbix.
- `array_merge_recursive` is used to combine actions across rule sets. This means scalar action values like `status` end up as arrays — `applyPipelineActions` defensively does `reset($actions['status'])` to extract the first one. Don't assume scalars stay scalar after pipeline processing.
- Addresses move between devices, so both `findCompsZabbixHostid` and `findTechsZabbixHostid` refuse a host found by FQDN/IP when it is bound by macros to a *different* inventory record that still exists (`techsHostIsTaken`). A replacement switch inheriting its predecessor's IP therefore gets its own Zabbix host instead of hijacking the old one's history. Sandbox-clone hosts share an FQDN with their original and are covered by the same rule. If you're debugging "why isn't this host being found", check whether something with the same FQDN already owns it via macros.
- `zabbix.hosts.txt` (6.6 MB, gitignored under `*.txt`) is a captured-state dump, not a live input — scripts do not read it.
