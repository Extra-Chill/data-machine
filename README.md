# Data Machine

Automate WordPress content workflows with AI — fetch from anywhere, process with AI, publish everywhere.

## What It Does

Data Machine turns WordPress into an AI-powered content automation hub:

- **Visual pipeline builder** — Create multi-step workflows without code
- **AI processing** — Enhance, filter, and transform content with any provider
- **Scheduled execution** — Run workflows on intervals or on-demand
- **Agent orchestration** — AI agents can schedule recurring tasks for themselves using Agent Pings 

## How It Works

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│    FETCH    │ ──▶ │     AI      │ ──▶ │   PUBLISH   │
│  RSS, API,  │     │  Enhance,   │     │  WordPress, │
│  Sheets...  │     │  Transform  │     │  Social...  │
└─────────────┘     └─────────────┘     └─────────────┘
```

**Pipelines** define your workflow template. **Flows** schedule when they run. **Jobs** track each execution.

## Example Workflows

| Workflow | Steps |
|----------|-------|
| Content Syndication | RSS → AI rewrites → Publish to WordPress |
| Content Aggregation | Reddit/Sheets → AI filters → Create drafts |
| Site Maintenance | Local posts → AI improves SEO → Update content |
| Multi-platform Publishing | Content → AI optimizes → Publish to multiple destinations via extensions |

## For AI Agents

Data Machine is also a **self-scheduling execution layer** for autonomous AI agents.

### Core Concepts

1. **Flows run on schedules** — Daily, hourly, or cron expressions
2. **Prompts are queueable** — Both AI and Agent Ping steps pop from queues
3. **Agent Ping triggers external agents** — Webhook fires after pipeline completion (outbound-only; inbound triggers use REST endpoints like `/execute` and `/chat`)

### The Pattern

```
Agent queues task → Flow runs → Agent Ping fires → 
Agent executes → Agent queues next task → Loop continues
```

The prompt queue is your **persistent project memory**. Multi-phase work survives across sessions. You're not waiting to be called — you schedule yourself.

See [skills/data-machine/SKILL.md](skills/data-machine/SKILL.md) for agent integration patterns.

## Handlers

| Type | Options |
|------|---------|
| **Fetch** | RSS, Reddit, Google Sheets, WordPress API, Files, Media |
| **Publish** | WordPress, Google Sheets |
| **Update** | WordPress posts with AI enhancement |

## Extensions

Extend Data Machine with companion plugins:

| Plugin | Description |
|--------|-------------|
| [data-machine-socials](https://github.com/Extra-Chill/data-machine-socials) | Publish to Twitter, Threads, Bluesky, Facebook, Pinterest |
| [datamachine-events](https://github.com/Extra-Chill/datamachine-events) | Event data extraction and structured data processing |
| [datamachine-recipes](https://github.com/Sarai-Chinwag/datamachine-recipes) | Recipe content extraction and schema processing |
| [data-machine-quiz](https://github.com/Sarai-Chinwag/data-machine-quiz) | Quiz creation and management tools |

## AI Providers

OpenAI, Anthropic, Google, Grok, OpenRouter — configure per-site or per-pipeline.

## Requirements

- WordPress 6.9+ (Abilities API)
- PHP 8.2+
- Action Scheduler

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
