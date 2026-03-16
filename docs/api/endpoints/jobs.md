# Jobs Endpoints

**Implementation**: `inc/Api/Jobs.php`

**Base URL**: `/wp-json/datamachine/v1/jobs`

## Overview

Jobs endpoints provide monitoring and management of workflow executions. Jobs represent individual execution instances of flows, including batch job hierarchies where a parent job spawns child jobs for parallel processing.

## Authentication

Requires `manage_flows` permission (checked via `PermissionHelper`). Supports user-scoped and agent-scoped filtering for multi-agent environments.

## React Interface

The Jobs interface is a React-based management dashboard built on `@wordpress/components` and TanStack Query.

## Endpoints

### GET /jobs

Retrieve jobs with filtering, sorting, and pagination.

**Permission**: `manage_flows` capability required

**Parameters**:
- `orderby` (string, optional): Order jobs by field (default: `job_id`)
- `order` (string, optional): Sort order - `ASC` or `DESC` (default: `DESC`)
- `per_page` (integer, optional): Number of jobs per page (default: 50, max: 100)
- `offset` (integer, optional): Offset for pagination (default: 0)
- `pipeline_id` (integer, optional): Filter by pipeline ID
- `flow_id` (integer, optional): Filter by flow ID
- `status` (string, optional): Filter by job status (`completed`, `failed`, `processing`, etc.)
- `user_id` (integer, optional): Filter by user ID (admin only; non-admins always see own data)

**Example Requests**:

```bash
# Get all jobs (recent first)
curl https://example.com/wp-json/datamachine/v1/jobs \
  -u username:application_password

# Get failed jobs only
curl https://example.com/wp-json/datamachine/v1/jobs?status=failed \
  -u username:application_password

# Get jobs for specific flow with pagination
curl https://example.com/wp-json/datamachine/v1/jobs?flow_id=42&per_page=25&offset=0 \
  -u username:application_password

# Get jobs for specific pipeline
curl https://example.com/wp-json/datamachine/v1/jobs?pipeline_id=5 \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": [
    {
      "job_id": 1523,
      "flow_id": 42,
      "pipeline_id": 5,
      "status": "completed",
      "parent_job_id": null,
      "source": "scheduler",
      "started_at": "2024-01-02 14:30:00",
      "completed_at": "2024-01-02 14:30:15",
      "error_message": null
    },
    {
      "job_id": 1522,
      "flow_id": 42,
      "pipeline_id": 5,
      "status": "failed",
      "parent_job_id": null,
      "source": "manual",
      "started_at": "2024-01-02 14:00:00",
      "completed_at": "2024-01-02 14:00:05",
      "error_message": "Handler configuration missing"
    }
  ],
  "total": 1523,
  "per_page": 50,
  "offset": 0
}
```

**Response Fields**:
- `success` (boolean): Request success status
- `data` (array): Array of job objects
- `total` (integer): Total number of jobs matching filters
- `per_page` (integer): Number of jobs per page
- `offset` (integer): Pagination offset

**Job Object Fields**:
- `job_id` (integer): Unique job identifier
- `flow_id` (integer): Associated flow ID
- `pipeline_id` (integer): Associated pipeline ID
- `status` (string): Job status (see statuses below)
- `parent_job_id` (integer|null): Parent job ID for batch child jobs (null for top-level jobs)
- `source` (string): Job trigger source (`scheduler`, `manual`, `system`, `pipeline_system_task`, `chat`)
- `started_at` (string): Job start timestamp
- `completed_at` (string|null): Job completion timestamp
- `error_message` (string|null): Error message if failed
- `engine_data` (object): Job execution data including task parameters and effects (for system tasks)

**Job Statuses**:
- `pending` - Job queued but not started
- `processing` - Currently executing
- `completed` - Successfully completed
- `completed_no_items` - Completed successfully but no new items found to process
- `agent_skipped` - Completed intentionally without processing the current item (supports compound statuses like `agent_skipped - {reason}`)
- `failed` - Execution failed with error

### GET /jobs/{id}

Get a specific job by ID with full details.

**Permission**: `manage_flows` capability required

**Parameters**:
- `id` (integer, required): Job ID

**Example Request**:

```bash
curl https://example.com/wp-json/datamachine/v1/jobs/1523 \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "job_id": 1523,
    "flow_id": 42,
    "pipeline_id": 5,
    "status": "completed",
    "parent_job_id": null,
    "source": "scheduler",
    "started_at": "2024-01-02 14:30:00",
    "completed_at": "2024-01-02 14:30:15",
    "error_message": null,
    "engine_data": {}
  }
}
```

**Error Response (404 Not Found)**:

```json
{
  "code": "job_not_found",
  "message": "Job not found.",
  "data": {"status": 404}
}
```

### DELETE /jobs

Clear jobs from the database.

**Permission**: `manage_flows` capability required

**Parameters**:
- `type` (string, required): Which jobs to clear - `all` or `failed`
- `cleanup_processed` (boolean, optional): Also clear processed items tracking (default: false)

**Example Requests**:

```bash
# Clear all jobs
curl -X DELETE https://example.com/wp-json/datamachine/v1/jobs \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"type": "all"}'

# Clear failed jobs only
curl -X DELETE https://example.com/wp-json/datamachine/v1/jobs \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"type": "failed"}'

# Clear all jobs and processed items
curl -X DELETE https://example.com/wp-json/datamachine/v1/jobs \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"type": "all", "cleanup_processed": true}'
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "message": "Jobs cleared successfully.",
  "jobs_deleted": 42,
  "processed_items_cleaned": false
}
```

**Error Response (400 Bad Request)**:

```json
{
  "code": "invalid_type",
  "message": "Invalid type parameter. Must be 'all' or 'failed'.",
  "data": {"status": 400}
}
```

## Batch Job Hierarchy

Jobs support parent-child relationships for batch processing. When a pipeline uses the `PipelineBatchScheduler`, a parent job spawns multiple child jobs that execute in parallel.

### Structure

```
Parent Job (job_id: 100, parent_job_id: null)
├── Child Job (job_id: 101, parent_job_id: 100)
├── Child Job (job_id: 102, parent_job_id: 100)
└── Child Job (job_id: 103, parent_job_id: 100)
```

**Key behaviors**:
- Parent job status remains `processing` until all children complete
- `parent_job_id` field links children to their parent
- Child completion triggers `PipelineBatchScheduler::onChildComplete()` to check if all children are done
- Batch size and chunk processing are configured at the pipeline level

### Querying Batch Jobs

```bash
# Get children of a batch parent
curl "https://example.com/wp-json/datamachine/v1/jobs?parent_job_id=100" \
  -u username:application_password
```

## Job Undo System

**Implementation**: `inc/Engine/AI/System/Tasks/SystemTask.php` (@since v0.33.0)

System tasks that modify WordPress content can opt into undo support. The undo system reads a standardized `effects` array from the job's `engine_data` and reverses each effect in reverse order.

### Supported Undo Effect Types

| Effect Type | Reversal Action |
|---|---|
| `post_content_modified` | Restores from WordPress revision |
| `post_meta_set` | Restores previous value or deletes meta |
| `attachment_created` | Deletes the attachment |
| `featured_image_set` | Removes or restores previous thumbnail |

### How Undo Works

1. During execution, tasks record effects in `engine_data.effects`:
   ```json
   {
     "effects": [
       {
         "type": "post_content_modified",
         "post_id": 42,
         "revision_id": 123
       },
       {
         "type": "post_meta_set",
         "post_id": 42,
         "meta_key": "description",
         "previous_value": "old value"
       }
     ]
   }
   ```

2. Tasks opt in by returning `true` from `supportsUndo()`
3. Undo reverses effects in reverse order (last effect first)
4. Unknown effect types are skipped (not failed) so tasks with mixed reversible/irreversible effects degrade gracefully

### CLI Undo

```bash
# Undo a job's effects
wp datamachine jobs undo <job_id> --allow-root

# Dry run to preview what would be reverted
wp datamachine jobs undo <job_id> --dry-run --allow-root

# Undo specific task type only
wp datamachine jobs undo <job_id> --task-type=alt_text_generation --allow-root

# Force undo even on non-completed jobs
wp datamachine jobs undo <job_id> --force --allow-root
```

### Undo Response Format

```json
{
  "success": true,
  "reverted": [
    {
      "type": "post_content_modified",
      "post_id": 42,
      "message": "Restored from revision 123"
    }
  ],
  "skipped": [],
  "failed": []
}
```

## Common Workflows

### Monitor Flow Execution

```bash
# Get recent jobs for specific flow
curl https://example.com/wp-json/datamachine/v1/jobs?flow_id=42&per_page=10 \
  -u username:application_password
```

### Debug Failed Executions

```bash
# Get all failed jobs
curl https://example.com/wp-json/datamachine/v1/jobs?status=failed \
  -u username:application_password
```

### Cleanup Job History

```bash
# Clear failed jobs to reset execution history
curl -X DELETE https://example.com/wp-json/datamachine/v1/jobs \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"type": "failed"}'
```

## Integration Examples

### Python Job Monitoring

```python
import requests
from requests.auth import HTTPBasicAuth

url = "https://example.com/wp-json/datamachine/v1/jobs"
auth = HTTPBasicAuth("username", "application_password")

# Get failed jobs
params = {"status": "failed", "per_page": 100}
response = requests.get(url, params=params, auth=auth)

if response.status_code == 200:
    data = response.json()
    print(f"Found {len(data['data'])} failed jobs")

    for job in data['data']: 
        print(f"Job {job['job_id']}: {job['error_message']}")
else:
    print(f"Error: {response.json()['message']}")
```

### JavaScript Job Dashboard

```javascript
const axios = require('axios');

const jobAPI = {
  baseURL: 'https://example.com/wp-json/datamachine/v1/jobs',
  auth: {
    username: 'admin',
    password: 'application_password'
  }
};

// Get job statistics
async function getJobStats(flowId) {
  const params = { flow_id: flowId, per_page: 100 };
  const response = await axios.get(jobAPI.baseURL, {
    params,
    auth: jobAPI.auth
  });

  const jobs = response.data.data;
  const stats = {
    total: jobs.length,
    completed: jobs.filter(j => j.status === 'completed').length,
    failed: jobs.filter(j => j.status === 'failed').length,
    processing: jobs.filter(j => j.status === 'processing').length
  };

  return stats;
}

// Clear old jobs
async function clearJobs(type = 'failed') {
  const response = await axios.delete(jobAPI.baseURL, {
    data: { type },
    auth: jobAPI.auth
  });

  return response.data.success;
}

// Usage
const stats = await getJobStats(42);
console.log(`Flow 42: ${stats.completed} completed, ${stats.failed} failed`);

await clearJobs('failed');
console.log('Failed jobs cleared');
```

### PHP Job Monitoring

```php
$url = 'https://example.com/wp-json/datamachine/v1/jobs';
$auth = base64_encode('username:application_password');

// Get jobs for specific flow
$params = http_build_query([
    'flow_id' => 42,
    'status' => 'failed',
    'per_page' => 50
]);

$response = wp_remote_get($url . '?' . $params, [
    'headers' => [
        'Authorization' => 'Basic ' . $auth
    ]
]);

if (!is_wp_error($response)) {
    $data = json_decode(wp_remote_retrieve_body($response), true);

    foreach ($data['data'] as $job) {
        error_log(sprintf(
            'Job %d failed: %s',
            $job['job_id'],
            $job['error_message']
        ));
    }
}
```

## Use Cases

### Execution Monitoring

Monitor workflow success rates and identify failing patterns:

```bash
# Get all jobs ordered by completion time
curl https://example.com/wp-json/datamachine/v1/jobs?orderby=completed_at&order=DESC \
  -u username:application_password
```

### Performance Analysis

Analyze execution duration and identify bottlenecks:

```bash
# Get recent completed jobs
curl https://example.com/wp-json/datamachine/v1/jobs?status=completed&per_page=100 \
  -u username:application_password
```

### Cleanup and Maintenance

Regularly clear old job records to maintain database performance:

```bash
# Clear all jobs and reset processed items tracking
curl -X DELETE https://example.com/wp-json/datamachine/v1/jobs \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"type": "all", "cleanup_processed": true}'
```

## Related Documentation

- [Execute](execute.md) - Flow execution
- [Flows](flows.md) - Flow management
- [Processed Items](processed-items.md) - Deduplication tracking
- [Logs](logs.md) - Detailed execution logs

---

**Base URL**: `/wp-json/datamachine/v1/jobs`
**Permission**: `manage_flows` capability required
**Implementation**: `inc/Api/Jobs.php`
