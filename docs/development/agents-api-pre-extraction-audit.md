# Agents API Pre-Extraction Audit

Parent issue: [Explore splitting Agents API out of Data Machine](https://github.com/Extra-Chill/data-machine/issues/1561)

Strategy issue: [Agents API blocker: update extraction docs around in-repo module strategy](https://github.com/Extra-Chill/data-machine/issues/1640)

Related blockers: [standalone extraction umbrella](https://github.com/Extra-Chill/data-machine/issues/1596), [standalone skeleton plan](https://github.com/Extra-Chill/data-machine/issues/1618), [in-repo module boundary](https://github.com/Extra-Chill/data-machine/issues/1631), [candidate relocation](https://github.com/Extra-Chill/data-machine/issues/1632), [wp-ai-client dependency contract](https://github.com/Extra-Chill/data-machine/issues/1633), [built-in loop ownership](https://github.com/Extra-Chill/data-machine/issues/1634), and [ai-http-client removal](https://github.com/Extra-Chill/data-machine/issues/1027).

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
- Later standalone extraction means moving the already-bounded module into its own plugin/repo and adding plugin bootstrap, dependency, release, and distribution ceremony.

Dependency direction:

```text
WordPress / wp-ai-client
        ↑
data-machine/agents-api
        ↑
Data Machine pipelines/product
```

`ai-http-client` is not future architecture. It is only packaging precedent for bundled-then-extracted code. The future runtime dependency direction is `Data Machine -> agents-api -> wp-ai-client`; `ai-http-client` dies as part of [#1027](https://github.com/Extra-Chill/data-machine/issues/1027) / [#1633](https://github.com/Extra-Chill/data-machine/issues/1633).

## Current State

The initial untangling wave is complete:

- Pipeline tool policy translation is separated from generic tool policy filtering.
- Adjacent handler tools are a Data Machine provider instead of generic source-registry behavior.
- Ability-native tool execution is separated from Data Machine pending-action and post-tracking decorators.
- Declarative agent registration is separated from Data Machine materialization.
- Conversation transcript storage is narrowed behind a transcript facade, and the aggregate chat-product store is documented as a Data Machine compatibility layer rather than the default extraction target.
- Memory store ownership is documented behind an Agents API-shaped store contract and filter name.
- The runner request boundary exists.
- `AgentConversationRequest::payload()` now exposes the generic runtime payload with Data Machine job/flow/pipeline/handler/transcript fields removed. Data Machine keeps those fields in `adapterContext()` and reconstructs the historical flat payload through `adapterPayload()` until the loop, prompt builder, and tool executor stop consuming the compatibility shape.
- The built-in loop now receives runtime completion and transcript collaborators. Data Machine's handler-completion and pipeline-transcript behavior lives behind adapter classes instead of being hardcoded as generic loop state.

This branch starts the naming phase by renaming the neutral runner result/request seam from `AIConversation*` to `AgentConversation*` while leaving `AIConversationLoop` as the temporary compatibility facade. The target home for boring generic runtime pieces is the in-repo `data-machine/agents-api/` module, not an immediate standalone plugin. `AgentMessageEnvelope`, `AgentConversationResult`, and `RuntimeToolDeclaration` have moved into that module with their existing namespaces preserved for behavior compatibility.

## In-Repo Module Gate

Before standalone extraction, the in-repo module should satisfy these gates:

- `data-machine/agents-api/` exists and loads before Data Machine product runtime bootstraps.
- A bootstrap smoke can load `agents-api` without Data Machine product code.
- No `agents-api` file imports `DataMachine\Core\Steps`, `DataMachine\Core\Database\Jobs`, handler, queue, retention, pending-action, admin UI, or content-operation namespaces.
- Data Machine product code imports the module as a dependency instead of reaching across same-layer runtime/product paths.
- Provider runtime code targets `wp-ai-client`; no `ai-http-client` fallback is introduced or preserved inside `agents-api`.

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
- Keep wpcom message DTOs as adapters/source material, not public dependency vocabulary.

### 4. Conversation Storage Boundary

The transcript interface is now separated from the aggregate chat-product store. The implementation still lives under `Core\Database\Chat` while extraction is in-place, but the dependency direction is explicit: runtime transcript persistence depends on `ConversationTranscriptStoreInterface`, while Data Machine chat UI/REST/CLI/retention/reporting depend on the broader `ConversationStoreInterface` aggregate.

Target shape:

- Extract transcript CRUD first.
- Keep read state, reporting, retention, and session list behavior optional or Data Machine-owned until proven generic.
- Keep Data Machine chat UI as product surface.
- Do not expose `datamachine_conversation_store` as the future Agents API transcript filter without narrowing its contract; it currently requires the Data Machine aggregate for behavior compatibility.

### 5. Memory Store Boundary

The memory store contract is close to extractable.

Target shape:

- `AgentMemoryStoreInterface` becomes a WordPress-shaped Agents API contract.
- `GuidelineAgentMemoryStore` becomes the core-friendly/default implementation where `wp_guideline` exists.
- `DiskAgentMemoryStore` becomes `MarkdownMemoryStore` only if disk-backed agent memory is part of the public Agents API product.
- Data Machine file scaffolding stays a Data Machine adapter.

Current in-place migration:

- `agents_api_memory_store` is the active memory-store resolver hook.
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
- Mention wpcom classes in public signatures.

## What Agents API Can Enable Without Data Machine

Agents API should be useful even when a site does not install Data Machine. In that shape, a plugin could build:

- A single-purpose site copilot that registers an agent, grants a bounded tool set, persists memory, and runs conversations.
- A support bot that reads tickets or docs through abilities and writes responses through approved tools.
- A personal knowledge agent with guideline-backed memory and a chat UI supplied by the consuming plugin.
- A code-review or repository agent that uses GitHub abilities without adopting Data Machine pipelines.
- A Slack, email, or helpdesk assistant that owns its own channel adapter and delegates only the runtime loop to Agents API.
- A domain expert agent bundled by another plugin, with default memory/guidelines and a constrained tool policy.
- A WordPress.com-hosted agent that uses wpcom provider/storage adapters while sharing the same public WordPress-shaped contracts.

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
