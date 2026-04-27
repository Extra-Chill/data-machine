# Data Machine User Documentation

**AI-first WordPress plugin for automating and orchestrating content workflows with a visual pipeline builder, conversational chat agent, REST API, and extensibility through handlers and tools.**

## Agent-First Architecture

Data Machine is designed for AI agents as primary users, not just tool operators.

### The Self-Orchestration Pattern

While humans use Data Machine to automate content workflows, AI agents can use it to **automate themselves**:

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   AGENT     │ ──▶ │   QUEUE     │ ──▶ │  PIPELINE   │ ──▶ │ AGENT PING  │
│ queues task │     │  persists   │     │  executes   │     │  wakes agent│
│             │     │  context    │     │             │     │             │
└─────────────┘     └─────────────┘     └─────────────┘     └──────┬──────┘
       ▲                                                          │
       └──────────────────────────────────────────────────────────┘
                         Agent processes, queues next task
```

**Key concepts:**

- **Prompt Queue as Project Memory**: Queue items persist across sessions, storing project context that survives context window limits. Your multi-week project becomes a series of queued prompts.

- **Agent Ping for Continuity**: The `agent_ping` step type triggers external agents (via webhook) after pipeline completion. This is how the loop closes — you get notified when it's your turn to act. Agent Ping is outbound-only; inbound triggers use the REST API.

- **Phased Execution**: Complex projects execute in stages over days or weeks. Each stage completes, pings the agent, and the agent queues the next stage.

- **Autonomous Loops**: An agent can run indefinitely: process result → queue next task → sleep → wake on ping → repeat. Use explicit stop conditions to avoid runaway loops.

This transforms Data Machine from a content automation tool into a **self-scheduling execution layer for AI agents**.

## System Architecture

- **Pipelines** are reusable workflow templates that store handler order, tool selections, and AI settings.
- **Flows** instantiate pipelines with schedule metadata, flow-level overrides, and runtime configuration values stored per flow.
- **Ephemeral Workflows** (@since v0.8.0) are temporary, on-the-fly workflows triggered via the REST API. They skip database persistence for the workflow definition itself, using sentinel values (`flow_id='direct'`, `pipeline_id='direct'`) and dynamic configuration stored within the job's engine snapshot.
- **Jobs** track individual flow executions, persist engine parameters, and power the fully React-based Jobs dashboard for real-time monitoring. Jobs support parent-child relationships for batch execution via `parent_job_id`.
- **Steps** execute sequentially (Fetch → AI → Publish/Update) with shared base classes that enforce validation, logging, and engine data synchronization.

## Multi-Agent Architecture

Data Machine supports **multiple agents on a single WordPress installation** (@since v0.36.1). Each agent has its own identity, memory, and resource scope.

- **Agent Registry**: Agents are stored in `datamachine_agents` with unique slugs, owner relationships, and configuration.
- **Access Control**: The `datamachine_agent_access` table implements role-based access (viewer, operator, admin) for sharing agents across WordPress users.
- **Resource Scoping**: All major resources (pipelines, flows, jobs, chat sessions) carry an `agent_id` column. Queries filter by agent context automatically.
- **Filesystem Isolation**: Each agent gets its own directory under `agents/{slug}/` for identity files (SOUL.md, MEMORY.md) and daily memory.
- **Three-Layer Directory System**: Memory files are organized into shared (site-wide), agent (identity), and user (personal) layers under `wp-content/uploads/datamachine-files/`.

See [Multi-Agent Architecture](core-system/wordpress-as-agent-memory.md#multi-agent-architecture) for details.

## Memory System

Data Machine uses **WordPress itself as the persistent memory layer** for AI agents — files on disk, conversations in the database, context assembled at request time.

### Agent Memory Files

Markdown files organized in three layers:

| Layer | Directory | Contents |
|-------|-----------|----------|
| **Shared** | `shared/` | SITE.md, RULES.md (site-wide context) |
| **Agent** | `agents/{slug}/` | SOUL.md, MEMORY.md (agent identity and knowledge) |
| **User** | `users/{id}/` | USER.md, MEMORY.md (human preferences) |

### Daily Memory System

Temporal knowledge preserved in date-organized files (`agents/{slug}/daily/YYYY/MM/DD.md`). The **DailyMemoryTask** system task automatically:

1. Synthesizes daily activity into summary files
2. Prunes MEMORY.md when it exceeds 8KB, archiving session-specific content to daily files

Pipelines can selectively inject daily memory via the **DailyMemorySelectorDirective** with modes: recent days, specific dates, date range, or by month.

### Memory Path Discovery

```bash
wp datamachine memory paths --allow-root
```

This canonical CLI command returns the full directory structure and file locations for any agent — the recommended way for external consumers to discover memory file paths.

See [WordPress as Agent Memory](core-system/wordpress-as-agent-memory.md) for the complete memory architecture.

## Abilities API

The Abilities API (DataMachine\Abilities) provides direct method calls for core operations via the WordPress 6.9 Abilities API:

- `FlowAbilities`, `PipelineAbilities`, `FlowStepAbilities`, and `PipelineStepAbilities` handle creation, duplication, synchronization, and ordering.
- Job abilities monitor execution outcomes, retries, manual failure, recovery, summaries, and deletion.
- `ProcessedItemsAbilities` deduplicates content across executions by tracking previously processed identifiers.
- `AgentAbilities` manages agent CRUD, renaming (with filesystem migration), and deletion.
- `AgentMemoryAbilities` provides section-based read, write, append, and search operations on memory files.
- `DailyMemoryAbilities` manages daily memory files — read, write, list, search, and delete by date.
- `LogAbilities` and the `LogRepository` aggregate log entries in the `wp_datamachine_logs` table for filtering in the admin UI.
- Cache invalidation is handled by ability-level `clearCache()` methods to ensure dynamic handler and step type registrations are immediately reflected across the system.

Abilities are the single source of truth for REST endpoints, CLI commands, and Chat tools, ensuring validation and sanitization before persisting data or enqueuing jobs.

## System Tasks Framework

System tasks are background operations that run outside the normal pipeline execution model. The **SystemTask** base class provides:

- **Job lifecycle**: `completeJob()`, `failJob()`, `reschedule()` with attempt tracking (max 24 retries)
- **Editable prompts**: `getPromptDefinitions()` system with overrides stored in `datamachine_task_prompts` option
- **Undo system**: `supportsUndo()` and `undo()` for reversible operations, with effect types for post content, meta, attachments, and featured images

### Built-in System Tasks

| Task Type | Class | Description |
|-----------|-------|-------------|
| `image_generation` | `ImageGenerationTask` | AI-powered image generation |
| `image_optimization` | `ImageOptimizationTask` | Image compression and optimization |
| `alt_text_generation` | `AltTextTask` | AI-generated alt text for images |
| `internal_linking` | `InternalLinkingTask` | Automated internal link injection |
| `daily_memory_generation` | `DailyMemoryTask` | Daily memory synthesis and MEMORY.md cleanup |
| `meta_description_generation` | `MetaDescriptionTask` | AI-generated meta descriptions |

### Job Undo System

Jobs that record effects in `engine_data` can be reversed. The undo system handles:

- `post_content_modified` — restores WordPress revisions
- `post_meta_set` — restores previous meta values
- `attachment_created` — deletes created attachments
- `featured_image_set` — restores or removes thumbnails

```bash
wp datamachine jobs undo <job_id> --allow-root
wp datamachine jobs undo <job_id> --dry-run --allow-root
```

## Data Flow

- **DataPacket** standardizes the payload (content, metadata, attachments) that AI agents receive, keeping packets chronological and clean of URLs when not needed.
- **EngineData** stores engine-specific parameters such as `source_url`, `image_url`, and flow context, which fetch handlers persist via the `datamachine_engine_data` filter for downstream handlers.
- **FilesRepository modules** (DirectoryManager, FileStorage, RemoteFileDownloader, ImageValidator, FileCleanup, FileRetrieval) isolate file storage per flow, validate uploads, and enforce automatic cleanup after jobs complete.

## AI Integration

- **Tool-first architecture** enables AI agents (pipeline and chat) to call tools that interact with handlers, external APIs, or workflow metadata.
- **PromptBuilder + RequestBuilder** apply layered directives via the `datamachine_directives` filter so every request includes identity, context, and site-specific instructions.
- **Global tools** (Google Search, Local Search, Web Fetch, WordPress Post Reader) are registered under `/inc/Engine/AI/Tools/` and available to all agents.
- **Chat-specific tools** (AddPipelineStep, ApiQuery, AuthenticateHandler, ConfigureFlowSteps, ConfigurePipelineStep, CopyFlow, CreateFlow, CreatePipeline, CreateTaxonomyTerm, ExecuteWorkflowTool, GetHandlerDefaults, ManageLogs, ReadLogs, RunFlow, SearchTaxonomyTerms, SetHandlerDefaults, UpdateFlow) orchestrate pipeline and flow management within conversations.
- **ToolParameters + ToolResultFinder** gather parameter metadata for tools and interpret results inside data packets to keep conversations consistent.

## Authentication & Security

- **Authentication providers** extend BaseAuthProvider, BaseOAuth1Provider, or BaseOAuth2Provider under `/inc/Core/OAuth/`; concrete providers live next to their handlers in core or extension plugins.
- **OAuth handlers** (`OAuth1Handler`, `OAuth2Handler`) standardize callback handling, nonce validation, and credential storage.
- **Capability checks** (`manage_options`) and WordPress nonces guard REST endpoints; inputs run through `sanitize_*` helpers before hitting services.
- **Multi-agent permissions**: `PermissionHelper` handles agent-level access checks via `resolve_scoped_agent_id()`, `can_access_agent()`, and `owns_agent_resource()`.
- **HttpClient** centralizes outbound HTTP requests with consistent headers, browser-mode simulation, timeout control, and logging via `datamachine_log`.

## Scheduling & Jobs

- **Action Scheduler** drives scheduled flow execution while REST endpoints handle immediate runs.
- **Flow schedules** support manual runs, one-time execution, and recurring intervals (from 5 minutes to weekly). See [Scheduling Intervals](api/endpoints/intervals.md) for available options.
- **System task scheduling**: DailyMemoryTask and other system tasks run on cron schedules via Action Scheduler.
- **Batch execution**: Jobs support parent-child relationships via `parent_job_id` for processing multiple items in coordinated batches.
- Job abilities and repositories update statuses, emit extensibility actions (`datamachine_update_job_status`), and link jobs to logs and processed items for auditing.

## Admin Interface

- **React-First Architecture**: Admin pages are React apps built with `@wordpress/components` and TanStack Query for server state.
- **Client UI state**: The Pipelines page uses a small Zustand store for UI state (pipeline selection, modals, chat sidebar). Other pages may use local React state.
- **Pipeline Builder**: Visual pipeline/flow configuration with modal-driven step and handler settings.
- **Job Management**: React dashboard for job history with server-driven pagination and admin cleanup modal.
- **Logs Interface**: React logs viewer with filtering controls and REST-backed content loading.
- **Integrated Chat**: Collapsible sidebar for context-aware pipeline automation and AI-driven workflow assistance, using specialized tools to manage the entire ecosystem.
- **Agent Management**: Agent creation, configuration, and access control UI.

## Key Capabilities

- **Multi-agent support** with isolated identity, memory, and resources per agent on a single WordPress installation.
- **Multi-platform publishing** via core fetch/publish/upsert handlers for files, RSS, email, and WordPress, plus extension-provided handlers for social, business, and event destinations.
- **Daily memory system** for automatic temporal knowledge management with AI-driven pruning.
- **System tasks** for background AI operations (image generation, alt text, internal linking, meta descriptions) with undo support.
- **Extension points** through filters such as `datamachine_handlers`, `datamachine_tools`, `datamachine_step_types`, `datamachine_auth_providers`, and `datamachine_engine_data`.
- **Directive orchestration** ensures every AI request is context-aware, tool-enabled, and consistent with site policies.
- **Chartable logging, deduplication, and error handling** keep operators informed about job outcomes and prevent duplicate processing.
