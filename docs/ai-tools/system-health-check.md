# System Health Check

`system_health_check` runs unified diagnostics for Data Machine and extensions.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Read-only |
| Registered in | `ToolServiceProvider.php` via `SystemHealthCheck` |
| Backing ability | `datamachine/system-health-check` |

## Inputs

- `types`: optional check type IDs, or `all`.
- `options`: type-specific options such as scope, limit, or URL.

Use for proactive health checks and support triage.
