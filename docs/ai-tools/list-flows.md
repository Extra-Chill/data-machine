# List Flows

`list_flows` lists flows with optional filters.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Read-only |
| Registered in | `ToolServiceProvider.php` via `ListFlows` |
| Backing ability | `datamachine/get-flows` |

## Inputs

- `pipeline_id`: optional pipeline filter.
- `handler_slug`: optional filter for flows using a handler.
- `per_page`: page size, default `20`, max `100`.
- `offset`: pagination offset.

Use for flow discovery and admin inventory.
