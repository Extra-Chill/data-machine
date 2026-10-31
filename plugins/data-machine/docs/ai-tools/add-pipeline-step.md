# Add Pipeline Step Tool

Specialized chat tool for adding steps to existing pipelines with automatic flow synchronization.

## Overview

The `add_pipeline_step` tool allows AI agents to add new steps to existing pipelines. When a step is added to a pipeline, it is automatically synchronized to all flows associated with that pipeline, ensuring consistency across all workflow instances.

## Parameters

- **pipeline_id** (integer, required): The ID of the pipeline to add the step to
- **step_type** (string, required): The type of step to add. Valid types include: fetch, ai, publish, upsert

## Usage

```json
{
  "pipeline_id": 123,
  "step_type": "ai"
}
```

## Response

Returns detailed information about the added step and synchronization results:

```json
{
  "success": true,
  "data": {
    "pipeline_id": 123,
    "pipeline_step_id": "456_uuid",
    "step_type": "ai",
    "flows_updated": 2,
    "flow_step_ids": [
      {
        "flow_id": 789,
        "flow_step_id": "pipeline_step_456_1"
      },
      {
        "flow_id": 790,
        "flow_step_id": "pipeline_step_456_2"
      }
    ],
    "message": "Step 'ai' added to pipeline. Use configure_flow_step with the flow_step_ids to set handler configuration."
  }
}
```

## Behavior

1. Validates the pipeline exists and user has permissions
2. Validates the step_type is supported
3. Adds the step to the pipeline with proper execution ordering
4. Automatically syncs the new step to all flows on the pipeline
5. Returns flow_step_ids for subsequent configuration using `configure_flow_step`

## Integration

This tool is designed to work seamlessly with `configure_flow_step` for complete workflow setup. After adding a step, use the returned `flow_step_ids` to configure handler settings or AI prompts for each flow instance.

## Error Handling

Returns structured error responses for:
- Invalid or missing pipeline_id
- Unsupported step_type
- Permission issues
- Pipeline not found