# Daily Memory System

The daily memory system provides temporal, append-only memory for AI agents. While MEMORY.md holds persistent knowledge that every session needs, daily memory captures **what happened** — session activity, investigation notes, decisions made on a specific day. Daily files are never auto-cleared and form a searchable archive of agent history.

## Overview

Daily memory has six components:

1. **DailyMemory service** — filesystem storage with CRUD, search, and date-range queries
2. **DailyMemoryTask** — AI-powered system task that synthesizes daily summaries and cleans MEMORY.md
3. **DailyMemoryAbilities** — WordPress 6.9 Abilities API registration
4. **AgentDailyMemoryDirective** — opt-in injection at Priority 35 for agents that enable it via `agent_config.daily_memory` (chat + pipeline contexts). Since 0.71.0; replaces `DailyMemorySelectorDirective`.
5. **AgentDailyMemory tool** — AI chat tool for agent self-service
6. **CLI and REST endpoints** — human and programmatic access

## File Storage

**Location:** `wp-content/uploads/datamachine-files/agents/{agent_slug}/daily/YYYY/MM/DD.md`

Files follow the WordPress Media Library date convention (`YYYY/MM/`). Each day gets one markdown file, named by the two-digit day.

```
agents/chubes-bot/daily/
  2026/
    02/
      15.md
      24.md
      25.md
    03/
      01.md
      16.md
```

### DailyMemory Service

**Source:** `inc/Core/FilesRepository/DailyMemory.php`
**Since:** v0.32.0

The `DailyMemory` class handles all file operations. It accepts `user_id` and `agent_id` constructor parameters for multi-agent scoping — the directory is resolved through `DirectoryManager`.

```php
// Default (current agent context)
$daily = new DailyMemory();

// Scoped to a specific agent
$daily = new DailyMemory(user_id: 0, agent_id: 42);
```

### CRUD Operations

| Method | Description |
|--------|-------------|
| `read(string $year, string $month, string $day)` | Read a daily file. Returns `{success, date, content}` or `{success: false, message}`. |
| `write(string $year, string $month, string $day, string $content)` | Replace entire file content. Creates directory structure if needed. |
| `append(string $year, string $month, string $day, string $content)` | Append to file. Creates file with date header (`# YYYY-MM-DD`) if it doesn't exist. |
| `delete(string $year, string $month, string $day)` | Delete a daily file. |
| `exists(string $year, string $month, string $day)` | Check if a daily file exists. |

### Listing and Search

| Method | Description |
|--------|-------------|
| `list_all()` | List all daily files grouped by month. Returns `{months: {'2026/03': ['01', '16'], '2026/02': ['15', '24', '25']}}` (newest first). |
| `list_months()` | List just the month keys (e.g. `['2026/03', '2026/02']`). |
| `search(string $query, ?string $from, ?string $to, int $context_lines)` | Case-insensitive substring search across all daily files. Returns up to 50 matches with surrounding context lines. Optional date range filtering. |

### Path Helpers

| Method | Description |
|--------|-------------|
| `get_base_path()` | Base directory for daily files (e.g. `agents/{slug}/daily`). |
| `get_file_path(string $year, string $month, string $day)` | Full file path for a specific date. |
| `get_today_path()` | Full file path for today (UTC). |
| `parse_date(string $date)` | Static. Parse `YYYY-MM-DD` into `{year, month, day}` components. Returns null if invalid. |

## DailyMemoryTask

**Source:** `inc/Engine/AI/System/Tasks/DailyMemoryTask.php`
**Since:** v0.32.0

An AI-powered system task that runs daily via Action Scheduler. It has two phases:

### Phase 1: Activity Synthesis

Gathers the day's activity from Data Machine (completed jobs and chat sessions) and uses AI to synthesize a concise daily memory entry.

**Data sources:**
- `datamachine_jobs` — pipeline and system jobs created on the target date (job_id, pipeline_id, flow_id, source, label, status)
- `datamachine_chat_sessions` — chat sessions created on the target date (session_id, title, context)

**Process:**
1. Query jobs and chat sessions for the target date
2. Format as structured context with `## Jobs completed on YYYY-MM-DD` and `## Chat sessions on YYYY-MM-DD` sections
3. Send to AI with the `daily_summary` prompt template
4. Append the AI-generated summary to the day's daily memory file

If no DM activity occurred (empty context), Phase 1 is skipped. Phase 2 still runs because most agent work happens via external tools (e.g. Kimaki sessions) that don't create DM jobs.

### Phase 2: MEMORY.md Cleanup

Reads the full MEMORY.md file and uses AI to split it into persistent knowledge (stays) and session-specific content (archived to the daily file). This keeps MEMORY.md lean for the context window budget.

**Process:**
1. Read MEMORY.md via `AgentMemory` service
2. **Skip if within budget** — if file size ≤ `AgentMemory::MAX_FILE_SIZE` (8192 bytes / 8 KB), no cleanup needed
3. Send to AI with the `memory_cleanup` prompt template
4. Parse AI response into `===PERSISTENT===` and `===ARCHIVED===` sections
5. **Safety check** — verify the new content isn't suspiciously small:
   - If file is >2x over budget: allow reduction down to half the target size (4 KB minimum)
   - If file is near budget: don't allow reduction below 10% of original size
6. Write cleaned content back to MEMORY.md
7. Append archived content to the daily file under `### Archived from MEMORY.md`

**Failure handling:** Cleanup failures are logged but never fail the overall job. The daily summary (Phase 1) is already written. Key safety rules:
- Never wipe MEMORY.md if the AI response can't be parsed
- Never write if the persistent section is empty
- Never write if the new content is suspiciously small (safety threshold check)

### Editable Prompts

DailyMemoryTask defines two customizable prompts via `getPromptDefinitions()`:

**`daily_summary`** — Synthesizes the day's DM activity into a concise entry.
- Variable: `{{context}}` — gathered activity from jobs and chat sessions

**`memory_cleanup`** — Splits MEMORY.md into persistent vs. session-specific content.
- Variables: `{{memory_content}}` (current MEMORY.md), `{{date}}` (target date), `{{max_size}}` (recommended limit, e.g. "8 KB")

Prompts can be overridden via the System Tasks admin tab or programmatically:

```php
SystemTask::setPromptOverride('daily_memory_generation', 'memory_cleanup', 'Your custom prompt...');
```

### Scheduling

Scheduling is registered separately from the task handler via the
`datamachine_recurring_schedules` filter in `SystemAgentServiceProvider`.
All AS plumbing runs through the shared `RecurringScheduler` primitive
(see [recurring-scheduler.md](recurring-scheduler.md)).

- **Hook:** `datamachine_recurring_daily_memory_generation`
- **Schedule:** Daily; first run at tomorrow midnight UTC
- **Setting:** `daily_memory_enabled` (default: false) — when disabled, the
  schedule is unregistered during reconciliation on `action_scheduler_init`
- **Manual run:** Supported (`supports_run: true` in task meta) — can be
  triggered via CLI / REST / UI

Upgrading sites have any pending action under the pre-refactor hook
(`datamachine_system_agent_daily_memory`) unscheduled during the first
`action_scheduler_init` reconciliation.

### Task Metadata

```php
[
    'label'           => 'Daily Memory',
    'description'     => 'AI-generated daily summary of activity and automatic MEMORY.md cleanup',
    'setting_key'     => 'daily_memory_enabled',
    'default_enabled' => false,
    'supports_run'    => true,
    // trigger / trigger_type are resolved by TaskRegistry from the matching
    // schedule in RecurringScheduleRegistry — the task is a pure handler.
]
```

### Schedule Registration

```php
add_filter( 'datamachine_recurring_schedules', function ( $schedules ) {
    $schedules['daily_memory_generation'] = [
        'task_type'          => 'daily_memory_generation',
        'interval'           => 'daily',
        'enabled_setting'    => 'daily_memory_enabled',
        'default_enabled'    => false,
        'label'              => 'Daily at midnight UTC',
        'first_run_callback' => 'strtotime',
        'first_run_arg'      => 'tomorrow midnight',
    ];
    return $schedules;
} );
```

## DailyMemoryAbilities

**Source:** `inc/Abilities/DailyMemoryAbilities.php`
**Since:** v0.32.0

Five abilities registered under the `datamachine` category:

| Ability | Description |
|---------|-------------|
| `datamachine/daily-memory-read` | Read a daily file by date. Defaults to today. |
| `datamachine/daily-memory-write` | Write or append to a daily file. Mode: `write` (replace) or `append` (default). |
| `datamachine/daily-memory-list` | List all daily files grouped by month. |
| `datamachine/search-daily-memory` | Search across daily files with optional date range. |
| `datamachine/daily-memory-delete` | Delete a daily file by date. |

All abilities accept `user_id` and `agent_id` parameters for multi-agent scoping. Write and delete operations respect the `daily_memory_enabled` setting — they return an error if daily memory is disabled.

```php
// Read today's daily memory
wp_execute_ability('datamachine/daily-memory-read', ['date' => '2026-03-16']);

// Append a note
wp_execute_ability('datamachine/daily-memory-write', [
    'content' => '- Deployed v0.41.0 to production',
    'date'    => '2026-03-16',
    'mode'    => 'append',
]);

// Search for mentions of "homeboy"
wp_execute_ability('datamachine/search-daily-memory', [
    'query' => 'homeboy',
    'from'  => '2026-03-01',
    'to'    => '2026-03-31',
]);
```

## AgentDailyMemoryDirective

**Source:** `inc/Engine/AI/Directives/AgentDailyMemoryDirective.php`
**Since:** v0.71.0
**Priority:** 35
**Contexts:** `chat`, `pipeline`

Opt-in directive that reads recent daily archive files directly from disk and injects each one as its own `system_text` block into the AI context. Replaces the former `DailyMemorySelectorDirective` + per-flow `flow_config.daily_memory` config.

### Opt-in per agent

Daily memory injection is **off by default for every agent**. A stateless agent (alt-text generator, wiki builder, one-shot pipeline worker) should never carry `what happened yesterday` in its context — session continuity matters for personal assistants, not for purpose-built workers.

Enable per agent via `agent_config`:

```json
{
  "daily_memory": {
    "enabled":     true,
    "recent_days": 3
  }
}
```

| Field | Default | Range | Purpose |
|---|---|---|---|
| `enabled` | `false` | bool | Master switch. When false, the directive emits nothing. |
| `recent_days` | `3` | `1` – `14` | Number of most recent days to include. Hard-clamped. |

### How it reads

- Walks `agents/{slug}/daily/YYYY/MM/DD.md` on disk from newest to oldest.
- Each available day becomes one `system_text` block labelled `"## Daily Memory: YYYY-MM-DD"`.
- Real filenames and dates stay intact — the AI sees discrete files, not a stitched-together blob.
- Total injected size is bounded by `AgentMemory::MAX_FILE_SIZE` (8 KB). When adding an older day would push total past the budget, iteration stops — the freshest days always win.

### Priority in directive chain

```
Priority 10 — Plugin Core (agent identity)
Priority 20 — Core Memory Files (SOUL.md, USER.md, MEMORY.md, etc.)
Priority 35 — Agent Daily Memory (THIS; chat + pipeline, opt-in)
Priority 40 — Pipeline Memory Files
Priority 45 — Flow Memory Files
Priority 50 — Pipeline System Prompt
Priority 60 — Pipeline Context Files
Priority 70 — Tool Definitions
Priority 80 — Site Context
```

### For precise historical lookups

The directive only injects *recent* days on a rolling window. Anything beyond `recent_days`, or any specific date / date range / month query, should be served by the `agent_daily_memory` tool on demand — it covers every former selector mode with more precision than a pre-configured dropdown ever did.

## AgentDailyMemory Tool

**Source:** `inc/Engine/AI/Tools/Global/AgentDailyMemory.php`
**Tool ID:** `agent_daily_memory`
**Contexts:** `chat`, `pipeline`, `standalone`

An AI chat tool that lets agents manage their own daily memory during conversations. Always available (no external configuration required).

### Tool Schema

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | yes | `read`, `write`, `list`, or `search` |
| `user_id` | integer | no | WordPress user ID for multi-agent scoping |
| `date` | string | no | `YYYY-MM-DD` (defaults to today) |
| `content` | string | no | Markdown content (required for `write`) |
| `mode` | string | no | `append` (default) or `write` (replace) |
| `query` | string | no | Search term (required for `search`) |
| `from` | string | no | Start date for search range |
| `to` | string | no | End date for search range |

### Delegation

The tool delegates to WordPress Abilities:

| Action | Ability |
|--------|---------|
| `read` | `datamachine/daily-memory-read` |
| `write` | `datamachine/daily-memory-write` |
| `list` | `datamachine/daily-memory-list` |
| `search` | `datamachine/search-daily-memory` |

### Usage Guidance (from tool description)

> Use for session activity, temporal events, and work logs. Daily memory captures WHAT HAPPENED — persistent knowledge belongs in agent_memory (MEMORY.md) instead.

## REST API

**Source:** `inc/Api/AgentFiles.php`

Daily memory endpoints are registered alongside other agent file endpoints:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/datamachine/v1/files/agent/daily` | List all daily memory files |
| `GET` | `/datamachine/v1/files/agent/daily/{year}/{month}/{day}` | Read a specific daily file |
| `PUT` | `/datamachine/v1/files/agent/daily/{year}/{month}/{day}` | Write/replace a daily file |
| `DELETE` | `/datamachine/v1/files/agent/daily/{year}/{month}/{day}` | Delete a daily file |

All endpoints require `manage_options` capability. The `PUT` endpoint accepts content as a JSON body parameter or raw body and always uses `mode: 'write'` (replace). An optional `user_id` query parameter enables multi-agent scoping.

**Note:** There is no REST search endpoint for daily memory. Search is only available via the AI tool and CLI.

## CLI

**Source:** `inc/Cli/Commands/MemoryCommand.php`

```bash
# List all daily memory files
wp datamachine agent daily list [--agent=<slug>] [--user=<id>] [--format=table|json]

# Read a daily file (defaults to today)
wp datamachine agent daily read [YYYY-MM-DD] [--agent=<slug>]

# Write (replace) a daily file
wp datamachine agent daily write [YYYY-MM-DD] <content> [--agent=<slug>]

# Append to a daily file
wp datamachine agent daily append [YYYY-MM-DD] <content> [--agent=<slug>]

# Delete a daily file
wp datamachine agent daily delete <YYYY-MM-DD> [--agent=<slug>]

# Search across daily files
wp datamachine agent daily search <query> [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--agent=<slug>]
```

All commands resolve agent scoping via `AgentResolver` (from `--agent` flag) with fallback to `--user`.

## Delegation Architecture

All three interfaces delegate to the same storage layer, but through different paths:

```
AI Chat Tool                  REST API                CLI
     |                           |                     |
     v                           v                     |
WP Abilities API          DailyMemoryAbilities         |
     |                           |                     |
     v                           v                     v
DailyMemoryAbilities      DailyMemory service    DailyMemory service
     |                           |                     |
     v                           v                     v
DailyMemory service     (same filesystem)        (same filesystem)
     |
     v
agents/{slug}/daily/YYYY/MM/DD.md
```

The AI tool and REST API go through the Abilities layer. The CLI accesses the `DailyMemory` service directly (bypassing Abilities) for performance.

## Source Files

| File | Purpose |
|------|---------|
| `inc/Core/FilesRepository/DailyMemory.php` | Core service — file storage, CRUD, search |
| `inc/Engine/AI/System/Tasks/DailyMemoryTask.php` | System task — AI synthesis and MEMORY.md cleanup |
| `inc/Abilities/DailyMemoryAbilities.php` | WordPress 6.9 Abilities (5 abilities) |
| `inc/Engine/AI/Directives/AgentDailyMemoryDirective.php` | Opt-in chat + pipeline context injection (Priority 35, since v0.71.0) |
| `inc/Engine/AI/Tools/Global/AgentDailyMemory.php` | AI chat tool for daily memory access |
| `inc/Cli/Commands/MemoryCommand.php` | CLI subcommands (daily list/read/write/append/delete/search) |
| `inc/Api/AgentFiles.php` | REST endpoints for daily file operations |
