# Jobs Endpoints

**Implementation**: `inc/Api/Jobs.php`

**Base URL**: `/wp-json/datamachine/v1/jobs`

Jobs expose workflow execution history and cleanup operations.

## Authentication

Requires the Data Machine `manage_flows` permission (`PermissionHelper::can( 'manage_flows' )`). Requests may be user-scoped or agent-scoped through `PermissionHelper`.

## Routes

### GET `/wp-json/datamachine/v1/jobs`

List jobs with filtering, sorting, and pagination.

**Query parameters**:

- `orderby` (string, optional, default `job_id`): field to order by.
- `order` (string, optional, default `DESC`): `ASC` or `DESC`.
- `per_page` (integer, optional, default `50`, max `100`): page size.
- `offset` (integer, optional, default `0`): pagination offset.
- `pipeline_id` (integer, optional): filter by pipeline.
- `flow_id` (integer, optional): filter by flow.
- `status` (string, optional): filter by job status.
- `user_id` (integer, optional): filter by user when allowed by the permission scope.
- `parent_job_id` (integer, optional): filter child jobs by parent job.
- `hide_children` (boolean, optional, default `false`): omit child jobs from top-level lists.

**Success response**:

```json
{
  "success": true,
  "data": [],
  "total": 0,
  "per_page": 50,
  "offset": 0
}
```

### GET `/wp-json/datamachine/v1/jobs/{id}`

Get one job by ID.

**Success response**:

```json
{
  "success": true,
  "data": {}
}
```

**Errors**:

- `job_not_found` (404): no matching job.

### DELETE `/wp-json/datamachine/v1/jobs`

Clear jobs.

**Body parameters**:

- `type` (string, required): `all` or `failed`.
- `cleanup_processed` (boolean, optional, default `false`): also clear processed item tracking.

**Success response**:

```json
{
  "success": true,
  "message": "Jobs cleared successfully.",
  "jobs_deleted": 42,
  "processed_items_cleaned": false
}
```

## Batch Jobs

Batch processing uses parent/child jobs. Use `parent_job_id` to list children and `hide_children=true` for a top-level-only job list.

```bash
curl "https://example.com/wp-json/datamachine/v1/jobs?parent_job_id=100" \
  -u username:application_password
```

## Notes for Agents

- REST does not expose job undo. Undo is CLI-only via `wp datamachine jobs undo <job_id>`.
- Job objects are returned by `GetJobsAbility`; fields depend on stored job data and may include `engine_data` for task effects.
