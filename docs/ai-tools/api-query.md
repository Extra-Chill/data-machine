# API Query Tool

Internal REST API query tool for chat agents providing discovery, monitoring, and troubleshooting capabilities.

## Overview

The `api_query` tool enables chat agents to query the Data Machine REST API (via `rest_do_request`) for discovery and monitoring. It supports single requests and batch requests.

## Parameters

### Single request

- **endpoint** (string, required): REST API endpoint path (e.g., `/datamachine/v1/tools`)
- **method** (string, optional): HTTP method (defaults to `GET`)
- **data** (object, optional): Request body data for `POST`, `PUT`, or `PATCH`

### Batch requests

- **requests** (array): Array of requests: `{ endpoint, method, data?, key? }`. If `key` is omitted, a key is derived from the endpoint path.

## Available Endpoints

### Discovery
- Handlers, step types, and handler details were retired from `datamachine/v1` (#3456); query the ability runner routes instead (see below)
- `GET /datamachine/v1/auth/{handler}/status` - Check OAuth connection status
- `GET /datamachine/v1/providers` - List AI providers and models
- `GET /datamachine/v1/tools` - List available AI tools

### Pipelines (Read-Only)
Pipelines routes were retired (#3456); query the ability runner routes instead:
- `GET /wp-abilities/v1/abilities/datamachine/get-pipelines/run?input={"pipeline_id":123}` - Get pipeline details with steps and flows
- `POST /wp-abilities/v1/abilities/datamachine/get-pipelines/run` with `{"input": {}}` - List all pipelines

### Flows (Read-Only)
Flow routes were retired (#3456); query the ability runner routes instead:
- `GET /wp-abilities/v1/abilities/datamachine/get-flows/run?input={"flow_id":123}` - Get flow details
- `POST /wp-abilities/v1/abilities/datamachine/get-flows/run` with `{"input": {"pipeline_id": 123}}` - List flows for a pipeline
- `POST /wp-abilities/v1/abilities/datamachine/get-problem-flows/run` with `{"input": {}}` - List flows flagged for review due to consecutive failures/no items

### Jobs & Monitoring
Jobs routes were retired (#3456); query the ability runner routes instead:
- `POST /wp-abilities/v1/abilities/datamachine/get-jobs/run` with `{"input": {}}` - List all jobs
- `POST /wp-abilities/v1/abilities/datamachine/get-jobs/run` with `{"input": {"flow_id": 123}}` - Jobs for specific flow
- `POST /wp-abilities/v1/abilities/datamachine/get-jobs/run` with `{"input": {"status": "failed"}}` - Filter by status
- `POST /wp-abilities/v1/abilities/datamachine/get-jobs/run` with `{"input": {"job_id": 456}}` - Job details

### Logs
Log routes were retired (#3456); query the ability runner routes instead:
- `POST /wp-abilities/v1/abilities/datamachine/read-logs/run` with `{"input": {"job_id": 456}}` - Logs for specific job
- `POST /wp-abilities/v1/abilities/datamachine/get-log-metadata/run` with `{"input": {}}` - Log counts and time range

### System
Settings routes were retired (#3456); query the ability runner routes instead:
- `POST /wp-abilities/v1/abilities/datamachine/get-settings/run` with `{"input": {}}` - Get plugin settings
- `POST /wp-abilities/v1/abilities/datamachine/get-step-types/run` with `{"input": {}}` - List step types
- `POST /wp-abilities/v1/abilities/datamachine/get-handlers/run` with `{"input": {}}` - List handlers

### Files
- `GET /datamachine/v1/files` listing and `DELETE /datamachine/v1/files/{filename}` were retired (#3456) — use the `datamachine/list-flow-files` and `datamachine/delete-flow-file` ability run routes (`flow_step_id` required)
- `POST /datamachine/v1/files` - Upload file (multipart, retained)

## Usage Examples

### List All Handlers
```json
{
  "endpoint": "/wp-abilities/v1/abilities/datamachine/get-handlers/run",
  "method": "POST",
  "body": { "input": {} }
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
  "endpoint": "/wp-abilities/v1/abilities/datamachine/get-pipelines/run",
  "method": "POST",
  "body": { "input": { "pipeline_id": 123 } }
}
```

### Monitor Job Status
```json
{
  "endpoint": "/wp-abilities/v1/abilities/datamachine/get-jobs/run",
  "method": "POST",
  "body": { "input": { "job_id": 456 } }
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
