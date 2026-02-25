# Data Machine

AI-first WordPress automation and agent self-orchestration platform — pipelines, chat agent, memory, and tools.

## What It Does

Data Machine turns WordPress into an AI-powered automation and agent platform:

- **Visual pipeline builder** — Create multi-step workflows without code
- **Chat agent** — Conversational AI interface with specialized tools for managing workflows, content, and site operations
- **Agent memory** — Persistent SOUL.md, USER.md, MEMORY.md, and daily memory files that survive across sessions
- **Workspace** — Managed directory for repo clones and file operations with security sandboxing
- **Self-scheduling orchestration** — AI agents schedule recurring tasks for themselves using Agent Pings and prompt queues
- **Webhook triggers** — Inbound REST endpoints to trigger flows from external systems

## How It Works

### Pipelines

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│    FETCH    │ ──▶ │     AI      │ ──▶ │   PUBLISH   │
│  RSS, API,  │     │  Enhance,   │     │  WordPress, │
│  WordPress  │     │  Transform  │     │  Social...  │
└─────────────┘     └─────────────┘     └─────────────┘
```

**Pipelines** define your workflow template. **Flows** schedule when they run. **Jobs** track each execution.

### Chat Agent

An integrated conversational AI that lives in wp-admin. It can create pipelines, manage flows, run workflows, query logs, and operate on your site — all through natural language. Tools are focused and mutation-safe: read operations go through `ApiQuery`, writes go through specialized tools.

### Agent Memory

Persistent markdown files that define who your agent is and what it knows:

- **SOUL.md** — Identity, voice, rules
- **USER.md** — Information about the site owner
- **MEMORY.md** — Accumulated knowledge, structured by section
- **Daily memory** — Automatic YYYY/MM/DD.md journal files

Memory files are injected as AI directives, so every conversation starts with context.

## Agents

Data Machine runs three distinct AI agents, each configurable with its own provider and model:

| Agent | Purpose | Tools |
|-------|---------|-------|
| **Pipeline** | Automated workflow execution — processes content through pipeline steps | Per-step AI processing with handler-specific context |
| **Chat** | Conversational interface in wp-admin — manages workflows, content, and site operations | 30+ specialized tools (ApiQuery, CreateFlow, RunFlow, ManageLogs, etc.) |
| **System** | Background infrastructure tasks triggered by hooks | Alt text generation, daily memory, image generation, internal linking, GitHub issues |

Configure provider and model per agent type in Settings. Each agent type falls back to the global default if no override is set.

## Step Types & Handlers

Pipelines are built from **step types**. Some step types use pluggable **handlers** — interchangeable implementations that define *how* the step operates. Others are self-contained.

### Steps with handlers

| Step Type | Handlers |
|-----------|----------|
| **Fetch** | RSS, Reddit, WordPress (local posts), WordPress API (remote), WordPress Media, Files |
| **Publish** | WordPress |
| **Update** | WordPress posts with AI enhancement |

Additional handlers available via [extensions](#extensions) (Google Sheets, social platforms, etc.).

### Self-contained steps

| Step Type | Description |
|-----------|-------------|
| **AI** | Process content with the configured AI provider |
| **Agent Ping** | Outbound webhook to trigger external agents |
| **Webhook Gate** | Pause pipeline mid-execution until an external webhook callback fires |

## Example Workflows

| Workflow | Steps |
|----------|-------|
| Content Syndication | RSS → AI rewrites → Publish to WordPress |
| Content Aggregation | Reddit → AI filters → Create drafts |
| Site Maintenance | Local posts → AI improves SEO → Update content |
| Multi-platform Publishing | Content → AI optimizes → Publish to multiple destinations via extensions |

## For AI Agents

Data Machine is a **self-scheduling execution layer** for autonomous AI agents.

### Core Concepts

1. **Flows run on schedules** — Daily, hourly, or cron expressions
2. **Prompts are queueable** — Both AI and Agent Ping steps pop from queues
3. **Agent Ping triggers external agents** — Outbound webhook fires with context for the receiving agent
4. **Webhook triggers fire flows** — `POST /datamachine/v1/trigger/{flow_id}` with Bearer token auth starts flows from external systems
5. **Webhook Gate pauses pipelines** — Mid-pipeline pause/resume awaiting an external webhook callback

### The Pattern

```
Agent queues task → Flow runs → Agent Ping fires →
Agent executes → Agent queues next task → Loop continues
```

The prompt queue is your **persistent project memory**. Multi-phase work survives across sessions. You're not waiting to be called — you schedule yourself.

See [skills/data-machine/SKILL.md](skills/data-machine/SKILL.md) for agent integration patterns.

## AI Tools

Global tools available to the chat agent and system agent:

| Tool | Description |
|------|-------------|
| **Web Fetch** | Fetch and parse web content |
| **Google Search** | Web search |
| **Google Search Console** | Search analytics and performance data |
| **Bing Webmaster** | Bing search analytics |
| **Image Generation** | AI image creation with smart content-gap placement |
| **Local Search** | WordPress site search |
| **WordPress Post Reader** | Read and inspect WordPress post content |
| **Agent Memory** | Read and write agent memory sections |
| **Queue Validator** | Validate and inspect prompt queues |
| **Amazon Affiliate Link** | Generate affiliate links |

## WP-CLI

Comprehensive command-line interface for all operations:

```bash
wp datamachine settings          # Plugin settings
wp datamachine pipelines         # Pipeline CRUD
wp datamachine flows             # Flow CRUD and queue management
wp datamachine jobs              # Job management and monitoring
wp datamachine posts             # Query Data Machine-created posts
wp datamachine logs              # Log operations
wp datamachine memory            # Agent memory read/write
wp datamachine workspace         # Workspace file operations
wp datamachine alt-text          # AI alt text generation
wp datamachine links             # Internal linking
wp datamachine blocks            # Gutenberg block operations
```

## REST API

Full REST API under the `datamachine/v1` namespace — pipelines, flows, jobs, settings, auth, chat, files, logs, and more. Key endpoints:

- `POST /datamachine/v1/execute` — Execute a flow directly
- `POST /datamachine/v1/trigger/{flow_id}` — Webhook trigger with Bearer token auth
- `POST /datamachine/v1/chat` — Chat agent interface
- `GET/POST /datamachine/v1/pipelines` — Pipeline CRUD
- `GET/POST /datamachine/v1/flows` — Flow CRUD with queue management

## Extensions

Extend Data Machine with companion plugins:

| Plugin | Description |
|--------|-------------|
| [data-machine-business](https://github.com/Extra-Chill/data-machine-business) | Google Sheets (fetch + publish), Slack, Discord integrations |
| [data-machine-socials](https://github.com/Extra-Chill/data-machine-socials) | Publish to Twitter, Threads, Bluesky, Facebook, Pinterest |
| [datamachine-events](https://github.com/Extra-Chill/datamachine-events) | Event data extraction and structured data processing |
| [datamachine-recipes](https://github.com/Sarai-Chinwag/datamachine-recipes) | Recipe content extraction and schema processing |
| [data-machine-quiz](https://github.com/Sarai-Chinwag/data-machine-quiz) | Quiz creation and management tools |

## AI Providers

OpenAI, Anthropic, Google, Grok, OpenRouter — configure a global default per-site, with per-agent-type overrides for chat, pipeline, and system agents.

## Requirements

- WordPress 6.9+ (Abilities API)
- PHP 8.2+
- Action Scheduler (bundled)

## Development

```bash
homeboy build data-machine  # Test, lint, build, package
homeboy test data-machine   # PHPUnit tests
homeboy lint data-machine   # PHPCS with WordPress standards
```

## Documentation

- [docs/](docs/) — User documentation
- [skills/data-machine/SKILL.md](skills/data-machine/SKILL.md) — Agent integration patterns
- [AGENTS.md](AGENTS.md) — Technical reference for contributors
- [docs/CHANGELOG.md](docs/CHANGELOG.md) — Version history

## Star History

[![Star History Chart](https://api.star-history.com/svg?repos=Extra-Chill/data-machine&type=date&legend=top-left)](https://www.star-history.com/#Extra-Chill/data-machine&type=date&legend=top-left)
