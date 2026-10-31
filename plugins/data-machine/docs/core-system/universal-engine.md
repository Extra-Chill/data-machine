# AI Runtime Adapter Layer

Data Machine's AI runtime adapts Agents API and wp-ai-client to pipeline, chat, tool, packet, and job semantics. It is part of the Data Machine product runtime, not a separate workflow engine.

## Current Boundary

| Layer | Responsibility |
|---|---|
| WordPress Abilities API | Reusable business operations exposed as `datamachine/*` abilities. |
| wp-ai-client | Provider/model execution for a single AI request. |
| Agents API | Generic durable conversation sequencing, transcripts, locks, events, iteration budgets, messages, and portable declarations. |
| Data Machine | Pipeline/chat product policy, request assembly, directives, tools, handlers, packets, jobs, artifacts, and operator surfaces. |

`datamachine_run_conversation()` is Data Machine's runtime entry point for chat and pipeline turns. It composes `AgentsAPI\AI\WP_Agent_Conversation_Loop` with Data Machine's turn runner. The generic loop owns sequencing and durable runtime primitives; Data Machine owns `RequestBuilder`, wp-ai-client dispatch, `ToolExecutor`, completion assertions, adjacent-handler behavior, logging, and job artifacts.

See `inc/Engine/AI/README.md` for the source-level ownership map.

## Current Components

- `RequestBuilder` assembles directive-aware wp-ai-client requests.
- `ConversationManager` normalizes Data Machine messages, logging, and tool-call tracking.
- `ToolManager` resolves Data Machine tool sources and availability for a mode/context.
- `ToolPolicyResolver` applies product-specific tool policy.
- `ToolExecutor` executes resolved tools, including abilities and approval-aware actions.
- `ToolResultFinder` interprets tool and adjacent-handler results in packet history.
- `DataMachineToolRegistrySource` and `AdjacentHandlerToolSource` adapt Data Machine registrations and flow neighbors into runtime tool declarations.
- Pipeline transcript and completion adapters attach Data Machine job, flow, and handler semantics to the generic Agents API loop.

## Tool Surfaces

Tools are model-facing adapters. A chat tool may execute the same ability as a REST controller or CLI command while retaining a model-appropriate name, description, input schema, and result shape. Static product tools, ability projections, and adjacent handler tools are composed into a resolved registry and then filtered by mode and policy.

The `datamachine_tools` filter remains an extension registration point; it is not the preferred way for consumers to discover executable business operations. Use abilities for operations and `ToolManager`/tool sources for resolved model-facing tools. See [Tool Manager](tool-manager.md) and [Tool Execution](tool-execution.md).
