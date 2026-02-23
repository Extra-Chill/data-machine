---
name: data-machine
description: "WordPress-native AI content automation platform with memory system, pipeline execution, abilities API, and agent tools. Use for automated workflows, self-scheduling, queued task progression, memory-driven AI context, and 24/7 autonomous operation."
compatibility: "WordPress 6.9+ with Data Machine plugin. WP-CLI required for CLI operations."
---

# Data Machine Skill

**A WordPress-native AI automation platform.** Content generation, publishing, memory, scheduling, and agent tools — all inside WordPress. Designed with AI agents as primary users.

## When to Use This Skill

Use this skill when:
- Setting up automated workflows (content generation, publishing, notifications)
- Creating self-scheduling patterns (reminders, recurring tasks)
- Building multi-phase projects with queued task progression
- Configuring Agent Ping webhooks to trigger external agents
- Managing agent memory files for persistent AI context
- Working with block-level content editing

---

## Core Philosophy

Data Machine functions as a **reminder system + task manager + workflow executor + memory system** all in one.

### Key Concepts

1. **Flows operate on schedules** — Configure "ping me at X time to do Y"
2. **Step-level prompt queues** — Each execution can pop a different task instruction
3. **Multiple purpose-specific flows** — Separate flows for separate concerns
4. **Agent memory** — Persistent files that inject context into AI calls

### Mental Model

| Role | How It Works |
|------|--------------|
| **Reminder System** | Flows run on schedules (daily, hourly, cron) and ping the agent |
| **Task Manager** | Queues hold task backlog; each run pops the next task |
| **Workflow Executor** | Pipeline steps execute work (AI generation, publishing, API calls) |
| **Memory System** | Agent files provide persistent context across all executions |

---

## Architecture Overview

### Execution Model

```
Pipeline (template) → Flow (instance) → Job (execution)
```

- **Pipeline**: Reusable workflow template with steps
- **Flow**: Instance of a pipeline with specific configuration and schedule
- **Job**: Single execution of a flow

### Step Types

| Type | Purpose | Has Queue |
|------|---------|-----------|
| `fetch` | Import data (RSS, Sheets, Files, Reddit) | No |
| `ai` | Process with AI (multi-turn, tools) | **Yes** |
| `publish` | Output to platforms (single or multi-handler) | No |
| `update` | Modify existing content | No |
| `agent_ping` | Webhook to external agents | **Yes** |
| `webhook_gate` | Pause pipeline until external webhook fires | No |

### 7-Tier Directive System

System prompts are injected in priority order into every AI call:

| Priority | Directive | Scope |
|----------|-----------|-------|
| 10 | Plugin Core | Hardcoded agent identity |
| 20 | Agent SOUL.md | Global AI personality (identity, voice, rules) |
| 22 | Agent MEMORY.md | Accumulated knowledge (state, lessons, context) |
| 25 | Pipeline Memory Files | Per-pipeline selected memory files |
| 30 | Pipeline System Prompt | Per-pipeline AI step instructions |
| 35 | Pipeline Context Files | Uploaded reference materials |
| 40 | Tool Definitions | Available tools and workflow context |
| 50 | Site Context | WordPress metadata |

**Key:** SOUL.md (Priority 20) and MEMORY.md (Priority 22) are always injected into every AI call. SOUL.md defines *who* the agent is. MEMORY.md defines *what* the agent knows. Other memory files are selectable per-pipeline via the admin UI.

### Scheduling Options

Configure via `scheduling_config` in the flow:

| Interval | Behavior |
|----------|----------|
| `manual` | Only runs when triggered via UI or CLI |
| `daily` | Runs once per day |
| `hourly` | Runs once per hour |
| `{"cron": "0 9 * * 1"}` | Cron expression (e.g., Mondays at 9am) |

---

## Memory System

Data Machine has file-based agent memory in `{wp-content}/uploads/datamachine-files/agent/`. Files here provide persistent context to AI agents across all executions.

### How It Works

1. Files live in the agent directory (managed via Admin UI or REST API)
2. **SOUL.md** is always injected at Priority 20 — defines agent identity, voice, and rules
3. **MEMORY.md** is always injected at Priority 22 — accumulated state, lessons learned, and domain context
4. Other files can be selected per-pipeline as memory file references (Priority 25)
5. Selected files are injected as system context — the AI sees them every execution

### REST API

```
GET    /datamachine/v1/files/agent           — List all agent files
GET    /datamachine/v1/files/agent/{filename} — Read file content
PUT    /datamachine/v1/files/agent/{filename} — Write/update file (raw body)
DELETE /datamachine/v1/files/agent/{filename} — Delete file
```

### Pipeline Memory File Selection

Each pipeline can select which agent memory files to include in its AI context. Configure via the "Agent Memory Files" section in the pipeline settings UI. SOUL.md and MEMORY.md are excluded from the picker since they're always injected.

This enables different pipelines to see different context — an ideation pipeline might reference a strategy doc, while a generation pipeline might reference style guidelines.

---

## Prompt Queues

Both AI and Agent Ping steps support queues via `QueueableTrait`. If the configured prompt is empty and `queue_enabled` is true, the step pops from its queue.

### Queue Management

```bash
wp datamachine flows queue add <flow_id> "Task instruction here"
wp datamachine flows queue list <flow_id>
wp datamachine flows queue clear <flow_id>
wp datamachine flows queue remove <flow_id> <index>
wp datamachine flows queue update <flow_id> <index> "new prompt text"
wp datamachine flows queue move <flow_id> <from_index> <to_index>
```

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

Flow: Weekly Review (cron: Monday 9am)
  → Agent Ping: "Analyze last week's performance"

Flow: Coding Tasks (manual, queue-driven)
  → Agent Ping (pops from queue): specific coding task instructions
```

Each flow has its own schedule, queue, and single-responsibility purpose.

---

## Agent Ping Configuration

Agent Ping steps send webhooks to external agent frameworks (OpenClaw, LangChain, custom handlers).

### Handler Configuration

- `webhook_url`: Where to send the ping
- `prompt`: Static prompt, or leave empty to use queue
- `queue_enabled`: Whether to pop from queue when prompt is empty

### Webhook Payload

The ping includes flow/job context, the prompt (from config or queue), and any data from previous steps.

**Note**: Data Machine is agent-agnostic. It sends webhooks — whatever listens on the URL handles the prompt.

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
local_search(query="topic name", title_only=true)
```

**Tip**: Search for core topic, not exact title. "pelicans dangerous" catches "Are Australian Pelicans Dangerous?"

---

## Webhook Gate Steps

*Since v0.25.0.* The `webhook_gate` step type pauses a pipeline until an external webhook fires. It is handler-free — no `handler_config` or `handler_slug` needed.

When the step executes:
1. A unique webhook URL is generated and stored as a transient
2. The job is parked in `waiting` status
3. When the webhook URL receives a POST, the pipeline resumes from the next step with the webhook payload injected as data packets
4. If the webhook is not received before the configured timeout, the job fails with `webhook_gate_timeout`

Use this for integrations where an external system must complete work before the pipeline continues.

---

## Multi-Handler Publish Steps

Publish steps support multiple handlers in a single step via `handler_slugs` (array) and `handler_configs` (keyed by slug). This enables publishing to multiple platforms in one step.

Configuration:
- `handler_slugs`: Array of handler slugs to execute (e.g., `["wordpress", "twitter"]`)
- `handler_configs`: Per-handler configuration keyed by slug

Falls back to singular `handler_slug` / `handler_config` for backward compatibility.

### Available Publish Handlers

| Handler | Platform |
|---------|----------|
| `wordpress` | WordPress posts |
| `twitter` | Twitter/X |
| `bluesky` | Bluesky |
| `facebook` | Facebook |
| `threads` | Threads |
| `pinterest` | Pinterest |
| `google_sheets` | Google Sheets |

---

## Per-Agent Model Configuration

Each agent type (`chat`, `pipeline`, `system`) can use a different AI provider and model. Configure via the `agent_models` setting in the admin UI (Agent tab).

Resolution order for a given agent type:
1. Agent-specific override from `agent_models`
2. Global `default_provider` / `default_model`
3. Empty (no model configured)

This is resolved via `PluginSettings::getAgentModel( $agent_type )`.

---

## Image Insert Modes

When generating images for posts, the `mode` parameter controls placement:

| Mode | Behavior |
|------|----------|
| `featured` | Set as the post's featured image (default) |
| `insert` | Insert an image block directly into post content |

When using `insert` mode, the `position` parameter controls where the image is placed:

| Position | Behavior |
|----------|----------|
| `after_intro` | After the introductory paragraph (default) |
| `before_heading` | Before the next heading element |
| `end` | At the end of the content |
| `index:N` | At a specific block index |

---

## Block Content Editing

*Since v0.28.0.* Abilities and CLI for block-level content manipulation with automatic sanitization.

### Abilities

- **GetPostBlocks** — Parse and list Gutenberg blocks with optional filtering by type or search text
- **EditPostBlocks** — Find/replace within specific blocks by index
- **ReplacePostBlocks** — Replace entire block innerHTML by index

All write operations use BlockSanitizer to strip dangerous tags/attributes while preserving safe HTML.

### CLI

```bash
# List blocks in a post
wp datamachine blocks list <post_id> [--type=<block_type>] [--search=<text>] [--format=<table|json|csv>]

# Edit block content via find/replace
wp datamachine blocks edit <post_id> <block_index> --find="old text" --replace="new text" [--dry-run]

# Replace entire block innerHTML
wp datamachine blocks replace <post_id> <block_index> --content="<p>New content</p>"
```

---

## Chat Agent

Data Machine includes a conversational chat interface in the admin UI with:
- Session history and session switching
- Full tool/ability access (same as pipeline agents)
- The complete 7-tier directive stack
- Per-agent model configuration

Use the chat agent for ad-hoc tasks, testing prompts, or direct interaction with DM's capabilities.

---

## CLI Reference

**Note:** If running WP-CLI as root, add `--allow-root` to commands.

```bash
# Settings
wp datamachine settings list
wp datamachine settings get <key>
wp datamachine settings set <key> <value>

# Flows
wp datamachine flows list
wp datamachine flows get <flow_id>
wp datamachine flows run <flow_id>

# Queues
wp datamachine flows queue add <flow_id> "prompt"
wp datamachine flows queue list <flow_id>
wp datamachine flows queue clear <flow_id>
wp datamachine flows queue remove <flow_id> <index>
wp datamachine flows queue update <flow_id> <index> "new prompt text"
wp datamachine flows queue move <flow_id> <from_index> <to_index>

# Jobs
wp datamachine jobs list [--status=<status>] [--limit=<n>]
wp datamachine jobs get <job_id>
wp datamachine jobs summary
wp datamachine jobs fail <job_id> [--reason=<reason>]
wp datamachine jobs retry <job_id> [--force]
wp datamachine jobs recover-stuck [--dry-run] [--timeout=2]

# Logs
wp datamachine logs read <agent_type> [--job-id=N] [--limit=50]
wp datamachine logs info [<agent_type>]
wp datamachine logs clear <agent_type|all> --yes

# Pipelines
wp datamachine pipelines list
wp datamachine pipeline get <pipeline_id>

# Blocks
wp datamachine blocks list <post_id> [--type=<block_type>] [--search=<text>] [--format=<format>]
wp datamachine blocks edit <post_id> <block_index> --find="<text>" --replace="<text>" [--dry-run]
wp datamachine blocks replace <post_id> <block_index> --content="<html>"
```

Agent types for logs: `pipeline`, `system`, `chat`

---

## Debugging

### Check Logs

```bash
tail -f {wp-content}/uploads/datamachine-logs/datamachine-pipeline.log
```

### Failed Jobs

```bash
wp datamachine jobs list --status=failed
```

### Scheduled Actions

```bash
wp action-scheduler run --hooks=datamachine --force
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

### Memory-Driven Ideation

```
1. Store strategy docs and performance data as agent memory files
2. Configure ideation pipeline to include those memory files
3. AI generates ideas informed by strategy + what's working
4. No external agent needed — DM handles it internally
```

---

## Code Locations

For contributors working on Data Machine itself:

- Steps: `inc/Core/Steps/`
- Abilities: `inc/Abilities/`
- CLI: `inc/Cli/`
- Directives: `inc/Engine/AI/Directives/` and `inc/Core/Steps/AI/Directives/`
- Memory/Files: `inc/Core/FilesRepository/`
- Taxonomy Handler: `inc/Core/WordPress/TaxonomyHandler.php`
- Queueable Trait: `inc/Core/Steps/QueueableTrait.php`
- React UI: `inc/Core/Admin/Pages/`

---

## Development Status & Contributing

**Data Machine is in active development.** It works well and is used in production, but it's not yet available on WordPress.org — no auto-updates.

### Installation

Data Machine is installed from GitHub:
```
https://github.com/Extra-Chill/data-machine
```

### Updating

```bash
cd /path/to/wp-content/plugins/data-machine
git pull origin main
```

Check for breaking changes in the CHANGELOG before updating production sites.

### Reporting Issues

If you encounter bugs: https://github.com/Extra-Chill/data-machine/issues

Include what you were trying to do, what happened, steps to reproduce, and relevant logs.

### Contributing

PRs are welcome. Fork, branch, make changes following existing patterns, submit a PR with clear description. If you hit a limitation, consider fixing it upstream rather than working around it.

---

*This skill teaches AI agents how to use Data Machine for autonomous operation. For contributing to Data Machine development, see AGENTS.md in the repository root.*
