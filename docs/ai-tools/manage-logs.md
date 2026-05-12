# Manage Logs

`manage_logs` clears Data Machine logs or reads log metadata.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Destructive |
| Registered in | `ToolServiceProvider.php` via `ManageLogs` |
| Backing abilities | `datamachine/clear-logs`, `datamachine/get-log-metadata` |

## Actions

- `get_metadata`: read log counts and date ranges.
- `clear`: delete logs for one agent or all agents.

Use `read_logs` for log inspection before clearing.
