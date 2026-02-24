# Flows Endpoints

**Implementation**: `/inc/Api/Flows/` directory structure
- `Flows.php` - Main flow CRUD operations
- `FlowSteps.php` - Flow step configuration (`/flows/{id}/config`, `/flows/steps/{flow_step_id}/config`)

**Base URL**: `/wp-json/datamachine/v1/flows`

## Overview

Flow endpoints manage flow instances (configured and scheduled executions of pipeline templates).

## Authentication

Requires `manage_options` capability.

## Endpoints

### GET /flows/problems

Retrieve flows flagged as "problem flows" based on consecutive failures or no items found.

**Permission**: `manage_options` capability required

**Parameters**:
- `threshold` (integer, optional): Override the site-wide `problem_flow_threshold` for this query.

**Example Request**:

```bash
curl https://example.com/wp-json/datamachine/v1/flows/problems \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "problem_flows": [
      {
        "flow_id": 42,
        "flow_name": "Broken RSS Flow",
        "consecutive_failures": 5,
        "consecutive_no_items": 0
      }
    ],
    "total": 1,
    "threshold": 3
  }
}
```

### POST /flows

Create a new flow from an existing pipeline.

**Permission**: `manage_options` capability required

**Parameters**:
- `pipeline_id` (integer, required): Parent pipeline ID
- `flow_name` (string, optional): Flow name (default: "Flow")
- `flow_config` (array, optional): Handler settings per step
- `scheduling_config` (array, optional): Scheduling configuration

**Example Request**:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/flows \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "pipeline_id": 5,
    "flow_name": "My Custom Flow",
    "scheduling_config": {"interval": "daily"}
  }'
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "flow_id": 42,
    "pipeline_id": 5,
    "flow_name": "My Custom Flow",
    "flow_config": {},
    "scheduling_config": {}
  }
}
```

**Response Fields**:
- `success` (boolean)
- `data` (object): Created flow payload

### DELETE /flows/{flow_id}

Delete a flow.

**Permission**: `manage_options` capability required

**Parameters**:
- `flow_id` (integer, required): Flow ID to delete (in URL path)

**Example Request**:

```bash
curl -X DELETE https://example.com/wp-json/datamachine/v1/flows/42 \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {"flow_id": 42}
}
```

**Response Fields**:
- `success` (boolean)
- `data.flow_id` (integer): Deleted flow ID

**Error Response (404 Not Found)**:

```json
{
  "code": "flow_not_found",
  "message": "Flow not found.",
  "data": {"status": 404}
}
```

### POST /flows/{flow_id}/duplicate

Duplicate an existing flow with all configuration.

**Permission**: `manage_options` capability required

**Parameters**:
- `flow_id` (integer, required): Source flow ID to duplicate (in URL path)

**Example Request**:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/flows/42/duplicate \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "source_flow_id": 42,
  "new_flow_id": 43,
  "flow_name": "My Custom Flow (Copy)",
  "pipeline_id": 5,
  "flow_data": {...},
  "pipeline_steps": [...]
}
```

**Response Fields**:
- `success` (boolean): Request success status
- `source_flow_id` (integer): Original flow ID
- `new_flow_id` (integer): Newly created duplicate flow ID
- `flow_name` (string): Duplicate flow name (appends "(Copy)")
- `pipeline_id` (integer): Parent pipeline ID
- `flow_data` (object): Complete flow record
- `pipeline_steps` (array): Pipeline step configuration

**Error Response (404 Not Found)**:

```json
{
  "code": "flow_not_found",
  "message": "Flow not found.",
  "data": {"status": 404}
}
```

## Flow Configuration

### Handler Settings

Flow configuration stores handler-specific settings per step:

```json
{
  "flow_step_id_123": {
    "handler_slug": "rss",
    "handler_config": {
      "feed_url": "https://example.com/feed/",
      "max_items": 10
    }
  },
  "flow_step_id_456": {
    "handler_slug": "twitter",
    "handler_config": {
      "max_length": 280
    }
  }
}
```

### Scheduling Configuration

Scheduling configuration defines execution intervals:

```json
{
  "interval": "hourly"
}
```

**Available Intervals**:
- `manual` - No automatic execution
- `one_time` - Execute once at a specific timestamp
- `every_5_minutes` - Every 5 minutes
- `hourly` - Every hour
- `every_2_hours` - Every 2 hours
- `every_4_hours` - Every 4 hours
- `qtrdaily` - Every 6 hours
- `twicedaily` - Twice per day (every 12 hours)
- `daily` - Once per day
- `weekly` - Once per week
- Custom intervals via `datamachine_scheduler_intervals` filter

## Flow Step Configuration

### GET /flows/{flow_id}/config

Retrieve complete flow configuration including all step settings.

**Permission**: `manage_options` capability required

**Parameters**:
- `flow_id` (integer, required): Flow ID (in URL path)

**Example Request**:

```bash
curl https://example.com/wp-json/datamachine/v1/flows/42/config \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "flow_id": 42,
  "flow_config": {
    "flow_step_id_123": {
      "flow_step_id": "flow_step_id_123",
      "pipeline_step_id": "step_uuid",
      "step_type": "fetch",
      "execution_order": 0,
      "handler_slug": "rss",
      "handler_config": {
        "feed_url": "https://example.com/feed/",
        "max_items": 10
      },
      "enabled": true
    }
  }
}
```

### GET /flows/steps/{flow_step_id}/config

Retrieve configuration for a specific flow step.

**Permission**: `manage_options` capability required

**Parameters**:
- `flow_step_id` (string, required): Flow step ID (in URL path)

**Example Request**:

```bash
curl https://example.com/wp-json/datamachine/v1/flows/steps/flow_step_id_123/config \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "flow_step_id": "flow_step_id_123",
  "config": {
    "flow_step_id": "flow_step_id_123",
    "pipeline_step_id": "step_uuid",
    "step_type": "fetch",
    "execution_order": 0,
    "handler_slug": "rss",
    "handler_config": {
      "feed_url": "https://example.com/feed/",
      "max_items": 10
    },
    "enabled": true
  }
}
```

**Error Response (404 Not Found)**:

```json
{
  "code": "config_not_found",
  "message": "Flow step configuration not found.",
  "data": {"status": 404}
}
```

## Related endpoints

- [Execute](execute.md) for running flows or ephemeral workflows.
- [Jobs](jobs.md) for monitoring executions.
- [Settings](settings.md) for `problem_flow_threshold`.

---

## Related Documentation

```bash
# 1. Create flow
FLOW_ID=$(curl -X POST https://example.com/wp-json/datamachine/v1/flows \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"pipeline_id": 5}' | jq -r '.flow_id')

# 2. Execute flow immediately
curl -X POST https://example.com/wp-json/datamachine/v1/execute \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d "{\"flow_id\": $FLOW_ID}"
```

### Duplicate and Modify

```bash
# 1. Duplicate existing flow
NEW_FLOW=$(curl -X POST https://example.com/wp-json/datamachine/v1/flows/42/duplicate \
  -u username:application_password)

# 2. Modify configuration via admin interface or additional API calls
# 3. Execute new flow independently
```

### Cleanup Old Flows

```bash
# Delete flow and associated jobs
curl -X DELETE https://example.com/wp-json/datamachine/v1/flows/42 \
  -u username:application_password
```

## Integration Examples

### Python Flow Management

```python
import requests
from requests.auth import HTTPBasicAuth

url = "https://example.com/wp-json/datamachine/v1/flows"
auth = HTTPBasicAuth("username", "application_password")

# Create flow
payload = {
    "pipeline_id": 5,
    "flow_name": "Automated RSS to Twitter",
    "scheduling_config": {"interval": "hourly"}
}

response = requests.post(url, json=payload, auth=auth)
flow_data = response.json()

print(f"Created flow {flow_data['flow_id']}: {flow_data['flow_name']}")

# Duplicate flow
duplicate_url = f"{url}/{flow_data['flow_id']}/duplicate"
duplicate_response = requests.post(duplicate_url, auth=auth)
duplicate_data = duplicate_response.json()

print(f"Duplicated to flow {duplicate_data['new_flow_id']}")
```

### JavaScript Flow Operations

```javascript
const axios = require('axios');

const flowAPI = {
  baseURL: 'https://example.com/wp-json/datamachine/v1/flows',
  auth: {
    username: 'admin',
    password: 'application_password'
  }
};

// Create flow
async function createFlow(pipelineId, flowName) {
  const response = await axios.post(flowAPI.baseURL, {
    pipeline_id: pipelineId,
    flow_name: flowName
  }, { auth: flowAPI.auth });

  return response.data.flow_id;
}

// Delete flow
async function deleteFlow(flowId) {
  const response = await axios.delete(
    `${flowAPI.baseURL}/${flowId}`,
    { auth: flowAPI.auth }
  );

  return response.data.deleted_jobs;
}

// Usage
const flowId = await createFlow(5, 'My Flow');
const deletedJobs = await deleteFlow(flowId);
```

## Related Documentation

- [Pipelines](pipelines.md)
- [Execute](execute.md)
- [Jobs](jobs.md)


---

**Base URL**: `/wp-json/datamachine/v1/flows`
**Permission**: `manage_options` capability required
**Implementation**: `/inc/Api/Flows/` (Flows.php, FlowSteps.php)

## Flow Queue Endpoints

Flow queues enable step-level prompt queues for varied task instructions per execution.

### GET /flows/{flow_id}/queue

Get the queue for a flow.

**Permission**: `manage_options` capability required

**Parameters**:
- `flow_id` (integer, required): Flow ID

**Response**:
```json
{
  "success": true,
  "queue": ["First task", "Second task"],
  "count": 2
}
```

### POST /flows/{flow_id}/queue

Add items to the flow queue.

**Permission**: `manage_options` capability required

**Parameters**:
- `flow_id` (integer, required): Flow ID
- `items` (array, required): Queue items to add
- `position` (string, optional): "start" or "end" (default: "end")

**Example**:
```bash
curl -X POST https://example.com/wp-json/datamachine/v1/flows/5/queue \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"items": ["Task 1", "Task 2"]}'
```

### DELETE /flows/{flow_id}/queue

Clear all items from the flow queue.

**Permission**: `manage_options` capability required

**Parameters**:
- `flow_id` (integer, required): Flow ID

### GET /flows/{flow_id}/queue/{index}

Get a specific queue item.

**Permission**: `manage_options` capability required

**Parameters**:
- `flow_id` (integer, required): Flow ID
- `index` (integer, required): Queue position (0-based)

### PUT /flows/{flow_id}/queue/{index}

Update a specific queue item.

**Permission**: `manage_options` capability required

**Parameters**:
- `flow_id` (integer, required): Flow ID
- `index` (integer, required): Queue position
- `item` (string, required): New item content

### DELETE /flows/{flow_id}/queue/{index}

Remove a specific queue item.

**Permission**: `manage_options` capability required

**Parameters**:
- `flow_id` (integer, required): Flow ID
- `index` (integer, required): Queue position

### GET /flows/{flow_id}/queue/settings

Get queue settings for a flow.

**Permission**: `manage_options` capability required

**Response**:
```json
{
  "success": true,
  "settings": {
    "enabled": true,
    "mode": "sequential"
  }
}
```

### PUT /flows/{flow_id}/queue/settings

Update queue settings for a flow.

**Permission**: `manage_options` capability required

**Parameters**:
- `flow_id` (integer, required): Flow ID
- `enabled` (boolean, optional): Enable/disable queue
- `mode` (string, optional): "sequential" or "random"
