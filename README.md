# Data Machine

Agentic infrastructure for WordPress.

## What It Does

Data Machine turns a WordPress site into an agent runtime — persistent identity, memory, pipelines, abilities, and tools that AI agents use to operate autonomously.

- **Pipelines** — Multi-step workflows: fetch content, process with AI, publish anywhere
- **Abilities API** — Typed, permissioned functions that agents and extensions call (`datamachine/upload-media`, `datamachine/validate-media`, etc.)
- **Agent memory** — Layered markdown files (SOUL.md + MEMORY.md in agent layer, USER.md in user layer) injected into every AI context
- **Multi-agent** — Multiple agents with scoped pipelines, flows, jobs, and filesystem directories
- **Workspace** — Managed directory for repo clones and file operations with security sandboxing
- **Self-scheduling** — Agents schedule their own recurring tasks using flows, prompt queues, and Agent Pings

## Architecture

### Pipelines

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│    FETCH    │ ──▶ │     AI      │ ──▶ │   PUBLISH   │
│  RSS, API,  │     │  Enhance,   │     │  WordPress, │
│  WordPress  │     │  Transform  │     │  Workspace  │
└─────────────┘     └─────────────┘     └─────────────┘
```

**Pipelines** define the workflow template. **Flows** schedule when they run. **Jobs** track each execution with full undo support.

### Agent Contexts

One agent, three operational modes — same identity and memory, different tools:

| Context | Purpose | Tools |
|---------|---------|-------|
| **Pipeline** | Automated workflow execution | Handler-specific tools scoped to the current step |
| **Chat** | Conversational interface in wp-admin | 30+ management tools (flows, pipelines, jobs, logs, memory, content) |
| **System** | Background infrastructure tasks | Alt text, daily memory, image generation, internal linking, meta descriptions, GitHub issues |

Configure AI provider and model per context in Settings. Each context falls back to the global default if no override is set.

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

Discovery: `wp datamachine agent paths --allow-root`

### Abilities API

Typed, permissioned functions registered via WordPress's Abilities API. Extensions and agents consume them instead of reaching into internals:

| Ability | Description |
|---------|-------------|
| `datamachine/upload-media` | Upload/fetch image or video, store in repository or Media Library |
| `datamachine/validate-media` | Validate against platform constraints (duration, size, codec, aspect ratio) |
| `datamachine/video-metadata` | Extract duration, resolution, codec via ffprobe |
| `datamachine/instagram-publish` | Publish to Instagram (image, carousel, Reel, Story) |
| `datamachine/twitter-publish` | Publish to Twitter with media support |
| `datamachine/flow-execute` | Execute a flow programmatically |
| ... | 40+ abilities across media, publishing, content, SEO, and infrastructure |

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
| **Fetch** | RSS, WordPress (local posts), WordPress API (remote), WordPress Media, Files, GitHub | Google Sheets, Reddit, social platforms |
| **Publish** | WordPress, Workspace | Twitter, Instagram, Facebook, Threads, Bluesky, Pinterest, Google Sheets, Slack, Discord |
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

## System Tasks

Background AI tasks that run on hooks or schedules:

| Task | Description |
|------|-------------|
| **Alt Text** | Generate alt text for images missing it |
| **Image Generation** | AI image creation with content-gap placement |
| **Daily Memory** | Consolidate MEMORY.md, archive to daily files |
| **Internal Linking** | AI-powered internal link suggestions |
| **Meta Descriptions** | Generate SEO meta descriptions |
| **GitHub Issues** | Create issues from pipeline findings |

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
| [data-machine-socials](https://github.com/Extra-Chill/data-machine-socials) | Publish to Instagram (images, carousels, Reels, Stories), Twitter (text + media + video), Facebook, Threads, Bluesky, Pinterest (image + video pins). Reddit fetch. |
| [data-machine-business](https://github.com/Extra-Chill/data-machine-business) | Google Sheets (fetch + publish), Slack, Discord integrations |
| [datamachine-events](https://github.com/Extra-Chill/datamachine-events) | Event data extraction and structured data processing |
| [datamachine-recipes](https://github.com/Sarai-Chinwag/datamachine-recipes) | Recipe content extraction and schema processing |
| [data-machine-quiz](https://github.com/Sarai-Chinwag/data-machine-quiz) | Quiz creation and management tools |

## AI Providers

OpenAI, Anthropic, Google, Grok, OpenRouter — configure a global default per-site, with per-context overrides for pipeline, chat, and system.

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
- [skills/data-machine/SKILL.md](skills/data-machine/SKILL.md) — Agent integration patterns
- [AGENTS.md](AGENTS.md) — Technical reference for contributors
- [docs/CHANGELOG.md](docs/CHANGELOG.md) — Version history

## Star History

[![Star History Chart](https://api.star-history.com/svg?repos=Extra-Chill/data-machine&type=date&legend=top-left)](https://www.star-history.com/#Extra-Chill/data-machine&type=date&legend=top-left)
