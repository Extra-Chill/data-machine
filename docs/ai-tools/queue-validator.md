# Queue Validator

`queue_validator` checks whether a topic already exists in published content or a Data Machine queue.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Read-only |
| Registered in | `ToolServiceProvider.php` via `QueueValidator` |
| Access | Admin |

## Inputs

- `topic`: topic or title to validate.
- `post_type`: WordPress post type to check.
- `flow_id` and `flow_step_id`: optional queue scope.
- `threshold`: Jaccard similarity threshold, default `0.65`.

Use before adding new content prompts to avoid duplicate work.
