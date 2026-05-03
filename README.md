# Data Machine

Agentic workflow automation for WordPress.

## What It Does

Data Machine turns a WordPress site into an agent runtime — persistent identity, memory, pipelines, abilities, and tools that AI agents use to operate autonomously.

- **Pipelines** — Multi-step workflows: fetch content, process with AI, publish anywhere
- **Abilities API** — Typed, permissioned functions that agents and extensions call (`datamachine/upload-media`, `datamachine/validate-media`, etc.)
- **Agent memory** — Layered markdown files (SOUL.md + MEMORY.md in agent layer, USER.md in user layer) injected into every AI context
- **Multi-agent** — Multiple agents with scoped pipelines, flows, jobs, and filesystem directories
- **Self-scheduling** — Agents schedule their own recurring tasks using flows, prompt queues, and Agent Pings

## Architecture

### Pipelines

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│    FETCH    │ ──▶ │     AI      │ ──▶ │   PUBLISH   │
│  RSS, API,  │     │  Enhance,   │     │  WordPress, │
│  WordPress  │     │  Transform  │     │   Social,   │
└─────────────┘     └─────────────┘     └─────────────┘
```

**Pipelines** define the workflow template. **Flows** schedule when they run. **Jobs** track each execution with full undo support.

### Agent Modes

One agent, three operational modes — same identity and memory, different guidance and tools:

| Mode | Purpose | Tools |
|---------|---------|-------|
| **Pipeline** | Automated workflow execution | Handler-specific tools scoped to the current step |
| **Chat** | Conversational interface in wp-admin | 30+ management tools (flows, pipelines, jobs, logs, memory, content) |
| **System** | Background infrastructure tasks | Alt text, daily memory, image generation, internal linking, meta descriptions (GitHub issues in data-machine-code extension) |

Built-in mode guidance is injected by `AgentModeDirective` at runtime and extensions can register more modes through `AgentModeRegistry`. Configure AI provider and model per mode in Settings. Each mode falls back to the global default if no override is set.

### Agent Memory

Persistent markdown files injected into every AI context:

```
shared/
  SITE.md                  — Site-wide context
agents/{slug}/
  SOUL.md                  — Identity, voice, rules
  MEMORY.md                — Accumulated knowledge
  daily/YYYY/MM/DD.md      — Automatic daily journals
users/{id}/
  USER.md                  — Information about the human
```

Discovery: `wp datamachine memory paths --allow-root`

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

Social publishing, workspace, and GitHub abilities live in extension plugins such as data-machine-socials and data-machine-code.

### Content Formats

Content and publish abilities accept `content_format` (`markdown`, `html`, or `blocks`) as the caller's source format. Data Machine stores content in the post type's canonical format from `datamachine_post_content_format`, converting through its bundled Block Format Bridge substrate.

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
wp datamachine agents           # Agent management and path discovery
wp datamachine pipelines        # Pipeline CRUD
wp datamachine flows            # Flow CRUD and queue management
wp datamachine jobs             # Job management, monitoring, undo
wp datamachine settings         # Plugin settings
wp datamachine posts            # Query Data Machine-created posts
wp datamachine logs             # Log operations
wp datamachine memory           # Agent memory read/write
wp datamachine handlers         # List registered handlers
wp datamachine step-types       # List registered step types
wp datamachine chat             # Chat agent interface
wp datamachine alt-text         # AI alt text generation
wp datamachine links            # Internal linking
wp datamachine blocks           # Gutenberg block operations
wp datamachine image            # Image generation
wp datamachine meta-description # SEO meta descriptions
wp datamachine auth             # OAuth provider management
wp datamachine taxonomy         # Taxonomy operations
wp datamachine batch            # Batch operations
wp datamachine system           # System task management
wp datamachine analytics        # Analytics and tracking
```

## REST API

Full REST API under `datamachine/v1`:

- `POST /execute` — Execute a flow
- `POST /trigger/{flow_id}` — Webhook trigger with Bearer token auth
- `POST /chat` — Chat agent interface
- `GET|POST /pipelines` — Pipeline CRUD
- `GET|POST /flows` — Flow CRUD with queue management
- `GET|POST /jobs` — Job management
- `POST /jobs/{id}/undo` — Job undo
- `GET /agent/paths` — Agent file path discovery

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

OpenAI, Anthropic, Google, Grok, OpenRouter — configure a global default per-site, with per-mode overrides for pipeline, chat, and system.

## Runtime Adapters

Data Machine ships its own multi-turn conversation loop and uses it by default. The loop is also swappable: a single Agents API-shaped filter (`agents_api_conversation_runner`) lets an external runtime take over while Data Machine still provides pipelines, flows, tool resolution, abilities, and memory.

```php
add_filter(
    'agents_api_conversation_runner',
    function ( $result, $messages, $tools, $provider, $model, $context, $payload, $max_turns, $single_turn ) {
        // Return an array matching AIConversationLoop::execute()'s shape to
        // replace the built-in loop, or null to let Data Machine run it.
        return my_runtime_run( ... );
    },
    10,
    9
);
```

This mirrors the provider pattern used by the bundled AI HTTP Client: providers swap how the LLM is called; runtime adapters swap how the conversation is run. Data Machine makes no assumptions about the host runtime — the filter is the entire contract.

See [`docs/core-system/ai-conversation-loop.md`](docs/core-system/ai-conversation-loop.md#runtime-adapters) for the full adapter contract and return-shape reference.

## Memory Storage Adapters

Agent memory files (MEMORY.md, SOUL.md, USER.md, NETWORK.md, AGENTS.md, plus any custom files registered through `MemoryFileRegistry`) persist on the local filesystem by default. The persistence layer is swappable through a single Agents API-shaped filter (`agents_api_memory_store`), enabling DB-backed implementations on managed hosts that don't expose a writable filesystem.

```php
add_filter(
    'agents_api_memory_store',
    function ( $store, $scope ) {
        // Return an AgentMemoryStoreInterface to replace the disk default
        // for this scope, or null to let Data Machine read/write through
        // the filesystem.
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
homeboy test data-machine    # PHPUnit tests
homeboy audit data-machine   # Architecture and convention audits
homeboy build data-machine   # Test, lint, build, package
homeboy lint data-machine    # PHPCS with WordPress standards
```

## Documentation

- [docs/](docs/) — User documentation
- [docs/architecture/pipeline-execution-axes.md](docs/architecture/pipeline-execution-axes.md) — Four orthogonal axes of work expansion in a pipeline
- Data Machine skill and agent instruction files are generated into consumer environments rather than stored in this plugin tree
- [docs/CHANGELOG.md](docs/CHANGELOG.md) — Version history

## Star History

[![Star History Chart](https://api.star-history.com/svg?repos=Extra-Chill/data-machine&type=date&legend=top-left)](https://www.star-history.com/#Extra-Chill/data-machine&type=date&legend=top-left)
