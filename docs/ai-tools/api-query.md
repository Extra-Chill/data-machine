# API Query Tool

Internal REST API query tool for chat agents providing discovery, monitoring, and troubleshooting capabilities.

## Overview

The `api_query` tool enables chat agents to query the Data Machine REST API (via `rest_do_request`) for discovery and monitoring. It supports single requests and batch requests.

## Parameters

### Single request

- **endpoint** (string, required): REST API endpoint path (e.g., `/datamachine/v1/handlers`)
- **method** (string, optional): HTTP method (defaults to `GET`)
- **data** (object, optional): Request body data for `POST`, `PUT`, or `PATCH`

### Batch requests

- **requests** (array): Array of requests: `{ endpoint, method, data?, key? }`. If `key` is omitted, a key is derived from the endpoint path.

## Available Endpoints

### Discovery
- `GET /datamachine/v1/handlers` - List all handlers
- `GET /datamachine/v1/handlers?step_type={fetch|publish|upsert}` - Filter by type
- `GET /datamachine/v1/handlers/{slug}` - Handler details and config schema
- `GET /datamachine/v1/auth/{handler}/status` - Check OAuth connection status
- `GET /datamachine/v1/providers` - List AI providers and models
- `GET /datamachine/v1/tools` - List available AI tools

### Pipelines (Read-Only)
- `GET /datamachine/v1/pipelines` - List all pipelines
- `GET /datamachine/v1/pipelines/{id}` - Get pipeline details with steps and flows

### Flows (Read-Only)
- `GET /datamachine/v1/flows` - List all flows
- `GET /datamachine/v1/flows/{id}` - Get flow details
- `GET /datamachine/v1/flows/problems` - List flows flagged for review due to consecutive failures/no items

### Jobs & Monitoring
- `GET /datamachine/v1/jobs` - List all jobs
- `GET /datamachine/v1/jobs?flow_id={id}` - Jobs for specific flow
- `GET /datamachine/v1/jobs?status={pending|processing|completed|failed|completed_no_items|agent_skipped}` - Filter by status.
- `GET /datamachine/v1/jobs/{id}` - Job details

### Logs
- `GET /datamachine/v1/logs/content` - Get log content
- `GET /datamachine/v1/logs/content?job_id={id}` - Logs for specific job
- `DELETE /datamachine/v1/logs` - Clear logs
- `PUT /datamachine/v1/logs/level` - Set log level

### System
- `GET /datamachine/v1/settings` - Get plugin settings
- `PATCH /datamachine/v1/settings` - Update settings (partial)

### Files
- `GET /datamachine/v1/files` - List uploaded files
- `POST /datamachine/v1/files` - Upload file
- `DELETE /datamachine/v1/files/{filename}` - Delete file

## Usage Examples

### List All Handlers
```json
{
  "endpoint": "/datamachine/v1/handlers",
  "method": "GET"
}
```

### Check OAuth Status
```json
{
  "endpoint": "/datamachine/v1/auth/twitter/status",
  "method": "GET"
}
```

### Get Pipeline Details
```json
{
  "endpoint": "/datamachine/v1/pipelines/123",
  "method": "GET"
}
```

### Monitor Job Status
```json
{
  "endpoint": "/datamachine/v1/jobs/456",
  "method": "GET"
}
```

## Response Format

### Single request response

```json
{
  "success": true,
  "data": { /* response body */ },
  "status": 200,
  "tool_name": "api_query"
}
```

### Batch request response

```json
{
  "success": true,
  "batch": true,
  "data": {
    "handlers": { /* ... */ },
    "pipelines": { /* ... */ }
  },
  "errors": {
    "jobs": "Missing endpoint"
  },
  "partial": true,
  "request_count": 3,
  "success_count": 2,
  "error_count": 1,
  "tool_name": "api_query"
}
```

## Error Handling

Returns structured error responses for:
- Invalid endpoints or methods
- Authentication/authorization failures
- Malformed request data
- Server-side processing errors

## Integration

This tool complements specialized workflow tools by providing comprehensive API access for:
- System monitoring and diagnostics
- Configuration verification
- Troubleshooting workflow issues
- Administrative operations

Use specialized Focused Tools like `create_pipeline`, `delete_flow`, `add_pipeline_step`, and `configure_flow_step` for mutation operations. `api_query` is strictly read-only for discovery and monitoring.
