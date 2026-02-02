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

- **Agent Ping for Continuity**: The `agent_ping` step type triggers external agents (via webhook) after pipeline completion. This is how the loop closes — you get notified when it's your turn to act.

- **Phased Execution**: Complex projects execute in stages over days or weeks. Each stage completes, pings the agent, and the agent queues the next stage.

- **Autonomous Loops**: An agent can run indefinitely: process result → queue next task → sleep → wake on ping → repeat.

This transforms Data Machine from a content automation tool into a **self-scheduling execution layer for AI agents**.

## System Architecture

- **Pipelines** are reusable workflow templates that store handler order, tool selections, and AI settings.
- **Flows** instantiate pipelines with schedule metadata, flow-level overrides, and runtime configuration values stored per flow.
- **Ephemeral Workflows** (@since v0.8.0) are temporary, on-the-fly workflows triggered via the REST API. They skip database persistence for the workflow definition itself, using sentinel values (`flow_id=0`, `pipeline_id=0`) and dynamic configuration stored within the job's engine snapshot.
- **Jobs** track individual flow executions, persist engine parameters, and power the fully React-based Jobs dashboard for real-time monitoring.
- **Steps** execute sequentially (Fetch → AI → Publish/Update) with shared base classes that enforce validation, logging, and engine data synchronization.

## Abilities API

The Abilities API (DataMachine\Abilities) provides direct method calls for core operations via the WordPress 6.9 Abilities API:

- `FlowAbilities`, `PipelineAbilities`, `FlowStepAbilities`, and `PipelineStepAbilities` handle creation, duplication, synchronization, and ordering.
- `JobAbilities` monitors execution outcomes and updates statuses.
- `ProcessedItemsAbilities` deduplicates content across executions by tracking previously processed identifiers.

**Remaining Services** (utilities for cross-cutting concerns):
- `LogsManager` aggregates log entries in the `wp_datamachine_logs` table for filtering in the admin UI.
- `CacheManager` provides centralized cache invalidation to ensure dynamic handler and step type registrations are immediately reflected across the system.

Abilities are the single source of truth for REST endpoints, CLI commands, and Chat tools, ensuring validation and sanitization before persisting data or enqueuing jobs.

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

- **Authentication providers** extend BaseAuthProvider, BaseOAuth1Provider, or BaseOAuth2Provider under `/inc/Core/OAuth/`, covering Twitter, Reddit, Facebook, Threads, Google Sheets, and Bluesky (app passwords).
- **OAuth handlers** (`OAuth1Handler`, `OAuth2Handler`) standardize callback handling, nonce validation, and credential storage.
- **Capability checks** (`manage_options`) and WordPress nonces guard REST endpoints; inputs run through `sanitize_*` helpers before hitting services.
- **HttpClient** centralizes outbound HTTP requests with consistent headers, browser-mode simulation, timeout control, and logging via `datamachine_log`.

## Scheduling & Jobs

- **Action Scheduler** drives scheduled flow execution while REST endpoints handle immediate runs.
- **Flow schedules** support manual runs, one-time execution, and recurring intervals (from 5 minutes to weekly). See [Scheduling Intervals](api/endpoints/intervals.md) for available options.
- **JobManager** updates statuses, emits extensibility actions (`datamachine_update_job_status`), and links jobs to logs and processed items for auditing.

## Admin Interface

- **React-First Architecture**: Admin pages are React apps built with `@wordpress/components` and TanStack Query for server state.
- **Client UI state**: The Pipelines page uses a small Zustand store for UI state (pipeline selection, modals, chat sidebar). Other pages may use local React state.
- **Pipeline Builder**: Visual pipeline/flow configuration with modal-driven step and handler settings.
- **Job Management**: React dashboard for job history with server-driven pagination and admin cleanup modal.
- **Logs Interface**: React logs viewer with filtering controls and REST-backed content loading.
- **Integrated Chat**: Collapsible sidebar for context-aware pipeline automation and AI-driven workflow assistance, using specialized tools to manage the entire ecosystem.

## Key Capabilities

- **Multi-platform publishing** via dedicated fetch/publish/update handlers for files, RSS, Reddit, Google Sheets, WordPress, Twitter, Threads, Bluesky, Facebook, and Google Sheets output.
- **Extension points** through filters such as `datamachine_handlers`, `chubes_ai_tools`, `datamachine_step_types`, `datamachine_auth_providers`, and `datamachine_engine_data`.
- **Directive orchestration** ensures every AI request is context-aware, tool-enabled, and consistent with site policies.
- **Chartable logging, deduplication, and error handling** keep operators informed about job outcomes and prevent duplicate processing.
