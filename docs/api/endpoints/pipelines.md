# Pipelines Endpoints

**Implementation**: `inc/Api/Pipelines/`

**Base URL**: `/wp-json/datamachine/v1/pipelines`

Pipelines are reusable workflow templates. Flows are executable instances of pipelines.

## Authentication

Requires the Data Machine `manage_flows` permission (`PermissionHelper::can( 'manage_flows' )`). Requests may be user-scoped or agent-scoped through `PermissionHelper`.

## Response Envelope

Current JSON routes generally return:

```json
{
  "success": true,
  "data": {}
}
```

List routes may also include top-level pagination fields. CSV export returns raw `text/csv`, not a JSON envelope.

## Pipeline Routes

### GET `/wp-json/datamachine/v1/pipelines`

List pipelines or export CSV.

**Query parameters**:

- `pipeline_id` (integer, optional): retrieve one pipeline through the list route.
- `fields` (string, optional): comma-separated fields to keep.
- `format` (string, optional, default `json`): `json` or `csv`.
- `ids` (string, optional): comma-separated pipeline IDs for CSV export.
- `user_id` (integer, optional): filter by user when allowed by scope.
- `search` (string, optional): substring match on pipeline name.
- `per_page` (integer, optional, default `20`, max `100`): page size.
- `offset` (integer, optional, default `0`): pagination offset.
- `include_flows` (boolean, optional): include full flows. Defaults to `false` for list mode and `true` for single-pipeline lookups.

**List success shape**:

```json
{
  "success": true,
  "per_page": 20,
  "offset": 0,
  "total": 0,
  "data": {
    "pipelines": [],
    "total": 0
  }
}
```

### GET `/wp-json/datamachine/v1/pipelines/{pipeline_id}`

Get one pipeline.

**Success shape**:

```json
{
  "success": true,
  "data": {
    "pipeline": {},
    "flows": []
  }
}
```

### POST `/wp-json/datamachine/v1/pipelines`

Create a pipeline. Despite the REST schema default, `pipeline_name` is required by the controller.

**Body parameters**:

- `pipeline_name` (string, required): pipeline name.
- `steps` (array, optional): pipeline step configuration.
- `flow_config` (array, optional): initial flow configuration.

**Success shape**:

```json
{
  "success": true,
  "data": {}
}
```

### PATCH `/wp-json/datamachine/v1/pipelines/{pipeline_id}`

Rename a pipeline.

**Body parameters**:

- `pipeline_name` (string, required): new title.

### DELETE `/wp-json/datamachine/v1/pipelines/{pipeline_id}`

Delete a pipeline and related records.

## CSV Export

### GET `/wp-json/datamachine/v1/pipelines?format=csv`

Export pipelines as CSV. Use `ids=1,2` or `pipeline_id=1` to limit the export. The response is raw CSV with `Content-Type: text/csv; charset=utf-8`.

CSV import is not wired through `inc/Api/Pipelines/Pipelines.php`. The REST schema still registers `batch_import`, `format`, and `data` args on `POST /pipelines`, but the controller currently ignores them and requires `pipeline_name` for normal creation.

## Pipeline Step Routes

### POST `/wp-json/datamachine/v1/pipelines/{pipeline_id}/steps`

Add a step.

**Body parameters**:

- `step_type` (string, required): registered step type.

### DELETE `/wp-json/datamachine/v1/pipelines/{pipeline_id}/steps/{step_id}`

Delete a pipeline step.

### PUT `/wp-json/datamachine/v1/pipelines/{pipeline_id}/steps/reorder`

Reorder steps.

**Body parameters**:

- `step_order` (array, required): objects with `pipeline_step_id` and numeric `execution_order`.

### PATCH `/wp-json/datamachine/v1/pipelines/steps/{pipeline_step_id}/system-prompt`

Update the AI system prompt for a pipeline step.

**Body parameters**:

- `system_prompt` (string, required): system prompt text.

### PUT `/wp-json/datamachine/v1/pipelines/steps/{pipeline_step_id}/config`

Upsert AI step configuration. `step_type` and `pipeline_id` are required for `PUT` unless they can be inferred from the step ID.

**Body parameters**:

- `step_type` (string, required for PUT): currently only `ai` supports this config route.
- `pipeline_id` (integer, required for PUT): pipeline context.
- `disabled_tools` (array, optional): disabled tool IDs.
- `tool_categories` (array, optional): allowed ability categories.
- `system_prompt` (string, optional): AI system prompt.

### PATCH `/wp-json/datamachine/v1/pipelines/steps/{pipeline_step_id}/config`

Patch AI step configuration. Only supplied fields are changed.

Accepted fields are `disabled_tools`, `tool_categories`, and `system_prompt`. `provider`, `model`, and `ai_api_key` are not accepted; model/provider resolution comes from the mode settings system.

## Pipeline Flow Routes

### GET `/wp-json/datamachine/v1/pipelines/{pipeline_id}/flows`

List flows attached to a pipeline.

**Success shape**:

```json
{
  "success": true,
  "data": {
    "pipeline_id": 5,
    "flows": [],
    "flow_count": 0,
    "first_flow_id": null
  }
}
```

## Pipeline Memory Routes

### GET `/wp-json/datamachine/v1/pipelines/{pipeline_id}/memory-files`

Return configured memory filenames for a pipeline.

### POST/PUT/PATCH `/wp-json/datamachine/v1/pipelines/{pipeline_id}/memory-files`

Replace configured memory filenames.

**Body parameters**:

- `memory_files` (array of strings, required): agent memory filenames.
