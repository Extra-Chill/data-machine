# Data Machine

Agentic workflow automation for WordPress.

[![Try Data Machine in WordPress Playground](https://img.shields.io/badge/Try_Data_Machine_in-WordPress_Playground-3858e9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/Extra-Chill/data-machine/main/blueprints/playground.json)

Click the badge to open a fresh Data Machine in a browser-only WordPress instance. The Playground boots WordPress, installs Data Machine plus its substrate (Agents API and the WordPress AI Provider for OpenAI), and drops you into the Data Machine admin. Add your own OpenAI key in Settings to start chatting with an agent or running a pipeline. Nothing persists once the tab closes.

## What It Does

Data Machine turns a WordPress site into an agent runtime — persistent identity, memory, pipelines, abilities, and tools that AI agents use to operate autonomously.

- **Pipelines** — Multi-step workflows: fetch content, process with AI, publish anywhere
- **Abilities API** — Typed, permissioned functions that agents and extensions call (`datamachine/upload-media`, `datamachine/validate-media`, etc.)
- **Agent memory** — Layered markdown files (SOUL.md + MEMORY.md in agent layer, USER.md in user layer) injected into every AI context
- **Multi-agent** — Multiple agents with scoped pipelines, flows, jobs, and filesystem directories
- **Self-scheduling** — Agents schedule their own recurring tasks using flows, prompt queues, and Agent Pings

Data Machine builds on [Agents API](https://github.com/Automattic/agents-api) for generic agent runtime contracts and durable agent primitives. Data Machine owns the WordPress automation product layer: pipelines, flows, jobs, handlers, tools, abilities, memory files, system tasks, request assembly, and admin/CLI surfaces.

## Architecture

Data Machine is the WordPress automation product layer on top of generic agent primitives:

```text
WordPress site
  -> Data Machine product runtime
       pipelines, flows, jobs, handlers, system tasks, policy-gated tools,
       pending actions, memory files, bundles, REST, WP-CLI, admin UI
  -> Agents API substrate
       agent contracts, durable conversation loop, transcripts, memory-store
       interfaces, approval vocabulary, locks, event sinks
  -> wp-ai-client / provider adapters
       one-shot model calls and durable agent turns
```

Use **Agents API** for durable, generic agent runtime behavior. Use **Data Machine** for WordPress automation behavior: pipeline orchestration, publishing, content operations, queues, files, policies, approvals, bundles, and operator surfaces. Extension plugins add handlers, abilities, tools, and bundle extras without reaching into core internals.

### Pipelines

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│    FETCH    │ ──▶ │     AI      │ ──▶ │   PUBLISH   │
│  RSS, API,  │     │  Enhance,   │     │  WordPress, │
│  WordPress  │     │  Transform  │     │   Social,   │
└─────────────┘     └─────────────┘     └─────────────┘
```

**Pipelines** define the workflow template. **Flows** configure and schedule a pipeline. **Jobs** track each execution, artifacts, retries, status, and undo support. The engine processes work as a bounded cycle: fetch or receive input, process one item through the configured steps, persist job/log/artifact state, then either complete, fail, reschedule, or let the next drain/worker pass continue the queue.

### Worker, Drain, and Cycle

Long-running automation uses small repeatable units instead of one unbounded request:

| Concept | Purpose |
|---------|---------|
| **Cycle** | One bounded scheduler/engine pass. It claims due work, advances jobs, and exits. |
| **Drain** | CLI/operator command that repeatedly runs due work until the queue is empty or a budget is reached. |
| **Worker** | Runtime wrapper for ongoing background processing. It runs cycles under configured limits. |
| **Retention** | Cleanup tasks for old logs, jobs, processed items, files, Action Scheduler rows, stale claims, and chat sessions. |

Useful entry points: `wp datamachine cycle`, `wp datamachine drain`, `wp datamachine worker`, `wp datamachine jobs summary`, and `wp datamachine retention`.

### Agent Modes

One agent, three operational modes — same identity and memory, different guidance and tools:

| Mode | Purpose | Tools |
|---------|---------|-------|
| **Pipeline** | Automated workflow execution | Handler-specific tools scoped to the current step |
| **Chat** | Conversational interface in wp-admin | 30+ management tools (flows, pipelines, jobs, logs, memory, content) |
| **System** | Background infrastructure tasks | Alt text, daily memory, image generation, internal linking, meta descriptions, retention (GitHub issues in data-machine-code extension) |

Built-in mode guidance is injected by `AgentModeDirective` at runtime and extensions can register more modes through `AgentModeRegistry`. Configure AI provider and model per mode in Settings. Each mode falls back to the global default if no override is set.

### Agent Memory

Persistent markdown files injected into every AI context:

```
shared/
  SITE.md                  — Site-wide context
agents/{slug}/
  SOUL.md                  — Identity, voice, rules
  MEMORY.md                — Durable knowledge every session should know
  daily/YYYY/MM/DD.md      — Automatic daily journals
users/{id}/
  USER.md                  — Information about the human
```

Daily memory files are append-only history and are also system-task artifacts: `DailyMemoryTask` can synthesize recent activity, archive older details under `daily/YYYY/MM/DD.md`, and keep `MEMORY.md` focused on persistent knowledge. Discovery: `wp datamachine memory paths --allow-root`.

### Policy-Gated Tools and Pending Actions

Tool access is resolved at runtime by policy classes instead of hardcoded allow-lists. Data Machine combines tool sources, mode policy, handler adjacency, memory policy, and action policy to decide what an agent can see and what requires approval.

When an operation needs human approval, it becomes a **pending action** backed by Agents API approval vocabulary and Data Machine storage/resolution adapters. Pending actions can be inspected and resolved through REST, abilities, chat tools, and CLI (`wp datamachine pending-actions`).

### Abilities API

Typed, permissioned functions registered via WordPress's Abilities API. Extensions and agents consume them instead of reaching into internals:

| Ability | Description |
|---------|-------------|
| `datamachine/query-posts` | Query WordPress posts for pipeline/content operations |
| `datamachine/publish-wordpress` | Publish canonical content to WordPress |
| `datamachine/update-wordpress` | Update existing WordPress content |
| `datamachine/generate-alt-text` | Generate alt text for media |
| `datamachine/generate-meta-description` | Generate SEO meta descriptions |
| `datamachine/run-flow` | Execute a flow programmatically |
| ... | Additional core abilities across pipelines, flows, jobs, memory, media, SEO, email, and infrastructure |

Social publishing, workspace, GitHub, and business integration abilities live in extension plugins such as data-machine-socials, data-machine-code, and data-machine-business.

### Agent Bundles

Agent bundles are portable packages for an agent recipe: manifest metadata, memory, pipelines, flows, prompts, rubrics, tool policies, auth references, seed queues, and extension-owned extras. Core owns the transport and reserved tree schema; extensions own their extras through bundle export/import hooks. See [`docs/core-system/agent-bundles.md`](docs/core-system/agent-bundles.md).

### Content Formats

Content and publish abilities accept `content_format` (`markdown`, `html`, or `blocks`) as the caller's source format. Data Machine stores content in the post type's canonical format from `datamachine_post_content_format`, converting through the active Blocks Engine PHP Transformer runtime when available.

### Multi-Agent

Agents are scoped by user. Each agent gets its own:

- Filesystem directory (`agents/{slug}/`)
- Memory files (SOUL.md, MEMORY.md)
- Pipelines, flows, and jobs (scoped by `user_id`)

Single-agent mode (`user_id=0`) works out of the box. Multi-agent adds scoping without breaking existing setups.

## Step Types & Handlers

Pipelines are built from **step types**. Some use pluggable **handlers** — interchangeable implementations that define *how* the step operates.

### Steps with handlers

| Step Type | Core Handlers | Extension Handlers |
|-----------|---------------|-------------------|
| **Fetch** | RSS, WordPress (local posts), WordPress API (remote), WordPress Media, Files | GitHub, Google Sheets, Reddit, social platforms (in extensions) |
| **Publish** | WordPress | Workspace (data-machine-code), Twitter, Instagram, Facebook, Threads, Bluesky, Pinterest, Google Sheets, Slack, Discord (in extensions) |
| **Update** | WordPress posts with AI enhancement | — |

### Self-contained steps

| Step Type | Description |
|-----------|-------------|
| **AI** | Process content with the configured AI provider |
| **Agent Ping** | Outbound webhook to trigger external agents |
| **Webhook Gate** | Pause pipeline until an external webhook callback fires |
| **System Task** | Background tasks (alt text, image generation, daily memory, etc.) |

Agent Ping is outbound-only. It hands context to another agent or system and supports confirmation/callback REST routes; inbound starts use flow webhooks or chat/API surfaces.

## Media Primitives

Core provides platform-agnostic media handling that extensions consume:

```
Pipeline flow:

  Fetch step → video_file_path / image_file_path in engine data
    → PublishHandler.resolveMediaUrls(engine)
      → MediaValidator (ImageValidator or VideoValidator)
      → FileStorage.get_public_url()
    → Platform API (Instagram, Twitter, etc.)
```

- **MediaValidator** — Abstract base with ImageValidator and VideoValidator subclasses
- **VideoMetadata** — ffprobe extraction with graceful degradation
- **EngineData** — `getImagePath()` and `getVideoPath()` for pipeline media flow
- **PublishHandler** — `resolveMediaUrls()`, `validateImage()`, `validateVideo()` on the base class

## Theming

Data Machine exposes two aligned theming surfaces: CSS custom properties for browser-rendered UI and `BrandTokens` for PHP/GD-rendered image templates. See [`docs/theming.md`](docs/theming.md) for the decision matrix and token catalogs.

## System Tasks

Background AI tasks that run on hooks or schedules:

| Task | Description |
|------|-------------|
| **Alt Text** | Generate alt text for images missing it |
| **Image Generation** | AI image creation with content-gap placement |
| **Daily Memory** | Consolidate MEMORY.md, archive to daily files |
| **Internal Linking** | AI-powered internal link suggestions |
| **Meta Descriptions** | Generate SEO meta descriptions |
| **GitHub Issues** | Create issues from pipeline findings (in data-machine-code extension) |

Tasks support undo via the Job Undo system (revision-based rollback for post content, meta, attachments, featured images).

## Self-Scheduling

```
Agent queues task → Flow runs → Agent Ping fires →
Agent executes → Agent queues next task → Loop continues
```

- **Flows** run on schedules — daily, hourly, or cron expressions
- **Prompt queues** — AI and Agent Ping steps pop tasks from persistent queues
- **Webhook triggers** — `POST /datamachine/v1/trigger/{flow_id}` with Bearer token auth
- **Agent Ping** — Outbound webhook with context for receiving agents

## WP-CLI

```bash
wp datamachine agent|agents      # Agent CRUD, access, tokens, bundles, installed state
wp datamachine ai                # AI/provider diagnostics
wp datamachine analytics         # Analytics reporting
wp datamachine alt-text          # AI alt text generation
wp datamachine auth              # OAuth/provider auth management
wp datamachine batch             # Batch operations
wp datamachine block|blocks      # Gutenberg block operations
wp datamachine chat              # Chat agent interface
wp datamachine cycle|cycles      # Run one bounded scheduler/engine cycle
wp datamachine drain             # Drain due work until empty or budgeted
wp datamachine email             # Site email read/send/reply operations
wp datamachine external          # Remote agent/site calls and auth helpers
wp datamachine fetch test        # Fetch-handler test harness
wp datamachine flow|flows        # Flow CRUD, queue, webhook, scheduling, run
wp datamachine handler|handlers  # List registered handlers
wp datamachine image             # Image generation
wp datamachine indexnow          # IndexNow submission helpers
wp datamachine job|jobs          # Job management, monitoring, undo, summary
wp datamachine link|links        # Internal linking
wp datamachine log|logs          # Log operations
wp datamachine memory            # Agent memory and daily memory read/write/search
wp datamachine meta-description  # SEO meta descriptions
wp datamachine pending-actions   # Inspect/resolve approval-gated actions
wp datamachine pipeline|pipelines # Pipeline CRUD and memory-file links
wp datamachine post|posts        # Query/update Data Machine-created posts
wp datamachine processed-items   # Dedupe/processed item management
wp datamachine retention         # Retention cleanup tasks
wp datamachine setting|settings  # Plugin settings
wp datamachine step-type|step-types # List registered step types
wp datamachine system            # System health, tasks, prompts, runs
wp datamachine taxonomy          # Taxonomy operations
wp datamachine test              # Diagnostic command surface
wp datamachine worker            # Long-running worker wrapper
```

`wp datamachine workspace` and `wp datamachine github` live in the `data-machine-code` extension. Use `wp help datamachine` and `wp help datamachine <command>` as the authoritative runtime command list.

## REST API

Full REST API under `datamachine/v1`:

- `POST /execute` — Execute a flow or ephemeral workflow
- `POST /trigger/{flow_id}` — Webhook trigger with Bearer token auth
- `GET|POST /pipelines`, `GET|PATCH|DELETE /pipelines/{id}` — Pipeline CRUD, steps, flows, memory files
- `GET|POST /flows`, `GET|PATCH|DELETE /flows/{id}` — Flow CRUD, pause/resume, duplicate, queue, config, webhooks
- `GET|POST|DELETE /jobs`, `GET /jobs/{id}` — Job management, cleanup, monitoring, undo-capable artifacts
- `GET|POST|DELETE /logs` — Log search, metadata, and cleanup
- `GET|POST /chat`, `POST /chat/continue`, `GET|DELETE /chat/{session_id}` — Chat and sessions
- `GET|POST|DELETE /files`, `/files/agent`, `/files/agent/daily/...` — Flow files, memory files, daily memory artifacts
- `GET|POST|PATCH|DELETE /agents...` — Agent CRUD, current agent, access, tokens
- `POST /agent-ping/confirm`, `POST /agent-ping/callback/{callback_id}` — Agent Ping confirmation/callbacks
- `GET /handlers`, `/step-types`, `/providers`, `/tools`, `/settings`, `/system/*` — Discovery and settings
- `GET|POST /auth/*`, `/email/*`, `/users/*`, `/analytics/*`, `/links/*`; `DELETE /processed-items` — Supporting product surfaces
- `GET /actions/pending`, `GET /actions/pending/{id}`, `POST /actions/resolve` — Approval-gated pending actions

Detailed endpoint docs live under [`docs/api/`](docs/api/). Routes under `wp-json/datamachine/v1` are the Data Machine product API, not the generic Agents API substrate.

## Extensions

| Plugin | Description |
|--------|-------------|
| [data-machine-code](https://github.com/Extra-Chill/data-machine-code) | Workspace management, GitHub integration, git operations |
| [data-machine-socials](https://github.com/Extra-Chill/data-machine-socials) | Publish to Instagram (images, carousels, Reels, Stories), Twitter (text + media + video), Facebook, Threads, Bluesky, Pinterest (image + video pins). Reddit fetch. |
| [data-machine-business](https://github.com/Extra-Chill/data-machine-business) | Google Sheets (fetch + publish), Slack, Discord integrations |
| [data-machine-editor](https://github.com/Extra-Chill/data-machine-editor) | Gutenberg inline diff visualization, accept/reject review, editor sidebar |
| [data-machine-frontend-chat](https://github.com/Extra-Chill/data-machine-frontend-chat) | Floating agent chat widget for any WordPress site |
| [data-machine-chat-bridge](https://github.com/Extra-Chill/data-machine-chat-bridge) | Message queue, webhook delivery, and REST API for external chat clients |
| [data-machine-events](https://github.com/Extra-Chill/data-machine-events) | Event calendar automation with AI + Gutenberg blocks |
| [datamachine-recipes](https://github.com/Sarai-Chinwag/datamachine-recipes) | Recipe content extraction and schema processing |
| [data-machine-quiz](https://github.com/Sarai-Chinwag/data-machine-quiz) | Quiz creation and management tools |

### Skills

| Package | Description |
|---------|-------------|
| [data-machine-skills](https://github.com/Extra-Chill/data-machine-skills) | Agent skills — discoverable instruction sets that coding agents load on demand |

### Integrations

| Project | Description |
|---------|-------------|
| [mautrix-data-machine](https://github.com/Extra-Chill/mautrix-data-machine) | Matrix/Beeper bridge — chat with your WordPress AI agent via any Matrix client |

## AI Providers

Configure any provider registered with `wp-ai-client`, then choose a global default per site with optional per-mode overrides for pipeline, chat, and system. Data Machine reads provider/model metadata from the wp-ai-client provider registry and Connectors-shaped settings via `WpAiClientProviderAdmin`; runtime dispatch goes through `RequestBuilder` and has no fallback to `chubes_ai_request` or `ai-http-client`.

## Runtime Architecture

Data Machine's runtime seam uses Agents API vocabulary but keeps Data Machine product policy in Data Machine:

- `datamachine_run_conversation()` is the Data Machine entry point for chat and pipeline AI turns.
- `AgentsAPI\AI\WP_Agent_Conversation_Loop::run()` owns generic turn sequencing, budgets, transcript persistence, locks, event callbacks, and normalized result fields.
- Data Machine's turn runner owns request assembly, wp-ai-client dispatch, Data Machine tool execution, duplicate-call protection, tool runtime rules, completion assertions, job artifacts, and product logging.
- `RequestBuilder::build()` returns `WordPress\AiClient\Results\DTO\GenerativeAiResult` or `WP_Error`; the old `success/data/error` array shape is historical.
- Message storage and runtime results use Agents API message envelopes; provider-specific message shapes are projections at the wp-ai-client boundary.

Runtime tests and host integrations can use stable hooks such as `datamachine_wp_ai_client_text_result`, `datamachine_wp_ai_client_availability`, `datamachine_wp_ai_client_request_timeout`, and `datamachine_wp_ai_client_connect_timeout` to replace provider dispatch or inspect transport behavior without replacing the Agents API substrate.

See [`docs/core-system/ai-conversation-loop.md`](docs/core-system/ai-conversation-loop.md), [`docs/core-system/request-builder.md`](docs/core-system/request-builder.md), and [`docs/core-system/ai-message-envelope.md`](docs/core-system/ai-message-envelope.md) for the full runtime contract.

## Memory Storage Adapters

Agent memory files (MEMORY.md, SOUL.md, USER.md, NETWORK.md, AGENTS.md, plus any custom files registered through `MemoryFileRegistry`) persist on the local filesystem by default. The persistence layer is swappable through the canonical Agents API `wp_agent_memory_store` resolver/filter, enabling DB-backed implementations on managed hosts that don't expose a writable filesystem.

```php
add_filter(
    'wp_agent_memory_store',
    function ( $store, $context ) {
        // Return an WP_Agent_Memory_Store to replace the disk default
        // for this context, or null to let Data Machine read/write through
        // the filesystem. Data Machine passes the current scope as
        // $context['scope'].
        return new My_DB_Agent_Memory_Store();
    },
    10,
    2
);
```

Section parsing, scaffolding, and editability gating stay in Data Machine; the store is just the bytes layer underneath. All consumer paths — section reads/writes (`AgentMemory`), the React Agent UI (`AgentFileAbilities`), and AI context injection (`CoreMemoryFilesDirective`) — flow through the same store, so a single swap makes the entire memory surface backend-agnostic.

See [`docs/development/hooks/core-filters.md`](docs/development/hooks/core-filters.md#agentmemorystoreinterface-inccorefilesrepositoryagentmemorystoreinterfacephp) for the full interface contract.

## Requirements

- WordPress 6.9+ (Abilities API)
- PHP 8.2+
- Action Scheduler (bundled)

## Development

```bash
composer test    # PHP smoke tests
composer lint    # PHPCS with WordPress standards
npm run build    # Build admin assets
```

## Documentation

- [docs/](docs/) — User documentation
- [docs/core-system/wp-cli.md](docs/core-system/wp-cli.md) — WP-CLI reference
- [docs/api/index.md](docs/api/index.md) — REST API overview
- [docs/core-system/agent-bundles.md](docs/core-system/agent-bundles.md) — Portable agent recipes
- [docs/core-system/daily-memory-system.md](docs/core-system/daily-memory-system.md) — Daily memory artifacts and system task
- [docs/architecture/pipeline-execution-axes.md](docs/architecture/pipeline-execution-axes.md) — Four orthogonal axes of work expansion in a pipeline
- Data Machine skill and agent instruction files are generated into consumer environments rather than stored in this plugin tree
- [docs/CHANGELOG.md](docs/CHANGELOG.md) — Version history

## Star History

[![Star History Chart](https://api.star-history.com/svg?repos=Extra-Chill/data-machine&type=date&legend=top-left)](https://www.star-history.com/#Extra-Chill/data-machine&type=date&legend=top-left)
