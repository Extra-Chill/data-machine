# Run Flow Tool

Tool for executing existing flows immediately or scheduling them for delayed execution.

## Overview

The `run_flow` tool provides flexible flow execution with support for both immediate and scheduled runs. Flows execute asynchronously in the background, allowing for monitoring through job tracking.

## Parameters

- **flow_id** (integer, required): Flow ID to execute
- **timestamp** (integer, optional): Unix timestamp for scheduled execution (omit for immediate execution)

## Execution Types

### Immediate Execution
Omit the `timestamp` parameter entirely for immediate background execution:

```json
{
  "flow_id": 123
}
```

Note: don’t send `timestamp: null`—the tool treats a missing `timestamp` as immediate.

### Scheduled Execution
Provide a future Unix timestamp for delayed execution:

```json
{
  "flow_id": 123,
  "timestamp": 1735689600
}
```

## Usage Examples

### Immediate Execution
```json
{
  "flow_id": 456
}
```

### Scheduled Execution
```json
{
  "flow_id": 456,
  "timestamp": 1735689600
}
```

## Response

Returns execution confirmation with job tracking information:

```json
{
  "success": true,
  "data": {
    "flow_id": 456,
    "execution_type": "immediate",
    "job_id": 789,
    "flow_name": "Daily Content Processor",
    "message": "Flow queued for immediate background execution. It will start within seconds. Use job_id to check status."
  }
}
```

For scheduled execution:

```json
{
  "success": true,
  "data": {
    "flow_id": 456,
    "execution_type": "delayed",
    "message": "Flow scheduled for delayed background execution at the specified time."
  }
}
```

## Execution Behavior

- **Asynchronous**: All executions run in background via WordPress Action Scheduler
- **Job Tracking**: Each execution creates a job record for monitoring
- **Status Monitoring**: Use the `get-jobs` ability with `{"job_id": <id>}` to check status
- **Logging**: Comprehensive execution logs available through the logging system

## Job Status Values

- `pending`: Queued for execution
- `processing`: Currently executing
- `completed`: Finished successfully
- `completed_no_items`: Finished successfully but no new items are found to process
- `agent_skipped`: Finished intentionally without processing the current item (supports compound statuses like `agent_skipped - {reason}`)
- `failed`: Execution failed with errors

## Integration

This tool integrates with the monitoring ecosystem:

1. Execute flow with `run_flow`
2. Monitor execution with the `get-jobs` ability (`job_id` input)
3. View detailed logs with the `read-logs` ability (`job_id` input)
4. Check flow status and history through the flows API

## Validation

- Validates flow_id exists and is accessible
- For scheduled execution: ensures timestamp is future-dated
- Checks user permissions for flow execution

## Error Handling

Returns structured error responses for:
- Invalid or non-existent flow_id
- Past timestamps for scheduled execution
- Permission denied for flow execution
- Flow already running (prevents duplicate executions)

## Scheduling Considerations

- **WordPress Cron**: Uses Action Scheduler for reliable execution
- **Time Zones**: Timestamps should be in Unix format (seconds since epoch)
- **Server Time**: Execution timing based on server time, not user timezone
- **Overlaps**: System prevents multiple simultaneous executions of the same flow