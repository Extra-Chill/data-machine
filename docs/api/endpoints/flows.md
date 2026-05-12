# Flows Endpoints

**Implementation**: `inc/Api/Flows/`

**Base URL**: `/wp-json/datamachine/v1/flows`

Flows are configured executions of pipeline templates.

## Authentication

Requires the Data Machine `manage_flows` permission (`PermissionHelper::can( 'manage_flows' )`). Requests may be user-scoped or agent-scoped through `PermissionHelper`.

## Response Envelope

Most flow routes return:

```json
{
  "success": true,
  "data": {}
}
```

Some ability-backed mutation routes return the ability result directly when it already includes `success`.

## Flow Routes

### GET `/wp-json/datamachine/v1/flows`

List flows.

**Query parameters**:

- `pipeline_id` (integer, optional): filter by pipeline.
- `per_page` (integer, optional, default `20`, max `100`): page size.
- `offset` (integer, optional, default `0`): pagination offset.
- `output_mode` (string, optional, default `full`): `full`, `list`, `summary`, or `ids`.
- `user_id` (integer, optional): filter by user when allowed by scope.

Without `pipeline_id`, `data` is the flow array. With `pipeline_id`, `data` is `{ pipeline_id, flows }`.

### POST `/wp-json/datamachine/v1/flows`

Create a flow.

**Body parameters**:

- `pipeline_id` (integer, required): parent pipeline.
- `flow_name` (string, optional, default `Flow`): flow name.
- `flow_config` (array, optional): per-flow step settings.
- `scheduling_config` (array, optional): scheduling config.

### GET `/wp-json/datamachine/v1/flows/{flow_id}`

Get one flow.

### PATCH `/wp-json/datamachine/v1/flows/{flow_id}`

Update a flow title and/or scheduling.

**Body parameters**:

- `flow_name` (string, optional): new flow title.
- `scheduling_config` (object, optional): scheduling config.

### DELETE `/wp-json/datamachine/v1/flows/{flow_id}`

Delete a flow.

### POST `/wp-json/datamachine/v1/flows/{flow_id}/duplicate`

Duplicate a flow.

### POST `/wp-json/datamachine/v1/flows/{flow_id}/pause`

Pause one flow.

### POST `/wp-json/datamachine/v1/flows/{flow_id}/resume`

Resume one flow.

### POST `/wp-json/datamachine/v1/flows/pause`

Bulk-pause flows. Body must include `pipeline_id` or `agent_id`.

### POST `/wp-json/datamachine/v1/flows/resume`

Bulk-resume flows. Body must include `pipeline_id` or `agent_id`.

### GET `/wp-json/datamachine/v1/flows/problems`

List problem flows.

**Query parameters**:

- `threshold` (integer, optional): override the `problem_flow_threshold` setting.

**Success response**:

```json
{
  "success": true,
  "data": {
    "problem_flows": [],
    "total": 0,
    "threshold": 3,
    "failing": [],
    "idle": []
  }
}
```

## Flow Step Configuration Routes

### GET `/wp-json/datamachine/v1/flows/{flow_id}/config`

Return all configured steps for a flow.

**Success shape**:

```json
{
  "success": true,
  "data": {
    "flow_id": 42,
    "flow_config": {}
  }
}
```

### GET `/wp-json/datamachine/v1/flows/steps/{flow_step_id}/config`

Return one flow step config.

**Success shape**:

```json
{
  "success": true,
  "data": {
    "flow_step_id": "<pipeline_step_id>_<flow_id>",
    "step_config": {}
  }
}
```

### PATCH `/wp-json/datamachine/v1/flows/steps/{flow_step_id}/config`

Patch one flow step config.

**Body parameters**:

- `handler_slug` (string, optional): handler identifier.
- `handler_config` (object, optional): handler settings to merge.
- `user_message` (string, optional): AI user message.

### PUT `/wp-json/datamachine/v1/flows/steps/{flow_step_id}/handler`

Save handler selection/settings for one flow step.

**Body parameters**:

- `handler_slug` (string, required): handler identifier.
- `pipeline_id` (integer, required): pipeline context.
- `step_type` (string, required): step type.
- `settings` (object, optional): raw handler settings.

### PATCH `/wp-json/datamachine/v1/flows/steps/{flow_step_id}/user-message`

Save the AI user message for one flow step.

**Body parameters**:

- `user_message` (string, required): message text.

## Queue Routes

All queue routes require `flow_step_id` as a request parameter. The route path only carries `flow_id` and, where applicable, `index`.

### GET `/wp-json/datamachine/v1/flows/{flow_id}/queue`

List a queue.

**Query parameters**:

- `flow_step_id` (string, required): flow step ID.

**Success shape**:

```json
{
  "success": true,
  "data": {
    "flow_id": 42,
    "flow_step_id": "<pipeline_step_id>_<flow_id>",
    "queue": [],
    "count": 0,
    "queue_mode": "drain"
  }
}
```

### POST `/wp-json/datamachine/v1/flows/{flow_id}/queue`

Add queue items.

**Body parameters**:

- `flow_step_id` (string, required): flow step ID.
- `prompt` (string, optional): one prompt.
- `prompts` (array of strings, optional): multiple prompts.

At least one non-empty `prompt` or `prompts[]` entry is required.

### DELETE `/wp-json/datamachine/v1/flows/{flow_id}/queue`

Clear a queue.

**Body/query parameters**:

- `flow_step_id` (string, required): flow step ID.

### POST/PUT/PATCH `/wp-json/datamachine/v1/flows/{flow_id}/queue/{index}`

Update one queue item.

**Body parameters**:

- `flow_step_id` (string, required): flow step ID.
- `prompt` (string, required): replacement prompt.

### DELETE `/wp-json/datamachine/v1/flows/{flow_id}/queue/{index}`

Remove one queue item.

**Body/query parameters**:

- `flow_step_id` (string, required): flow step ID.

### POST/PUT/PATCH `/wp-json/datamachine/v1/flows/{flow_id}/queue/mode`

Set queue mode.

**Body parameters**:

- `flow_step_id` (string, required): flow step ID.
- `mode` (string, required): `drain`, `loop`, or `static`.

## Flow Memory Routes

### GET `/wp-json/datamachine/v1/flows/{flow_id}/memory-files`

Return configured memory filenames for a flow.

### POST/PUT/PATCH `/wp-json/datamachine/v1/flows/{flow_id}/memory-files`

Replace configured memory filenames.

**Body parameters**:

- `memory_files` (array of strings, required): agent memory filenames. `daily_memory` is not accepted.
