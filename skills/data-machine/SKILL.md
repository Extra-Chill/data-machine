---
name: data-machine
description: "Self-scheduling execution layer for autonomous task orchestration. Use for queuing tasks, chaining pipeline executions, scheduling recurring work, and 24/7 autonomous operation via Agent Ping webhooks."
compatibility: "WordPress 6.9+ with Data Machine plugin. WP-CLI required for queue management."
---

# Data Machine Skill

Self-scheduling execution layer for AI agents — reminder system + task manager + workflow executor.

**Pipeline** (template) → **Flow** (instance + schedule) → **Job** (single execution)

## Core Concepts

1. **Flows operate on schedules** — "ping me at X time to do Y"
2. **Step-level prompt queues** — each run can be a different task
3. **Purpose-specific flows** — separate flows for separate concerns

## Step Types

| Type | Purpose | Queueable | Uses Handlers |
|------|---------|-----------|---------------|
| `fetch` | Import from external sources | No | Yes |
| `ai` | AI processing (multi-turn, tools) | **Yes** | No |
| `publish` | Output to platforms | No | Yes |
| `update` | Modify existing content | No | Yes |
| `agent_ping` | Webhook to external agents | **Yes** | No |
| `webhook_gate` | Pause until external POST resumes it | No | No |

## Handlers

**Fetch:** `rss`, `files`, `reddit`, `wordpress_posts`, `wordpress_api`, `wordpress_media`
**Publish:** `wordpress_publish` (social handlers in separate `data-machine-socials` plugin)
**Update:** `wordpress_update`

## Scheduling

Set via `--scheduling` on flow create/update: `manual`, `one_time`, `every_5_minutes`, `hourly`, `every_2_hours`, `every_4_hours`, `qtrdaily` (6h), `twicedaily`, `daily`, `every_3_days`, `weekly`, `monthly`. Filterable via `datamachine_scheduler_intervals`.

## Memory System

Files in `{uploads}/datamachine-files/agent/` are injected as system context into every AI call:

- **SOUL.md** — agent identity, voice, rules (always injected)
- **USER.md** — who the human user is, preferences, goals (always injected)
- **MEMORY.md** — accumulated state, lessons, domain context (always injected)
- Additional files selectable per-pipeline via admin UI

All three core files are protected from deletion. Manage via CLI, REST API, or admin UI.

```
GET/PUT/DELETE  /datamachine/v1/files/agent/{filename}
```

### Daily Memory

Date-based logs at `YYYY/MM/DD.md` — useful for per-run notes, progress tracking.

```bash
wp datamachine agent daily list
wp datamachine agent daily read [<date>]
wp datamachine agent daily append <date> --content="..."
```

---

## AI Tools (Pipeline Execution)

| Tool | Purpose |
|------|---------|
| `local_search` | Search site content (duplicate detection) |
| `skip_item` | Skip items, sets `agent_skipped` status |
| `image_generation` | Generate images via Replicate API, auto-sets featured image |
| `agent_memory` | Read/write memory files during execution |
| `web_fetch` | Fetch external URLs |
| `wordpress_post_reader` | Read full post content by ID |
| `google_search` | Web search via Google Custom Search |
| `google_search_console` | GSC analytics queries |
| `bing_webmaster` | Bing Webmaster analytics |
| `amazon_affiliate_link` | Generate Amazon affiliate links |
| `queue_validator` | Validate/manage queue state |
| `github_create_issue` | File GitHub issues from pipelines |

**`local_search` tip:** Search core topic, not exact title. "pelicans dangerous" catches "Are Australian Pelicans Dangerous?"

**`image_generation`** is async — System Agent handles polling and attachment creation in background.

---

## CLI Reference

All commands accept `--allow-root`. Singular/plural aliases work interchangeably (`flow`/`flows`, `job`/`jobs`, etc.).

### Pipelines

```bash
wp datamachine pipelines                              # list
wp datamachine pipelines get <id>                     # details
wp datamachine pipelines create --name="..." [--steps='[...]'] [--dry-run]
wp datamachine pipelines update <id> [--name=<n>] [--config=<json>]
wp datamachine pipelines update <id> --set-system-prompt="..." [--step=<step_id>]
wp datamachine pipelines delete <id> [--force]
```

### Flows

```bash
wp datamachine flows [<pipeline_id>]                  # list
wp datamachine flows get <id>                         # details
wp datamachine flows create --pipeline_id=<id> --name="..." [--scheduling=<interval>]
wp datamachine flows update <id> [--name=<n>] [--scheduling=<interval>]
wp datamachine flows update <id> --set-prompt="..." [--step=<step_id>]
wp datamachine flows run <id> [--count=<1-10>]
wp datamachine flows delete <id> [--yes]
```

### Queues

AI and Agent Ping steps pop from queue when prompt is empty and `queue_enabled` is true.

```bash
wp datamachine flows queue add <flow_id> "Task instruction" [--step=<step_id>]
wp datamachine flows queue list <flow_id>
wp datamachine flows queue remove <flow_id> <index>
wp datamachine flows queue update <flow_id> <index> "New text"
wp datamachine flows queue move <flow_id> <from> <to>
wp datamachine flows queue clear <flow_id>
```

### Jobs

```bash
wp datamachine jobs list [--status=<s>] [--flow=<id>] [--limit=<n>]
wp datamachine jobs show <id> [--format=json]
wp datamachine jobs summary
wp datamachine jobs fail <id> [--reason="..."]
wp datamachine jobs retry <id> [--force]
wp datamachine jobs recover-stuck [--dry-run] [--flow=<id>]
```

### Agent

```bash
wp datamachine agent read <filename>
wp datamachine agent write <filename> --content="..."
wp datamachine agent search <query>
wp datamachine agent sections <filename>
wp datamachine agent files list
wp datamachine agent files read <filename>
wp datamachine agent files write <filename>       # from stdin
wp datamachine agent files check [--days=<n>]
# Daily: list, read, write, append, delete, search
wp datamachine agent daily list [--limit=<n>]
wp datamachine agent daily append <date> --content="..."
```

Alias: `wp datamachine memory` = `wp datamachine agent`

### Posts

```bash
wp datamachine posts recent [--limit=<n>] [--post_type=<t>] [--format=json]
wp datamachine posts by-flow <flow_id> [--per_page=<n>]
wp datamachine posts by-handler <slug> [--post_type=<t>]
wp datamachine posts by-pipeline <id>
```

### Workspace

Managed repo access at `/var/lib/datamachine/workspace/`. Use CLI over direct filesystem access — enforces security boundaries.

```bash
wp datamachine workspace list                         # list repos
wp datamachine workspace show <repo>                  # repo details
wp datamachine workspace ls <repo> [path]             # list files
wp datamachine workspace read <repo> <path>           # read file
wp datamachine workspace write <repo> <path> --content="..."       # write
wp datamachine workspace write <repo> <path> --content=@/tmp/f.rs  # write from file
wp datamachine workspace edit <repo> <path> --old="..." --new="..." [--replace-all]
wp datamachine workspace clone <git_url>
wp datamachine workspace remove <repo>
```

### Webhook Triggers

External systems can fire flows via `POST /datamachine/v1/trigger/{flow_id}` with Bearer token.

```bash
wp datamachine flows webhook enable <flow_id>
wp datamachine flows webhook disable <flow_id>
wp datamachine flows webhook regenerate <flow_id>
wp datamachine flows webhook status <flow_id>
wp datamachine flows webhook list
```

### Content Tools

```bash
# Blocks
wp datamachine blocks list <post_id> [--type=<t>] [--search=<s>]
wp datamachine blocks edit <post_id> <index> --find="..." --replace="..." [--dry-run]

# Links
wp datamachine links diagnose
wp datamachine links crosslink [--category=<slug>] [--all] [--links-per-post=<n>]

# Alt Text
wp datamachine alt-text diagnose
wp datamachine alt-text generate [--post_id=<id>] [--force]
```

### Settings & Logs

```bash
wp datamachine settings list | get <key> | set <key> <value>
wp datamachine logs read <pipeline|system|chat>
wp datamachine logs level <type> [<level>]
wp datamachine logs clear <type|all> [--yes]
```

---

## Prompt Queue Patterns

### Chaining

Agent executes task, queues the next phase:

```
"Phase 1: Design architecture" → agent queues "Phase 2: Implement schema"
"Phase 2: Implement schema"    → agent queues "Phase 3: Build API"
```

### Purpose-Specific Flows

```
Content Generation (queue-driven): AI → Publish → Agent Ping
Content Ideation (daily):          Agent Ping: "Review analytics, queue topics"
Weekly Review (weekly):            Agent Ping: "Analyze performance"
Coding Tasks (manual, queue):      Agent Ping: pops specific task
```

Each flow has its own schedule, queue, and single responsibility.

---

## Agent Ping Payload

```json
{
  "prompt": "Task instruction",
  "context": {
    "flow_id": 7, "pipeline_id": 3, "job_id": 1234,
    "post_id": 5678, "post_type": "post",
    "published_url": "https://example.com/my-post/",
    "data_packets": [], "engine_data": {},
    "from_queue": false,
    "site_url": "https://example.com", "wp_path": "/var/www/html/"
  },
  "timestamp": "2026-02-25T03:00:00+00:00"
}
```

Config: `webhook_url`, `prompt` (static or empty to use queue), `queue_enabled`.

---

## Taxonomy Handling

| Mode | Behavior |
|------|----------|
| `skip` | Don't assign |
| `ai_decides` | AI provides values via tool params (hierarchical: string, flat: array) |
| `<term>` | Pre-selected term (ID, name, or slug) |

---

## Debugging

```bash
wp datamachine logs read pipeline              # check logs
wp datamachine jobs list --status=failed        # find failures
wp datamachine jobs show <id> --format=json     # failure details
wp datamachine jobs summary                     # status overview
wp datamachine jobs recover-stuck [--dry-run]   # fix stuck jobs
wp datamachine jobs retry <id>                  # retry specific job
```

---

## Chat API

REST at `/wp-json/datamachine/v1/chat/` with 30+ tool-based abilities: flow/pipeline/queue/job management, content editing, taxonomy CRUD, memory read/write, analytics queries, image generation, settings, and session management.
