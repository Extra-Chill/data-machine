# Engine AI Runtime Boundary

`DataMachine\Engine\AI` is the Data Machine adapter layer around Agents API and wp-ai-client. It is not itself the extraction boundary.

Layer boundaries:

| Layer | Responsibility |
|---|---|
| Abilities API | Actions and tools. |
| wp-ai-client | Direct provider/model prompt execution for one-shot AI operations. |
| Agents API | Durable agent runtime: registration, memory, transcripts, sessions, locks, event sinks, multi-turn tool loops, and portable declarations. |
| Data Machine | Automation product: flows, pipelines, jobs, handlers, queues, retention, content operations, and admin UI. |

Data Machine pipeline AI steps should not move to Agents API solely for provider dispatch. They should use the direct `wp-ai-client` path through Data Machine request assembly unless the feature needs durable agent runtime semantics.

Current conversation ownership:

- `datamachine_run_conversation()` is Data Machine's public runtime entry point for chat and pipeline turns.
- `AgentsAPI\AI\WP_Agent_Conversation_Loop::run()` owns generic turn sequencing, budgets, transcripts, locks, events, and normalized result fields.
- Data Machine's turn runner closure owns `RequestBuilder::build()`, wp-ai-client dispatch, `ToolExecutor::executeTool()`, tool runtime rules, completion assertions, job artifact summaries, and product logging.
- `RequestBuilder::build()` returns `WordPress\AiClient\Results\DTO\GenerativeAiResult` or `WP_Error`; old `success/data/error` arrays are test compatibility inputs only through `datamachine_wp_ai_client_text_result`.
- Message storage uses `AgentsAPI\AI\WP_Agent_Message` envelopes. Provider-specific shapes are projections at the wp-ai-client boundary.

Use this quick map before moving or renaming files:

| Area | Boundary |
|---|---|
| `WP_Agent_Message`, `WP_Agent_Conversation_Loop`, `WP_Agent_Conversation_Result`, `WP_Agent_Conversation_Request`, `WP_Agent_Tool_Declaration` | Agents API substrate. |
| `datamachine_run_conversation()`, `datamachine_build_turn_runner()`, `RequestBuilder`, prompt/directive helpers, completion assertions, Data Machine transcript/job adapters | Data Machine adapters and product policy. |
| `System\*`, `System\Tasks\*`, `System\Tasks\Retention\*` | Data Machine product automation. Do not move into Agents API. |
| `Actions\*` | Data Machine pending-action product surface. Do not move into Agents API. |
| `Tools\Global\*` | Data Machine curated site-ops/product tools. Do not treat as the public Agents API tool registry. |
| `Tools\Sources\DataMachineToolRegistrySource`, `Tools\Sources\AdjacentHandlerToolSource`, pipeline policy helpers | Data Machine adapters from flows/handlers into runtime tool inputs. |
| `Memory\*`, `MemoryFileRegistry`, `SectionRegistry`, composable file helpers | Data Machine memory policy/file-composition layer around generic memory contracts. |
| `DataMachinePipelineTranscriptPersister`, `DataMachineHandlerCompletionPolicy`, `PipelineTranscriptPolicy` | Data Machine adapters for job/pipeline metadata and adjacent-handler completion. |

Docs that own the full map:

- `docs/development/agents-api-extraction-map.md`
- `docs/development/agents-api-pre-extraction-audit.md`
- `docs/core-system/ai-conversation-loop.md`
- `docs/core-system/request-builder.md`
- `docs/core-system/ai-message-envelope.md`
