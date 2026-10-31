# Manage Queue

`manage_queue` manages prompt queues for flow steps.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Config mutation |
| Registered in | `ToolServiceProvider.php` via `ManageQueue` |
| Backing abilities | `datamachine/queue-add`, `datamachine/queue-list`, `datamachine/queue-clear`, `datamachine/queue-remove`, `datamachine/queue-update`, `datamachine/queue-move`, `datamachine/queue-mode` |

## Actions

- `add`, `list`, `clear`, `remove`, `update`, `move`, `mode`.

All actions require `flow_id` and `flow_step_id`. Queue modes are `static`, `drain`, and `loop`.
