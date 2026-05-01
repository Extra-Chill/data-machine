# Agents API Pre-Extraction Audit

Parent issue: [Explore splitting Agents API out of Data Machine](https://github.com/Extra-Chill/data-machine/issues/1561)

Strategy issue: [Agents API blocker: update extraction docs around in-repo module strategy](https://github.com/Extra-Chill/data-machine/issues/1640)

Related blockers: [standalone extraction umbrella](https://github.com/Extra-Chill/data-machine/issues/1596), [standalone skeleton plan](https://github.com/Extra-Chill/data-machine/issues/1618) ([docs](agents-api-standalone-skeleton-plan.md)), [in-repo module boundary](https://github.com/Extra-Chill/data-machine/issues/1631), [candidate relocation](https://github.com/Extra-Chill/data-machine/issues/1632), [wp-ai-client dependency contract](https://github.com/Extra-Chill/data-machine/issues/1633), [built-in loop ownership](https://github.com/Extra-Chill/data-machine/issues/1634), [backend-only boundary](https://github.com/Extra-Chill/data-machine/issues/1651), [agent category/capability metadata](https://github.com/Extra-Chill/data-machine/issues/1669), [REST surface decision](https://github.com/Extra-Chill/data-machine/issues/1670), [registration lifecycle](https://github.com/Extra-Chill/data-machine/issues/1671), [core-shape readiness checklist](https://github.com/Extra-Chill/data-machine/issues/1672), [one-shot AI boundary](https://github.com/Extra-Chill/data-machine/issues/1693), and [ai-http-client removal](https://github.com/Extra-Chill/data-machine/issues/1027).

This audit records the remaining work after the first in-place untangling wave. The boundary is now mostly visible: Data Machine owns pipelines and automation; the future Agents API owns generic agent runtime primitives. The next phase is to make those primitives live behind an in-repo `data-machine/agents-api/` module boundary while they still ship with Data Machine.

## Strategy Update

The next step is not direct slice-by-slice extraction to an external repository. Build an in-repo module first:

```text
data-machine/
  agents-api/
    agents-api.php
    inc/
    tests/
  inc/
    ...Data Machine pipelines/product code...
```

Treat `data-machine/agents-api/` like WordPress core substrate while it still lives inside Data Machine:

- `agents-api` owns the WordPress-shaped agent runtime vocabulary and contracts.
- Data Machine consumes `agents-api` as product code.
- `agents-api` must not import Data Machine product namespaces.
- `agents-api` owns runner interfaces, value objects, and generic contracts first; Data Machine keeps `AIConversationLoop` and the built-in compatibility runner until the loop no longer carries Data Machine job, flow, handler, logging, transcript, or legacy payload/result assumptions.
- Data Machine keeps flows, pipelines, jobs, handlers, queues, retention, pending actions, content operations, and admin UI.
- `agents-api` is backend-only and invisible by default: no admin menus, screens, human CRUD forms, React apps, or Data Machine product UI.
- Data Machine and other product consumers own any admin/product UI they build on top of the substrate.
- Later standalone extraction means moving the already-bounded module into its own plugin/repo and adding plugin bootstrap, dependency, release, and distribution ceremony.

WordPress substrate boundary:

```text
Abilities API  -> actions and tools
wp-ai-client   -> provider/model prompt execution
Agents API     -> durable agent runtime behavior
Data Machine   -> automation product built on those substrates
```

`wp-ai-client` remains the direct WordPress provider primitive for one-shot AI operations: summarize text, classify content, generate titles/excerpts, transform content, produce a structured response from one prompt, or run a non-durable pipeline AI step where the product owns orchestration and storage. Agents API provider code may consume `wp-ai-client` directly when implementing durable agent runs, but that does not make Agents API the required path for every model call.

Use Agents API when the caller needs durable agent runtime behavior: agent registration and lookup, chat/session persistence, multi-turn tool loops, memory/guideline composition, conversation locks or queued messages, event sinks, progress/streaming runtime events, provider-agnostic run lifecycle, or portable agent declarations. Data Machine pipeline AI steps should not move to Agents API solely for provider dispatch; they should keep using the direct `wp-ai-client` path unless they need those durable runtime semantics.

`ai-http-client` is not future architecture. It is only packaging precedent for bundled-then-extracted code. The future dependency direction is not "all AI calls -> Agents API -> wp-ai-client"; it is "durable agent runs -> Agents API -> wp-ai-client" plus "one-shot AI operations -> wp-ai-client". `ai-http-client` dies as part of [#1027](https://github.com/Extra-Chill/data-machine/issues/1027) / [#1633](https://github.com/Extra-Chill/data-machine/issues/1633).

## Current State

The initial untangling wave is complete:

- Pipeline tool policy translation is separated from generic tool policy filtering.
- Adjacent handler tools are a Data Machine provider instead of generic source-registry behavior.
- Ability-native tool execution is separated from Data Machine pending-action and post-tracking decorators.
- Declarative agent registration is separated from Data Machine materialization.
- Conversation transcript storage is narrowed behind a transcript facade, and the aggregate chat-product store is documented as a Data Machine compatibility layer rather than the default extraction target.
- Memory store contracts/value objects live in the in-repo `agents-api/` module; Data Machine still owns the behavior-preserving factory/default store adapter.
- The runner request boundary exists.
- `AgentConversationRequest::payload()` now exposes the generic runtime payload with Data Machine job/flow/pipeline/handler/transcript fields removed. Data Machine keeps those fields in `adapterContext()` and reconstructs the historical flat payload through `adapterPayload()` until the loop, prompt builder, and tool executor stop consuming the compatibility shape.
- The built-in loop now receives runtime completion and transcript collaborators. Data Machine's handler-completion and pipeline-transcript behavior lives behind adapter classes instead of being hardcoded as generic loop state.

This branch starts the naming phase by renaming the neutral runner result/request seam from `AIConversation*` to `AgentConversation*` while leaving `AIConversationLoop` as the temporary compatibility facade. The target home for boring generic runtime pieces is the in-repo `data-machine/agents-api/` module, not an immediate standalone plugin. `AgentMessageEnvelope`, `AgentConversationResult`, and `RuntimeToolDeclaration` have moved into that module with their existing namespaces preserved for behavior compatibility.

## In-Repo Module Gate

Before standalone extraction, the in-repo module should satisfy these gates:

- `data-machine/agents-api/` exists and loads before Data Machine product runtime bootstraps.
- A bootstrap smoke can load `agents-api` without Data Machine product code.
- No `agents-api` file imports `DataMachine\Core\Steps`, `DataMachine\Core\Database\Jobs`, handler, queue, retention, pending-action, admin UI, or content-operation namespaces.
- No `agents-api` file registers admin menus, admin screens, settings forms, or admin-only UI hooks.
- Data Machine product code imports the module as a dependency instead of reaching across same-layer runtime/product paths.
- Provider runtime code targets `wp-ai-client`; no `ai-http-client` fallback is introduced or preserved inside `agents-api`.
- One-shot AI calls may use `wp-ai-client` directly without Agents API. Data Machine pipeline AI steps should not move to Agents API solely for provider dispatch.

Registration lifecycle gate for [#1671](https://github.com/Extra-Chill/data-machine/issues/1671):

- `agents-api/agents-api.php` wires `WP_Agents_Registry::init()` to WordPress `init`.
- `WP_Agents_Registry::init()` fires `wp_agents_api_init` once per request, matching the Abilities API's deterministic registration-window shape.
- `wp_register_agent()` is valid only while `wp_agents_api_init` is running; calls before or after that hook return `null` and emit `_doing_it_wrong()`.
- Public reads before `init` return empty/null/false and emit `_doing_it_wrong()` through `WP_Agents_Registry::get_instance()`.
- Public reads after `init` do not replay the hook, so callbacks added after initialization are not collected lazily.
- Data Machine materialization remains product-owned: `AgentRegistry::reconcile()` runs at `init` priority 15, after `wp_agents_api_init` has collected definitions and before existing scaffold checks.

## Core-Shape Decisions

These decisions keep the first standalone shape small enough to extract without freezing Data Machine product vocabulary into the substrate.

### Agent Categories And Capability Metadata

Decision for [#1669](https://github.com/Extra-Chill/data-machine/issues/1669): **no first-class agent category registry in v1**.

Agents differ from Abilities API abilities in the part of the system that needs discovery:

- Abilities are executable units. Core's Abilities API requires category registration because categories organize many small callable actions and drive REST filtering through `GET /wp-abilities/v1/categories` and the `category` collection parameter.
- Agents are runtime definitions. Their tool surface should be discovered through abilities/runtime tool declarations, not through a second category hierarchy on the agent definition itself.
- Data Machine UI grouping is not a valid reason to add a generic substrate field. Product UIs can group agents through their own settings, bundles, roles, or persisted metadata.
- Tool policy and memory policy should key off explicit policy fields, tool declarations, abilities, or run context. They should not infer permissions from a display category.

The v1 extension path is **lightweight metadata**, not category parity:

- Allow a future `metadata`/`annotations` object on `WP_Agent` definitions after the public class contract is finalized.
- Suggested generic keys, if proven by consumers: `type` for broad role hints, `capabilities` for declarative non-security affordances, and `annotations` for machine-readable hints.
- Metadata must be descriptive only in v1. It must not grant REST visibility, tool access, memory access, owner access, or execution permission by itself.
- If a future consumer proves that category parity is necessary, add it as a separate issue with Abilities API-style registration timing, validation, and REST discovery. Do not smuggle category semantics into `default_config`.

### `wp-agents/v1` REST Surface

Decision for [#1670](https://github.com/Extra-Chill/data-machine/issues/1670): **defer public `wp-agents/v1` routes from the first standalone extraction**.

The first extraction should prove the backend PHP substrate before publishing an HTTP contract. A premature REST controller would force decisions that are still unsettled: persistence ownership, visibility flags, run/session identity, transcript exposure, memory exposure, and permission ceilings.

The deferred shape is reserved as a backend substrate namespace, not a Data Machine product API:

- `GET /wp-agents/v1/agents` may list registered agent definitions that explicitly opt into REST discovery.
- `GET /wp-agents/v1/agents/{slug}` may read one registered definition.
- `POST /wp-agents/v1/agents/{slug}/runs` may eventually execute a generic `AgentConversationRequest` through `AgentConversationRunnerInterface`.
- Any future transcript, memory, run-state, or session routes require separate decisions. They must not inherit Data Machine chat-session switcher, jobs, flows, pipelines, queues, or pending-action vocabulary.

The v1 standalone skeleton should therefore document that `wp-agents/v1` is intentionally absent. Data Machine's existing `datamachine/v1` routes remain product REST for flows, pipelines, jobs, chat UI, agent files, and automation surfaces. They may adapt to Agents API contracts later, but they do not define the public substrate.

REST acceptance gates before any future controller lands:

- Define a `show_in_rest`-equivalent visibility flag or intentionally choose authenticated-only discovery with no public listing.
- Define list/read/run schemas with no Data Machine table names, job IDs, flow IDs, pipeline IDs, or handler slugs in the public contract.
- Define permission callbacks separately for list, read, and run. `current_user_can( 'read' )` is not automatically sufficient for agent execution.
- Define whether run endpoints are synchronous only, asynchronous only, or both. Do not reuse Action Scheduler job semantics unless the route is explicitly Data Machine-owned.
- Decide whether metadata is exposed, and if so which keys are public versus edit-context only.

## Extraction Readiness Checklist

[#1596](https://github.com/Extra-Chill/data-machine/issues/1596) is blocked until every **must-fix** item below is complete or explicitly reclassified in a follow-up decision.

| Gate | Status | Proof issue |
|---|---|---|
| In-repo `agents-api/` module loads before Data Machine product runtime and can boot in a smoke without product code. | Must fix | [#1631](https://github.com/Extra-Chill/data-machine/issues/1631), [#1618](https://github.com/Extra-Chill/data-machine/issues/1618) |
| Public contract candidates live in the module or have a documented reason to stay in Data Machine for compatibility. | Must fix | [#1632](https://github.com/Extra-Chill/data-machine/issues/1632) |
| No public `agents-api/` contract imports `DataMachine\` product namespaces or requires jobs, flows, pipelines, handlers, queues, pending actions, content operations, or admin UI. | Must fix | [#1631](https://github.com/Extra-Chill/data-machine/issues/1631), [#1672](https://github.com/Extra-Chill/data-machine/issues/1672) |
| `WP_Agent`, `WP_Agents_Registry`, `wp_register_agent()`, and registry lifecycle are validated and documented. | Must fix | [#1618](https://github.com/Extra-Chill/data-machine/issues/1618), [#1632](https://github.com/Extra-Chill/data-machine/issues/1632) |
| Registration helper parity is decided for `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`, and `wp_unregister_agent()`. | Must fix | [#1618](https://github.com/Extra-Chill/data-machine/issues/1618) |
| Agent categories/capabilities decision is recorded: no v1 category registry; future descriptive metadata only. | Complete | [#1669](https://github.com/Extra-Chill/data-machine/issues/1669) |
| `wp-agents/v1` REST decision is recorded: defer public REST from first standalone extraction, reserve namespace for backend substrate. | Complete | [#1670](https://github.com/Extra-Chill/data-machine/issues/1670) |
| Generic run/result/message contracts contain no Data Machine pipeline/handler vocabulary. | Must fix | [#1596](https://github.com/Extra-Chill/data-machine/issues/1596), [#1634](https://github.com/Extra-Chill/data-machine/issues/1634) |
| Built-in loop ownership is settled: Data Machine keeps compatibility loop until completion/transcript/provider/logging assumptions are behind generic collaborators. | Must fix | [#1634](https://github.com/Extra-Chill/data-machine/issues/1634) |
| Provider runtime depends directly on `wp-ai-client`; no `ai-http-client` or `chubes_ai_request` runtime fallback survives inside `agents-api`. | Must fix | [#1633](https://github.com/Extra-Chill/data-machine/issues/1633), [#1027](https://github.com/Extra-Chill/data-machine/issues/1027), [#1660](https://github.com/Extra-Chill/data-machine/issues/1660), [#1661](https://github.com/Extra-Chill/data-machine/issues/1661) |
| Memory and transcript interfaces are generic and UI-free. Data Machine chat/session switcher/read-state/reporting remain product adapters unless separately adopted. | Must fix | [#1632](https://github.com/Extra-Chill/data-machine/issues/1632), [#1651](https://github.com/Extra-Chill/data-machine/issues/1651) |
| Data Machine adapters own flows, jobs, pipelines, handlers, pending actions, bundles, queues, retention, content operations, and chat/admin UI. | Must fix | [#1561](https://github.com/Extra-Chill/data-machine/issues/1561), [#1640](https://github.com/Extra-Chill/data-machine/issues/1640), [#1651](https://github.com/Extra-Chill/data-machine/issues/1651) |
| Standalone skeleton documents that `wp-agents/v1` is absent in v1 and includes no REST controllers until the REST acceptance gates are satisfied. | Must fix | [#1618](https://github.com/Extra-Chill/data-machine/issues/1618), [#1670](https://github.com/Extra-Chill/data-machine/issues/1670) |
| Optional future stores, sessions, async run state, REST controllers, category parity, and product UI are deferred until the core backend contracts are extractable. | Can follow | [#1596](https://github.com/Extra-Chill/data-machine/issues/1596), [#1670](https://github.com/Extra-Chill/data-machine/issues/1670), [#1669](https://github.com/Extra-Chill/data-machine/issues/1669) |

## Remaining In-Place Rename Work

### 1. Runner Facade

`AIConversationLoop` still carries the old runtime name and owns built-in loop execution. It should not be physically extracted until the generic loop and the Data Machine completion/transcript policies are separated further. The current decision for [#1634](https://github.com/Extra-Chill/data-machine/issues/1634) is to keep `AIConversationLoop` and `BuiltInAgentConversationRunner` in Data Machine, while `agents-api` grows the runner contracts and value objects that a future generic loop would consume.

Target shape:

- `AgentConversationRunnerInterface` is the public runtime boundary.
- `AgentConversationRequest` and `AgentConversationResult` are neutral value contracts.
- `AIConversationLoop` remains a Data Machine compatibility facade until callers are moved to the new name.
- `AgentConversationCompletionPolicyInterface` and `AgentConversationTranscriptPersisterInterface` are in-place runtime collaborator seams; Data Machine provides the current handler-completion and transcript adapters.
- A future `AgentConversationLoop` or `WP_Agent_Runner` should not know about `job_id`, `flow_step_id`, `pipeline_id`, or handler completion policy.
- The built-in compatibility loop must not move into `agents-api` while it preserves historical `AIConversationLoop::execute()` result normalization, Data Machine logging/transcript metadata, adjacent-handler completion, or `ai-http-client` / `chubes_ai_*` provider compatibility.

### 2. Runtime Hooks And Filters

These generic seams still need Agents API naming decisions or have already moved
in place:

- `agents_api_conversation_runner`
- `datamachine_conversation_store`
- `agents_api_memory_store`
- `agents_api_tool_sources`
- `agents_api_tool_sources_for_mode`
- `wp_agents_api_init`
- `datamachine_guideline_updated`

Target shape:

- Decide the `agents_api_*` / `wp_agents_api_*` hook names before extraction.
- Keep existing Data Machine hooks while code is in this repository unless a seam can move cleanly to Agents API vocabulary in place; new agent registrations should use the WordPress-shaped hook/helper.
- Do not add runtime fallback ladders that survive extraction. When a seam moves to a new hook name, migrate consumers to the new hook directly.

### 3. Message Envelope Vocabulary

`AgentMessageEnvelope` is the in-place Agents API-shaped name for the generic
message contract. Its schema is `agents-api.message` while the class still lives
inside Data Machine.

Target shape:

- Review whether the physically extracted public class should stay `AgentMessageEnvelope` or become `WP_Agent_Message`.
- Keep schema metadata generic; do not reintroduce Data Machine-owned schema names for the shared contract.
- Keep host-specific message DTOs as adapters/source material, not public dependency vocabulary.

### 4. Conversation Storage Boundary

The transcript interface is now separated from the aggregate chat-product store. The interface lives in the in-repo `agents-api/` module while preserving its current namespace for behavior compatibility, and the dependency direction is explicit: runtime transcript persistence depends on `ConversationTranscriptStoreInterface`, while Data Machine chat UI/REST/CLI/retention/reporting depend on the broader `ConversationStoreInterface` aggregate.

Target shape:

- Extract transcript CRUD first.
- Keep read state, reporting, retention, and session list behavior optional or Data Machine-owned until proven generic.
- Keep Data Machine chat UI as product surface.
- Do not expose `datamachine_conversation_store` as the future Agents API transcript filter without narrowing its contract; it currently requires the Data Machine aggregate for behavior compatibility.

### 5. Memory Store Boundary

The memory store contract is close to extractable.

Target shape:

- `AgentMemoryStoreInterface`, `AgentMemoryScope`, `AgentMemoryReadResult`, `AgentMemoryWriteResult`, and `AgentMemoryListEntry` are WordPress-shaped Agents API contracts/value objects hosted in `agents-api/` with current namespaces preserved for compatibility.
- `AgentMemoryStoreFactory` stays Data Machine-owned for now because its behavior-preserving fallback constructs `DiskAgentMemoryStore`.
- `GuidelineAgentMemoryStore` may become an optional Agents API implementation later, guarded by `post_type_exists( 'wp_guideline' )` and taxonomy availability checks.
- `DiskAgentMemoryStore` stays Data Machine product/adapter behavior with file scaffolding, SOUL/MEMORY/USER composition, section editing, and operator CLI surfaces.

Current in-place migration:

- `agents_api_memory_store` remains the active memory-store resolver hook through Data Machine's factory.
- The previous `datamachine_memory_store` hook is intentionally not mirrored; pre-1.0 consumers should register on `agents_api_memory_store`.
- `DiskAgentMemoryStore` keeps its class name until a physical Agents API package decides whether disk/markdown memory is part of the public product.

### 6. Tool Registry And Execution

The execution core is split, but `ToolManager` still centers on `datamachine_tools` and legacy handler/class tool declarations.

The source-composition seam is now clearer: `ToolSourceRegistry` composes named providers, while `DataMachineToolRegistrySource` adapts the legacy/product `datamachine_tools` registry and `AdjacentHandlerToolSource` adapts pipeline-neighbor handler tools. Those providers are Data Machine consumers of the generic source idea, not the future Agents API tool registry.

The policy-filtering seam is also split in place: `ToolPolicyFilter` owns reusable allow/deny/category/capability filtering, while `ToolPolicyResolver` remains the Data Machine adapter that gathers Data Machine sources, reads persisted agent policy, preserves adjacent handler tools, and delegates permission checks to `DataMachineToolAccessPolicy`.

Target shape:

- Agents API should prefer Ability-native tools and runtime tool declarations.
- Data Machine can keep its curated `datamachine_tools` compatibility/product registry.
- Adjacent handler tools stay Data Machine-only.
- Data Machine permission helpers, persisted agent table reads, and mandatory handler-tool preservation stay adapter-only.

### 7. Agent Registry And Identity

Declarative registration is separated from materialization, and the public vocabulary is now mirrored in-place as `wp_register_agent()`, `WP_Agent`, `WP_Agents_Registry`, and `wp_agents_api_init`. Persistence still targets Data Machine tables through the materializer while Data Machine hosts the substrate.

Target shape:

- Extract the WordPress-shaped facade/registry contract before moving Data Machine persistence repositories.
- Data Machine reconciler continues to create its rows/access records from registered definitions while Data Machine hosts the substrate.
- Decide whether Agents API owns persistence tables, only contracts, or optional stores before moving repositories.

### 8. Data Machine Product Still Under `Engine\AI`

System tasks, retention tasks, pending actions, and several product tools still live under `Engine\AI`.

Target shape:

- Do not move these into Agents API.
- Rename namespaces later if useful, but treat them as Data Machine automation/product surface.
- Use them as consumers of Agents API, not part of the substrate.
- Keep `System\*`, `System\Tasks\*`, `System\Tasks\Retention\*`, `Actions\*`, `Tools\Global\*`, `Tools\Sources\DataMachineToolRegistrySource`, `Tools\Sources\AdjacentHandlerToolSource`, `PipelineTranscriptPolicy`, `DataMachinePipelineTranscriptPersister`, and `DataMachineHandlerCompletionPolicy` in the Data Machine adapter/product bucket until there is a narrow, behavior-preserving move.
- Use `inc/Engine/AI/README.md` and the extraction map's `Current Engine\AI Namespace Split` table as the grep guide while this namespace remains mixed.

## Lingering Entanglement Checklist

Before physical extraction, verify that generic runtime candidates do not:

- Import `DataMachine\Core\Steps\*`.
- Require `job_id`, `flow_step_id`, `pipeline_id`, `flow_id`, or `handler_slug` as first-class fields.
- Call Data Machine job/engine APIs directly.
- Emit `datamachine_log` directly instead of using a generic event sink.
- Depend on `datamachine_tools` legacy class/method declarations.
- Depend on `ai-http-client` or `chubes_ai_*` filters.
- Mention host-specific classes in public signatures.

## What Agents API Can Enable Without Data Machine

Agents API should be useful even when a site does not install Data Machine. In that shape, a plugin could build:

- A single-purpose site copilot that registers an agent, grants a bounded tool set, persists memory, and runs conversations.
- A support bot that reads tickets or docs through abilities and writes responses through approved tools.
- A personal knowledge agent with guideline-backed memory and a chat UI supplied by the consuming plugin.
- A code-review or repository agent that uses GitHub abilities without adopting Data Machine pipelines.
- A Slack, email, or helpdesk assistant that owns its own channel adapter and delegates only the runtime loop to Agents API.
- A domain expert agent bundled by another plugin, with default memory/guidelines and a constrained tool policy.
- A hosted agent that uses environment-specific provider/storage adapters while sharing the same public WordPress-shaped contracts.

Agents API should provide the substrate for those products:

- Agent registration and identity.
- Message/result contracts.
- Conversation runner contract.
- Tool declaration and execution contracts.
- Memory store contract and default memory implementations.
- Conversation transcript contract.
- Event sink / streaming / observation contract.
- Permission ceiling and acting-context primitives.

## What Still Requires Data Machine

Data Machine remains the product layer for automation workflows. Without Data Machine, consumers do not get:

- Flow and pipeline builders.
- Fetch -> AI -> publish orchestration.
- Queue modes, config patch queues, and backfill rotation.
- Jobs, parent/child fan-out, Action Scheduler orchestration, and undo/effect tracking.
- Fetch/publish/upsert handler ecosystem.
- Retention tasks and pipeline logs.
- Data Machine content operations such as alt text, meta descriptions, IndexNow, post/taxonomy/block automation, and pipeline admin UI.

That distinction is the point of the split: Agents API makes agents possible; Data Machine makes repeatable content/data automation products out of agents.
