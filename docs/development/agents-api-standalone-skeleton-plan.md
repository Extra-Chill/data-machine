# Agents API Standalone Skeleton Plan

Parent issue: [Agents API extraction: create initial standalone plugin skeleton plan](https://github.com/Extra-Chill/data-machine/issues/1618)

Refs: [standalone extraction umbrella](https://github.com/Extra-Chill/data-machine/issues/1596), [pre-extraction audit](agents-api-pre-extraction-audit.md), [extraction map](agents-api-extraction-map.md)

This plan turned the bounded in-repo `agents-api/` module into the first reviewable standalone-plugin extraction step. The standalone repository now provides the public WordPress-shaped surface and dependency direction before behavior-heavy runtime services move.

Status update for [#1596](https://github.com/Extra-Chill/data-machine/issues/1596): Data Machine consumes `automattic/agents-api` as a Composer/plugin dependency and no longer carries an authoritative in-repo copy of the module.

## Goal

Create a minimal `agents-api` plugin/repo that can boot on a clean WordPress install, expose the same backend PHP contracts that were bounded during the in-repo module phase, and let Data Machine consume those contracts as a product dependency.

The skeleton should make [#1596](https://github.com/Extra-Chill/data-machine/issues/1596) actionable only after the remaining gates in the pre-extraction audit are complete.

## Directory Shape

The standalone repository should start with the smallest plugin shape that can load independently:

```text
agents-api/
  agents-api.php
  composer.json
  README.md
  docs/
    extraction-checklist.md
  inc/
    class-wp-agent.php
    class-wp-agents-registry.php
    register-agents.php
    class-wp-agent-package.php
    class-wp-agent-package-artifact.php
    class-wp-agent-package-artifact-type.php
    class-wp-agent-package-artifacts-registry.php
    class-wp-agent-package-adoption-diff.php
    class-wp-agent-package-adoption-result.php
    class-wp-agent-package-adopter.php
    register-agent-package-artifacts.php
    AI/
      WP_Agent_Message.php
      WP_Agent_Conversation_Result.php
      Tools/
        WP_Agent_Tool_Declaration.php
    Core/
      Database/
        Chat/
          WP_Agent_Conversation_Store.php
      FilesRepository/
        WP_Agent_Memory_Scope.php
        WP_Agent_Memory_List_Entry.php
        WP_Agent_Memory_Read_Result.php
        WP_Agent_Memory_Write_Result.php
        WP_Agent_Memory_Store.php
  tests/
    bootstrap-smoke.php
    no-product-imports-smoke.php
```

Do not start with admin assets, React code, REST controllers, Data Machine adapters, or persistence tables. Add those only when a later issue proves they are generic substrate instead of product behavior. Pending-action / diff-approval primitives are not part of this first skeleton, but their generic contract vocabulary belongs upstream when the next extraction stage defines it.

## Plugin Bootstrap

The standalone `agents-api.php` should mirror the current in-repo bootstrap, then add normal plugin ceremony:

- WordPress plugin header: `Plugin Name: Agents API`.
- Slug: `agents-api`.
- Constants: `AGENTS_API_VERSION`, `AGENTS_API_LOADED`, `AGENTS_API_PATH`, and optionally `AGENTS_API_PLUGIN_FILE`.
- Bootstrap order: define constants, require value objects/contracts, require registration helpers, then fire registration lifecycle hooks.
- Load before Data Machine product runtime when both plugins are active.
- No activation side effects in the first skeleton beyond future-safe version bookkeeping if required.
- No database schema, cron jobs, admin menus, REST routes, or default agents in the first skeleton.

The plugin should remain usable as a backend dependency. A consuming plugin should be able to `wp_register_agent()` on `wp_agents_api_init` without installing Data Machine.

## Public Names

The first skeleton freezes only the names already used by the in-repo module:

| Surface | v1 skeleton decision |
|---|---|
| Registration hook | `wp_agents_api_init` |
| Agent registration | `wp_register_agent()` |
| Agent reads | `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()` |
| Agent unregister | `wp_unregister_agent()` |
| Agent value object | `WP_Agent` |
| Agent registry | `WP_Agents_Registry` |
| Package artifacts | `WP_Agent_Package*` classes and `wp_register_agent_package_artifact_type()` helpers |
| Message/result contracts | `AgentsAPI\AI\WP_Agent_Message`, `AgentsAPI\AI\WP_Agent_Conversation_Result` |
| Tool declaration | `AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration` |
| Transcript contract | `AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store` |
| Memory contracts | `AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store`, `WP_Agent_Memory_Scope`, `WP_Agent_Memory_List_Entry`, `WP_Agent_Memory_Read_Result`, and `WP_Agent_Memory_Write_Result` |

Do not add aliases back to old `DataMachine\...` class names. Data Machine is pre-1.0 and should hard-cut to the standalone package when the extraction PR lands.

## Dependency Policy

The standalone skeleton depends on WordPress and targets `wp-ai-client` as the provider/runtime direction. It must not depend on Data Machine.

Rules:

- Data Machine may depend on `agents-api`; `agents-api` must not depend on Data Machine.
- Provider runtime work should target `wp-ai-client` directly.
- Do not reintroduce `chubes4/ai-http-client`, `chubes_ai_request`, `chubes_ai_providers`, `chubes_ai_models`, or `chubes_ai_provider_api_keys` into the skeleton.
- If `wp-ai-client` is unavailable, the future runtime layer should return a structured unavailable-provider error. The skeleton itself should not provide a fallback runtime.
- Composer autoloading is acceptable, but v1 should remain readable and bootable without a framework-specific loader.

## Explicit Non-Goals For V1

- No `wp-agents/v1` REST routes.
- No admin UI, React app, settings screen, list table, or agent CRUD screen.
- No Data Machine flow, pipeline, job, queue, handler, retention, concrete pending-action implementation, content-operation, or chat/session-switcher behavior.
- No default persistence tables unless a separate issue decides that Agents API owns persistence rather than contracts.
- No built-in Data Machine compatibility loop.
- No agent category registry. Descriptive metadata can be considered later, but it must not grant permission, visibility, tool access, or memory access.
- No generic approval classes yet. A later Agents API stage should define pending-action/action values, diff/change envelopes, resolver result shapes, and approval policy vocabulary without importing Data Machine route, table, or handler names.
- No Intelligence wiki, briefing, digest, or domain-brain vocabulary.

## First Files To Move

Move contracts/value objects before services. The first extraction PR should be mostly a copy of the bounded in-repo module plus bootstrap ceremony.

| Move first | Current in-repo location | Why first |
|---|---|---|
| `WP_Agent`, `WP_Agents_Registry`, and registration helpers | `agents-api/src/Registry/class-wp-agent.php`, `class-wp-agents-registry.php`, `register-agents.php` | Public registration facade; no Data Machine product import. |
| Agent package artifact contracts/helpers | `agents-api/src/Packages/class-wp-agent-package*.php`, `register-agent-package-artifacts.php` | Bundle/package contract is already backend-only. |
| `WP_Agent_Message` and `WP_Agent_Conversation_Result` | `agents-api/src/Runtime/` | Generic run/message value contracts. |
| `WP_Agent_Tool_Declaration` | `agents-api/src/Tools/` | Generic run-scoped tool declaration validation. |
| `WP_Agent_Conversation_Store` | `agents-api/src/Transcripts/` | Narrow transcript CRUD contract; does not require Data Machine chat UI. |
| Memory store value objects/interfaces | `agents-api/src/Memory/` | Generic memory seam; Data Machine default store remains an adapter. |

Current in-repo source checklist for the first move:

- `agents-api.php`
- `src/Registry/class-wp-agent.php`
- `src/Registry/class-wp-agents-registry.php`
- `src/Registry/register-agents.php`
- `src/Packages/class-wp-agent-package.php`
- `src/Packages/class-wp-agent-package-artifact.php`
- `src/Packages/class-wp-agent-package-artifact-type.php`
- `src/Packages/class-wp-agent-package-artifacts-registry.php`
- `src/Packages/class-wp-agent-package-adoption-diff.php`
- `src/Packages/class-wp-agent-package-adoption-result.php`
- `src/Packages/class-wp-agent-package-adopter.php`
- `src/Packages/register-agent-package-artifacts.php`
- `src/Runtime/class-wp-agent-message.php`
- `src/Runtime/class-wp-agent-conversation-result.php`
- `src/Tools/class-wp-agent-tool-declaration.php`
- `src/Transcripts/class-wp-agent-conversation-store.php`
- `src/Memory/class-wp-agent-memory-scope.php`
- `src/Memory/class-wp-agent-memory-list-entry.php`
- `src/Memory/class-wp-agent-memory-read-result.php`
- `src/Memory/class-wp-agent-memory-write-result.php`
- `src/Memory/class-wp-agent-memory-store.php`

## Keep In Data Machine For Now

Data Machine should remain the product adapter after the first skeleton exists:

- `AIStep`, `FlowStepConfig`, queue modes, config patch queues, and pipeline tool-policy translation.
- `AIConversationLoop`, `BuiltInAgentConversationRunner`, `RequestBuilder`, `WpAiClientAdapter`, `PromptBuilder`, and Data Machine directive/logging policy until their Data Machine assumptions are removed.
- Data Machine transcript persister and handler completion policy implementations.
- `ToolPolicyResolver`, `DataMachineToolRegistrySource`, `AdjacentHandlerToolSource`, legacy `datamachine_tools`, and mandatory adjacent handler preservation.
- Jobs, flows, pipelines, handlers, retention tasks, concrete pending-action storage/resolution, content abilities, admin UI, chat UI, and bundle import/export adapters.
- `datamachine/resolve-pending-action`, `datamachine/v1/actions/resolve`, chat pending-action wrappers, product handlers, and Data Machine permission checks until Agents API has a real upstream approval contract to adapt to.
- `AgentMemoryStoreFactory`, `DiskAgentMemoryStore`, and Data Machine memory file composition until the default-store decision is separate from the interface.

## Extraction Sequence

1. Finish the in-repo gates in `agents-api-pre-extraction-audit.md`.
2. Create the standalone plugin skeleton by copying only the bounded `agents-api` contracts and bootstrap into the new repo.
3. Add standalone bootstrap and no-product-import smokes in the new repo.
4. Add `agents-api` as a required Data Machine dependency and remove the in-repo module copy from Data Machine.
5. Update Data Machine imports/autoloading to consume the external plugin package.
6. Run behavior-preserving Data Machine and Intelligence smoke coverage before merging the dependency cut.
7. Open separate follow-ups for provider runtime, REST, persistence, admin UI, and optional stores after the skeleton is green.

## Acceptance Tests For The First Extraction PR

The first physical extraction PR is not complete until these checks pass:

| Area | Required proof |
|---|---|
| Standalone boot | A clean WordPress/PHP smoke loads `agents-api.php`, exposes `AGENTS_API_LOADED`, `wp_register_agent()`, `WP_Agent`, `WP_Agents_Registry`, package artifact helpers, message/result contracts, runtime tool declarations, transcript store interface, and memory store contracts without loading Data Machine classes. |
| Product boundary | Static smoke proves standalone `agents-api/` imports no `DataMachine\` namespaces and registers no admin menus, settings screens, REST routes, cron hooks, jobs, flows, queues, handlers, retention tasks, Data Machine pending-action implementations, or content operations. |
| Data Machine pipeline behavior | A focused Data Machine AI/pipeline smoke still runs through `AIStep`, tool policy, provider request assembly, transcript persistence, and handler-completion behavior after Data Machine consumes the external plugin. |
| Intelligence wiki behavior | Intelligence wiki create/read/update or wiki-generator smoke still works with Data Machine plus external Agents API. Wiki behavior must remain Intelligence/Data Machine product behavior, not Agents API vocabulary. |
| Memory store seam | Existing memory store smoke proves canonical `wp_agent_memory_store` resolution, guideline-backed memory if available, and Data Machine default memory behavior still work after contracts move out. |
| wp-ai-client gate | A focused runtime gate smoke proves no `ai-http-client` or `chubes_ai_request` fallback is introduced by the skeleton/dependency cut. |

## Blockers Before #1596 Can Start

Do not start the physical extraction issue until these gates are complete or explicitly reclassified:

- The in-repo `agents-api/` module loads before Data Machine product runtime and passes the bootstrap/no-product-import smokes.
- Public registration helper parity is settled: `wp_register_agent()`, `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`, and `wp_unregister_agent()`.
- Generic run/result/message/tool/transcript/memory contracts contain no Data Machine pipeline/handler vocabulary.
- Built-in loop ownership remains Data Machine until Data Machine completion/transcript/provider/logging assumptions are behind generic collaborators.
- Pending-action / diff-approval vocabulary is tracked as a later Agents API contract stage, while Data Machine keeps current storage, resolver ability, REST route, chat wrapper, product handlers, and permission checks.
- Provider/admin settings migration off `chubes_ai_*` surfaces is complete or does not leak into standalone skeleton scope.
- The v1 REST decision remains explicit: `wp-agents/v1` is absent until separate acceptance gates exist.
- Data Machine adapter/product responsibilities are still documented in the extraction map.

## Review Checklist

Use this checklist on the first standalone skeleton PR:

- [ ] The standalone plugin boots without Data Machine installed.
- [ ] The standalone plugin has no admin UI, no REST controllers, and no database schema.
- [ ] Public names match the table in this plan.
- [ ] The moved files are limited to the bounded Agents API contracts/value objects and bootstrap ceremony.
- [ ] Data Machine depends on the standalone plugin/package instead of carrying a second copy.
- [ ] Data Machine pipeline behavior is unchanged.
- [ ] Intelligence wiki behavior is unchanged.
- [ ] Memory store resolution is unchanged.
- [ ] No `ai-http-client` or `chubes_ai_*` fallback is introduced.
- [ ] Follow-up issues exist for REST, persistence, provider runtime, optional memory stores, and admin/product UI if needed.
