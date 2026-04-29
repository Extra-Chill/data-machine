# Agents API Pre-Extraction Audit

Parent issue: [Explore splitting Agents API out of Data Machine](https://github.com/Extra-Chill/data-machine/issues/1561)

This audit records the remaining work after the first in-place untangling wave. The boundary is now mostly visible: Data Machine owns pipelines and automation; the future Agents API owns generic agent runtime primitives. The next phase is to make those primitives look like Agents API while they still live in this repository.

## Current State

The initial untangling wave is complete:

- Pipeline tool policy translation is separated from generic tool policy resolution.
- Adjacent handler tools are a Data Machine provider instead of generic source-registry behavior.
- Ability-native tool execution is separated from Data Machine pending-action and post-tracking decorators.
- Declarative agent registration is separated from Data Machine materialization.
- Memory store ownership is documented behind an Agents API-shaped store contract and filter name.
- Conversation transcript storage is narrowed behind a transcript facade.
- The runner request boundary exists.

This branch starts the naming phase by renaming the neutral runner result/request seam from `AIConversation*` to `AgentConversation*` while leaving `AIConversationLoop` as the temporary compatibility facade.

## Remaining In-Place Rename Work

### 1. Runner Facade

`AIConversationLoop` still carries the old runtime name and owns built-in loop execution. It should not be physically extracted until the generic loop and the Data Machine completion/transcript policies are separated further.

Target shape:

- `AgentConversationRunnerInterface` is the public runtime boundary.
- `AgentConversationRequest` and `AgentConversationResult` are neutral value contracts.
- `AIConversationLoop` remains a Data Machine compatibility facade until callers are moved to the new name.
- A future `AgentConversationLoop` or `WP_Agent_Runner` should not know about `job_id`, `flow_step_id`, `pipeline_id`, or handler completion policy.

### 2. Runtime Hooks And Filters

These generic seams still need Agents API naming decisions or have already moved
in place:

- `datamachine_conversation_runner`
- `datamachine_conversation_store`
- `agents_api_memory_store`
- `datamachine_tool_sources`
- `datamachine_tool_sources_for_mode`
- `datamachine_register_agents`
- `datamachine_guideline_updated`

Target shape:

- Decide the `agents_api_*` / `wp_agents_api_*` hook names before extraction.
- Keep existing Data Machine hooks while code is in this repository unless a seam can move cleanly to Agents API vocabulary in place.
- Do not add runtime fallback ladders that survive extraction. When a seam moves to a new hook name, migrate consumers to the new hook directly.

### 3. Message Envelope Vocabulary

`MessageEnvelope` is generic but still declares the schema `datamachine.ai.message`.

Target shape:

- Decide whether the public class is `WP_Agent_Message`, `AgentMessageEnvelope`, or a lower-level normalizer.
- Rename schema metadata away from `datamachine.ai.message` before physical extraction.
- Keep wpcom message DTOs as adapters/source material, not public dependency vocabulary.

### 4. Conversation Storage Boundary

The transcript interfaces are now separated, but the storage implementation still lives under `Core\Database\Chat` and the aggregate store includes chat UI concerns.

Target shape:

- Extract transcript CRUD first.
- Keep read state, reporting, retention, and session list behavior optional or Data Machine-owned until proven generic.
- Keep Data Machine chat UI as product surface.

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

Target shape:

- Agents API should prefer Ability-native tools and runtime tool declarations.
- Data Machine can keep its curated `datamachine_tools` compatibility/product registry.
- Adjacent handler tools stay Data Machine-only.

### 7. Agent Registry And Identity

Declarative registration is separated from materialization, but the public helper is still `datamachine_register_agent()` and persistence still targets Data Machine tables.

Target shape:

- Mirror Abilities API: `wp_register_agent()`, `WP_Agent`, `WP_Agents_Registry`, `wp_agents_api_init`.
- Data Machine reconciler continues to create its rows/access records from registered definitions while Data Machine hosts the substrate.
- Decide whether Agents API owns persistence tables, only contracts, or optional stores before moving repositories.

### 8. Data Machine Product Still Under `Engine\AI`

System tasks, retention tasks, pending actions, and several product tools still live under `Engine\AI`.

Target shape:

- Do not move these into Agents API.
- Rename namespaces later if useful, but treat them as Data Machine automation/product surface.
- Use them as consumers of Agents API, not part of the substrate.

## Lingering Entanglement Checklist

Before physical extraction, verify that generic runtime candidates do not:

- Import `DataMachine\Core\Steps\*`.
- Require `job_id`, `flow_step_id`, `pipeline_id`, `flow_id`, or `handler_slug` as first-class fields.
- Call Data Machine job/engine APIs directly.
- Emit `datamachine_log` directly instead of using a generic event sink.
- Depend on `datamachine_tools` legacy class/method declarations.
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
