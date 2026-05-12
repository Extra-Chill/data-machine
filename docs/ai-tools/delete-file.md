# Delete File

`delete_file` deletes an uploaded flow-step file.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Destructive |
| Registered in | `ToolServiceProvider.php` via `DeleteFile` |
| Backing ability | `datamachine/delete-flow-file` |

## Inputs

- `filename`: file to delete.
- `flow_step_id`: flow-step scope for the file.

Use only when the file is no longer needed by the flow.
