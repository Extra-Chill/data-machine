# Update Flow Tool

Tool for updating flow-level properties including title and scheduling configuration.

## Overview

The `update_flow` tool enables modification of existing flow properties without recreating the flow. This supports workflow management tasks like renaming flows or changing execution schedules.

## Parameters

- **flow_id** (integer, required): Flow ID to update
- **flow_name** (string, optional): New flow title
- **scheduling_config** (object, optional): Updated scheduling configuration

## Scheduling Configuration

Supports the same scheduling options as flow creation:

```json
{
  "interval": "manual|every_5_minutes|hourly|every_2_hours|every_4_hours|qtrdaily|twicedaily|daily|weekly|one_time",
  "timestamp": 1735689600  // Required for one_time
}
```

## Usage Examples

### Rename Flow
```json
{
  "flow_id": 123,
  "flow_name": "Updated Flow Name"
}
```

### Change Schedule
```json
{
  "flow_id": 123,
  "scheduling_config": {
    "interval": "daily"
  }
}
```

### Switch to Manual
```json
{
  "flow_id": 123,
  "scheduling_config": {
    "interval": "manual"
  }
}
```

### Schedule One-Time Execution
```json
{
  "flow_id": 123,
  "flow_name": "Special Event Flow",
  "scheduling_config": {
    "interval": "one_time",
    "timestamp": 1735689600
  }
}
```

## Response

Returns confirmation of successful updates:

```json
{
  "success": true,
  "data": {
    "flow_id": 123,
    "flow_name": "Updated Flow Name",
    "scheduling": "daily",
    "message": "Flow updated successfully."
  }
}
```

## Update Behavior

- **Partial Updates**: Only specified parameters are updated
- **Validation**: All parameters validated before any changes applied
- **Atomic Operations**: Either all updates succeed or none are applied
- **Scheduling Changes**: Immediately affects future executions

## Integration

This tool complements the flow management workflow:

1. Create flows with `create_flow` or `create_pipeline`
2. Configure steps with `configure_flow_step` and `configure_pipeline_step`
3. Update flow properties as needed with `update_flow`
4. Execute with `run_flow` or let scheduling handle it

## Validation

- Validates flow_id exists and user has permissions
- Validates scheduling_config format and parameters
- Ensures one_time schedules have valid future timestamps
- Checks flow is not currently executing (for scheduling changes)

## Error Handling

Returns structured error responses for:
- Invalid or non-existent flow_id
- Malformed scheduling configuration
- Invalid scheduling intervals
- Past timestamps for one_time schedules
- Permission issues
- Flow currently executing (blocks scheduling changes)

## Use Cases

- **Workflow Organization**: Rename flows for better organization
- **Schedule Changes**: Switch between manual, recurring, or one-time execution
- **Maintenance**: Temporarily disable scheduled execution by switching to manual
- **Event-Based**: Schedule one-time executions for special events or campaigns