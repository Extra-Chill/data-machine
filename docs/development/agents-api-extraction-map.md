# Agents API Extraction Map

This map classifies Data Machine's current agent/runtime surface for a possible future `agents-api` split. It is an extraction guide, not a migration plan. The immediate goal is to make the current boundary visible before moving code.

Parent issue: [Explore splitting Agents API out of Data Machine](https://github.com/Extra-Chill/data-machine/issues/1561)

## Target Vocabulary

Mirror the WordPress Abilities API shape instead of importing Data Machine, wpcom, or Automattic AI Framework names into the public contract.

| Current Data Machine surface | Possible Agents API vocabulary | Notes |
|---|---|---|
| `datamachine_register_agent()` | `wp_register_agent()` | Same declarative pattern, but without DB reconciliation side effects in the public helper. |
| `AgentRegistry` | `WP_Agents_Registry` | Registry should collect definitions. Persistence/adoption can remain adapter territory. |
| `datamachine_register_agents` | `wp_agents_api_init` or `wp_register_agents` | Prefer a core-shaped init hook; exact name needs review against Abilities API precedent. |
| `MessageEnvelope` | `WP_Agent_Message` or neutral envelope | Contract is generic. Data Machine schema/name is not. |
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
- If it uses wpcom or Automattic AI Framework vocabulary directly, treat that code as source material only until normalized behind WordPress-shaped contracts.

## Bucket Summary

| Bucket | Meaning | Current examples |
|---|---|---|
| Agents API public candidate | Generic WordPress-shaped contract or value object. | Message envelopes, transcript store interface, memory store interface, runtime tool declaration validation, agent registration vocabulary. |
| Agents API implementation candidate | Generic implementation, but naming or assumptions need cleanup first. | Built-in loop, request assembly, tool executor, guideline memory store, directive renderer. |
| Data Machine adapter | Glue that turns flows/jobs/pipelines into generic runtime inputs. | `AIStep`, pipeline tool-policy args, transcript persistence policy, adjacent handler tools. |
| Data Machine product | Data Machine automation/product layer. | Jobs, flows, pipelines, handlers, queues, retention, content abilities, admin UI. |
| Intelligence domain | Intelligence plugin concerns, not Data Machine or Agents API. | Wiki, briefings, digests, domain brains. |
| wpcom source material | Useful precedent only. | `\WPCOM\AI\Message`, `\Agent`, `\AgentsStore`, `Conversation_Storage`. |

## Agents API Public Candidate

These are closest to generic public contracts. Most should be extracted as contracts/value objects before services.

| Surface | Current location | Why it fits | Target notes |
|---|---|---|---|
| `MessageEnvelope` | `inc/Engine/AI/MessageEnvelope.php` | JSON-friendly canonical message envelope independent of flows/jobs. | Rename schema away from `datamachine.ai.message`; review whether public class is `WP_Agent_Message` or a neutral envelope helper. |
| `AgentConversationResult` | `inc/Engine/AI/AgentConversationResult.php` | Validates result arrays from any runtime runner. | Rename to `WP_Agent_Run_Result` or split into result value object plus validator. |
| `LoopEventSinkInterface` | `inc/Engine/AI/LoopEventSinkInterface.php` | Transport-neutral event sink for logs, streaming, CLI, REST, or chat UIs. | Make event vocabulary public and provider-neutral before extraction. |
| `NullLoopEventSink` | `inc/Engine/AI/NullLoopEventSink.php` | Generic no-op implementation for optional event sinks. | Implementation can move with the interface. |
| `RuntimeToolDeclaration` | `inc/Engine/AI/Tools/RuntimeToolDeclaration.php` | Validates run-scoped client/runtime tool declarations without Data Machine state. | Rename around `WP_Agent_Tool_Declaration`; keep executor/source/scope vocabulary generic. |
| `AgentMemoryStoreInterface` | `inc/Core/FilesRepository/AgentMemoryStoreInterface.php` | Generic memory persistence seam. | Rename `AgentMemoryScope` tuple fields only if needed; keep CAS/hash behavior. |
| `AgentMemoryScope` | `inc/Core/FilesRepository/AgentMemoryScope.php` | Encodes memory identity independently of disk/database implementations. | Review `layer`, `user_id`, `agent_id`, `filename` as the public model. |
| `AgentMemoryReadResult` | `inc/Core/FilesRepository/AgentMemoryReadResult.php` | Store-neutral read result. | Generic result value object can move unchanged after naming cleanup. |
| `AgentMemoryWriteResult` | `inc/Core/FilesRepository/AgentMemoryWriteResult.php` | Store-neutral write result with hash/bytes/error shape. | Generic result value object can move unchanged after naming cleanup. |
| `AgentMemoryListEntry` | `inc/Core/FilesRepository/AgentMemoryListEntry.php` | Store-neutral list entry. | Good candidate if file-backed memory stays in scope. |
| `ConversationTranscriptStoreInterface` | `inc/Core/Database/Chat/ConversationTranscriptStoreInterface.php` | Transcript CRUD is generic conversation persistence. | First extraction candidate. Rename namespace/vocabulary later; do not require chat UI listing/read-state/reporting for transcript-only backends. |
| `ConversationSessionIndexInterface` | `inc/Core/Database/Chat/ConversationSessionIndexInterface.php` | Session listing can be generic for UIs, but it is not required for transcript persistence. | Treat as optional until Agents API adopts an identity/listing model. Data Machine chat switcher uses it today. |
| `ConversationReadStateInterface` | `inc/Core/Database/Chat/ConversationReadStateInterface.php` | Read-state is generic UI behavior, not transcript CRUD. | Optional interface at most. Data Machine chat unread state keeps consuming it. |
| `ConversationRetentionInterface` | `inc/Core/Database/Chat/ConversationRetentionInterface.php` | Cleanup methods can be backend-generic, but retention policy/scheduling is product behavior. | Data Machine retention tasks stay product; future Agents API may expose only optional backend cleanup. |
| `ConversationReportingInterface` | `inc/Core/Database/Chat/ConversationReportingInterface.php` | Metrics/reporting reads are useful but product-shaped today. | Optional interface at most. Data Machine daily memory and retention CLI keep consuming it. |
| `ConversationStoreInterface` | `inc/Core/Database/Chat/ConversationStoreInterface.php` | Aggregate Data Machine chat-product compatibility contract. | Do not extract as the default public contract unless Agents API deliberately wants the full aggregate. Prefer the transcript interface first. |
| `ConversationStoreFactory::get_transcript_store()` | `inc/Core/Database/Chat/ConversationStoreFactory.php` | Narrow resolver for runtime transcript persistence. | Current implementation reuses the Data Machine aggregate filter for compatibility; future Agents API can own a transcript-specific resolver/filter. |
| `datamachine_conversation_store` filter | `ConversationStoreFactory::get()` | Existing Data Machine aggregate store swap seam. | Keep while code lives in Data Machine. A future Agents API filter should not force chat UI/listing/read-state/reporting responsibilities onto transcript-only backends. |
| `datamachine_conversation_runner` filter | `AIConversationLoop::run()` | Runner replacement seam is generic. | Target should be a runtime runner interface/filter, not Data Machine named. |
| `datamachine_guideline_updated` action | `GuidelineAgentMemoryStore` | Logical memory/guideline change event is generic. | Target event must not assume Data Machine option names or storage. |
| `datamachine_register_agent()` helper | `inc/Engine/Agents/register-agents.php` | Declarative agent registration is core-shaped. | Public helper should become `wp_register_agent()`; persistence reconciliation should not be part of the helper contract. |
| `datamachine_register_agents` action | `inc/Engine/Agents/register-agents.php` | Registration collection hook is generic. | Rename to `wp_agents_api_init` or a core-reviewed equivalent. |
| `datamachine_registered_agent_reconciled` action | `AgentRegistry::reconcile()` | Useful lifecycle event, but current name includes persistence behavior. | Public API should define lifecycle events separately from Data Machine DB reconciliation. |

## Agents API Implementation Candidate

These are plausibly generic implementations, but should not move until naming and Data Machine assumptions are removed.

| Surface | Current location | Why it is not public-ready yet | Extraction direction |
|---|---|---|---|
| `AIConversationLoop` | `inc/Engine/AI/AIConversationLoop.php` | Name says AI, result shape and payload include Data Machine job/flow context, and built-in completion behavior knows handler tools. | Split generic turn loop from Data Machine completion policy and pipeline handler tracking. |
| `RequestBuilder` | `inc/Engine/AI/RequestBuilder.php` | Mostly generic request assembly, but dispatch falls back to `chubes_ai_request` and applies Data Machine directives. | Extract assembler separately from provider dispatch and Data Machine directive policy. |
| `WpAiClientAdapter` | `inc/Engine/AI/WpAiClientAdapter.php` | Generic bridge to WordPress AI client, but currently lives as Data Machine implementation detail. | Good implementation candidate once request/message contracts are generic. |
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
| `ToolSourceRegistry` | `inc/Engine/AI/Tools/ToolSourceRegistry.php` | Source-provider idea is generic, but adjacent handler source is Data Machine-specific. | Extract source registry, leave adjacent-handler source as Data Machine provider. |
| `ToolManager` | `inc/Engine/AI/Tools/ToolManager.php` | Tool registry/normalization is generic-ish, but still based on `datamachine_tools`. | Rename registry and separate legacy class/method tools from ability-native tools. |
| `ToolParameters` | `inc/Engine/AI/Tools/ToolParameters.php` | Parameter merge helper is useful, but payload includes job/flow/packet fields. | Keep generic parameter validation; move Data Machine payload merge rules to adapter. |
| `ToolResultFinder` | `inc/Engine/AI/Tools/ToolResultFinder.php` | Generic enough if it only finds tool result envelopes. | Verify it does not rely on handler result naming before moving. |
| `BaseTool` | `inc/Engine/AI/Tools/BaseTool.php` | Useful base class for built-in tools, but public API should favor abilities. | Do not make base-tool inheritance the primary Agents API extension point. |
| `GuidelineAgentMemoryStore` | `inc/Core/FilesRepository/GuidelineAgentMemoryStore.php` | Generic implementation for `wp_guideline`, but Data Machine does not own that substrate. | Agents API can ship it as optional implementation guarded by `post_type_exists()`. |
| `DiskAgentMemoryStore` | `inc/Core/FilesRepository/DiskAgentMemoryStore.php` | Generic self-hosted implementation, but path conventions are Data Machine runtime conventions. | Extract only if Agents API deliberately supports disk memory. |
| `AgentMemoryStoreFactory` | `inc/Core/FilesRepository/AgentMemoryStoreFactory.php` | Generic store resolver pattern. | Rename filter and return type; preserve single resolution point. |
| `AgentRegistry` | `inc/Engine/Agents/AgentRegistry.php` | Generic declarative registry mixed with Data Machine DB reconciliation/scaffolding. | Split into registry contract plus Data Machine reconciler. |
| `Agents`, `AgentAccess`, `AgentTokens` repositories | `inc/Core/Database/Agents/` | Generic identity/access/token data model, but table names and permissions are Data Machine-owned. | Extract only after deciding whether Agents API owns persistence tables or just contracts. |
| `AgentAbilities`, `AgentTokenAbilities`, `AgentRemoteCallAbilities`, `AgentCallAbilities` | `inc/Abilities/` | Ability shapes are generic candidates, but slugs and permission helpers are Data Machine-specific. | Re-register as `wp-agents/v1`/Agents API abilities after permission model settles. |

## Data Machine Adapter

These should stay in Data Machine as compatibility glue if a generic runtime plugin appears.

| Surface | Current location | Adapter responsibility |
|---|---|---|
| `AIStep` | `inc/Core/Steps/AI/AIStep.php` | Converts flow-step config, data packets, queue prompt head, image engine data, adjacent steps, job snapshot, and transcript policy into a runtime run. |
| `ToolPolicyResolver::getPipelinePolicyArgs()` | `inc/Engine/AI/Tools/ToolPolicyResolver.php` | Translates `FlowStepConfig` enabled/disabled tool fields into generic resolver args. This is a prime adapter extraction seam. |
| `ToolPolicyResolver::gatherPipelineTools()` | `inc/Engine/AI/Tools/ToolPolicyResolver.php` | Knows pipeline handler/tool behavior and should not become public Agents API. |
| `PipelineTranscriptPolicy` | `inc/Engine/AI/PipelineTranscriptPolicy.php` | Reads flow/pipeline config and site option to decide transcript persistence. Generic runtime should receive an already-normalized boolean/policy. |
| `ToolSourceRegistry::SOURCE_ADJACENT_HANDLERS` | `inc/Engine/AI/Tools/ToolSourceRegistry.php` | Data Machine-specific source that exposes publish/upsert handler tools next to AI steps. |
| `FlowStepConfig::getAdjacentRequiredHandlerSlugsForAi()` consumers | `AIStep` and tool policy code | Converts pipeline topology into handler completion requirements. |
| `QueueableTrait` prompt consumption in `AIStep` | `inc/Core/Steps/AI/AIStep.php` | Data Machine flow queue semantics (`static`, `drain`, `loop`) feeding a runtime user-message slot. |
| `ConversationManager` transcript persistence calls from `AIStep` | `AIStep`/`ConversationManager` | Adapts a pipeline job run to the conversation store. Generic runtime should not know jobs. |
| `SystemAgentServiceProvider` task registration | `inc/Engine/AI/System/SystemAgentServiceProvider.php` | Registers Data Machine system tasks into Data Machine scheduling. The generic runtime may supply a task interface, not these tasks. |
| `AgentCallTask` | `inc/Engine/AI/System/Tasks/AgentCallTask.php` | Bridges scheduled/system tasks into the agent-call primitive. |
| `AgentBundler` and bundle CLI adapters | `inc/Core/Agents/AgentBundler.php`, `inc/Cli/Commands/AgentBundleCommand.php` | Convert Data Machine pipelines/flows into portable agent bundle artifacts. Bundle primitives may split, but flow/pipeline import/export remains adapter/product. |
| `Api\Agents`, `Api\AgentFiles`, `Api\AgentPing` | `inc/Api/` | Current REST routes are Data Machine API shape. They can adapt to future `wp-agents/v1` contracts. |
| `AgentsCommand`, `MemoryCommand` | `inc/Cli/Commands/` | Operator CLI wrapping current Data Machine repositories and abilities. Generic WP-CLI commands should be designed separately. |

## Conversation Storage Boundary

Conversation storage is split in place, but only the narrow transcript surface is ready to treat as a generic Agents API candidate.

| Layer | Current surface | Boundary decision |
|---|---|---|
| Generic transcript CRUD | `ConversationTranscriptStoreInterface`, `ConversationStoreFactory::get_transcript_store()` | Candidate for Agents API ownership. Runtime persistence should depend on this surface when it only needs complete transcript sessions. |
| Data Machine compatibility aggregate | `ConversationStoreInterface`, `ConversationStoreFactory::get()`, `datamachine_conversation_store` | Stays in Data Machine for now so chat UI, REST, CLI, retention, and reporting keep one behavior-preserving resolver. |
| Chat UI/session switcher | `ConversationSessionIndexInterface`, chat REST/abilities/UI callers | Product behavior today. It may become an optional Agents API UI contract later, but transcript-only backends should not implement it by default. |
| Read state | `ConversationReadStateInterface` | Optional UI behavior. Not part of transcript persistence. |
| Retention | `ConversationRetentionInterface`, retention system tasks/CLI | Backend cleanup methods may be generic, but scheduling and retention policy are Data Machine product. |
| Reporting | `ConversationReportingInterface`, daily memory/retention status readers | Product-shaped metrics today. Keep separate from transcript CRUD. |

Do not decide an `agents_session` CPT, wpcom `Conversation_Storage`, or a new Agents API filter name in this in-place clarification. The current goal is only to make the dependency direction obvious: Data Machine chat product consumes transcript persistence; transcript persistence does not require Data Machine chat product behavior.

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

## wpcom Source Material

These are reference points only. Do not expose them as public Data Machine or Agents API vocabulary.

| Source | How to use it |
|---|---|
| `\WPCOM\AI\Message` | Reference for message object semantics. Normalize behind `WP_Agent_Message` or neutral envelopes. |
| `\Agent` / AI Framework agent classes | Reference for run-loop integration and provider routing. Do not require inheritance from wpcom classes. |
| `\AgentsStore` | Reference for persistence/adoption semantics. Do not leak storage names into public API. |
| `Conversation_Storage` | Reference for compaction/resilience. Keep Data Machine/Agents API conversation store contracts portable and site-owned unless explicitly swapped. |
| Dolly agent architecture | Reference for WordPress-hosted agent UX and memory injection, not a dependency or target vocabulary. |

## Hook And Filter Classification

| Hook/filter | Bucket | Notes |
|---|---|---|
| `datamachine_conversation_runner` | Agents API public candidate | Generic runtime replacement seam. Rename and formalize result contract. |
| `datamachine_conversation_store` | Data Machine compatibility seam today | Existing aggregate store swap seam. A future Agents API transcript-store filter should be narrower instead of carrying Data Machine chat product responsibilities. |
| `datamachine_memory_store` | Agents API public candidate | Generic memory persistence swap seam. Keep as Data Machine's current public behavior until extraction; introduce a neutral Agents API filter only when that package owns the resolver and migration path. |
| `datamachine_register_agents` | Agents API public candidate | Registration hook should become WordPress-shaped. |
| `datamachine_registered_agent_reconciled` | Agents API implementation candidate | Lifecycle event is useful, current reconciliation semantics are Data Machine implementation. |
| `datamachine_guideline_updated` | Agents API public candidate | Generic memory/guideline change event after naming review. |
| `datamachine_tool_sources` | Agents API implementation candidate | Generic source-provider idea; current defaults include Data Machine adjacent handlers. |
| `datamachine_tool_sources_for_mode` | Agents API implementation candidate | Generic mode policy idea; mode names need Agents API contract. |
| `datamachine_tools` | Data Machine product today | Current registry includes legacy Data Machine handler/class shapes. Ability-native subset can inform Agents API. |
| `datamachine_directives` | Agents API implementation candidate | Generic prompt/guideline provider idea, but current directive classes use Data Machine modes. |
| `datamachine_pre_ai_step_check` | Data Machine adapter | Pipeline AI-step skip hook. |
| `datamachine_log` | Data Machine product | Product logging surface. Generic runtime events should use loop event sinks. |
| `chubes_ai_request` | wpcom/source-material adjacent legacy provider bridge | Legacy provider-dispatch filter. Do not carry this into Agents API public vocabulary. |
| `wp_ai_client` feature detection through `WpAiClientAdapter` | Agents API implementation candidate | WordPress AI client routing is useful, but should be normalized behind Agents API contracts. |

## Test Coverage Map

These tests currently pin the substrate most relevant to extraction.

| Test | Covers | Extraction signal |
|---|---|---|
| `tests/ai-message-envelope-smoke.php` | Message envelope normalization/projection and result validation. | Move with message/result contracts. |
| `tests/agent-conversation-result-smoke.php` | Conversation result shape validation. | Move with runner result contract. |
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

## First Seams To Make Boring

1. Split pipeline policy translation out of `ToolPolicyResolver` so the resolver no longer imports or reads `FlowStepConfig`.
2. Split `AgentRegistry` into a pure registry and a Data Machine reconciler that creates database rows, access rows, directories, and scaffold files.
3. Split `AIConversationLoop` completion policy so handler-tool completion is injected by Data Machine rather than built into a generic loop.
4. Rename and stabilize message/result/store interfaces in place before moving namespaces.
5. Split `ToolExecutor` into ability-native runtime execution plus Data Machine product hooks for pending actions and post-origin tracking.
6. Decide whether Agents API owns persistence tables or only contracts plus optional stores.
7. Keep wpcom/AI Framework classes behind adapters. No public contract should require `\WPCOM\AI\Message`, `\Agent`, `\AgentsStore`, or `Conversation_Storage`.

## Non-Goals

- Do not move files as part of this map.
- Do not rename runtime classes before the target contracts are settled.
- Do not make Data Machine depend on wpcom or Automattic AI Framework vocabulary.
- Do not move Data Machine flows, pipelines, jobs, handlers, queues, retention, content ops, or admin UI into Agents API.
- Do not move Intelligence wiki/briefing/domain-brain vocabulary into Data Machine or Agents API.
