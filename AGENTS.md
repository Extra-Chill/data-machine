# AGENTS.md

Data Machine — WordPress plugin for automating content workflows with AI. Visual pipeline builder, chat agent, REST API, and extensibility via handlers and tools.

This file provides a concise, present-tense technical reference for contributors and automated agents. For user-focused docs see `docs/`. For the agent skill (how to *use* Data Machine), see `skills/data-machine/SKILL.md`.

## Build system

- **Homeboy** is used for all build operations (versioning, packaging, deployment)
- Homeboy provides full WordPress test environment for running tests (no local WordPress setup required)
- Build command: `homeboy build data-machine` — runs tests, lints code, builds frontend, creates production ZIP
- Test command: `homeboy test data-machine` — runs PHPUnit tests using homeboy's WordPress environment
- Lint command: `homeboy lint data-machine` — runs PHP CodeSniffer with WordPress coding standards
- Auto-fix: `homeboy lint data-machine --fix` — runs PHPCBF to auto-fix formatting issues

## Testing

- PHPUnit tests located in `tests/Unit/` directory
- Tests use `WP_UnitTestCase` with homeboy's WordPress test environment
- Ability registration tests in `tests/Unit/Abilities/`
- Run tests: `homeboy test data-machine`
- Run build: `homeboy build data-machine` (tests + lint + frontend + ZIP)

## Abilities API

- WordPress 6.9 Abilities API provides standardized capability discovery and execution for all Data Machine operations
- Abilities are organized across classes in `inc/Abilities/`:
  - `Pipeline/` — Pipeline CRUD, import/export, duplication
  - `PipelineStepAbilities` — Pipeline step management
  - `Flow/` — Flow CRUD, duplication, queue management
  - `FlowStep/` — Flow step configuration and validation
  - `Job/` — Workflow execution, job management, health monitoring, problem flow detection, recovery
  - `FileAbilities` — File management and uploads
  - `ProcessedItemsAbilities` — Deduplication tracking
  - `SettingsAbilities` — Plugin and handler settings (includes `agent_models` for per-agent model configuration)
  - `AuthAbilities` — OAuth authentication management
  - `LogAbilities` — Logging operations (write, clear, read, metadata, level management)
  - `HandlerAbilities` — Handler discovery and configuration
  - `StepTypeAbilities` — Step type discovery and validation
  - `PostQueryAbilities` — Querying Data Machine-created posts
  - `LocalSearchAbilities` — WordPress site search
  - `Media/` — Image generation (with insert mode support), alt text
  - `Taxonomy/` — Taxonomy term CRUD and resolution
  - `Analytics/` — Google Search Console, Bing Webmaster
  - `SystemAbilities` — System-level operations
  - `AgentPing/` — Agent ping dispatch
- Category registration: `datamachine` category registered via `wp_register_ability_category()` on `wp_abilities_api_categories_init` hook
- Ability execution: Each ability implements `execute_callback` with `permission_callback` (checks `manage_options` or WP_CLI)
- REST API endpoints, CLI commands, and Chat tools delegate to abilities for business logic


Engine & execution

- The engine executes flows via a four-action cycle (@since v0.8.0): `datamachine_run_flow_now` → `datamachine_execute_step` → `datamachine_schedule_next_step`. `datamachine_run_flow_later` handles deferred/recurring scheduling.
- The system supports direct execution (@since v0.8.0) for ephemeral workflows that execute without database persistence. Use `flow_id='direct'` and/or `pipeline_id='direct'` to trigger direct execution mode. Configuration is stored dynamically in the job's engine snapshot.
- Scheduling is handled via WordPress Action Scheduler using the `data-machine` group.

Core architecture

- **Abilities-First Architecture**: All service logic has been migrated to the WordPress 6.9 Abilities API. The Services directory is empty.
- Base classes for `Step`, `FetchHandler`, `PublishHandler`, `UpdateHandler`, `SettingsHandler`, and `DataPacket` provide consistent behavior and reduce duplication.
- Base authentication provider architecture (`BaseAuthProvider`, `BaseOAuth1Provider`, `BaseOAuth2Provider`) centralizes option storage and authentication validation across all providers (@since v0.2.6).
- FilesRepository is modular (storage, cleanup, validation, download, retrieval) and provides flow-isolated file handling.
- EngineData provides platform-agnostic data access (single source of truth for engine parameters).
- WordPressPublishHelper provides WordPress-specific publishing operations (image attachment, source attribution).
- WordPressSettingsResolver provides centralized settings resolution with system defaults override.
- Handler and Step registration use standardized traits (`HandlerRegistrationTrait`, `StepTypeRegistrationTrait`) to auto-register services via WordPress filters. Tools extend `BaseTool` for unified registration.
- Cache Management: Each ability class provides its own `clearCache()` method for domain-specific invalidation. `SiteContext` handles site metadata caching. Admin UI uses TanStack Query for client-side state.
- Prompt and directive management is centralized via a PromptBuilder with ordered directives (site, pipeline, flow, context).
- Providers are pluggable and configured by site administrators (OpenAI, Anthropic, Google, Grok, OpenRouter).
- Universal Engine architecture supports both Pipeline and Chat agents with shared AI infrastructure.
- **System Agent Architecture**: Hook-based system agent handles infrastructure operations like session title generation (@since v0.13.7). Uses `datamachine_ai_response_received` hook to trigger system abilities immediately after AI responses, with graceful fallback to truncated titles when AI generation fails.
- Universal Web Scraper Architecture: A multi-layered system in `datamachine-events` that prioritizes structured data extraction (Schema.org JSON-LD/Microdata and 17+ specialized extractors) before falling back to AI-enhanced HTML section parsing. It coordinates fetching, pagination, and normalization via a centralized `StructuredDataProcessor`.
- Integrated Chat Sidebar: React-based context-aware chat interface in the Pipeline Builder that passes `selected_pipeline_id` for prioritized context.
- Specialized chat tools provide focused workflow management: AddPipelineStep, ApiQuery, AuthenticateHandler, ConfigureFlowSteps, ConfigurePipelineStep, CopyFlow, CreateFlow, CreatePipeline, CreateTaxonomyTerm, ExecuteWorkflowTool, GetHandlerDefaults, ManageLogs, ReadLogs, RunFlow, SearchTaxonomyTerms, SetHandlerDefaults, UpdateFlow.
- Focused Tools Strategy: Mutation operations (creation, deletion, duplication) are handled by specialized Focused Tools. The `ApiQuery` tool is strictly read-only for discovery and monitoring.
- Job Status Logic: Jobs use `completed_no_items` to distinguish between a successful execution that found no new items versus an actual `failed` execution. The jobs table is the single source of truth for execution status.
- Flow Monitoring: Problem flows are identified by computing consecutive failure/no-item counts from job history. Flows exceeding the `problem_flow_threshold` (default 3) are monitored via the `get_problem_flows` tool and `/flows/problems` endpoint.

Database

- Core tables store pipelines, flows, jobs, processed items, and chat sessions. See datamachine/inc/Core/Database/ for schema definitions used in code.

Security & conventions

- Use capability checks for admin operations (e.g., `manage_options`).
- Sanitize inputs (`wp_unslash()` then `sanitize_*`).
- Follow PSR-4 autoloading and PSR coding conventions for PHP where applicable.
- Prefer REST API over admin-ajax.php and vanilla JS over jQuery in the admin UI.

Agent guidance (for automated editors)

- Code-first verification: always validate claims against the code before editing docs. Read the relevant implementation files under `data-machine/inc/` and `data-machine/docs/`.
- Make minimal, targeted documentation edits; preserve accurate content and explain assumptions in changelogs.
- Use present-tense language and remove references to deleted functionality or historical counts.
- Do not modify source code when aligning documentation unless explicitly authorized.
- Do not create new top-level documentation directories. Creating or updating `.md` files is allowed only within existing directories.
- **Extension-based commands**: The `wp datamachine workspace` and `wp datamachine github` commands have been moved to the `data-machine-code` extension plugin. Core documentation should not reference these commands.

Agent orchestration patterns

Data Machine functions as a **reminder system + task manager + workflow executor** for AI agents.

### Three key concepts

1. **Flows operate on schedules** — Configure `scheduling_config` with interval (manual, daily, hourly) or cron expressions. Agents can set up "ping me at X time to do Y."

2. **Step-level prompt queues** — Both AI and Agent Ping steps use `QueueableTrait`. Each step can have its own queue. If `queue_enabled` is true and the configured prompt is empty, the step pops from its queue. This allows varied task instructions per execution, not the same ping every time.

3. **Multiple purpose-specific flows** — Create separate flows for separate concerns, each with its own schedule and queue:
   - Content generation (queue-driven, AI → Publish → Agent Ping)
   - Content ideation (daily ping to review and queue topics)
   - Maintenance tasks (weekly ping for cleanup/optimization)
   - Coding tasks (queue-driven with specific instructions per run)

### Chaining pattern

When an agent receives a ping, it should:
1. Execute the immediate task
2. Queue the next logical task (if continuation needed)
3. Let the cycle continue

The queue becomes the agent's persistent project memory — multi-phase work is tracked in the queue, not held in the agent's context.

### AI Decides taxonomy

The `TaxonomyHandler` supports automatic taxonomy assignment during publish:

| Selection | Behavior |
|-----------|----------|
| `skip` | Don't assign this taxonomy |
| `ai_decides` | AI provides values via tool parameters |
| `<term_id\|name\|slug>` | Pre-select specific term |

When `ai_decides` is set, `getTaxonomyToolParameters()` adds the taxonomy as an AI tool parameter. The AI provides term names, and the handler assigns them (creating terms if needed).

- Hierarchical taxonomies (category): expects single string
- Non-hierarchical (tags): expects array of strings

Design principles

- **Agent Agnosticism**: Data Machine is agnostic to which agent framework handles triggered prompts. Agent Ping sends webhooks with context — it does not hardcode assumptions about OpenClaw, LangChain, or any specific agent runtime. Whatever listens on the webhook URL handles the prompt. This keeps the plugin portable and framework-independent.

Where to find more

- Agent skill: `skills/data-machine/SKILL.md`
- User docs: `docs/`
- Code: `inc/`
- Admin UI source: `inc/Core/Admin/Pages/`
