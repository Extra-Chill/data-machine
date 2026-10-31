# Agent Daily Memory

`agent_daily_memory` manages daily memory journal files at `daily/YYYY/MM/DD.md`.

| Field | Value |
| --- | --- |
| Modes | chat, pipeline policy |
| Mutation risk | Low mutation |
| Registered in | `ToolServiceProvider.php` via `AgentDailyMemory` |
| Backing abilities | `datamachine/daily-memory-read`, `datamachine/daily-memory-write`, `datamachine/daily-memory-list`, `datamachine/search-daily-memory` |

## Actions

- `read`: read a specific daily file.
- `write`: append or replace daily notes.
- `list`: list available daily files.
- `search`: search daily memory over an optional date range.

Use daily memory for temporal work logs and session activity. Use `agent_memory` for durable facts and preferences.
