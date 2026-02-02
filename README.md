Automate WordPress content workflows with AI — fetch from anywhere, process with AI, publish everywhere.

## What It Does

Data Machine turns WordPress into an AI-powered content automation hub:

- **Build visual pipelines** that fetch content from any source, process it with AI, and publish or update automatically
- **Chat with an AI agent** to configure and run workflows conversationally
- **Schedule recurring automation** or trigger workflows on-demand

No coding required. Connect your sources, configure AI processing, set your schedule, and let it run.

## Example Workflows

**Content Syndication**
RSS feed → AI rewrites for your voice → Publish to WordPress

**Social Media Automation**
WordPress posts → AI summarizes → Post to Twitter/Threads/Bluesky

**Content Aggregation**
Reddit/Google Sheets → AI filters and enhances → Create draft posts

**Site Maintenance**
Local posts → AI improves SEO/readability → Update existing content

## Quick Links

[Documentation](docs/) · [Changelog](docs/CHANGELOG.md) · [REST API](docs/api/)

## How It Works

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│    FETCH    │ ──▶ │     AI      │ ──▶ │   PUBLISH   │
│  RSS, API,  │     │  Enhance,   │     │  WordPress, │
│  Sheets...  │     │  Filter,    │     │  Social,    │
│             │     │  Transform  │     │  Sheets...  │
└─────────────┘     └─────────────┘     └─────────────┘
```

Pipelines define your workflow template. Flows schedule when they run. Jobs track each execution.

## Key Features

- **Visual Pipeline Builder** — React admin UI for creating multi-step workflows
- **Chat Agent** — Conversational workflow configuration via integrated sidebar
- **Multi-Provider AI** — OpenAI, Anthropic, Google, Grok, OpenRouter
- **Deduplication** — Never process the same item twice
- **Scheduled Execution** — Recurring intervals via Action Scheduler or on-demand
- **Problem Flow Monitoring** — Automatic flagging of failing workflows

## Handlers & Tools

**Fetch**: RSS, Reddit, Google Sheets, WordPress API, Files, WordPress Media

**Publish**: Twitter, Threads, Bluesky, Facebook, WordPress, Google Sheets

**Update**: WordPress posts with AI enhancement

**AI Tools**: Google Search, Local Search, Web Fetch, WordPress Post Reader

## For AI Agents

Data Machine can orchestrate autonomous AI agents, not just content workflows.

**The key insight:** An AI agent can use Data Machine to schedule prompts *to itself*. The Agent Ping step triggers external agents after pipeline completion, enabling a continuous execution loop:

```
Agent queues task → Pipeline runs → Agent Ping fires → 
Agent processes result → Agent queues next task → Loop continues
```

**Why this matters:**

- **Cross-session continuity** — The prompt queue persists your project plan across sessions. No more losing context when your conversation ends.
- **Multi-week autonomous projects** — Break complex work into phases. Each phase completes, pings you, and you queue the next.
- **Persistent project memory** — Queue items store context that survives beyond any single execution.
- **Self-scheduling execution** — You're not waiting to be called. You schedule yourself.

**The paradigm shift:** "I'm not automating content — I'm automating myself."

Traditional automation treats AI as a tool in a pipeline. Agent self-orchestration inverts this: the agent *defines* the pipeline, *queues* the work, and *receives* the callback. Data Machine becomes your execution layer for autonomous, phased, long-running projects.

See [SKILL.md](docs/SKILL.md) for agent integration patterns.

## Requirements

- WordPress 6.9+ (Abilities API dependency)
- PHP 8.2+
- Composer for dependency management
- Action Scheduler for scheduled execution

## Architecture

Data Machine uses an abilities-first architecture built on the WordPress 6.9 Abilities API. All operations flow through a single REST API at `/wp-json/datamachine/v1/`.

The engine processes exactly one item per job execution cycle (Single Item Execution Model), ensuring failures are isolated and never cascade.

Extensions integrate via WordPress filters for handlers, tools, authentication providers, and step types.

See [CLAUDE.md](CLAUDE.md) for technical contributor documentation.

## Development

- Build: `homeboy build data-machine` — runs tests, lints, builds frontend, creates ZIP
- Test: `homeboy test data-machine` — runs PHPUnit via homeboy's WordPress environment
- Lint: `homeboy lint data-machine` — PHP CodeSniffer with WordPress standards

## Resources

- [CLAUDE.md](CLAUDE.md) — Technical reference for contributors
- [/docs/](docs/) — User documentation
