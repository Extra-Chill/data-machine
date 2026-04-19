# Pipelines Endpoints

**Implementation**: `/inc/Api/Pipelines/` directory structure
- `Pipelines.php` - Main pipeline CRUD operations
- `PipelineSteps.php` - Step management (`/pipelines/{id}/steps`)
- `PipelineFlows.php` - Pipeline-flow relationships (`/pipelines/{id}/flows`)

**Base URL**: `/wp-json/datamachine/v1/pipelines`

## Overview

Pipeline endpoints provide complete pipeline template management including creation, retrieval, modification, deletion, step management, and CSV import/export functionality. Directory-based structure (@since v0.2.0) organizes related operations with nested endpoints for steps and flows.

## Authentication

Requires `manage_options` capability. See Authentication Guide.

## Endpoints

### GET /pipelines

Retrieve all pipelines or a specific pipeline with optional field filtering.

**Permission**: `manage_options` capability required

**Parameters**:
- `pipeline_id` (integer, optional): Specific pipeline ID to retrieve
- `fields` (string, optional): Comma-separated list of fields to return

**Example Requests**:

```bash
# Get all pipelines
curl https://example.com/wp-json/datamachine/v1/pipelines \
  -u username:application_password

# Get specific pipeline
curl https://example.com/wp-json/datamachine/v1/pipelines?pipeline_id=5 \
  -u username:application_password

# Get specific fields only
curl https://example.com/wp-json/datamachine/v1/pipelines?fields=pipeline_id,pipeline_name \
  -u username:application_password
```

**Success Response (All Pipelines)**:

```json
{
  "success": true,
  "pipelines": [
    {
      "pipeline_id": 5,
      "pipeline_name": "Content Pipeline",
      "pipeline_config": "{}",
      "created_at": "2024-01-01 12:00:00",
      "updated_at": "2024-01-02 14:30:00"
    }
  ],
  "total": 1
}
```

**Success Response (Single Pipeline)**:

```json
{
  "success": true,
  "pipeline": {
    "pipeline_id": 5,
    "pipeline_name": "Content Pipeline",
    "pipeline_config": "{}",
    "created_at": "2024-01-01 12:00:00",
    "updated_at": "2024-01-02 14:30:00"
  },
  "flows": [...]
}
```

### POST /pipelines

Create a new pipeline with optional step configuration.

**Permission**: `manage_options` capability required

**Parameters**:
- `pipeline_name` (string, optional): Pipeline name (default: "Pipeline")
- `steps` (array, optional): Complete pipeline steps configuration for complete mode
- `flow_config` (array, optional): Flow configuration

**Creation Modes**:
- **Simple Mode**: Only `pipeline_name` provided - creates empty pipeline
- **Complete Mode**: `steps` array provided - creates pipeline with configured steps

**Example Requests**:

```bash
# Simple mode - empty pipeline
curl -X POST https://example.com/wp-json/datamachine/v1/pipelines \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"pipeline_name": "My Pipeline"}'

# Complete mode - with steps
curl -X POST https://example.com/wp-json/datamachine/v1/pipelines \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "pipeline_name": "Complete Pipeline",
    "steps": [
      {"step_type": "fetch"},
      {"step_type": "ai"},
      {"step_type": "publish"}
    ]
  }'
```

**Success Response**:

```json
{
  "success": true,
  "pipeline_id": 6,
  "pipeline_name": "My Pipeline",
  "pipeline_data": {...},
  "existing_flows": [],
  "creation_mode": "simple"
}
```

### DELETE /pipelines/{pipeline_id}

Delete a pipeline and all associated flows and jobs.

**Permission**: `manage_options` capability required

**Parameters**:
- `pipeline_id` (integer, required): Pipeline ID to delete (in URL path)

**Example Request**:

```bash
curl -X DELETE https://example.com/wp-json/datamachine/v1/pipelines/6 \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "pipeline_id": 6,
  "message": "Pipeline deleted successfully.",
  "deleted_flows": 2,
  "deleted_jobs": 15
}
```

### POST /pipelines/{pipeline_id}/steps

Add a step to an existing pipeline.

**Permission**: `manage_options` capability required

**Parameters**:
- `pipeline_id` (integer, required): Pipeline ID (in URL path)
- `step_type` (string, required): Step type (`fetch`, `ai`, `publish`, `upsert`)

**Example Request**:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/pipelines/6/steps \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"step_type": "fetch"}'
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "message": "Step \"Fetch\" added successfully",
  "step_type": "fetch",
  "step_config": {...},
  "pipeline_id": 6,
  "pipeline_step_id": "abc123-def456-789",
  "step_data": {...},
  "created_type": "step"
}
```

### DELETE /pipelines/{pipeline_id}/steps/{step_id}

Delete a step from a pipeline.

**Permission**: `manage_options` capability required

**Parameters**:
- `pipeline_id` (integer, required): Pipeline ID (in URL path)
- `step_id` (string, required): Pipeline step ID (in URL path)

**Example Request**:

```bash
curl -X DELETE https://example.com/wp-json/datamachine/v1/pipelines/6/steps/abc123-def456-789 \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "pipeline_step_id": "abc123-def456-789",
  "pipeline_id": 6,
  "message": "Step deleted successfully."
}
```

## CSV Export

### GET /pipelines?format=csv

Export pipelines to CSV format for backup, migration, or version control.

**Permission**: `manage_options` capability required

**Parameters**:
- `format` (string, required): Must be `csv` for export
- `ids` (string, optional): Comma-separated pipeline IDs to export
- `pipeline_id` (integer, optional): Single pipeline ID to export

**Export Behavior**:
- If `ids` provided → Export specified pipelines
- If `pipeline_id` provided → Export single pipeline
- If neither provided → Export all pipelines

**CSV Structure**:

The exported CSV includes 9 columns: `pipeline_id`, `pipeline_name`, `step_position`, `step_type`, `step_config`, `flow_id`, `flow_name`, `handler`, `settings`

**Example Requests**:

```bash
# Export all pipelines
curl https://example.com/wp-json/datamachine/v1/pipelines?format=csv \
  -u username:application_password \
  -o pipelines-export.csv

# Export specific pipelines
curl "https://example.com/wp-json/datamachine/v1/pipelines?format=csv&ids=5,6,7" \
  -u username:application_password \
  -o pipelines-export.csv

# Export single pipeline
curl https://example.com/wp-json/datamachine/v1/pipelines?format=csv&pipeline_id=5 \
  -u username:application_password \
  -o pipeline-5.csv
```

**Success Response (200 OK)**:

```csv
pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,handler,settings
5,Content Pipeline,0,fetch,"{""pipeline_step_id"":""abc-123""}",,,""
5,Content Pipeline,1,ai,"{""pipeline_step_id"":""def-456""}",,,""
5,Content Pipeline,0,fetch,"{""pipeline_step_id"":""abc-123""}",42,My Flow,rss,"{""feed_url"":""https://example.com/feed""}"
```

**Response Headers**:

```
Content-Type: text/csv; charset=utf-8
Content-Disposition: attachment; filename="pipelines-export-2024-01-02-14-30-00.csv"
```

**Use Cases**:
- Backup pipeline configurations
- Share workflows between Data Machine installations
- Version control pipeline templates
- Migrate pipelines from development to production

## CSV Import

### POST /pipelines (CSV Import Mode)

Import pipelines from CSV format.

**Permission**: `manage_options` capability required

**Parameters**:
- `batch_import` (boolean, required): Must be `true` to enable import mode
- `format` (string, required): Must be `csv` for CSV import
- `data` (string, required): CSV content to import

**Import Behavior**:
- Creates pipelines if they don't exist (matched by name)
- Adds steps with configuration from CSV
- Reuses existing pipelines with matching names
- Returns array of imported pipeline IDs

**Example Request**:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/pipelines \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "batch_import": true,
    "format": "csv",
    "data": "pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,handler,settings\n5,Content Pipeline,0,fetch,\"{}\",,,\"\"\n5,Content Pipeline,1,ai,\"{}\",,,\"\""
  }'
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "imported_pipeline_ids": [5, 6, 7],
  "count": 3,
  "message": "Successfully imported 3 pipeline(s)"
}
```

**Error Response (500 Internal Server Error)**:

```json
{
  "code": "import_failed",
  "message": "Failed to import pipelines from CSV.",
  "data": {"status": 500}
}
```

**CSV Format Requirements**:
- Header row required with 9 columns
- Pipeline rows: Include pipeline structure (empty flow columns)
- Flow rows: Include flow configuration (populated flow columns)
- JSON-encoded fields must be properly escaped
- Steps sorted by execution_order for consistency

**Integration Example (Python)**:

```python
import requests
from requests.auth import HTTPBasicAuth

# Read CSV file
with open('pipelines-export.csv', 'r') as f:
    csv_content = f.read()

# Import to Data Machine
url = "https://example.com/wp-json/datamachine/v1/pipelines"
auth = HTTPBasicAuth("username", "application_password")
payload = {
    "batch_import": True,
    "format": "csv",
    "data": csv_content
}

response = requests.post(url, json=payload, auth=auth)

if response.status_code == 200:
    result = response.json()
    print(f"Imported {result['count']} pipeline(s)")
    print(f"Pipeline IDs: {result['imported_pipeline_ids']}")
else:
    print(f"Import failed: {response.json()['message']}")
```

## Related Documentation

- Flows Endpoints - Flow instance management
- Execute Endpoint - Pipeline execution
- StepTypes Endpoint - Available step types
- Authentication - Auth methods

---

**Base URL**: `/wp-json/datamachine/v1/pipelines`
**Permission**: `manage_options` capability required
**Implementation**: `/inc/Api/Pipelines/` directory (Pipelines.php, PipelineSteps.php, PipelineFlows.php)
**Version**: Directory structure introduced in v0.2.1
