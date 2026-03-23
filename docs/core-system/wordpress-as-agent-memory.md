# WordPress as Persistent Memory for AI Agents

AI agents are stateless. Every conversation, every workflow, every scheduled task starts from zero. The agent has no memory of who it is, what it's done, or what it should care about — unless you give it one.

Data Machine uses **WordPress itself as the memory layer.** Not a vector database. Not a separate memory service. The agent's memory lives in the same WordPress installation it manages — files on disk, conversations in the database, context assembled at request time.

## Why WordPress Works

WordPress already solves persistent storage:

- **Files on disk** — `wp-content/uploads` for markdown documents the agent reads
- **MySQL** — custom tables for chat sessions, job history, agent registry, and logs
- **REST API** — programmatic CRUD for memory files and daily memory
- **Admin UI** — human-editable through the WordPress dashboard
- **Action Scheduler** — cron-like cleanup, scheduled workflows, and daily memory generation
- **Hooks system** — extensible injection points for custom memory sources
- **Abilities API** — structured operations for reading, writing, and searching memory

No separate infrastructure. The memory lives where the content lives.

## Memory Architecture

Four layers, each serving a different purpose:

### 1. Agent Files — Identity and Knowledge

**Location:** Three-layer directory system under `wp-content/uploads/datamachine-files/`:

| Layer | Directory | Contents |
|-------|-----------|----------|
| **Shared** (site-wide) | `shared/` | `SITE.md`, `RULES.md` — site-level context shared by all agents |
| **Agent** (identity + knowledge) | `agents/{agent_slug}/` | `SOUL.md`, `MEMORY.md`, `daily/` — agent-specific identity and knowledge |
| **User** (personal) | `users/{user_id}/` | `USER.md` — user preferences that follow the human across agents |

Markdown files stored on the WordPress filesystem. The agent reads these to know who it is, who it works with, and what it knows.

Data Machine ships with core memory files across layers:

**SITE.md** — Site-wide context. Information about the WordPress site that all agents share. Lives in the shared layer.

**RULES.md** — Site-wide rules. Behavioral constraints that apply to every agent. Lives in the shared layer.

**SOUL.md** — Agent identity. Who the agent is, how it communicates, what rules it follows. Injected into every AI request. Rarely changes. Lives in the agent layer.

**MEMORY.md** — Accumulated knowledge. Facts, decisions, lessons learned, project state. Grows and changes over time as the agent learns. Lives in the agent layer.

**USER.md** — Information about the human. Timezone, preferences, communication style, background. Lives in the user layer.

The **CoreMemoryFilesDirective** loads all files from the **MemoryFileRegistry**, resolving each to its layer directory. Core and custom files use the same registration API — the `datamachine_memory_files` action hook provides the extension point for third parties.

**Additional files** serve workflow-specific purposes — editorial strategies, project briefs, content plans. Each pipeline can select which additional files it needs, so a social media workflow doesn't carry the weight of a full content strategy. These are injected at Priority 40 (per-pipeline) or Priority 45 (per-flow).

**Technical details:**
- Protected by `index.php` silence files (standard WordPress pattern)
- CRUD via REST API: `GET/PUT/DELETE /datamachine/v1/files/agent/{filename}`
- Editable through the WordPress admin Agent page
- No serialization — plain markdown, human-readable, git-friendly
- Core files created on activation with starter templates
- Discover paths via `wp datamachine agent paths --allow-root`

### 2. Daily Memory — Temporal Knowledge

**Location:** `agents/{agent_slug}/daily/YYYY/MM/DD.md`

Daily memory files capture session-specific knowledge organized by date. Two-phase system:

1. **Daily Summary** (Phase 1): At the end of each day, Data Machine gathers completed jobs and chat sessions, synthesizes them via AI, and appends the result to the daily file.
2. **MEMORY.md Cleanup** (Phase 2): If MEMORY.md exceeds the size threshold (`MAX_FILE_SIZE = 8KB`), AI splits content into persistent facts and session-specific details. Persistent content stays in MEMORY.md; archived content moves to the daily file.

This keeps MEMORY.md lean while preserving the full temporal record in daily files.

**DailyMemoryTask** runs as a system task (`daily_memory_generation`) on a daily cron schedule. Both phases use editable AI prompts via the `getPromptDefinitions()` system.

**Pipeline integration:** The **DailyMemorySelectorDirective** (Priority 46) injects daily memory into pipeline AI requests. Four selection modes:

| Mode | Description |
|------|-------------|
| `recent_days` | Last N days (max 90) |
| `specific_dates` | Explicit date list |
| `date_range` | From/to date filtering |
| `months` | All dates in selected months |

Total injection capped at 100KB. Files sorted newest-first.

### 3. Chat Sessions — Conversation Memory

**Storage:** Custom MySQL table `{prefix}_datamachine_chat_sessions`

Full conversation history persisted in the database. Sessions survive page reloads and browser restarts. Agent-scoped via `agent_id` column. Configurable retention with automatic cleanup via Action Scheduler.

### 4. Pipeline Context — Workflow Memory

**Location:** `wp-content/uploads/datamachine-files/pipeline-{id}/context/`

Per-pipeline documents that provide background for specific workflows. Job execution data stored as JSON creates an audit trail of what was processed — transient working memory cleaned by retention policies.

## Multi-Agent Architecture

Data Machine supports multiple agents on a single WordPress installation. Each agent has its own identity, memory, and resource scope.

### Agent Registry

Agents are stored in the `datamachine_agents` table with:
- **Unique slug** — used for directory naming and CLI reference
- **Owner** — WordPress user who created the agent
- **Config** — JSON configuration (AI provider preferences, etc.)
- **Status** — `active` or `inactive`

### Access Control

The `datamachine_agent_access` table implements role-based access:

| Role | Level | Permissions |
|------|-------|-------------|
| `viewer` | 0 | Read agent resources |
| `operator` | 1 | Read + execute workflows |
| `admin` | 2 | Full control including configuration |

### Resource Scoping

All major resources carry an `agent_id` column:
- **Pipelines** — each pipeline belongs to an agent
- **Flows** — each flow belongs to an agent
- **Jobs** — each job runs under an agent
- **Chat sessions** — each conversation is agent-scoped

The `PermissionHelper` class provides methods for agent-level access checks:
- `resolve_scoped_agent_id()` — determines which agent context applies
- `can_access_agent()` — checks if a user has the required role
- `owns_agent_resource()` — verifies resource ownership

### Agent Management

```bash
# List agents
wp datamachine agents list --allow-root

# Create agent
wp datamachine agents create --slug=my-agent --name="My Agent" --allow-root

# Rename agent (moves filesystem directory + updates DB)
wp datamachine agents rename old-slug new-slug --allow-root

# Discover file paths for an agent
wp datamachine agent paths --agent=my-agent --allow-root
```

## Core Memory Files

The difference between useful memory and noise is structure. Each core file has a specific job.

### SITE.md — Site-Wide Context (Shared Layer)

SITE.md contains information about the WordPress installation that all agents share. Site URL, environment details, shared conventions.

### RULES.md — Site-Wide Rules (Shared Layer)

RULES.md holds behavioral constraints that apply to every agent regardless of identity. Security policies, coding standards, deployment rules.

### SOUL.md — Who the Agent Is (Agent Layer)

SOUL.md is **identity, not knowledge.** It should contain things that are true about the agent regardless of what it's working on:

- **Identity** — name, role, what site it manages
- **Voice and tone** — how it communicates
- **Rules** — behavioral constraints (what it must/must not do)
- **Context** — background about the domain and audience

SOUL.md should be **stable.** If you're editing it frequently, the content probably belongs in MEMORY.md instead.

**Good SOUL.md content:**
```markdown
## Identity
I am the voice of example.com — a music and culture publication.

## Rules
- Follow AP style for articles
- Never publish without a featured image
- Ask for clarification when topic scope is ambiguous
```

**Bad SOUL.md content:**
```markdown
## Current Tasks
- Finish the interview draft by Friday
- Pinterest pins are underperforming — try new formats
```

That's memory, not identity. Put it in MEMORY.md.

### USER.md — Who the Human Is (User Layer)

USER.md holds **information about the human the agent works with.** This is separate from agent identity and agent knowledge because it serves a different purpose — it helps the agent adapt to its user.

- **Timezone and location** — so the agent knows when to schedule things
- **Communication preferences** — concise vs detailed, formal vs casual
- **Background** — relevant context about the human's expertise or role
- **Working patterns** — night owl, prefers async, etc.

Created on activation with a starter template, same as SOUL.md and MEMORY.md.

```markdown
# User

## About
<!-- Name, timezone, location, background -->

## Preferences
<!-- Communication style, update format, decision-making approach -->

## Working Patterns
<!-- Schedule, availability, things the agent should know about how you work -->
```

### MEMORY.md — What the Agent Knows (Agent Layer)

MEMORY.md is the agent's **accumulated knowledge** — facts, decisions, lessons, context that builds up over time. It lives in the agent layer alongside SOUL.md. Structure it for scanability:

- **Use clear section headers** — the agent needs to find relevant info quickly
- **Be factual, not narrative** — bullet points over paragraphs
- **Date important decisions** — "Switched to weekly publishing (2026-02-15)" is more useful than "We publish weekly"
- **Prune stale info** — remove things that are no longer true

**Recommended structure:**
```markdown
# Agent Memory

## State
- Content calendar migration — in progress
- SEO audit — completed 2026-02-20

## Site Knowledge
- WordPress at /var/www/example.com
- Custom theme: flavor
- Docs plugin: flavor-docs v0.9.11

## Lessons Learned
- WP-CLI needs --allow-root on this server
- Image uploads fail above 5MB — server limit
- Category "Reviews" has ID 14
```

MEMORY.md supports **section-based operations** via the AgentMemory service and WordPress Abilities API:

```bash
# Read a specific section
wp_execute_ability('datamachine/get-agent-memory', ['section' => 'Lessons Learned'])

# Append to a section
wp_execute_ability('datamachine/update-agent-memory', [
    'section' => 'Lessons Learned',
    'content' => '- New fact the agent learned',
    'mode'    => 'append',
])

# Search across memory
wp_execute_ability('datamachine/search-agent-memory', [
    'query' => 'theme',
    'user_id' => 1,
    'agent_id' => 1,
])

# List all sections
wp_execute_ability('datamachine/list-agent-memory-sections')
```

This allows agents to surgically update specific sections of memory without rewriting the entire file.

### When to Create Additional Files

Create a new file when a body of knowledge is:

1. **Large enough to be distracting** — if a section of MEMORY.md is 50+ lines and only relevant to one workflow, split it out
2. **Workflow-specific** — a content strategy doc only matters to content pipelines, not maintenance tasks
3. **Frequently updated independently** — if one person updates the editorial brief while another maintains site knowledge, separate them

**Naming conventions:**
- Lowercase with hyphens: `content-strategy.md`, `seo-guidelines.md`
- Be descriptive: `content-briefing.md` is better than `notes.md`
- Core files (SOUL.md, USER.md, MEMORY.md, SITE.md, RULES.md) are uppercase by convention — additional files are lowercase

### File Size Awareness

Agent memory files are injected as system messages. Every token counts against the context window.

- **SITE.md**: Keep under 300 words. Shared site facts.
- **RULES.md**: Keep under 300 words. Universal behavioral rules.
- **SOUL.md**: Keep under 500 words. Identity should be concise.
- **USER.md**: Keep under 300 words. Key facts about the human.
- **MEMORY.md**: Target under 8KB (`MAX_FILE_SIZE`). The DailyMemoryTask automatically archives excess to daily files.
- **Additional files**: Keep focused. A 5,000-word strategy doc injected into a simple social media pipeline is wasteful.

If a file grows unwieldy, that's a signal to split it or prune it.

## How Memory Gets Into AI Prompts

Data Machine uses a **directive system** — a priority-ordered chain that injects context into every AI request.

| Priority | Directive | Context | What It Injects |
|----------|-----------|---------|-----------------|
| 10 | `PipelineCoreDirective` | Pipeline | Base Data Machine identity for pipelines |
| **15** | **`ChatAgentDirective`** | **Chat** | **Chat agent identity and instructions** |
| **20** | **`CoreMemoryFilesDirective`** | **All** | **SITE.md, RULES.md (shared), SOUL.md, MEMORY.md (agent), USER.md (user) + custom registry files** |
| **20** | **`SystemAgentDirective`** | **System** | **System task agent identity** |
| 40 | `PipelineMemoryFilesDirective` | Pipeline | Per-pipeline selected additional files |
| **45** | **`ChatPipelinesDirective`** | **Chat** | **Pipeline/flow context for chat** |
| **45** | **`FlowMemoryFilesDirective`** | **Pipeline** | **Per-flow selected additional files** |
| **46** | **`DailyMemorySelectorDirective`** | **Pipeline** | **Selected daily memory files based on flow config** |
| 50 | `PipelineSystemPromptDirective` | Pipeline | Workflow instructions |
| 80 | `SiteContextDirective` | All | WordPress metadata (filterable/replaceable) |

### Core Memory Files (Priority 20)

The **CoreMemoryFilesDirective** loads all files from the **MemoryFileRegistry**, resolving each to its layer directory:

```
Registry priority order → resolve layer → read file → inject as system message
```

All core files register through the same API that plugins use:

```php
// From bootstrap.php — these are just the defaults.
MemoryFileRegistry::register( 'SITE.md',   10, [ 'layer' => 'shared', 'protected' => true ] );
MemoryFileRegistry::register( 'RULES.md',  15, [ 'layer' => 'shared', 'protected' => true ] );
MemoryFileRegistry::register( 'SOUL.md',   20, [ 'layer' => 'agent',  'protected' => true ] );
MemoryFileRegistry::register( 'USER.md',   25, [ 'layer' => 'user',   'protected' => true ] );
MemoryFileRegistry::register( 'MEMORY.md', 30, [ 'layer' => 'agent',  'protected' => true ] );
```

The priority number determines **load order**. Missing files are silently skipped. Empty files are silently skipped. Third parties register additional files through the same `register()` API or the `datamachine_memory_files` action hook.

Plugins and themes can register their own memory files through the same API:

```php
// A theme adding its own context file
MemoryFileRegistry::register( 'brand-guidelines.md', 40 );

// Or deregister a default
MemoryFileRegistry::deregister( 'USER.md' );
```

### Pipeline Memory Files (Priority 40)

Each pipeline can select additional agent files beyond the core set. Configure via the "Agent Memory Files" section in the pipeline settings UI. Core files (SOUL.md, USER.md, MEMORY.md) are excluded from the picker since they're always injected at Priority 20.

### Flow Memory Files (Priority 45)

Each flow can independently select additional memory files, allowing flow-level customization beyond pipeline defaults.

### Daily Memory Files (Priority 46)

Flows can configure daily memory injection through the `daily_memory` flow config setting. See the [Daily Memory section](#2-daily-memory--temporal-knowledge) for selection modes.

```
"Daily Music News"    -> [content-strategy.md] + recent 7 days daily memory
"Social Media Posts"  -> []
"Album Reviews"       -> [content-strategy.md, content-briefing.md] + specific dates
```

Different workflows access different slices of knowledge. This is deliberate — selective memory injection over RAG means you know exactly what context the agent has, with no embedding cost and no hallucination from irrelevant similarity matches.

## External Agent Integration

Not every agent runs inside Data Machine's pipeline or chat system. An agent might be a CLI tool, a Discord bot, or a standalone script that uses the WordPress site as its memory backend.

### Reading Memory via AGENTS.md

Agents that operate on the server (like Claude Code via Kimaki) can read memory files directly from disk and inject them into their own session context. A common pattern is an `AGENTS.md` file in the site root that includes the contents of SOUL.md, USER.md, and MEMORY.md at session startup:

```
AGENTS.md (at site root)
  ├── includes SOUL.md content   (who the agent is)
  ├── includes USER.md content   (who the human is)
  └── includes MEMORY.md content (what the agent knows)
```

The agent wakes up with identity, user context, and knowledge already loaded. Updates to the files on disk take effect on the next session — no deployment needed.

### Discovering Memory File Paths

The canonical way to find agent memory file paths is via WP-CLI:

```bash
# Discover all paths for the current agent
wp datamachine agent paths --allow-root

# For a specific agent
wp datamachine agent paths --agent=my-agent --allow-root

# Table format for readability
wp datamachine agent paths --format=table --allow-root
```

Output structure:
```json
{
    "agent_slug": "chubes-bot",
    "user_id": 1,
    "layers": {
        "shared": "/path/to/datamachine-files/shared",
        "agent": "/path/to/datamachine-files/agents/chubes-bot",
        "user": "/path/to/datamachine-files/users/1"
    },
    "files": {
        "SITE.md": { "layer": "shared", "path": "...", "exists": true },
        "SOUL.md": { "layer": "agent", "path": "...", "exists": true },
        "MEMORY.md": { "layer": "agent", "path": "...", "exists": true },
        "USER.md": { "layer": "user", "path": "...", "exists": true }
    }
}
```

This is the recommended discovery method for external consumers (CI scripts, AGENTS.md generators, etc.) rather than hardcoding paths.

### Reading Memory via REST API

Remote agents can read and write memory files over HTTP:

```bash
# Read MEMORY.md
curl -s https://example.com/wp-json/datamachine/v1/files/agent/MEMORY.md \
  -H "Authorization: Bearer $TOKEN"

# Update MEMORY.md
curl -X PUT https://example.com/wp-json/datamachine/v1/files/agent/MEMORY.md \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: text/plain" \
  --data-binary @MEMORY.md

# List daily memory files
curl -s https://example.com/wp-json/datamachine/v1/files/agent/daily \
  -H "Authorization: Bearer $TOKEN"

# Read a specific daily file
curl -s https://example.com/wp-json/datamachine/v1/files/agent/daily/2026/03/15 \
  -H "Authorization: Bearer $TOKEN"
```

This makes WordPress the single source of truth for agent memory, regardless of where the agent runs.

### Reading Memory via WP-CLI

Agents with shell access can use the `agent` command for structured access:

```bash
# Discover file paths (canonical command for external consumers)
wp datamachine agent paths --allow-root

# Read memory file
wp datamachine agent files read SOUL.md --allow-root
wp datamachine agent files read MEMORY.md --allow-root

# List agent directory contents
wp datamachine agent files list --allow-root

# Read daily memory
wp datamachine agent daily read 2026-03-15 --allow-root

# Search daily memory
wp datamachine agent daily search "deployment" --allow-root
```

### The Key Principle

However the agent consumes memory — directives, AGENTS.md injection, REST API, WP-CLI, direct file read — the **files on disk are the source of truth.** All paths lead to the same markdown documents organized in the three-layer directory structure.

## Memory Maintenance

Memory degrades if you never maintain it. Agent files need periodic attention — and Data Machine automates much of this.

### Automated Maintenance: DailyMemoryTask

The `DailyMemoryTask` system task handles routine memory maintenance automatically:

1. **Daily summaries** — synthesizes the day's activity into a daily file
2. **MEMORY.md pruning** — when MEMORY.md exceeds 8KB, AI separates persistent facts from session-specific details, archiving temporal content to daily files

Enable via Settings → System Tasks → Daily Memory, or:
```bash
wp datamachine system run daily_memory_generation --allow-root
```

### Review Cadence

- **SITE.md / RULES.md**: Review when site infrastructure or policies change.
- **SOUL.md**: Review quarterly. Identity shouldn't change often, but verify rules and context are still accurate.
- **USER.md**: Review when circumstances change. New timezone, new preferences, new role.
- **MEMORY.md**: Automatically maintained by DailyMemoryTask. Manual review monthly for accuracy.
- **Workflow files**: Review when the workflow changes. A content strategy from six months ago may be actively misleading.

### Signs Memory Needs Attention

- The agent keeps making the same mistake → missing or incorrect info in memory
- MEMORY.md exceeds 8KB → DailyMemoryTask should be enabled
- The agent references outdated facts → stale entries need removal
- A pipeline behaves inconsistently → check which memory files are attached

### Who Maintains Memory

Both humans and agents can update memory files:

- **Humans** edit via the WordPress admin Agent page or any text editor with server access
- **Agents** update via REST API, Abilities API, or direct file write during workflows
- **DailyMemoryTask** automatically archives session-specific content from MEMORY.md
- **Pipelines** can include memory-update steps that append learned information

The most effective pattern is **agent writes, human reviews** — the agent appends what it learns, DailyMemoryTask keeps it pruned, and the human periodically curates for accuracy and relevance.

## Memory Lifecycle

### Creation

- **SITE.md, RULES.md**: Created on layered architecture migration. Shared across all agents.
- **SOUL.md, MEMORY.md**: Created per-agent on agent creation or plugin activation.
- **USER.md**: Created per-user on plugin activation with starter template.
- **Daily files**: Created automatically by DailyMemoryTask or daily memory write operations.
- **Additional files**: Created via REST API, admin UI, or by the agent itself.
- **Chat sessions**: Created on first message in a conversation.
- **Job data**: Created during pipeline execution.

### Updates

- **Agent files**: Updated via REST API, Abilities API, or admin UI. Changes take effect on the next AI request — no restart needed.
- **Daily files**: Appended by DailyMemoryTask; writable via REST API and Abilities.
- **Chat sessions**: Grow with each message exchange.
- **Job data**: Accumulated during multi-step execution.

### Cleanup

- **Chat sessions**: Retention-based (default 90 days) via Action Scheduler
- **Orphaned sessions**: Auto-cleaned after 1 hour if empty
- **Job data**: Cleaned by FileCleanup based on retention policies
- **MEMORY.md**: Automatically pruned by DailyMemoryTask when oversized
- **Agent files**: Manual only — no auto-cleanup (except MEMORY.md pruning)
- **Daily files**: Manual only — no auto-cleanup

## Design Decisions

### Files over database

Agent memory is stored as **files on disk**, not in `wp_options` or custom tables. Files are human-readable, git-friendly, have no serialization overhead, and match the mental model of "documents the agent reads."

### Three-layer directory architecture

The shared/agent/user layer separation serves distinct purposes:
- **Shared** — site-wide facts all agents need (SITE.md, RULES.md)
- **Agent** — identity and accumulated knowledge specific to one agent (SOUL.md, MEMORY.md, daily/)
- **User** — human preferences that follow the user across agents (USER.md only)

This means a single WordPress site can host multiple agents with distinct identities while sharing common site context.

### Registry-driven loading with layer resolution

All memory files — core and custom — register through the same `MemoryFileRegistry` API. Each registration specifies its layer (`shared`, `agent`, `user`), and the `CoreMemoryFilesDirective` resolves each file to the correct directory at runtime. This makes the system fully extensible: plugins register files in any layer through the same API that core uses. No special-casing, no hardcoded file lists.

### Selective injection over RAG

Each pipeline explicitly selects which additional memory files it needs. No embeddings, no similarity search. This is deterministic (you know exactly what context the agent has), simple to debug, and appropriate for the scale — agent memory is typically kilobytes, not gigabytes.

### Daily memory for temporal knowledge

Rather than letting MEMORY.md grow indefinitely, the daily memory system provides a natural temporal archive. Current facts stay in MEMORY.md; historical context is preserved in daily files and can be selectively injected when needed.

## REST API Reference

### Agent Files

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/datamachine/v1/files/agent` | List all agent files |
| `GET` | `/datamachine/v1/files/agent/{filename}` | Get file content |
| `PUT` | `/datamachine/v1/files/agent/{filename}` | Create or update (raw body = content) |
| `DELETE` | `/datamachine/v1/files/agent/{filename}` | Delete file (blocked for SOUL.md, MEMORY.md) |

### Daily Memory Files

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/datamachine/v1/files/agent/daily` | List daily memory files (grouped by month) |
| `GET` | `/datamachine/v1/files/agent/daily/{YYYY}/{MM}/{DD}` | Get daily file content |
| `PUT` | `/datamachine/v1/files/agent/daily/{YYYY}/{MM}/{DD}` | Write daily file |
| `DELETE` | `/datamachine/v1/files/agent/daily/{YYYY}/{MM}/{DD}` | Delete daily file |

### Flow Files

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/datamachine/v1/files?flow_step_id={id}` | List flow files |
| `POST` | `/datamachine/v1/files` | Upload file (multipart form) |
| `DELETE` | `/datamachine/v1/files/{filename}?flow_step_id={id}` | Delete flow file |

All agent file endpoints support a `user_id` parameter for multi-agent scoping. Requires authentication.

### Agent Memory Abilities

| Ability | Description |
|---------|-------------|
| `datamachine/get-agent-memory` | Read full file or a specific section |
| `datamachine/update-agent-memory` | Set or append to a section |
| `datamachine/search-agent-memory` | Search across memory files (supports `user_id`, `agent_id`) |
| `datamachine/list-agent-memory-sections` | List all `##` section headers |

### Daily Memory Abilities

| Ability | Description |
|---------|-------------|
| `datamachine/daily-memory-read` | Read daily file by date (defaults to today) |
| `datamachine/daily-memory-write` | Write or append to daily file |
| `datamachine/daily-memory-list` | List all daily files grouped by month |
| `datamachine/search-daily-memory` | Search daily files with date range and context |
| `datamachine/daily-memory-delete` | Delete daily file by date |

All daily memory abilities accept `user_id` and `agent_id` for multi-agent scoping.

## WP-CLI Reference

### Agent Path Discovery

```bash
# Canonical discovery command — returns all layer paths and file locations
wp datamachine agent paths --allow-root

# Resolve for a specific agent
wp datamachine agent paths --agent=my-agent --allow-root

# Table format
wp datamachine agent paths --format=table --allow-root

# Relative paths (useful for AGENTS.md generators)
wp datamachine agent paths --relative --allow-root
```

### Agent Management

```bash
wp datamachine agents list --allow-root
wp datamachine agents create --slug=bot --name="My Bot" --allow-root
wp datamachine agents rename old-slug new-slug --allow-root
```

### Agent File Commands

```bash
wp datamachine agent paths --allow-root
wp datamachine agent files list --allow-root
wp datamachine agent files read <file> --allow-root
wp datamachine agent files write <file> --content="..." --allow-root
wp datamachine agent files edit <file> --old="..." --new="..." --allow-root
```

> **Note:** For workspace/git operations, install the `data-machine-code` extension and use `wp datamachine-code workspace`.

## Extending the Memory System

### Register Custom Memory Files

Add files to the core injection (Priority 20) via the registry. Each file specifies its **layer**, which determines where it lives and who can see it:

```php
use DataMachine\Engine\AI\MemoryFileRegistry;

// Agent-layer file — scoped to a single agent.
MemoryFileRegistry::register( 'brand-guidelines.md', 40, [
    'layer'       => MemoryFileRegistry::LAYER_AGENT,
    'label'       => 'Brand Guidelines',
    'description' => 'Voice, tone, and visual brand standards.',
] );

// Shared-layer file — visible to ALL agents on the site.
MemoryFileRegistry::register( 'editorial-policy.md', 45, [
    'layer'       => MemoryFileRegistry::LAYER_SHARED,
    'label'       => 'Editorial Policy',
    'description' => 'Site-wide editorial standards.',
] );

// User-layer file — visible to ALL agents for a specific user.
MemoryFileRegistry::register( 'work-context.md', 50, [
    'layer'       => MemoryFileRegistry::LAYER_USER,
    'label'       => 'Work Context',
    'description' => 'User-specific project context.',
] );

// Protected file — cannot be deleted.
MemoryFileRegistry::register( 'compliance.md', 12, [
    'layer'     => MemoryFileRegistry::LAYER_SHARED,
    'protected' => true,
    'label'     => 'Compliance Rules',
] );
```

**Registration arguments:**

| Argument | Type | Default | Description |
|----------|------|---------|-------------|
| `layer` | string | `'agent'` | One of `shared`, `agent`, `user` |
| `protected` | bool | `false` | Protected files cannot be deleted or blanked |
| `label` | string | *derived from filename* | Human-readable display label |
| `description` | string | `''` | Purpose description shown in the admin UI |

Files are resolved to their layer directory at runtime. Missing files are silently skipped.

### Extension Hook

Third parties can register files via the `datamachine_memory_files` action, which fires once per request when the registry is first consumed:

```php
add_action( 'datamachine_memory_files', function( $current_files ) {
    // Inspect existing registrations if needed.
    // Register additional files via the standard API.
    MemoryFileRegistry::register( 'my-plugin-context.md', 60, [
        'layer' => MemoryFileRegistry::LAYER_AGENT,
        'label' => 'My Plugin Context',
    ] );
} );
```

### Custom Directives

Register a directive to inject memory from non-file sources:

```php
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class'       => 'MyPlugin\Directives\CustomMemory',
        'priority'    => 25, // Between core memory files (20) and pipeline memory (40)
        'agent_types' => ['pipeline'],
    ];
    return $directives;
});
```

The directive class implements `DirectiveInterface` and returns system messages with one of three output types:

```php
class CustomMemory implements \DataMachine\Engine\AI\Directives\DirectiveInterface {
    public static function get_outputs(
        string $provider_name,
        array $tools,
        ?string $step_id = null,
        array $payload = []
    ): array {
        return [
            [
                'type'    => 'system_text',    // or 'system_json' or 'system_file'
                'content' => 'Custom memory content here',
            ],
        ];
    }
}
```

**Valid output types:**
- `system_text` — requires `content` (string)
- `system_json` — requires `label` (string) and `data` (array)
- `system_file` — requires `file_path` (string) and `mime_type` (string)

### Custom Site Context

Extend what the agent knows about the site:

```php
add_filter('datamachine_site_context', function($context) {
    $context['inventory'] = get_product_inventory_summary();
    return $context;
});
```
