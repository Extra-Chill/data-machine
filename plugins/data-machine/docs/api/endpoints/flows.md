# Flows Abilities

**Implementation**: `inc/Abilities/Flow/`, `inc/Abilities/FlowStep/`

The `datamachine/v1/flows` REST routes were retired in #3456. Flows are managed through the REST-visible Data Machine abilities, executed through WordPress core's ability runner:

```
POST /wp-json/wp-abilities/v1/abilities/datamachine/<slug>/run
Content-Type: application/json

{ "input": { ... } }
```

The admin Pipeline Builder calls these through the shared `executeAbility()` client (`inc/Core/Admin/shared/utils/api.js`).

Flows are configured executions of pipeline templates.

## Authentication

Each ability's permission callback enforces Data Machine permissions (`PermissionHelper::can_manage()` for the flow family). The core ability runner also requires `show_in_rest` and a valid REST nonce for cookie-authenticated callers.

## Response Envelope

The ability runner returns the ability's output directly (no `{success, data}` wrapper). Errors return standard REST error objects (`code`, `message`, `data.status`).

## Flow Abilities

| Ability slug | Purpose |
| --- | --- |
| `datamachine/get-flows` | List flows or fetch one by `flow_id`. Supports `pipeline_id`, `agent_id`, `user_id`, `per_page`, `offset`, `output_mode` (`full`/`list`/`summary`/`ids`). |
| `datamachine/create-flow` | Create a flow (`pipeline_id`, optional `flow_name`, `flow_config`, `scheduling_config`, `agent_id`). |
| `datamachine/update-flow` | Update flow title (`flow_name`) and/or `scheduling_config`. |
| `datamachine/delete-flow` | Delete a flow. |
| `datamachine/duplicate-flow` | Duplicate a flow (`source_flow_id`). |
| `datamachine/pause-flow` / `datamachine/resume-flow` | Pause/resume a flow (`flow_id`), or in bulk by `pipeline_id` or `agent_id`. |
| `datamachine/get-flow-memory-files` | Get the agent memory filenames attached to a flow. |
| `datamachine/update-flow-memory-files` | Replace the agent memory filenames attached to a flow (`memory_files`). |
| `datamachine/get-problem-flows` | List flows flagged for consecutive failures or no-item runs. |

## Flow Step Abilities

| Ability slug | Purpose |
| --- | --- |
| `datamachine/get-flow-steps` | Get all configured steps for a flow (`flow_id`), or one step (`flow_step_id`). |
| `datamachine/update-flow-step` | Update one flow step: `handler_slug` + `handler_config` settings, `user_message`, or multi-handler `add_handler`/`remove_handler`. |

## Queue Abilities

All queue abilities require `flow_id` and `flow_step_id`.

| Ability slug | Purpose |
| --- | --- |
| `datamachine/queue-list` | List queued prompts (`queue`, `count`, `queue_mode`). |
| `datamachine/queue-add` | Add one `prompt` to the queue (runs duplicate validation). |
| `datamachine/queue-clear` | Clear the queue (`cleared_count`). |
| `datamachine/queue-remove` | Remove one item by `index`. |
| `datamachine/queue-update` | Replace the `prompt` at `index`. |
| `datamachine/queue-move` | Move an item (`from_index`, `to_index`). |
| `datamachine/queue-mode` | Set the access `mode`: `drain`, `loop`, or `static`. |

## Flow Files

Uploaded flow files are listed and deleted through the `datamachine/list-flow-files` and `datamachine/delete-flow-file` abilities (both require `flow_step_id`). Multipart upload remains a transport route at `POST /wp-json/datamachine/v1/files` (see [files](files.md)).
