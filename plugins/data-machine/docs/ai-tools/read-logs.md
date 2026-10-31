# Read Logs

`read_logs` reads Data Machine logs for troubleshooting.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Read-only |
| Registered in | `ToolServiceProvider.php` via `ReadLogs` |
| Backing ability | `datamachine/read-logs` |

## Inputs

- `agent_id`: optional agent scope.
- `mode`: `recent` or `full`.
- `limit`: maximum recent entries.
- `job_id`, `pipeline_id`, `flow_id`: optional filters combined with AND logic.

Use for execution audits and failure diagnosis.
