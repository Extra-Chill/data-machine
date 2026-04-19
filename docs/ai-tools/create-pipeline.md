# Create Pipeline Tool

Comprehensive tool for creating pipelines with optional predefined steps and automatic flow instantiation.

## Overview

The `create_pipeline` tool creates new pipeline templates with optional step definitions. Unlike other tools, this automatically creates an associated flow for immediate configuration and execution, eliminating the need for separate `create_flow` calls.

## Parameters

- **pipeline_name** (string, required): Name for the new pipeline
- **steps** (array, optional): Array of step definitions in execution order
- **flow_name** (string, optional): Name for the automatically created flow (defaults to pipeline_name)
- **scheduling_config** (object, optional): Scheduling configuration (defaults to manual)

## Step Definition Format

Each step in the `steps` array supports:

```json
{
  "step_type": "fetch|ai|publish|upsert",
  "handler_slug": "handler_name",
  "handler_config": {
    // Handler-specific configuration
  },
  "provider": "ai_provider",     // For AI steps
  "model": "ai_model",           // For AI steps
  "system_prompt": "AI instructions" // For AI steps
}
```

## Scheduling Configuration

Supports the same scheduling options as `create_flow`:

```json
{
  "interval": "manual|hourly|daily|weekly|monthly|one_time",
  "timestamp": 1735689600  // Required for one_time
}
```

## Usage Examples

### Simple Pipeline
```json
{
  "pipeline_name": "Basic RSS Processor"
}
```

### Pipeline with Steps
```json
{
  "pipeline_name": "Social Media Publisher",
  "steps": [
    {
      "step_type": "fetch",
      "handler_slug": "rss",
      "handler_config": {
        "feed_url": "https://example.com/feed.xml"
      }
    },
    {
      "step_type": "ai",
      "provider": "anthropic",
      "model": "claude-sonnet-4-20250514",
      "system_prompt": "Create engaging social media posts"
    },
    {
      "step_type": "publish",
      "handler_slug": "twitter",
      "handler_config": {
        "include_images": true
      }
    }
  ]
}
```

### Scheduled Pipeline
```json
{
  "pipeline_name": "Daily News Digest",
  "flow_name": "Daily Digest Flow",
  "scheduling_config": {
    "interval": "daily"
  },
  "steps": [
    {
      "step_type": "fetch",
      "handler_slug": "wordpress_api",
      "handler_config": {
        "api_url": "https://news.example.com/wp-json/wp/v2/posts"
      }
    }
  ]
}
```

## Response

Returns comprehensive creation details:

```json
{
  "success": true,
  "data": {
    "pipeline_id": 123,
    "pipeline_name": "Social Media Publisher",
    "flow_id": 456,
    "flow_name": "Social Media Publisher",
    "steps_created": 3,
    "flow_step_ids": [
      "pipeline_step_123_1",
      "pipeline_step_123_2",
      "pipeline_step_123_3"
    ],
    "scheduling": "manual",
    "message": "Pipeline and flow created with 3 steps. Use configure_flow_step with the flow_step_ids to set handler configurations."
  }
}
```

## Automatic Flow Creation

This tool automatically creates a flow because:
- Pipelines are templates, flows are executable instances
- Immediate configuration is required for AI steps (provider, model, system prompt)
- Eliminates extra API calls and confusion
- Provides `flow_step_ids` for immediate configuration with `configure_flow_step`

## Step Validation

Validates all step definitions:
- Checks `step_type` against available types
- Validates `handler_slug` for fetch/publish/upsert steps
- Ensures AI steps have required provider/model when specified
- Verifies execution order and step relationships

## Integration Workflow

1. Use `create_pipeline` for complete pipeline + flow creation
2. Configure steps using returned `flow_step_ids` with `configure_flow_step`
3. Execute immediately with `run_flow` or modify scheduling with `update_flow`

## Error Handling

Returns structured error responses for:
- Missing or invalid pipeline_name
- Malformed step definitions
- Invalid step types or handler slugs
- Scheduling configuration errors
- Permission issues

## Important Notes

- **Do NOT call `create_flow`** after using this tool - a flow is already created
- Use returned `flow_step_ids` with `configure_flow_step` for handler configuration
- AI steps can include provider/model/system_prompt in the step definition
- Flow inherits pipeline name if `flow_name` not specified