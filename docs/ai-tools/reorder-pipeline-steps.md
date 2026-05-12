# Reorder Pipeline Steps

`reorder_pipeline_steps` changes the execution order of steps in a pipeline.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Config mutation |
| Registered in | `ToolServiceProvider.php` via `ReorderPipelineSteps` |
| Backing ability | `datamachine/reorder-pipeline-steps` |

## Inputs

- `pipeline_id`: pipeline to update.
- `step_order`: array of `{ pipeline_step_id, execution_order }` objects.

Use after adding/removing steps or when changing workflow order.
