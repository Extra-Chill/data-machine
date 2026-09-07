# Pipelines Abilities

**Implementation**: `inc/Abilities/Pipeline/`

The `datamachine/v1/pipelines` REST routes were retired in #3456. Pipelines are managed through the REST-visible Data Machine abilities, executed through WordPress core's ability runner:

```
POST /wp-json/wp-abilities/v1/abilities/datamachine/<slug>/run
Content-Type: application/json

{ "input": { ... } }
```

The admin Pipeline Builder calls these through the shared `executeAbility()` client (`inc/Core/Admin/shared/utils/api.js`).

Pipelines are reusable workflow templates. Flows are executable instances of pipelines.

## Authentication

Each ability's permission callback enforces Data Machine permissions (`PermissionHelper::can_manage()` for the pipeline family, which covers `manage_flows`, `manage_settings`, and `manage_agents`). The core ability runner also requires `show_in_rest` and a valid REST nonce for cookie-authenticated callers.

## Response Envelope

The ability runner returns the ability's output directly (no `{success, data}` wrapper). Errors return standard REST error objects (`code`, `message`, `data.status`).

## Pipeline Abilities

| Ability slug | Purpose |
| --- | --- |
| `datamachine/get-pipelines` | List pipelines or fetch one by `pipeline_id`. Supports `per_page`, `offset`, `search`, `output_mode` (`full`/`list`/`summary`/`ids`), `include_flows`. |
| `datamachine/create-pipeline` | Create a pipeline. Supports `steps`, `flow_config`, `workflow`, bulk `pipelines`, `template`, `validate_only`. |
| `datamachine/update-pipeline` | Rename a pipeline (`pipeline_name`) or reassign it (`agent_id`). |
| `datamachine/delete-pipeline` | Delete a pipeline and its flows. |
| `datamachine/duplicate-pipeline` | Duplicate a pipeline. |
| `datamachine/import-pipelines` | Import the canonical Data Machine CSV 1.0 export (`format=csv`, `data=<csv>`). |
| `datamachine/export-pipelines` | Export pipelines as CSV (`pipeline_ids` optional; returns the CSV string in `data`). |
| `datamachine/get-pipeline-memory-files` | Get the agent memory filenames attached to a pipeline. |
| `datamachine/update-pipeline-memory-files` | Replace the agent memory filenames attached to a pipeline (`memory_files`). |

## Pipeline Step Abilities

| Ability slug | Purpose |
| --- | --- |
| `datamachine/get-pipeline-steps` | Get all steps for a pipeline, or one step by `pipeline_step_id`. |
| `datamachine/add-pipeline-step` | Add a step (`pipeline_id`, `step_type`, optional `step_config`). Syncs to all flows on the pipeline. |
| `datamachine/update-pipeline-step` | Update AI step configuration: `system_prompt`, `agent_modes`, `disabled_tools`, `tool_categories`. Model/provider resolution comes from the mode settings system; `provider`, `model`, and `ai_api_key` are not accepted. |
| `datamachine/delete-pipeline-step` | Remove a step from a pipeline and its flows. |
| `datamachine/reorder-pipeline-steps` | Reorder steps (`step_order`: objects with `pipeline_step_id` and numeric `execution_order`). |

## Flow Listing

Flows attached to a pipeline are listed through `datamachine/get-flows` with the `pipeline_id` input filter.
