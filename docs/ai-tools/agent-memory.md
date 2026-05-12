# Agent Memory

`agent_memory` reads and updates agent markdown files such as `MEMORY.md`, `SOUL.md`, and `USER.md`.

| Field | Value |
| --- | --- |
| Modes | chat, pipeline policy |
| Mutation risk | Low mutation |
| Registered in | `ToolServiceProvider.php` via `AgentMemory` |
| Backing abilities | `datamachine/get-agent-memory`, `datamachine/update-agent-memory`, `datamachine/list-agent-memory-sections` |

## Actions

- `list_sections`: list `##` sections in the target file.
- `get`: read a full file or a section.
- `update`: write a section with `set` or `append` mode.

Use this for durable, cross-session knowledge. For session logs and time-bound events, use `agent_daily_memory`.
