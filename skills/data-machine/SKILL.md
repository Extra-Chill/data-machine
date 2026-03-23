---
name: data-machine
description: "AI-powered WordPress operations engine. System tasks for site health (SEO, images, links, performance). Pipelines for automated content workflows. Agent memory for persistent context. Discover everything via wp help datamachine."
compatibility: "WordPress 6.9+ with Data Machine plugin. WP-CLI required."
---

# Data Machine

AI-powered WordPress operations engine — system tasks, content pipelines, and agent memory.

## Discovery

Data Machine is fully discoverable via WP-CLI. **Do not memorize commands from this file.** Use the CLI to find what you need in real time:

```bash
# See all command groups
wp datamachine

# See subcommands in any group
wp help datamachine <group>

# See full usage, flags, and examples for any command
wp help datamachine <group> <subcommand>
```

Singular/plural aliases work interchangeably: `flow`/`flows`, `job`/`jobs`, `link`/`links`, etc.

This pattern means you always have accurate, up-to-date documentation — the running plugin IS the docs.

## What Data Machine Does

DM has two layers:

### 1. System Tasks

Built-in operations that work on your WordPress site directly. No pipeline setup required — just run the command.

**Site Health & SEO:**
- **Alt text** — diagnose missing alt text, AI-generate it in bulk
- **Internal links** — diagnose coverage, find orphan posts, detect broken links, AI-powered crosslinking
- **Content blocks** — inspect and edit Gutenberg blocks in any post
- **Analytics** — PageSpeed Insights audits, Google Search Console, Google Analytics, Bing Webmaster
- **Images** — AI generation, template rendering

**Agent Operations:**
- **Memory** — read/write agent files (SOUL.md, MEMORY.md, etc.), daily logs, search
- **Workspace** — managed git repos with read/write/edit through security boundaries
- **GitHub** — issues, PRs, repos — managed from within WordPress
- **Batch** — track long-running operations

To explore any of these, run `wp help datamachine <group>` — e.g. `wp help datamachine links`, `wp help datamachine analytics`, `wp help datamachine alt-text`.

### 2. Pipelines & Flows

Automated content workflows that run on schedules.

**Pipeline** = template (defines steps) → **Flow** = instance (adds schedule + config) → **Job** = single execution

**Step types:** `fetch`, `ai`, `publish`, `update`, `agent_ping`, `webhook_gate`. Discover details with `wp help datamachine pipelines`.

**Scheduling options:** manual, one_time, every_5_minutes, hourly, every_2_hours, every_4_hours, qtrdaily (6h), twicedaily, daily, every_3_days, weekly, monthly.

**Prompt queues:** AI and Agent Ping steps can pop tasks from a queue, so each run processes a different instruction. Explore with `wp help datamachine flows queue`.

**Webhook triggers:** External systems can fire flows via POST. See `wp help datamachine flows webhook`.

## Memory System

Agent memory uses a three-layer directory system under `{uploads}/datamachine-files/`. Core files are injected as system context into every AI call:

- **shared/SITE.md** — site-wide context (shared layer)
- **shared/RULES.md** — site-wide rules (shared layer)
- **agents/{slug}/SOUL.md** — identity, voice, rules (agent layer)
- **agents/{slug}/MEMORY.md** — accumulated knowledge (agent layer)
- **users/{id}/USER.md** — human user profile (user layer)
- **agents/{slug}/daily/YYYY/MM/DD.md** — temporal session logs

Manage via `wp datamachine agent` (aliased as `wp datamachine memory`). Run `wp help datamachine agent` to see all subcommands.

## AI Tools (During Pipeline Execution)

When running inside a pipeline, the AI step has access to tools. These are NOT CLI commands — they're available to the AI model during flow execution. Key tools include: local_search, image_generation, agent_memory, web_fetch, wordpress_post_reader, google_search, google_search_console, bing_webmaster, skip_item, queue_validator.

The tool list is managed by the plugin and may grow. Check pipeline logs to see which tools are available.

## Debugging

```bash
wp datamachine logs read pipeline    # pipeline execution logs
wp datamachine logs read system      # system-level logs
wp datamachine jobs summary          # job status overview
wp datamachine jobs list --status=failed
wp datamachine jobs show <id> --format=json
```

## Key Patterns

**Queue-driven work:** Add tasks to a flow's queue → flow runs on schedule → pops and executes one task per run.

**Chaining:** An AI step can queue the next phase of work for a subsequent run.

**Agent Ping:** A step type that POSTs to an external webhook (Discord bot, automation engine, etc.) to hand off work to another agent.

**System tasks on demand:** Run `wp datamachine links diagnose` or `wp datamachine alt-text diagnose` anytime — no flow needed.

## REST API

Chat API at `/wp-json/datamachine/v1/chat/` with 30+ tool-based abilities. Agent files at `/datamachine/v1/files/agent/{filename}`. Webhook triggers at `/datamachine/v1/trigger/{flow_id}`.

## Remember

When in doubt, `wp help datamachine` will show you everything. Start there.
