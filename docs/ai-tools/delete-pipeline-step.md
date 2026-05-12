# Delete Pipeline Step

`delete_pipeline_step` removes a step from a pipeline and cascades removal to all flows on that pipeline.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Destructive |
| Registered in | `ToolServiceProvider.php` via `DeletePipelineStep` |
| Backing ability | `datamachine/delete-pipeline-step` |

## Inputs

- `pipeline_id`: pipeline containing the step.
- `pipeline_step_id`: step to remove.

Use when simplifying or restructuring a workflow.
