# Delete Flow

`delete_flow` deletes a flow instance.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Destructive |
| Registered in | `ToolServiceProvider.php` via `DeleteFlow` |
| Backing ability | `datamachine/delete-flow` |

## Inputs

- `flow_id`: flow to delete.

Use only after confirming the flow is no longer needed.
