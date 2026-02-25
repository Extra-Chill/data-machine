---
name: data-machine
description: "Self-scheduling execution layer for autonomous task orchestration. Use for queuing tasks, chaining pipeline executions, scheduling recurring work, and 24/7 autonomous operation via Agent Ping webhooks."
compatibility: "WordPress 6.9+ with Data Machine plugin. WP-CLI required for queue management."
---

# Data Machine Skill

**A self-scheduling execution layer for AI agents.** Not just content automation — it's how agents schedule themselves to achieve goals autonomously.

## When to Use This Skill

Use this skill when:
- Setting up automated workflows (content generation, publishing, notifications)
- Creating self-scheduling patterns (reminders, recurring tasks)
- Building multi-phase projects with queued task progression
- Configuring Agent Ping webhooks to trigger external agents
- Managing content blocks, internal links, alt text, or image generation
- Debugging failed jobs or recovering stuck pipelines

---

## Core Philosophy

Data Machine is designed with AI agents as primary users. It functions as a **reminder system + task manager + workflow executor** all in one.

### Three Key Concepts

1. **Flows operate on schedules** — Configure "ping me at X time to do Y"
2. **Step-level prompt queues** — Each ping can be a different task instruction
3. **Multiple purpose-specific flows** — Separate flows for separate concerns

### Mental Model

| Role | How It Works |
|------|--------------|
| **Reminder System** | Flows run on schedules (daily, hourly, weekly, etc.) and ping the agent |
| **Task Manager** | Queues hold task backlog; each run pops the next task |
| **Workflow Executor** | Pipeline steps execute work (AI generation, publishing, API calls) |

---

## Architecture Overview

### Execution Model

```
Pipeline (template) → Flow (instance) → Job (execution)
```

- **Pipeline**: Reusable workflow template with ordered steps
- **Flow**: Instance of a pipeline with specific configuration and schedule
- **Job**: Single execution of a flow

### Step Types

| Type | Purpose | Has Queue | Has Handlers |
|------|---------|-----------|-------------|
| `fetch` | Import data from external sources | No | Yes |
| `ai` | Process with AI (multi-turn, tools) | **Yes** | No |
| `publish` | Output to platforms | No | Yes |
| `update` | Modify existing content | No | Yes |
| `agent_ping` | Webhook to external agents | **Yes** | No |
| `webhook_gate` | Pause pipeline until external webhook fires | No | No |

### Fetch Handlers

| Handler | Source |
|---------|--------|
| `rss` | RSS/Atom feeds |
| `files` | Local/remote files |
| `reddit` | Reddit posts/comments |
| `wordpress_posts` | WordPress posts (local) |
| `wordpress_api` | WordPress REST API (remote) |
| `wordpress_media` | WordPress media library |

### Publish Handlers

| Handler | Destination |
|---------|------------|
| `wordpress_publish` | WordPress posts |

**Note:** Social publish handlers (Pinterest, Twitter, Bluesky, Facebook, Threads) are available in the separate `data-machine-socials` plugin.

### Update Handlers

| Handler | Target |
|---------|--------|
| `wordpress_update` | WordPress post content |

### Webhook Gate

A handler-free step type that pauses the pipeline and generates a unique webhook URL. When the webhook receives a POST, the pipeline resumes with the webhook payload injected as data packets. Configurable timeout (default: 7-day token expiry).

### Scheduling Options

Configure via `--scheduling` flag on flow create/update:

| Interval | Behavior |
|----------|----------|
| `manual` | Only runs when triggered via UI, CLI, or webhook |
| `one_time` | Runs once at a specified timestamp |
| `every_5_minutes` | Runs every 5 minutes |
| `hourly` | Runs once per hour |
| `every_2_hours` | Runs every 2 hours |
| `every_4_hours` | Runs every 4 hours |
| `qtrdaily` | Runs every 6 hours |
| `twicedaily` | Runs twice per day |
| `daily` | Runs once per day |
| `every_3_days` | Runs every 3 days |
| `weekly` | Runs once per week |
| `monthly` | Runs once per month |

Intervals are filterable via `datamachine_scheduler_intervals`.

---

## Directive System

System prompts are injected in priority order into every AI call. Different agent types (pipeline, chat, system) have different directive sets:

| Priority | Directive | Agent Types | Purpose |
|----------|-----------|-------------|---------|
| 10 | Pipeline Core | pipeline | Hardcoded pipeline agent identity |
| 15 | Chat Agent | chat | Chat agent identity and tool instructions |
| 20 | Core Memory Files | all | SOUL.md, USER.md, MEMORY.md (always injected) |
| 20 | System Agent | system | System agent identity (alt text, linking, etc.) |
| 40 | Pipeline Memory Files | pipeline | Per-pipeline selected memory files |
| 45 | Chat Pipelines | chat | Available pipeline context for chat |
| 50 | Pipeline System Prompt | pipeline | Per-pipeline AI step instructions |
| 80 | Site Context | all | WordPress metadata (site name, URL, post types) |

**Key:** Core Memory Files (Priority 20) injects three files in order: **SOUL.md** (file priority 10) defines *who* the agent is, **USER.md** (file priority 20) defines *who the user is*, and **MEMORY.md** (file priority 30) defines *what the agent knows*. All three are always injected into every AI call. Additional memory files are selectable per-pipeline via the admin UI (Priority 40).

---

## Memory System

Data Machine has file-based agent memory in `{wp-content}/uploads/datamachine-files/agent/`. Files here provide persistent context to AI agents across all executions.

### How It Works

1. Files live in the agent directory (managed via Admin UI, REST API, or CLI)
2. **SOUL.md** — always injected, defines agent identity, voice, and rules
3. **USER.md** — always injected, defines who the human user is (preferences, goals, context)
4. **MEMORY.md** — always injected, accumulated state, lessons learned, and domain context
5. Other files can be selected per-pipeline as memory file references (Priority 40)
6. Selected files are injected as system context — the AI sees them every execution
7. SOUL.md, USER.md, and MEMORY.md are protected from deletion (clear contents instead)

### REST API

```
GET    /datamachine/v1/files/agent           — List all agent files
GET    /datamachine/v1/files/agent/{filename} — Read file content
PUT    /datamachine/v1/files/agent/{filename} — Write/update file (raw body)
DELETE /datamachine/v1/files/agent/{filename} — Delete file (blocked for SOUL.md/MEMORY.md)
```

### Pipeline Memory File Selection

Each pipeline can select which agent memory files to include in its AI context. Configure via the "Agent Memory Files" section in the pipeline settings UI. SOUL.md and MEMORY.md are excluded from the picker since they're always injected.

This enables different pipelines to see different context — an ideation pipeline might reference a strategy doc, while a generation pipeline might reference style guidelines.

---

## Workspace (Repository Access)

Data Machine provides a managed workspace for cloned repositories at `/var/lib/datamachine/workspace/`. **Always use the workspace CLI** rather than direct filesystem access — the CLI enforces path sanitization, containment checks, and works through the Abilities API.

### Reading Files

```bash
# List cloned repos
wp datamachine workspace list

# Show repo details (path, remote, branch)
wp datamachine workspace show <repo>

# List files in a repo directory
wp datamachine workspace ls <repo> [path]

# Read file contents
wp datamachine workspace read <repo> <path>
```

### Writing Files

```bash
# Write with content flag
wp datamachine workspace write <repo> <path> --content="file contents"

# Write from a local file (@ syntax — avoids shell escaping)
wp datamachine workspace write <repo> <path> --content=@/tmp/staged-code.rs

# Write from stdin
cat local-file.rs | wp datamachine workspace write <repo> <path>
```

### Editing Files

```bash
# Find-and-replace
wp datamachine workspace edit <repo> <path> --old="old text" --new="new text"

# Replace all occurrences
wp datamachine workspace edit <repo> <path> --old="v1" --new="v2" --replace-all

# Use @ syntax for complex replacements
wp datamachine workspace edit <repo> <path> --old=@/tmp/old.txt --new=@/tmp/new.txt
```

### Managing Repos

```bash
# Clone a repo into workspace
wp datamachine workspace clone <git_url>

# Remove a repo from workspace
wp datamachine workspace remove <repo>

# Get the workspace base path
wp datamachine workspace path
```

**Why CLI over direct access:** Agents spawned via DM pipelines (non-root) may not have direct filesystem access. The workspace CLI enforces security boundaries and provides a consistent interface regardless of where the workspace physically lives.

---

## CLI Reference

**Note:** All commands accept `--allow-root` when running as root. Singular and plural aliases work interchangeably (`flow`/`flows`, `job`/`jobs`, `pipeline`/`pipelines`, `post`/`posts`, `block`/`blocks`, `link`/`links`, `log`/`logs`).

### Pipelines

```bash
# List all pipelines
wp datamachine pipelines [--per_page=<n>] [--offset=<n>] [--format=table|json|csv|yaml|ids|count] [--fields=<fields>]

# Get a specific pipeline
wp datamachine pipelines get <pipeline_id>
# or: wp datamachine pipelines <pipeline_id>

# Create a pipeline
wp datamachine pipelines create --name="My Pipeline" [--steps='[{"step_type":"fetch"},{"step_type":"ai"}]'] [--dry-run]

# Update a pipeline
wp datamachine pipelines update <pipeline_id> [--name=<name>] [--config=<json>]

# Update system prompt on AI step
wp datamachine pipelines update <id> --set-system-prompt="Write a blog post..."
# Target specific step if multiple AI steps:
wp datamachine pipelines update <id> --step=<pipeline_step_id> --set-system-prompt="..."

# Delete a pipeline
wp datamachine pipelines delete <pipeline_id> [--force]
```

### Flows

```bash
# List flows (all, or for a pipeline)
wp datamachine flows [<pipeline_id>] [--handler=<slug>] [--per_page=<n>] [--offset=<n>] [--format=table|json|csv|yaml|ids|count]

# Get a specific flow
wp datamachine flows get <flow_id>
# or: wp datamachine flows --id=<flow_id>

# Create a flow
wp datamachine flows create --pipeline_id=<id> --name="My Flow" [--scheduling=manual|hourly|daily] [--step_configs=<json>] [--dry-run]

# Update a flow
wp datamachine flows update <flow_id> [--name=<name>] [--scheduling=<interval>]

# Update prompt on handler step
wp datamachine flows update <flow_id> --set-prompt="New prompt" [--step=<flow_step_id>]

# Run a flow
wp datamachine flows run <flow_id> [--count=<1-10>] [--timestamp=<unix>]

# Delete a flow
wp datamachine flows delete <flow_id> [--yes]
```

### Queues

Both AI and Agent Ping steps support queues via `QueueableTrait`. If the configured prompt is empty and `queue_enabled` is true, the step pops from its queue.

```bash
# Add to queue (--step auto-resolved if flow has one queueable step)
wp datamachine flows queue add <flow_id> "Task instruction here" [--step=<flow_step_id>]

# List queue contents
wp datamachine flows queue list <flow_id> [--format=table|json]

# Remove by index
wp datamachine flows queue remove <flow_id> <index>

# Update prompt at index
wp datamachine flows queue update <flow_id> <index> "Updated prompt text"

# Move prompt from one position to another
wp datamachine flows queue move <flow_id> <from_index> <to_index>

# Clear entire queue
wp datamachine flows queue clear <flow_id>
```

### Jobs

```bash
# List jobs
wp datamachine jobs list [--status=pending|processing|completed|failed|agent_skipped|completed_no_items] [--flow=<flow_id>] [--source=pipeline|system] [--limit=<n>] [--format=table|json|csv|yaml|ids|count]

# Show job details (includes engine_data in JSON format)
wp datamachine jobs show <job_id> [--format=table|json|yaml]

# Status summary
wp datamachine jobs summary [--format=table|json|csv]

# Manually fail a processing job
wp datamachine jobs fail <job_id> [--reason=<reason>]

# Retry a failed/stuck job (requeues prompt if backup exists)
wp datamachine jobs retry <job_id> [--force]

# Recover stuck jobs (processing but with status override in engine_data)
wp datamachine jobs recover-stuck [--dry-run] [--flow=<flow_id>] [--timeout=<hours>]
```

### Settings

```bash
wp datamachine settings list
wp datamachine settings get <key>
wp datamachine settings set <key> <value>
```

### Logs

```bash
# Read log entries
wp datamachine logs read <agent_type>  # agent_type: pipeline, system, chat

# Log file metadata
wp datamachine logs info <agent_type>

# Get or set log level
wp datamachine logs level <agent_type> [<level>]

# Clear logs
wp datamachine logs clear <agent_type|all> [--yes]
```

### Posts (Query by Data Machine metadata)

```bash
# List recently published DM posts (all post types)
wp datamachine posts recent [--post_type=<type>] [--post_status=<status>] [--limit=<n>] [--format=table|json|csv|yaml|ids|count]

# Query posts created by a specific flow
wp datamachine posts by-flow <flow_id> [--post_type=<type>] [--post_status=<status>] [--per_page=<n>] [--format=table|json|csv|yaml|ids|count]

# Query posts by handler slug
wp datamachine posts by-handler <handler_slug> [--post_type=<type>] [--per_page=<n>]

# Query posts by pipeline
wp datamachine posts by-pipeline <pipeline_id> [--post_type=<type>] [--per_page=<n>]
```

### Blocks (Gutenberg block editing)

```bash
# List blocks in a post
wp datamachine blocks list <post_id> [--type=<block_type>] [--search=<text>] [--format=table|json|csv]

# Find/replace within a specific block
wp datamachine blocks edit <post_id> <block_index> --find=<text> --replace=<text> [--dry-run]

# Replace entire block content
wp datamachine blocks replace <post_id> <block_index> --content="<p>New HTML</p>"
```

### Internal Links

```bash
# Diagnose internal link coverage across published posts
wp datamachine links diagnose [--format=table|json|csv]

# Queue cross-linking for posts
wp datamachine links crosslink [--post_id=<id>] [--category=<slug>] [--all] [--links-per-post=<n>] [--force] [--dry-run]
```

### Alt Text

```bash
# Diagnose alt text coverage
wp datamachine alt-text diagnose [--format=table|json|csv]

# Queue alt text generation
wp datamachine alt-text generate [--attachment_id=<id>] [--post_id=<id>] [--force]
```

### Memory (Agent files)

```bash
# Read a memory file
wp datamachine memory read <filename>

# List sections in a memory file
wp datamachine memory sections <filename>

# Write/update a memory file
wp datamachine memory write <filename> --content="..."

# Search across memory files
wp datamachine memory search <query>

# Daily memory (date-based logs at YYYY/MM/DD.md)
wp datamachine memory daily list [--limit=<n>]
wp datamachine memory daily read [<date>]
wp datamachine memory daily write <date> --content="..."
wp datamachine memory daily append <date> --content="..."
wp datamachine memory daily delete <date>
wp datamachine memory daily search <query>
```

**Alias:** `wp datamachine agent` is a backward-compatible alias for `wp datamachine memory`.

### Webhook Triggers

```bash
# Enable webhook trigger for a flow (generates URL + token)
wp datamachine flows webhook enable <flow_id>

# Disable webhook trigger
wp datamachine flows webhook disable <flow_id>

# Regenerate the trigger token
wp datamachine flows webhook regenerate <flow_id>

# Check trigger status for a flow
wp datamachine flows webhook status <flow_id>

# List all flows with webhook triggers enabled
wp datamachine flows webhook list

# Configure rate limiting
wp datamachine flows webhook rate-limit <flow_id> --requests=<n> --window=<seconds>
```

Webhook triggers allow external systems to fire flows via `POST /datamachine/v1/trigger/{flow_id}` with Bearer token authentication.

---

## Prompt Queues

This enables **varied task instructions** per execution — not the same prompt every time.

### Chaining Pattern

When an agent receives a ping, it should:
1. Execute the immediate task
2. Queue the next logical task (if continuation needed)
3. Let the cycle continue

```
Ping: "Phase 1: Design the architecture"
  → Agent designs, writes DESIGN.md
  → Agent queues: "Phase 2: Implement schema per DESIGN.md"

Ping: "Phase 2: Implement schema per DESIGN.md"
  → Agent implements
  → Agent queues: "Phase 3: Build API endpoints"
```

The queue becomes the agent's **persistent project memory** — multi-phase work is tracked in the queue, not held in context.

---

## Purpose-Specific Flows

**Critical pattern**: Don't try to do everything in one flow. Create separate flows for separate concerns:

```
Flow: Content Generation (queue-driven)
  → AI Step (pops topic from queue) → Publish → Agent Ping

Flow: Content Ideation (daily)
  → Agent Ping: "Review analytics, add topics to content queue"

Flow: Weekly Review (weekly)
  → Agent Ping: "Analyze last week's performance"

Flow: Coding Tasks (manual, queue-driven)
  → Agent Ping (pops from queue): specific coding task instructions
```

Each flow has its own:
- **Schedule**: When it runs
- **Queue**: Task backlog specific to that workflow
- **Purpose**: Single responsibility, clear scope

---

## Agent Ping Configuration

Agent Ping steps send webhooks to external agent frameworks (OpenClaw, LangChain, custom handlers).

### Handler Configuration

- `webhook_url`: Where to send the ping
- `prompt`: Static prompt, or leave empty to use queue
- `queue_enabled`: Whether to pop from queue when prompt is empty

### Webhook Payload

The ping POST body includes:

```json
{
  "prompt": "Task instruction",
  "context": {
    "flow_id": 7,
    "pipeline_id": 3,
    "job_id": 1234,
    "post_id": 5678,
    "post_type": "post",
    "published_url": "https://example.com/my-post/",
    "data_packets": [...],
    "engine_data": {...},
    "from_queue": false,
    "site_url": "https://example.com",
    "wp_path": "/var/www/html/"
  },
  "reply_to": "channel_id",
  "timestamp": "2026-02-25T03:00:00+00:00"
}
```

`post_id`, `post_type`, and `published_url` are surfaced as top-level context fields for convenience (also available inside `engine_data`). Discord webhook URLs receive a simplified plaintext format instead.

**Note**: Data Machine is agent-agnostic. It sends webhooks — whatever listens on the URL handles the prompt.

---

## Chat API (Abilities System)

Data Machine exposes a comprehensive Chat API at `/wp-json/datamachine/v1/chat/` with 30+ tool-based abilities. This powers the admin UI chat interface but can also be used programmatically.

Key ability groups:
- **Flow management**: Create, list, update, delete, duplicate, run flows
- **Pipeline management**: Create, list, update, delete, duplicate, import/export pipelines
- **Queue management**: Add, list, clear queue items
- **Job management**: List, show, fail, retry, recover-stuck, summary, delete jobs
- **Content**: Get/edit/replace post blocks, query posts
- **Taxonomy**: Get, create, update, delete, merge, resolve terms
- **Memory**: Read/write/search agent memory files, daily memory
- **Logs**: Read, manage log entries
- **System**: Health check, problem flows detection
- **Analytics**: Bing Webmaster, Google Search Console queries
- **Media**: Image generation, alt text generation
- **Settings**: Read/update plugin settings
- **Chat sessions**: Create, get, list, delete sessions

The REST API also has dedicated endpoints for flows, flow steps, flow queues, pipelines, pipeline steps, jobs, settings, logs, and more under `/wp-json/datamachine/v1/`.

---

## Taxonomy Handling (Publishing)

When publishing WordPress content, taxonomies can be handled three ways:

| Selection | Behavior |
|-----------|----------|
| `skip` | Don't assign this taxonomy |
| `ai_decides` | AI provides values via tool parameters |
| `<term_id\|name\|slug>` | Pre-select specific term |

### AI Decides Mode

When `ai_decides` is set:
1. TaxonomyHandler generates a tool parameter for that taxonomy
2. AI provides term names in its tool call
3. Handler assigns terms (creating if needed)

- Hierarchical taxonomies (category): expects single string
- Non-hierarchical (tags): expects array of strings

**Best Practice**: AI taxonomy selection works for simple cases. For complex categorization, use `skip` and assign programmatically after publish.

---

## Key AI Tools

Tools available to the AI during pipeline execution. Global tools are available in every AI step; handler tools are injected by adjacent steps.

### Global Tools

| Tool | Purpose |
|------|---------|
| `local_search` | Search site content (duplicate detection, research) |
| `skip_item` | Skip items that shouldn't be processed (sets `agent_skipped` status) |
| `image_generation` | Generate images via Replicate API (featured images, illustrations) |
| `agent_memory` | Read/write agent memory files during execution |
| `web_fetch` | Fetch content from external URLs |
| `wordpress_post_reader` | Read full post content by ID |
| `google_search` | Web search via Google Custom Search API |
| `google_search_console` | Query GSC analytics data |
| `bing_webmaster` | Query Bing Webmaster analytics data |
| `amazon_affiliate_link` | Generate Amazon affiliate links |
| `queue_validator` | Validate and manage queue state |
| `github_create_issue` | File GitHub issues from within pipelines |

### skip_item

Allows AI to skip items that shouldn't be processed:

```
Before generating content:
1. Search for similar existing posts
2. If duplicate found, use skip_item("duplicate of [existing URL]")
```

The tool marks the item as processed and sets job status to `agent_skipped`.

### local_search

Search site content for duplicate detection:

```bash
# Search by title
local_search(query="topic name", title_only=true)
```

**Tip**: Search for core topic, not exact title. "pelicans dangerous" catches "Are Australian Pelicans Dangerous?"

### image_generation

Generate images via Replicate API. When called from a pipeline with a published post, the image is automatically set as the featured image.

```
image_generation(prompt="A serene mountain lake at sunset", aspect_ratio="16:9")
```

Image generation is asynchronous — the System Agent handles polling and attachment creation in the background.

---

## Debugging

### Check Logs

```bash
# Via CLI
wp datamachine logs read pipeline
wp datamachine logs read system
wp datamachine logs read chat

# Via file
tail -f wp-content/uploads/datamachine-logs/datamachine-pipeline.log
```

### Failed Jobs

```bash
# List failures
wp datamachine jobs list --status=failed

# Get details on a specific failure
wp datamachine jobs show <job_id> --format=json

# Status overview
wp datamachine jobs summary
```

### Stuck Jobs

```bash
# Preview what would be recovered
wp datamachine jobs recover-stuck --dry-run

# Recover (marks stuck jobs as failed, requeues prompts if backup exists)
wp datamachine jobs recover-stuck

# Retry a specific job
wp datamachine jobs retry <job_id>
```

### Scheduled Actions

```bash
# List pending actions
wp action-scheduler run --hooks=datamachine --force

# Check cron
wp cron event list
```

---

## Common Patterns

### Self-Improving Content Pipeline

```
1. Fetch topics (RSS, manual queue, or AI ideation)
2. AI generates content with local_search to avoid duplicates
3. Publish to WordPress
4. Agent Ping to notify agent for image addition / promotion
```

### Autonomous Maintenance

```
Daily Flow:
  → Agent Ping: "Check for failed jobs, investigate issues"

Weekly Flow:
  → Agent Ping: "Review analytics, identify optimization opportunities"
```

### Multi-Phase Project Execution

```
Queue tasks in sequence:
  "Phase 1: Research and planning"
  "Phase 2: Implementation"
  "Phase 3: Testing"
  "Phase 4: Documentation"

Flow runs daily, pops next phase, agent executes and queues follow-up if needed.
```

### Content Quality Maintenance

```bash
# Find posts with poor internal linking
wp datamachine links diagnose --format=json

# Queue cross-linking for a category
wp datamachine links crosslink --category=nature --links-per-post=5

# Find posts with missing alt text
wp datamachine alt-text diagnose

# Generate alt text for a post's images
wp datamachine alt-text generate --post_id=123

# Review recent DM posts across all types
wp datamachine posts recent --limit=10

# Review what a flow has produced
wp datamachine posts by-flow 29 --per_page=50
```

---

## Code Locations

For contributors working on Data Machine itself:

- Steps: `inc/Core/Steps/` (AI, AgentPing, Fetch, Publish, Update, WebhookGate)
- Abilities: `inc/Abilities/` (Engine, Chat, Flow, Pipeline, Job, Media, Publish, etc.)
- Engine Abilities: `inc/Abilities/Engine/` (RunFlow, ExecuteStep, ScheduleFlow, ScheduleNextStep)
- Chat Abilities: `inc/Abilities/Chat/` (Session CRUD)
- Chat Orchestrator: `inc/Api/Chat/ChatOrchestrator.php`
- Chat REST: `inc/Api/Chat/Chat.php` (thin controller)
- Chat Tools: `inc/Api/Chat/Tools/`
- CLI: `inc/Cli/Commands/`
- REST API: `inc/Api/`
- Directives: `inc/Engine/AI/Directives/`
- Global AI Tools: `inc/Engine/AI/Tools/Global/`
- Memory Registry: `inc/Engine/AI/MemoryFileRegistry.php`
- File Management: `inc/Core/FilesRepository/` (Workspace, DailyMemory, AgentMemory)
- Taxonomy Handler: `inc/Core/WordPress/TaxonomyHandler.php`
- Queueable Trait: `inc/Core/Steps/QueueableTrait.php`
- React UI: `inc/Core/Admin/Pages/Pipelines/assets/react/`

---

*This skill teaches AI agents how to use Data Machine for autonomous operation. For contributing to Data Machine development, see AGENTS.md in the repository root.*
