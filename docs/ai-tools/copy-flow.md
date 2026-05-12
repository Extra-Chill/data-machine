# Copy Flow

`copy_flow` duplicates a flow to the same pipeline or to another compatible pipeline.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Config mutation |
| Registered in | `ToolServiceProvider.php` via `CopyFlow` |
| Backing ability | `datamachine/duplicate-flow` |

## Inputs

- `flow_id`: source flow ID.
- `destination_pipeline_id`: optional target pipeline.
- `flow_name`: optional new name.
- `scheduling_config`: optional schedule override.
- `step_configs`: optional handler/message overrides by step type or execution order.

Cross-pipeline copies require compatible step structures.
