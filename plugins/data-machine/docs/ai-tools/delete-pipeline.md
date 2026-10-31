# Delete Pipeline

`delete_pipeline` deletes a pipeline and all associated flows.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Destructive |
| Registered in | `ToolServiceProvider.php` via `DeletePipeline` |
| Backing ability | `datamachine/delete-pipeline` |

## Inputs

- `pipeline_id`: pipeline to delete.

This removes durable workflow configuration. Use only with explicit intent.
