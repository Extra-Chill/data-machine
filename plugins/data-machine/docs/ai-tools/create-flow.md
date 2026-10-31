# Create Flow Tool

Specialized tool for creating flow instances from existing pipelines with automatic step synchronization.

## Overview

The `create_flow` tool creates executable flow instances from existing pipelines. When a flow is created, all pipeline steps are automatically synchronized to the new flow, ensuring consistency between the pipeline template and flow execution.

## Parameters

- **pipeline_id** (integer, required): ID of the pipeline to create a flow for
- **flow_name** (string, optional): Name for the flow (defaults to "Flow")
- **scheduling_config** (object, optional): Scheduling configuration object

## Scheduling Configuration

The `scheduling_config` object supports different scheduling types:

### Manual Execution (Default)
```json
{
  "interval": "manual"
}
```

### One-Time Execution
```json
{
  "interval": "one_time",
  "timestamp": 1735689600
}
```

### Recurring Schedules
```json
{
  "interval": "every_5_minutes"
}
```
```json
{
  "interval": "hourly"
}
```
```json
{
  "interval": "every_2_hours"
}
```
```json
{
  "interval": "every_4_hours"
}
```
```json
{
  "interval": "qtrdaily"
}
```
```json
{
  "interval": "twicedaily"
}
```
```json
{
  "interval": "daily"
}
```
```json
{
  "interval": "weekly"
}
```

## Usage Examples

### Basic Flow Creation
```json
{
  "pipeline_id": 123,
  "flow_name": "Daily Content Processing"
}
```

### Scheduled Flow
```json
{
  "pipeline_id": 123,
  "flow_name": "Hourly Social Media Posts",
  "scheduling_config": {
    "interval": "hourly"
  }
}
```

### One-Time Flow
```json
{
  "pipeline_id": 123,
  "flow_name": "Special Event Coverage",
  "scheduling_config": {
    "interval": "one_time",
    "timestamp": 1735689600
  }
}
```

## Response

Returns comprehensive flow creation details:

```json
{
  "success": true,
  "data": {
    "flow_id": 456,
    "flow_name": "Daily Content Processing",
    "pipeline_id": 123,
    "synced_steps": 3,
    "flow_step_ids": [
      "pipeline_step_123_1",
      "pipeline_step_123_2",
      "pipeline_step_123_3"
    ],
    "scheduling": "manual",
    "message": "Flow created successfully. Use configure_flow_step with the flow_step_ids to set handler configurations."
  }
}
```

## Flow Step Synchronization

When a flow is created:
1. All pipeline steps are copied to the flow
2. Each step gets a unique `flow_step_id` for configuration
3. Steps maintain execution order from the pipeline
4. Flow-specific configurations can be applied using `configure_flow_step`

## Integration Workflow

1. Create or identify target pipeline
2. Use `create_flow` to instantiate executable flow
3. Configure each step using returned `flow_step_ids` with `configure_flow_step`
4. Execute flow with `run_flow` or let scheduling system handle it

## Validation

- Validates pipeline_id exists and user has access
- Validates scheduling_config format and parameters
- Ensures one_time schedules have valid future timestamps
- Checks available scheduling intervals

## Error Handling

Returns structured error responses for:
- Invalid or non-existent pipeline_id
- Malformed scheduling configuration
- Invalid scheduling intervals
- Past timestamps for one_time schedules
- Permission issues

## Flow Management

Created flows can be:
- Executed immediately with `run_flow`
- Scheduled for automatic execution
- Modified with `update_flow`
- Duplicated or deleted via API
- Monitored through job tracking system