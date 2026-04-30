# Agents API Extraction Map

This map classifies Data Machine's current agent/runtime surface for the next Agents API phase. The next phase is not direct slice-by-slice extraction into an external repository. The next phase is an in-repo, core-style `data-machine/agents-api/` module that Data Machine consumes as product code while it still ships in this repository.

Parent issue: [Explore splitting Agents API out of Data Machine](https://github.com/Extra-Chill/data-machine/issues/1561)

Strategy update: [Agents API blocker: update extraction docs around in-repo module strategy](https://github.com/Extra-Chill/data-machine/issues/1640)

Related blockers: [standalone extraction umbrella](https://github.com/Extra-Chill/data-machine/issues/1596), [standalone skeleton plan](https://github.com/Extra-Chill/data-machine/issues/1618), [in-repo module boundary](https://github.com/Extra-Chill/data-machine/issues/1631), [candidate relocation](https://github.com/Extra-Chill/data-machine/issues/1632), [wp-ai-client dependency contract](https://github.com/Extra-Chill/data-machine/issues/1633), [built-in loop ownership](https://github.com/Extra-Chill/data-machine/issues/1634), [backend-only boundary](https://github.com/Extra-Chill/data-machine/issues/1651), and [ai-http-client removal](https://github.com/Extra-Chill/data-machine/issues/1027).

## Namespace Map

Public contracts inside `agents-api/` use neutral `AgentsAPI\...` namespaces. Data Machine imports these contracts as a consumer; no compatibility alias ladder is maintained while Data Machine remains pre-1.0.

| Former Data Machine namespace | Agents API namespace |
|---|---|
| `DataMachine\Engine\AI\AgentMessageEnvelope` | `AgentsAPI\Engine\AI\AgentMessageEnvelope` |
| `DataMachine\Engine\AI\AgentConversationResult` | `AgentsAPI\Engine\AI\AgentConversationResult` |
| `DataMachine\Engine\AI\Tools\RuntimeToolDeclaration` | `AgentsAPI\Engine\AI\Tools\RuntimeToolDeclaration` |
| `DataMachine\Core\Database\Chat\ConversationTranscriptStoreInterface` | `AgentsAPI\Core\Database\Chat\ConversationTranscriptStoreInterface` |
| `DataMachine\Core\FilesRepository\AgentMemoryStoreInterface` | `AgentsAPI\Core\FilesRepository\AgentMemoryStoreInterface` |
| `DataMachine\Core\FilesRepository\AgentMemoryScope` | `AgentsAPI\Core\FilesRepository\AgentMemoryScope` |
| `DataMachine\Core\FilesRepository\AgentMemoryReadResult` | `AgentsAPI\Core\FilesRepository\AgentMemoryReadResult` |
| `DataMachine\Core\FilesRepository\AgentMemoryWriteResult` | `AgentsAPI\Core\FilesRepository\AgentMemoryWriteResult` |
| `DataMachine\Core\FilesRepository\AgentMemoryListEntry` | `AgentsAPI\Core\FilesRepository\AgentMemoryListEntry` |

## Current Strategy

Build the Agents API as an in-repo module first:

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

- `agents-api` must not import Data Machine product namespaces.
- Data Machine may import and consume `agents-api` as product code.
- `agents-api` owns runner interfaces, value objects, and generic contracts first; Data Machine keeps `AIConversationLoop` and the built-in compatibility runner while they still carry Data Machine job, flow, handler, logging, transcript, and legacy result-shape assumptions.
- Data Machine keeps flows, pipelines, jobs, handlers, queues, retention, pending actions, content operations, and admin UI.
- `agents-api` is backend-only and invisible by default: no admin menus, screens, human CRUD forms, React apps, or Data Machine product UI.
- Data Machine and other product consumers own any admin/product UI they build on top of the substrate.
- Later standalone extraction means moving the already-bounded module into its own plugin/repo and adding plugin bootstrap, release, dependency, and distribution ceremony.
- `ai-http-client` is not future architecture. It is only packaging precedent for bundled-then-extracted code.
- The future runtime dependency direction is `Data Machine product adapter -> Agents API run request -> wp-ai-client public API`; `ai-http-client` dies as part of [#1027](https://github.com/Extra-Chill/data-machine/issues/1027) / [#1633](https://github.com/Extra-Chill/data-machine/issues/1633).

```text
wp-ai-client public API
        ↑
Agents API run request
        ↑
Data Machine product adapter
```

## Target Vocabulary

Mirror the WordPress Abilities API shape instead of importing Data Machine product names or host-specific implementation names into the public contract.

| Current Data Machine surface | Possible Agents API vocabulary | Notes |
|---|---|---|
| `wp_register_agent()` | `wp_register_agent()` | Same declarative pattern as Abilities API-style registration; no DB reconciliation side effects in the public helper. |
| `WP_Agent` / `WP_Agents_Registry` | `WP_Agent` / `WP_Agents_Registry` | Registry collects definitions. Persistence/adoption remains Data Machine adapter territory. |
| `wp_agents_api_init` | `wp_agents_api_init` | Core-shaped init hook mirrored in-place while Data Machine hosts the substrate. |
| `AgentMessageEnvelope` | `WP_Agent_Message` or same class name | Contract is generic and now uses Agents API-shaped vocabulary in place. |
| `ConversationTranscriptStoreInterface` | `WP_Agent_Conversation_Transcript_Store_Interface` | Transcript CRUD is the first extractable storage contract. Keep chat-product listing/read-state/reporting separate. |
| `AgentMemoryStoreInterface` | `WP_Agent_Memory_Store_Interface` | Generic identity tuple needs naming review; Data Machine scaffolding/abilities stay outside the store contract. |
| `RuntimeToolDeclaration` | `WP_Agent_Tool_Declaration` | Should stay ability-native and run-scoped. |
| `LoopEventSinkInterface` | `WP_Agent_Run_Event_Sink_Interface` | Useful for logs, streaming, chat UIs, and async workers. |
| REST `datamachine/v1` agent routes | REST `wp-agents/v1` | Data Machine product routes stay under `datamachine/v1`. |

## Boundary Rules

Use these checks before moving anything:

- If a plugin can use it without knowing about flows, pipeline steps, handlers, queues, jobs, or Data Machine content operations, it is an Agents API candidate.
- If it translates Data Machine concepts into runtime concepts, it is a Data Machine adapter.
- If it owns flows, jobs, queues, handlers, scheduled automation, retention, admin UI, or content ops, it stays Data Machine product.
- If it uses host-specific provider, storage, or implementation vocabulary directly, treat that code as source material only until normalized behind WordPress-shaped contracts.

## Backend-Only UI Boundary

Agents API is a generic WordPress-shaped substrate. It should be usable by any plugin that wants to register, run, persist, or observe agents without adopting Data Machine as a product.

`agents-api` may own backend contracts and implementations for:

- registration vocabulary and registries.
- runtime request/result/message contracts.
- memory, transcript, tool, event, and permission-ceiling contracts.
- direct public WordPress APIs such as Abilities API and `wp-ai-client`.

`agents-api` must not own product/admin surfaces:

- admin menus, screens, list tables, settings forms, or React admin apps.
- human agent CRUD screens or workflows.
- Data Machine flow, pipeline, chat, bundle, queue, job, retention, or content-operation UI.

Substrate CRUD is allowed when it is backend-only and generic: interfaces/services for definitions, sessions, memories, transcripts, tools, and run state. Product CRUD belongs to consumers: screens, forms, routes, workflows, and opinionated management UX. Data Machine may provide those product surfaces while consuming `agents-api`; the dependency direction must not reverse.

## Bucket Summary

| Bucket | Meaning | Current examples |
|---|---|---|
| Agents API public candidate | Generic WordPress-shaped contract or value object. | Message envelopes, transcript store interface, memory store interface, runtime tool declaration validation, agent registration vocabulary. |
| Agents API implementation candidate | Generic implementation, but naming or assumptions need cleanup first. | Built-in loop, request assembly, tool executor, guideline memory store, directive renderer. |
| Data Machine adapter | Glue that turns flows/jobs/pipelines into generic runtime inputs. | `AIStep`, pipeline tool-policy args, transcript persistence policy, adjacent handler tools. |
| Data Machine product | Data Machine automation/product layer. | Jobs, flows, pipelines, handlers, queues, retention, content abilities, admin UI. |
| Intelligence domain | Intelligence plugin concerns, not Data Machine or Agents API. | Wiki, briefings, digests, domain brains. |
| Host-specific source material | Useful precedent only. | Provider/storage implementations that must be normalized behind WordPress-shaped contracts before they can inform Agents API. |

## Current `Engine\AI` Namespace Split

The current namespace is intentionally mixed while extraction stays in place. Treat `DataMachine\Engine\AI` as a staging namespace, not as an extraction boundary. Grep for the class or subnamespace below before assuming a file is part of the future Agents API runtime.

| Current namespace/surface | Bucket | Boundary decision |
|---|---|---|
| `AgentsAPI\Engine\AI\AgentMessageEnvelope`, `AgentsAPI\Engine\AI\AgentConversationResult`, plus `DataMachine\Engine\AI\AgentConversationRequest`, `AgentConversationRunnerInterface`, `AgentConversationCompletionPolicyInterface`, `AgentConversationTranscriptPersisterInterface`, `LoopEventSinkInterface` | Agents API public candidate | Generic contracts/value objects. `AgentMessageEnvelope` and `AgentConversationResult` now live in the in-repo `agents-api/` module under neutral namespaces. `AgentConversationRequest` keeps Data Machine job/flow/pipeline/handler/transcript fields in adapter context rather than the generic runtime payload, so it remains outside until that compatibility shape is gone. |
| `DataMachine\Engine\AI\BuiltInAgentConversationRunner`, `AIConversationLoop`, `RequestBuilder`, `WpAiClientAdapter`, `RequestInspector`, `RequestMetadata`, `ConversationManager` | Agents API implementation candidate | Runtime implementation candidates, but still hosted by Data Machine and still carrying compatibility/provider/logging assumptions. Future provider direction is `wp-ai-client`; `ai-http-client` is removal work, not an Agents API runtime layer. |
| `AgentsAPI\Engine\AI\Tools\RuntimeToolDeclaration`, plus `DataMachine\Engine\AI\Tools\Execution\ToolExecutionCore`, `Tools\ToolSourceRegistry`, `Tools\Policy\ToolPolicyFilter`, `Tools\ToolResultFinder` | Mixed runtime candidate | `RuntimeToolDeclaration` now lives in the in-repo `agents-api/` module under a neutral namespace. The remaining generic-looking pieces still sit next to Data Machine adapters and should move only after their source-provider, policy, and execution boundaries are proven generic. |
| `DataMachine\Engine\AI\Tools\Sources\DataMachineToolRegistrySource`, `Tools\Sources\AdjacentHandlerToolSource`, `Tools\Policy\DataMachineAgentToolPolicyProvider`, `Tools\Policy\DataMachineMandatoryToolPolicy`, `Tools\Policy\DataMachineToolAccessPolicy`, `Tools\ToolManager`, `Tools\ToolPolicyResolver`, `Tools\ToolParameters` payload merging | Data Machine adapter/product | These translate Data Machine handler, pipeline, queue, permission, persisted-agent, and legacy tool registry concepts into runtime inputs. They stay Data Machine. |
| `DataMachine\Engine\AI\Tools\Global\*` | Data Machine product | Curated product/site-ops tools. Individual capabilities may move to abilities later, but the bundle is not the Agents API registry. |
| `DataMachine\Engine\AI\System\*` and `System\Tasks\*` | Data Machine product | System tasks, task prompts, retention cleanup, and scheduled maintenance stay in Data Machine. A future Agents API may provide a task contract, not these tasks. |
| `DataMachine\Engine\AI\Actions\*` | Data Machine product | Pending-action storage, approval policy, and action-resolution abilities stay Data Machine. Generic approval can be designed later without inheriting these tables/routes. |
| `DataMachine\Engine\AI\Memory\*`, `MemoryFileRegistry`, `SectionRegistry`, `ComposableFileGenerator`, `ComposableFileInvalidation` | Data Machine adapter/product | Memory policy artifacts and file composition are Data Machine's operator/product layer around the generic memory store contract. |
| `PipelineTranscriptPolicy`, `DataMachinePipelineTranscriptPersister`, `DataMachineHandlerCompletionPolicy` | Data Machine adapter | Pipeline/job metadata and adjacent-handler completion are normalized for the runtime through collaborator interfaces, but the implementations stay Data Machine. |

Exit rule for this in-place phase: do not physically move broad namespaces just because they sit under `Engine\AI`. Move only once a class is generic by dependency direction, vocabulary, and tests; otherwise document it as a Data Machine adapter or product surface.

## Built-In Loop Ownership Decision

The in-repo `agents-api` module does not own Data Machine's built-in loop implementation yet. Its current ownership line is the generic contract surface: runner interfaces, request/result value objects, message envelopes, runtime tool declarations, and collaborator contracts that a loop can depend on without knowing Data Machine product concepts.

Data Machine keeps `AIConversationLoop` and `BuiltInAgentConversationRunner` until the compatibility loop no longer needs Data Machine-owned assumptions. The loop must stay outside `agents-api` while it knows about or directly preserves any of these product concerns:

- job, flow, pipeline, flow-step, handler, or queue payload keys.
- Data Machine logging and transcript metadata.
- adjacent-handler completion semantics.
- historical `AIConversationLoop::execute()` result normalization.
- `ai-http-client` / `chubes_ai_*` provider compatibility.

Future extraction can move a generic loop only after those concerns are pushed behind collaborators such as completion policy, transcript persister, provider caller, request assembler, event sink, and Data Machine adapters. Until then, the enforceable boundary is: `agents-api` defines the contract shape; Data Machine owns the built-in compatibility loop that implements it for existing pipelines and chat callers.

## Agents API Public Candidate

These are closest to generic public contracts. Most should be extracted as contracts/value objects before services.

| Surface | Current location | Why it fits | Target notes |
|---|---|---|---|
| `AgentMessageEnvelope` | `agents-api/inc/Engine/AI/AgentMessageEnvelope.php` | JSON-friendly canonical message envelope independent of flows/jobs. | Lives at `AgentsAPI\Engine\AI\AgentMessageEnvelope`. Review whether a future standalone extraction keeps this class name or adopts `WP_Agent_Message`. |
| `AgentConversationResult` | `agents-api/inc/Engine/AI/AgentConversationResult.php` | Validates result arrays from any runtime runner. | Lives at `AgentsAPI\Engine\AI\AgentConversationResult`. Future standalone extraction can rename to `WP_Agent_Run_Result` or split into result value object plus validator. |
| `AgentConversationCompletionPolicyInterface` | `inc/Engine/AI/AgentConversationCompletionPolicyInterface.php` | Generic runtime collaborator for deciding whether a tool result completes a run. | Keep Data Machine handler semantics in adapter implementations, not in the loop contract. |
| `AgentConversationTranscriptPersisterInterface` | `inc/Engine/AI/AgentConversationTranscriptPersisterInterface.php` | Generic runtime collaborator for optional transcript persistence. | Future extraction should pair this with the transcript store contract and keep job/flow metadata in Data Machine adapters. |
| `LoopEventSinkInterface` | `inc/Engine/AI/LoopEventSinkInterface.php` | Transport-neutral event sink for logs, streaming, CLI, REST, or chat UIs. | Make event vocabulary public and provider-neutral before extraction. |
| `NullLoopEventSink` | `inc/Engine/AI/NullLoopEventSink.php` | Generic no-op implementation for optional event sinks. | Implementation can move with the interface. |
| `RuntimeToolDeclaration` | `agents-api/inc/Engine/AI/Tools/RuntimeToolDeclaration.php` | Validates run-scoped client/runtime tool declarations without Data Machine state. | Lives at `AgentsAPI\Engine\AI\Tools\RuntimeToolDeclaration`. Future standalone extraction can rename around `WP_Agent_Tool_Declaration`; keep executor/source/scope vocabulary generic. |
| `AgentMemoryStoreInterface` | `agents-api/inc/Core/FilesRepository/AgentMemoryStoreInterface.php` | Generic memory persistence seam consumed by the Data Machine `agents_api_memory_store` resolver hook. | Lives at `AgentsAPI\Core\FilesRepository\AgentMemoryStoreInterface`. Keep CAS/hash behavior. |
| `AgentMemoryScope` | `agents-api/inc/Core/FilesRepository/AgentMemoryScope.php` | Encodes memory identity independently of disk/database implementations. | Review `layer`, `user_id`, `agent_id`, `filename` as the public model before standalone extraction. |
| `AgentMemoryReadResult` | `agents-api/inc/Core/FilesRepository/AgentMemoryReadResult.php` | Store-neutral read result. | Lives at `AgentsAPI\Core\FilesRepository\AgentMemoryReadResult`. |
| `AgentMemoryWriteResult` | `agents-api/inc/Core/FilesRepository/AgentMemoryWriteResult.php` | Store-neutral write result with hash/bytes/error shape. | Lives at `AgentsAPI\Core\FilesRepository\AgentMemoryWriteResult`. |
| `AgentMemoryListEntry` | `agents-api/inc/Core/FilesRepository/AgentMemoryListEntry.php` | Store-neutral list entry. | Lives at `AgentsAPI\Core\FilesRepository\AgentMemoryListEntry`. |
| `ConversationTranscriptStoreInterface` | `agents-api/inc/Core/Database/Chat/ConversationTranscriptStoreInterface.php` | Transcript CRUD is generic conversation persistence. | Lives at `AgentsAPI\Core\Database\Chat\ConversationTranscriptStoreInterface`; do not require chat UI listing/read-state/reporting for transcript-only backends. |
| `ConversationSessionIndexInterface` | `inc/Core/Database/Chat/ConversationSessionIndexInterface.php` | Session listing can be generic for UIs, but it is not required for transcript persistence. | Treat as optional until Agents API adopts an identity/listing model. Data Machine chat switcher uses it today. |
| `ConversationReadStateInterface` | `inc/Core/Database/Chat/ConversationReadStateInterface.php` | Read-state is generic UI behavior, not transcript CRUD. | Optional interface at most. Data Machine chat unread state keeps consuming it. |
| `ConversationRetentionInterface` | `inc/Core/Database/Chat/ConversationRetentionInterface.php` | Cleanup methods can be backend-generic, but retention policy/scheduling is product behavior. | Data Machine retention tasks stay product; future Agents API may expose only optional backend cleanup. |
| `ConversationReportingInterface` | `inc/Core/Database/Chat/ConversationReportingInterface.php` | Metrics/reporting reads are useful but product-shaped today. | Optional interface at most. Data Machine daily memory and retention CLI keep consuming it. |
| `ConversationStoreInterface` | `inc/Core/Database/Chat/ConversationStoreInterface.php` | Aggregate Data Machine chat-product compatibility contract. | Do not extract as the default public contract unless Agents API deliberately wants the full aggregate. Prefer the transcript interface first. |
| `ConversationStoreFactory::get_transcript_store()` | `inc/Core/Database/Chat/ConversationStoreFactory.php` | Narrow resolver for runtime transcript persistence. | Current implementation reuses the Data Machine aggregate filter for compatibility; future Agents API can own a transcript-specific resolver/filter. |
| `datamachine_conversation_store` filter | `ConversationStoreFactory::get()` | Existing Data Machine aggregate store swap seam. | Keep while code lives in Data Machine. A future Agents API filter should not force chat UI/listing/read-state/reporting responsibilities onto transcript-only backends. |
| `agents_api_conversation_runner` filter | `AIConversationLoop::run()` | Runner replacement seam is generic. | Renamed in place from `datamachine_conversation_runner`; do not mirror the old hook under a runtime alias. |
| `datamachine_guideline_updated` action | `GuidelineAgentMemoryStore` | Logical memory/guideline change event is generic. | Target event must not assume Data Machine option names or storage. |
| `wp_register_agent()` helper | `agents-api/inc/register-agents.php` | Declarative agent registration is core-shaped. | Public helper contributes definitions only; persistence reconciliation is not part of the helper contract. |
| `wp_agents_api_init` action | `agents-api/inc/class-wp-agents-registry.php` | Registration collection hook is generic. | Keep as the in-place Agents API-shaped hook while Data Machine hosts the substrate. |
| `datamachine_registered_agent_reconciled` action | `AgentRegistry::reconcile()` | Useful lifecycle event, but current name includes persistence behavior. | Public API should define lifecycle events separately from Data Machine DB reconciliation. |

## Agents API Implementation Candidate

These are plausibly generic implementations, but should not move until naming and Data Machine assumptions are removed.

| Surface | Current location | Why it is not public-ready yet | Extraction direction |
|---|---|---|---|
| `AIConversationLoop` | `inc/Engine/AI/AIConversationLoop.php` | Name says AI and still carries the compatibility facade/result shape, but handler completion and transcript persistence now route through runtime collaborators. | Keep shrinking the compatibility adapter by extracting provider request assembly and Data Machine logging policy next. |
| `ProviderRequestAssembler` | `inc/Engine/AI/ProviderRequestAssembler.php` | Normalizes messages, tools, model, and caller-selected directives without dispatching, logging, or discovering Data Machine directives. | Good in-place request assembly candidate once prompt/directive vocabulary is settled. |
| `RequestBuilder` | `inc/Engine/AI/RequestBuilder.php` | Data Machine adapter around provider assembly: discovers/directive-policies `datamachine_directives`, emits `datamachine_log`, applies request-size guardrails, and still carries Data Machine request-array compatibility. | Keep as Data Machine adapter while those product concerns remain. Provider dispatch should consume `wp-ai-client` directly. |
| `WpAiClientAdapter` | `inc/Engine/AI/WpAiClientAdapter.php` | Data Machine adapter that maps the assembled provider request array onto the wp-ai-client public API and normalizes the result back to Data Machine's historical response array. | Keep in Data Machine until request/message contracts are generic enough for an Agents API provider runtime implementation. |
| `RequestMetadata` | `inc/Engine/AI/RequestMetadata.php` | Generic inspection/size metadata. | Move after field names are checked against Agents API message/tool vocabulary. |
| `RequestInspector` | `inc/Engine/AI/RequestInspector.php` | Generic debugging/inspection value, likely useful across runtimes. | Rename away from Data Machine only if public debug surface is desired. |
| `PromptBuilder` | `inc/Engine/AI/PromptBuilder.php` | Generic system-message composition engine, but wired to Data Machine directives. | Extract lower-level composer after directive contract is settled. |
| `DirectiveInterface` | `inc/Engine/AI/Directives/DirectiveInterface.php` | Generic system prompt directive contract. | Rename around guidelines/context providers; remove provider/step_id coupling if too narrow. |
| `DirectiveRenderer` | `inc/Engine/AI/Directives/DirectiveRenderer.php` | Generic renderer for directive outputs. | Candidate implementation after output shape is stabilized. |
| `DirectiveOutputValidator` | `inc/Engine/AI/Directives/DirectiveOutputValidator.php` | Generic shape validation. | Candidate implementation after naming cleanup. |
| `DirectivePolicyResolver` | `inc/Engine/AI/Directives/DirectivePolicyResolver.php` | Generic allow/deny policy idea, current inputs include Data Machine modes/agent config. | Extract after mode and agent policy contracts move. |
| `MemoryFilesReader` | `inc/Engine/AI/Directives/MemoryFilesReader.php` | Generic memory-to-prompt reader. | Move only after memory registry/store vocabulary is generic. |
| `CoreMemoryFilesDirective` | `inc/Engine/AI/Directives/CoreMemoryFilesDirective.php` | Generic default memory injection, but file names and layers are Data Machine conventions today. | Needs Agents API memory/guideline convention decision. |
| `AgentModeDirective` | `inc/Engine/AI/Directives/AgentModeDirective.php` | Generic mode-context directive idea. | Needs generic agent-mode vocabulary or stays Data Machine. |
| `ClientContextDirective` | `inc/Engine/AI/Directives/ClientContextDirective.php` | Generic client-provided context injection. | Candidate if sanitized context contract is public. |
| `CallerContextDirective` | `inc/Engine/AI/Directives/CallerContextDirective.php` | Generic caller metadata injection. | Candidate if caller context becomes part of Agents API run input. |
| `ConversationManager` | `inc/Engine/AI/ConversationManager.php` | Formats tool call/result messages and conversation artifacts. | Split message formatting helpers from Data Machine transcript details. |
| `ToolExecutor` | `inc/Engine/AI/Tools/ToolExecutor.php` | Executes ability-native and legacy tools with policy staging. | Extract only the ability-native execution path; leave Data Machine post tracking and pending-action glue behind. |
| `RuntimeToolDeclaration` validators in tests | `tests/runtime-tool-declaration-smoke.php` | Tests generic declaration shape. | Move with the declaration contract. |
| `ToolPolicyFilter` | `inc/Engine/AI/Tools/Policy/ToolPolicyFilter.php` | Generic allow/deny/category/capability filter that takes adapter callbacks for access and mandatory-tool preservation. | Move only with a generic access callback contract; Data Machine permission and handler preservation stay in adapters. |
| `ToolSourceRegistry` | `inc/Engine/AI/Tools/ToolSourceRegistry.php` | Source-provider composition is generic, but the default providers are Data Machine adapters. | Extract the source registry contract separately from `DataMachineToolRegistrySource` and `AdjacentHandlerToolSource`. |
| `DataMachineToolRegistrySource` | `inc/Engine/AI/Tools/Sources/DataMachineToolRegistrySource.php` | Adapts Data Machine's legacy/product `datamachine_tools` registry into source-provider composition. | Keep in Data Machine; Ability-native tool declarations should inform Agents API instead. |
| `ToolManager` | `inc/Engine/AI/Tools/ToolManager.php` | Data Machine registry/normalization is based on `datamachine_tools`, legacy class/method tools, handler wrappers, configuration, and UI status. | Keep as Data Machine adapter/product layer; do not make it the public Agents API registry. |
| `ToolParameters` | `inc/Engine/AI/Tools/ToolParameters.php` | Parameter merge helper is useful, but payload includes job/flow/packet fields. | Keep generic parameter validation; move Data Machine payload merge rules to adapter. |
| `ToolResultFinder` | `inc/Engine/AI/Tools/ToolResultFinder.php` | Generic enough if it only finds tool result envelopes. | Verify it does not rely on handler result naming before moving. |
| `BaseTool` | `inc/Engine/AI/Tools/BaseTool.php` | Useful base class for built-in tools, but public API should favor abilities. | Do not make base-tool inheritance the primary Agents API extension point. |
| `GuidelineAgentMemoryStore` | `inc/Core/FilesRepository/GuidelineAgentMemoryStore.php` | Generic implementation for `wp_guideline`, but Data Machine does not own that substrate. | Agents API can ship it as optional implementation guarded by `post_type_exists()`. |
| `DiskAgentMemoryStore` | `inc/Core/FilesRepository/DiskAgentMemoryStore.php` | Generic self-hosted implementation, but path conventions are Data Machine runtime conventions. | Extract only if Agents API deliberately supports disk memory. |
| `AgentMemoryStoreFactory` | `inc/Core/FilesRepository/AgentMemoryStoreFactory.php` | Resolver is still Data Machine-owned because its behavior-preserving default constructs `DiskAgentMemoryStore`. | Preserve `agents_api_memory_store` as the active hook; move a factory later only after the default-store provider is dependency-clean. |
| `WP_Agent` / `WP_Agents_Registry` / `AgentRegistry` | `agents-api/inc/class-wp-agent.php`, `agents-api/inc/class-wp-agents-registry.php`, `inc/Engine/Agents/AgentRegistry.php` | Generic declarative registry now has WordPress-shaped facade; Data Machine reconciliation is delegated to `AgentMaterializer`. | Extract facade/registry contract first; keep Data Machine materializer as consumer. |
| `Agents`, `AgentAccess`, `AgentTokens` repositories | `inc/Core/Database/Agents/` | Generic identity/access/token data model, but table names and permissions are Data Machine-owned. | Extract only after deciding whether Agents API owns persistence tables or just contracts. |
| `AgentAbilities`, `AgentTokenAbilities`, `AgentRemoteCallAbilities`, `AgentCallAbilities` | `inc/Abilities/` | Ability shapes are generic candidates, but slugs and permission helpers are Data Machine-specific. | Re-register as `wp-agents/v1`/Agents API abilities after permission model settles. |

## Data Machine Adapter

These should stay in Data Machine as compatibility glue if a generic runtime plugin appears.

| Surface | Current location | Adapter responsibility |
|---|---|---|
| `AIStep` | `inc/Core/Steps/AI/AIStep.php` | Converts flow-step config, data packets, queue prompt head, image engine data, adjacent steps, job snapshot, and transcript policy into a runtime run. |
| `PipelineToolPolicyArgs` | `inc/Core/Steps/AI/ToolPolicy/PipelineToolPolicyArgs.php` | Translates `FlowStepConfig` enabled/disabled tool fields into generic resolver args. This is a Data Machine pipeline adapter. |
| `ToolPolicyResolver` | `inc/Engine/AI/Tools/ToolPolicyResolver.php` | Orchestrates Data Machine source gathering, persisted agent policy lookup, mandatory adjacent-handler preservation, and permission adapters around the generic policy filter. |
| `PipelineTranscriptPolicy` | `inc/Engine/AI/PipelineTranscriptPolicy.php` | Reads flow/pipeline config and site option to decide transcript persistence. Generic runtime receives the normalized decision through `DataMachinePipelineTranscriptPersister`. |
| `ToolSourceRegistry::SOURCE_ADJACENT_HANDLERS` | `inc/Engine/AI/Tools/ToolSourceRegistry.php` | Data Machine-specific source that exposes publish/upsert handler tools next to AI steps. |
| `ToolSourceRegistry::SOURCE_STATIC_REGISTRY` / `DataMachineToolRegistrySource` | `inc/Engine/AI/Tools/Sources/DataMachineToolRegistrySource.php` | Data Machine-specific source that adapts the curated `datamachine_tools` registry into runtime tool resolution. |
| `FlowStepConfig::getAdjacentRequiredHandlerSlugsForAi()` consumers | `AIStep` and tool policy code | Converts pipeline topology into handler completion requirements. |
| `QueueableTrait` prompt consumption in `AIStep` | `inc/Core/Steps/AI/AIStep.php` | Data Machine flow queue semantics (`static`, `drain`, `loop`) feeding a runtime user-message slot. |
| `DataMachinePipelineTranscriptPersister` | `inc/Engine/AI/DataMachinePipelineTranscriptPersister.php` | Adapts a pipeline job run to the transcript store. Generic runtime calls the transcript persister contract and does not own job metadata. |
| `DataMachineHandlerCompletionPolicy` | `inc/Engine/AI/DataMachineHandlerCompletionPolicy.php` | Adapts adjacent-handler completion rules into a runtime completion policy. Generic runtime calls the policy contract and does not own pipeline handler semantics. |
| `SystemAgentServiceProvider` task registration | `inc/Engine/AI/System/SystemAgentServiceProvider.php` | Registers Data Machine system tasks into Data Machine scheduling. The generic runtime may supply a task interface, not these tasks. |
| `AgentCallTask` | `inc/Engine/AI/System/Tasks/AgentCallTask.php` | Bridges scheduled/system tasks into the agent-call primitive. |
| `AgentBundler` and bundle CLI adapters | `inc/Core/Agents/AgentBundler.php`, `inc/Cli/Commands/AgentBundleCommand.php` | Convert Data Machine pipelines/flows into portable agent bundle artifacts. Bundle primitives may split, but flow/pipeline import/export remains adapter/product. |
| `Api\Agents`, `Api\AgentFiles`, `Api\AgentPing` | `inc/Api/` | Current REST routes are Data Machine API shape. They can adapt to future `wp-agents/v1` contracts. |
| `AgentsCommand`, `MemoryCommand` | `inc/Cli/Commands/` | Operator CLI wrapping current Data Machine repositories and abilities. Generic WP-CLI commands should be designed separately. |

## Conversation Storage Boundary

Conversation storage is split in place, but only the narrow transcript surface is ready to treat as a generic Agents API candidate.

| Layer | Current surface | Boundary decision |
|---|---|---|
| Generic transcript CRUD | `ConversationTranscriptStoreInterface`, `ConversationStoreFactory::get_transcript_store()` | The interface now lives in `agents-api/`; Data Machine's factory remains the product adapter. Runtime persistence should depend on this surface when it only needs complete transcript sessions. |
| Data Machine compatibility aggregate | `ConversationStoreInterface`, `ConversationStoreFactory::get()`, `datamachine_conversation_store` | Stays in Data Machine for now so chat UI, REST, CLI, retention, and reporting keep one behavior-preserving resolver. |
| Chat UI/session switcher | `ConversationSessionIndexInterface`, chat REST/abilities/UI callers | Product behavior today. It may become an optional Agents API UI contract later, but transcript-only backends should not implement it by default. |
| Read state | `ConversationReadStateInterface` | Optional UI behavior. Not part of transcript persistence. |
| Retention | `ConversationRetentionInterface`, retention system tasks/CLI | Backend cleanup methods may be generic, but scheduling and retention policy are Data Machine product. |
| Reporting | `ConversationReportingInterface`, daily memory/retention status readers | Product-shaped metrics today. Keep separate from transcript CRUD. |

Do not decide an `agents_session` CPT, host-specific conversation storage, or a new Agents API filter name in this in-place clarification. The current goal is only to make the dependency direction obvious: Data Machine chat product consumes transcript persistence; transcript persistence does not require Data Machine chat product behavior.

## Data Machine Product

These should stay in Data Machine. They may consume Agents API later, but should not move into it.

| Surface | Current location | Why it stays |
|---|---|---|
| Flow and pipeline step system | `inc/Core/Steps/**`, `inc/Engine/Actions/**` | This is Data Machine's automation engine. |
| `AIStep` product behavior | `inc/Core/Steps/AI/AIStep.php` | The step is a Data Machine pipeline step even if it calls a generic runner. |
| Fetch, publish, upsert, webhook gate, system task, agent ping step types | `inc/Core/Steps/**`, `inc/Engine/AI/System/**` | Product workflow primitives, not generic agent runtime. |
| Handler registration and handler tools | `datamachine_handlers`, `datamachine_tools` handler callbacks | Data Machine source/destination plugin model. |
| Jobs and parent/child orchestration | `inc/Core/Database/Jobs/**`, job abilities/commands | Data Machine execution tracking. |
| Queue modes and config patch queues | flow queue abilities, `QueueableTrait` | Data Machine scheduling/backfill behavior. |
| Retention tasks | `inc/Engine/AI/System/Tasks/Retention/**`, `RetentionCommand` | Product cleanup for Data Machine tables/files/Action Scheduler state. |
| Content operations | post, taxonomy, block, alt text, meta description, image, link, IndexNow abilities | Data Machine content automation. |
| Pending actions store and approval workflows | `inc/Engine/AI/Actions/**` | Generic approval may exist later, but current implementation is product storage/policy. |
| Admin UI and React pipeline editor | `src/`, `inc/Core/Admin/**` | Data Machine product UI. |
| Global tools for site ops | `WebFetch`, `WordPressPostReader`, analytics/search console/page speed/image tools | Some tools can become abilities, but the curated Data Machine tool bundle is product. |
| Agent memory CLI command behavior | `MemoryCommand` | Product/operator surface; generic API should define contracts first. |

## Intelligence Domain

Data Machine should not absorb Intelligence-specific vocabulary during extraction.

| Surface | Owner | Notes |
|---|---|---|
| Wiki create/read/update/maintain behavior | Intelligence | If exposed through Data Machine today, treat it as a consumer/domain tool, not runtime substrate. |
| Briefings and digests | Intelligence | Domain workflows built on top of runtime and search abilities. |
| Domain brains and generated/shared wikis | Intelligence | Product/domain policy, not Agents API. |
| Intelligence memory policy additions | Intelligence | May consume generic memory contracts, but policy names and wiki roots stay outside Agents API. |

## Host-Specific Source Material

These are reference points only. Do not expose them as public Data Machine or Agents API vocabulary.

| Source | How to use it |
|---|---|
| Host message DTOs | Reference for message object semantics. Normalize behind `WP_Agent_Message` or neutral envelopes. |
| Host agent runtime classes | Reference for run-loop integration and provider routing. Do not require inheritance from host runtime classes. |
| Host agent stores | Reference for persistence/adoption semantics. Do not leak storage names into public API. |
| Host conversation storage | Reference for compaction/resilience. Keep Data Machine/Agents API conversation store contracts portable and site-owned unless explicitly swapped. |
| Host agent UX precedents | Reference for WordPress-hosted agent UX and memory injection, not a dependency or target vocabulary. |

## Hook Name Map

This map records the in-place hook vocabulary while Data Machine still hosts the
Agents API substrate. Generic runtime seams should use Agents API-shaped names;
Data Machine product and compatibility seams keep Data Machine names.
Hard-cut hook renames assume active cross-repo consumers are updated in lockstep;
this slice's known companion is the Intelligence tier-3 runner adapter update in
Automattic/intelligence#285.

| Previous hook/filter | Current hook/filter | Decision |
|---|---|---|
| `datamachine_conversation_runner` | `agents_api_conversation_runner` | Hard-cut rename. Generic runtime replacement seam. |
| `datamachine_tool_sources` | `agents_api_tool_sources` | Hard-cut rename. Generic source-provider composition seam; Data Machine sources remain providers. |
| `datamachine_tool_sources_for_mode` | `agents_api_tool_sources_for_mode` | Hard-cut rename. Generic mode-to-source ordering seam. |
| `datamachine_memory_store` | `agents_api_memory_store` | Already renamed in place; old hook is intentionally not mirrored. |
| `wp_agents_api_init` | `wp_agents_api_init` | Already WordPress-shaped registration hook. |
| `datamachine_conversation_store` | `datamachine_conversation_store` | Keep. This swaps Data Machine's aggregate chat store; a future Agents API transcript hook must be narrower. |
| `datamachine_guideline_updated` | `datamachine_guideline_updated` | Keep for this slice. Event payload and external projection consumers need a narrower Agents API memory-event decision. |
| `datamachine_registered_agent_reconciled` | `datamachine_registered_agent_reconciled` | Keep. This is Data Machine materialization/persistence lifecycle, not pure declarative registration. |
| `datamachine_tools` | `datamachine_tools` | Keep. Legacy/product tool registry; Agents API should prefer ability-native runtime declarations. |

## Hook And Filter Classification

| Hook/filter | Bucket | Notes |
|---|---|---|
| `agents_api_conversation_runner` | Agents API public candidate | Generic runtime replacement seam. Renamed in place from `datamachine_conversation_runner`. |
| `datamachine_conversation_store` | Data Machine compatibility seam today | Existing aggregate store swap seam. A future Agents API transcript-store filter should be narrower instead of carrying Data Machine chat product responsibilities. |
| `wp_agents_api_init` | Agents API public candidate | Registration hook is now WordPress-shaped in-place; Data Machine still fires the legacy hook while it hosts the substrate. |
| `agents_api_memory_store` | Agents API public candidate | Generic memory persistence swap seam. Renamed in place from `datamachine_memory_store`; do not mirror the old hook under a runtime alias. |
| `datamachine_registered_agent_reconciled` | Agents API implementation candidate | Lifecycle event is useful, current reconciliation semantics are Data Machine implementation. |
| `datamachine_guideline_updated` | Agents API public candidate | Generic memory/guideline change event after naming review. |
| `agents_api_tool_sources` | Agents API implementation candidate | Generic source-provider idea; current defaults include Data Machine adjacent handlers as Data Machine providers. |
| `agents_api_tool_sources_for_mode` | Agents API implementation candidate | Generic mode policy idea; mode names need Agents API contract. |
| `datamachine_tools` | Data Machine product today | Current registry includes legacy Data Machine handler/class shapes. Ability-native subset can inform Agents API. |
| `datamachine_directives` | Agents API implementation candidate | Generic prompt/guideline provider idea, but current directive classes use Data Machine modes. |
| `datamachine_pre_ai_step_check` | Data Machine adapter | Pipeline AI-step skip hook. |
| `datamachine_log` | Data Machine product | Product logging surface. Generic runtime events should use loop event sinks. |
| `chubes_ai_request` | Legacy provider bridge to delete | Legacy `ai-http-client` provider-dispatch filter. Do not carry this into Agents API public vocabulary or architecture; removal belongs to #1027 / #1633. |
| `WpAiClientCapability::unavailableReason()` | Agents API implementation candidate | Single wp-ai-client runtime capability gate. Missing support is surfaced as a request error; no `ai-http-client` fallback belongs in Agents API. |

## Test Coverage Map

These tests currently pin the substrate most relevant to extraction.

| Test | Covers | Extraction signal |
|---|---|---|
| `tests/ai-message-envelope-smoke.php` | Agent message envelope normalization/projection and result validation. | Move with message/result contracts. |
| `tests/agent-conversation-result-smoke.php` | Conversation result shape validation. | Move with runner result contract. |
| `tests/agent-conversation-runtime-policy-smoke.php` | Runtime completion and transcript collaborator seams. | Split generic policy/persister contracts from Data Machine handler/transcript adapter assertions during extraction. |
| `tests/conversation-store-contracts-smoke.php` | Split store interfaces, transcript-only method boundary, and factory return types. | Move transcript CRUD coverage with the generic contract; keep aggregate/product assertions with Data Machine. |
| `tests/guideline-agent-memory-store-smoke.php` | Optional guideline-backed memory implementation. | Move or duplicate if Agents API ships memory store implementations. |
| `tests/daily-memory-store-seam-smoke.php` | Daily memory through memory store seam. | Data Machine product consuming generic memory store. |
| `tests/agent-memory-events-smoke.php` | Memory/guideline change events. | Move event contract after naming review. |
| `tests/memory-bundle-policy-smoke.php` | Bundle-aware self-memory policy. | Mixed: memory contract candidate plus bundle/product policy. |
| `tests/tool-source-registry-smoke.php` | Tool source providers. | Split generic source registry from Data Machine adjacent-handler provider. |
| `tests/tool-executor-ability-native-smoke.php` | Ability-native tool execution. | Good Agents API implementation signal. |
| `tests/runtime-tool-declaration-smoke.php` | Runtime tool declaration validation. | Move with runtime tool declaration contract. |
| `tests/tool-policy-resolver-adjacency-smoke.php` | Pipeline adjacency and handler protection. | Data Machine adapter/product behavior. |
| `tests/pipeline-tool-policy-*.php` | Pipeline tool policy surfaces. | Data Machine adapter tests. |
| `tests/react-pipeline-tool-policy-contract-smoke.php` | React/API contract for pipeline tool policy. | Data Machine product. |
| `tests/system-task-agent-context-smoke.php` | Agent context propagation through system tasks. | Data Machine adapter/product. |
| `tests/agent-call-migration-smoke.php` | Agent-call migration. | Agent-call primitive may inform Agents API; migration stays Data Machine. |
| `tests/agent-bundle-*.php` | Bundle format, artifact store, upgrade planner, portable update. | Split pure manifest/auth/template artifacts from flow/pipeline file adapters. |
| `tests/ai-request-inspector-smoke.php` | Provider request assembly without dispatch plus Data Machine request inspection surface. | Split generic `ProviderRequestAssembler` assertions from Data Machine `RequestBuilder` directive-policy assertions during extraction. |
| `tests/wp-ai-client-runtime-gate-smoke.php` | wp-ai-client capability gate and no `chubes_ai_request` runtime fallback. | Move with provider runtime boundary if Agents API owns provider dispatch. |

## First Seams To Make Boring

1. Create `data-machine/agents-api/` as the in-repo module boundary before moving broad code into another repository.
2. Split pipeline policy translation out of `ToolPolicyResolver` so the resolver no longer imports or reads `FlowStepConfig`.
3. Split `AgentRegistry` into a pure registry and a Data Machine reconciler that creates database rows, access rows, directories, and scaffold files.
4. Rename and stabilize message/result/store interfaces in place before moving namespaces.
5. Split provider request assembly from `RequestBuilder` so Data Machine directives/logging stay adapter behavior and provider dispatch targets `wp-ai-client`, not `ai-http-client`.
6. Split `ToolExecutor` into ability-native runtime execution plus Data Machine product hooks for pending actions and post-origin tracking.
7. Decide whether Agents API owns persistence tables or only contracts plus optional stores.
8. Keep host-specific provider and storage classes behind adapters. No public contract should require implementation-specific message, agent, or conversation-storage DTOs.

## Non-Goals

- Do not move files as part of this map.
- Do not frame the next step as direct external repository extraction; the next code step is the in-repo `data-machine/agents-api/` module.
- Do not rename runtime classes before the target contracts are settled.
- Do not make Data Machine or Agents API depend on host-specific provider or storage vocabulary.
- Do not make Agents API depend on `ai-http-client`; that package is only a packaging precedent and a removal target.
- Do not move Data Machine flows, pipelines, jobs, handlers, queues, retention, content ops, or admin UI into Agents API.
- Do not move Intelligence wiki/briefing/domain-brain vocabulary into Data Machine or Agents API.
