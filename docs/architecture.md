# Data Machine Architecture

This is the current architecture entry point. Data Machine is the WordPress automation product layer built around the canonical runtime model:

```text
agent -> pipeline -> flow -> job -> packets/artifacts
```

- An **agent** owns identity, permissions, memory, and scoped resources.
- A **pipeline** is a reusable ordered workflow definition.
- A **flow** configures and schedules one pipeline for a specific use.
- A **job** is one durable execution record. Batch fan-out creates child jobs rather than changing this boundary.
- **Packets and artifacts** are the job's working data and durable outputs. Packets move between steps; engine snapshots and run artifacts make executions inspectable and reproducible.

The current model is implemented by the repositories under `inc/Core/Database/`, the execution abilities under `inc/Abilities/Engine/`, and the Action Scheduler bridges in `inc/Engine/Actions/Engine.php`.

## Runtime Layers

### Product Model

Pipelines store ordered step definitions. Flows bind those definitions to handler configuration, queues, and schedules. Starting a flow creates or resumes a job, snapshots its resolved configuration into `engine_data`, and schedules its first step. Each step reads the current packet set and returns an explicit `StepExecutionResult`; the engine persists the result and either schedules the next step or commits a terminal job state.

Ephemeral execution uses the same job and step engine with `direct` pipeline and flow identifiers. Only the workflow definition is not persisted as a pipeline or flow. See [Engine Execution](core-system/engine-execution.md) and [Ephemeral Workflows](core-system/ephemeral-workflows.md).

### Abilities

WordPress abilities under `inc/Abilities/` own reusable business operations: pipeline and flow mutation, execution, job management, files, memory, content, agents, settings, and other product capabilities. Engine action hooks delegate to the `datamachine/run-flow`, `datamachine/execute-step`, `datamachine/schedule-next-step`, and `datamachine/schedule-flow` abilities because Action Scheduler invokes WordPress actions.

Abilities are the reusable operation boundary, not the only public interface. See [Abilities API](core-system/abilities-api.md).

### Product Adapters

Data Machine intentionally maintains purpose-built adapters for its consumers:

- REST controllers under `inc/Api/` provide stable `datamachine/v1` resources, HTTP permissions, request schemas, status codes, and response presentation.
- WP-CLI commands under `inc/Cli/` provide a stable canonical `wp datamachine` operator namespace, command-oriented arguments, output formatting, and process exit behavior.
- Chat tools under `inc/Api/Chat/Tools/` provide model-facing names, descriptions, schemas, and conversational result shapes.

These adapters commonly execute abilities, but they are not accidental wrappers or duplicate infrastructure. They own consumer-specific semantics and preserve Data Machine's product namespaces while abilities remain reusable operations.

### Execution and Scheduling

Action Scheduler is the durable queue. The hooks `datamachine_run_flow_now`, `datamachine_execute_step`, `datamachine_schedule_next_step`, and `datamachine_run_flow_later` are scheduler-compatible bridges into engine abilities. `RecurringScheduler` and the flow scheduling API own recurring schedule reconciliation and generation fencing; they are not a second workflow engine.

The engine normally processes one primary item per child job. A fetch step may produce multiple packets, in which case `PipelineBatchScheduler` fans them out into isolated child jobs. Queue consumption, batch fan-out, per-step iteration, and recurring runs are separate execution axes. See [Pipeline Execution Axes](architecture/pipeline-execution-axes.md).

### Steps and Handlers

`Core\Bootstrap\RuntimeServiceProvider::register_step_types()` registers six core step types:

| Slug | Responsibility | Handler-backed |
|---|---|---|
| `fetch` | Collect source data and produce packets | Yes |
| `ai` | Run a pipeline agent turn over packets and tools | No |
| `publish` | Send content to one or more destinations | Yes |
| `upsert` | Create or update identity-aware content | Yes |
| `webhook_gate` | Park a job until an inbound webhook resumes it | No |
| `system_task` | Run a registered system task inline | No |

Step constructors register definitions through `StepTypeRegistrationTrait`; `StepTypeAbilities` is the current read and validation surface. Core handlers are loaded explicitly by `RuntimeServiceProvider::register_handlers()`. Extensions can add step types, handlers, tools, directives, authentication providers, and other documented registrations. Outbound agent calls use an ability or system task, and `Api\AgentPing` owns inbound callback routes.

### Persistence

Workflow definitions and execution state use Data Machine tables. Packet payloads and flow files use `FilesRepository` beneath the WordPress uploads directory. Job `engine_data` stores the resolved execution snapshot and runtime metadata. Run artifacts and bundle artifacts have dedicated persistence. Agent identity, access, and tokens are network-scoped on multisite; most workflow tables are site-scoped, while chat uses its network-aware repository.

`ActivationServiceProvider::ensure_all_tables()` and each repository's `TABLE_NAME`/`create_table()` implementation define the current persistence inventory. See [Database Schema](core-system/database-schema.md) for table scope and relationships.

### AI Runtime Boundary

Data Machine owns pipeline, flow, job, handler, queue, product policy, and admin semantics. The bundled or externally loaded Agents API owns generic durable agent-runtime primitives such as conversation sequencing, transcripts, locks, events, and portable declarations. `inc/Engine/AI/` adapts those primitives to Data Machine request assembly, tools, directives, completion policy, packets, and job artifacts. See [AI Runtime Boundary](../inc/Engine/AI/README.md) and [AI Conversation Loop](core-system/ai-conversation-loop.md).

### Extensions

Data Machine core owns generic automation primitives and core WordPress/email/file handlers. Product-specific integrations belong in extension plugins. For example, coding workspace and GitHub operations live in `data-machine-code`, while social destinations live in `data-machine-socials`. Extensions should register only the domain behavior they own rather than moving consumer-specific semantics into core.

## Discovery

Choose discovery by boundary instead of treating filters as a service locator:

- Use `wp_get_ability()` or the documented REST, CLI, or chat adapter for executable business operations.
- Use `StepTypeAbilities` and `HandlerAbilities` for resolved step and handler metadata.
- Use documented WordPress filters when registering extension-owned definitions or altering an explicit extension point.
- Read `data-machine.php` and `inc/bootstrap.php` for runtime loading and registration order.

## Focused Documentation

- [Overview](overview.md): user-facing product model and capabilities.
- [Engine Execution](core-system/engine-execution.md): job creation, step dispatch, persistence, and scheduling.
- [Database Schema](core-system/database-schema.md): current persistence inventory and relationships.
- [AI Runtime Adapter Layer](core-system/universal-engine.md): current Agents API and wp-ai-client integration.
- [REST API](api/index.md): product HTTP namespace.
- [WP-CLI](core-system/wp-cli.md): operator command namespace.
- [Tool Execution](core-system/tool-execution.md): model-facing tool resolution and execution.
