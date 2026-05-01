# Engine AI Namespace Boundary

`DataMachine\Engine\AI` is a staging namespace while the future Agents API runtime is clarified in place. It is not itself the extraction boundary.

Layer boundaries:

| Layer | Responsibility |
|---|---|
| Abilities API | Actions and tools. |
| wp-ai-client | Direct provider/model prompt execution for one-shot AI operations. |
| Agents API | Durable agent runtime: registration, memory, transcripts, sessions, locks, event sinks, multi-turn tool loops, and portable declarations. |
| Data Machine | Automation product: flows, pipelines, jobs, handlers, queues, retention, content operations, and admin UI. |

Data Machine pipeline AI steps should not move to Agents API solely for provider dispatch. They should use the direct `wp-ai-client` path through Data Machine request assembly unless the feature needs durable agent runtime semantics.

Use this quick map before moving or renaming files:

| Area | Boundary |
|---|---|
| `AgentMessageEnvelope`, `AgentConversation*`, `LoopEventSinkInterface`, `RuntimeToolDeclaration` | Generic runtime candidates. |
| `BuiltInAgentConversationRunner`, `AIConversationLoop`, request builders, prompt/directive helpers | Implementation candidates, still carrying Data Machine compatibility seams. |
| `System\*`, `System\Tasks\*`, `System\Tasks\Retention\*` | Data Machine product automation. Do not move into Agents API. |
| `Actions\*` | Data Machine pending-action product surface. Do not move into Agents API. |
| `Tools\Global\*` | Data Machine curated site-ops/product tools. Do not treat as the public Agents API tool registry. |
| `Tools\Sources\DataMachineToolRegistrySource`, `Tools\Sources\AdjacentHandlerToolSource`, pipeline policy helpers | Data Machine adapters from flows/handlers into runtime tool inputs. |
| `Memory\*`, `MemoryFileRegistry`, `SectionRegistry`, composable file helpers | Data Machine memory policy/file-composition layer around generic memory contracts. |
| `DataMachinePipelineTranscriptPersister`, `DataMachineHandlerCompletionPolicy`, `PipelineTranscriptPolicy` | Data Machine adapters for job/pipeline metadata and adjacent-handler completion. |

Docs that own the full map:

- `docs/development/agents-api-extraction-map.md`
- `docs/development/agents-api-pre-extraction-audit.md`
