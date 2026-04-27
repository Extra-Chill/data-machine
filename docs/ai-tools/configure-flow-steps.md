# Configure Flow Steps Tool

Configures handler settings or AI user messages on flow steps. Supports both single-step and bulk pipeline-scoped operations.

## Overview

The `configure_flow_steps` tool enables configuration of flow steps after creation:

1. **Single Mode**: Configure one specific flow step by ID
2. **Bulk Mode**: Configure all matching steps across all flows in a pipeline

## Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `flow_step_id` | string | No* | Flow step ID for single-step mode |
| `pipeline_id` | integer | No* | Pipeline ID for bulk mode |
| `step_type` | string | No** | Filter by step type (fetch, publish, upsert, ai) |
| `handler_slug` | string | No | Handler slug to set (single) or filter by (bulk) |
| `target_handler_slug` | string | No | Handler to switch TO. When provided, `handler_slug` filters existing handlers (bulk) and `target_handler_slug` sets the new handler. |
| `field_map` | object | No | Field mappings when switching handlers, e.g. `{"endpoint_url": "source_url"}`. |
| `handler_config` | object | No*** | Handler config to merge into existing config |
| `flow_configs` | array | No | Per-flow configurations for bulk mode. Array of `{flow_id: int, handler_config: object}`. |
| `user_message` | string | No*** | User message input for AI steps. Stored as a one-entry static `prompt_queue`. |

**Validation Rules:**
- *One of `flow_step_id` OR `pipeline_id` required
- **When `pipeline_id` provided, at least one of `step_type` or `handler_slug` required
- ***At least one of `handler_config`, `user_message`, or `target_handler_slug` required

## Operational Modes

### Single Mode
Provide `flow_step_id` to configure a specific step. If `target_handler_slug` is provided, the step will switch handlers.

### Bulk Mode
Provide `pipeline_id` and filters (`step_type` and/or `handler_slug`) to update multiple flows at once. This is highly efficient for updating credentials, endpoints, or prompts across an entire pipeline.

## Handler Switching & Field Mapping

When switching handlers (using `target_handler_slug`), the tool attempts to preserve existing configuration:
1. **Explicit Mapping**: Uses `field_map` to map old field names to new ones.
2. **Auto-Mapping**: Fields with identical names in both handlers are automatically preserved.
3. **Cleanup**: Fields that do not exist in the target handler and aren't mapped are dropped.

## Configuration Merge Logic

The tool uses a multi-layered merge approach for `handler_config`:

1. **Mapped Base**: Starts with existing config (mapped to new handler if switching).
2. **Shared Config**: `handler_config` is merged on top (applies to all targeted steps).
3. **Per-Flow Config**: In bulk mode, `flow_configs` allows overriding shared settings for specific flows (merges on top of Shared Config).

### Bulk Mode: Shared vs. Per-Flow Config

```json
{
  "pipeline_id": 42,
  "handler_slug": "rss",
  "handler_config": {
    "timeframe_limit": "24_hours"
  },
  "flow_configs": [
    {
      "flow_id": 101,
      "handler_config": { "feed_url": "https://site-a.com/rss" }
    },
    {
      "flow_id": 102,
      "handler_config": { "feed_url": "https://site-b.com/rss" }
    }
  ]
}
```
In this example, all matching steps get `timeframe_limit: "24_hours"`, but each flow receives its specific `feed_url`.

## Usage Examples

### Single Mode: Switch Handler with Mapping

```json
{
  "flow_step_id": "pipeline_step_123_456",
  "handler_slug": "old_handler",
  "target_handler_slug": "new_handler",
  "field_map": {
    "old_url_field": "new_source_field"
  },
  "handler_config": {
    "additional_new_setting": true
  }
}
```

### Single Mode: Update Existing Handler Config

```json
{
  "flow_step_id": "pipeline_step_123_456",
  "handler_config": {
    "taxonomy_category_selection": "skip"
  }
}
```

### Bulk Mode: Update All WordPress Publish Steps in Pipeline

```json
{
  "pipeline_id": 42,
  "handler_slug": "wordpress",
  "handler_config": {
    "taxonomy_category_selection": "skip"
  }
}
```

### Bulk Mode: Update All Publish Steps by Type

```json
{
  "pipeline_id": 42,
  "step_type": "publish",
  "handler_config": {
    "post_status": "draft"
  }
}
```

### Bulk Mode: Update AI Prompts Across Pipeline

```json
{
  "pipeline_id": 42,
  "step_type": "ai",
  "user_message": "Summarize the content in 2-3 sentences"
}
```

`user_message` is an input convenience, not a persisted flow-step field. The ability writes it through the same path as `wp datamachine flows update --set-user-message`, replacing the step's `prompt_queue` with one `{prompt, added_at}` entry and setting `queue_mode` to `static`.

## Response Format

### Single Mode Response

```json
{
  "success": true,
  "data": {
    "flow_step_id": "pipeline_step_123_456",
    "handler_updated": true,
    "handler_slug": "rss",
    "message": "Flow step configured successfully."
  }
}
```

### Bulk Mode Response

```json
{
  "success": true,
  "data": {
    "pipeline_id": 42,
    "flows_updated": 5,
    "steps_modified": 5,
    "details": [
      {"flow_id": 101, "flow_name": "Music Festival Feed", "flow_step_id": "ps_15_101"},
      {"flow_id": 102, "flow_name": "Food Festival Feed", "flow_step_id": "ps_15_102"}
    ],
    "message": "Updated 5 step(s) across 5 flow(s)."
  }
}
```

## Handler Config Merge Behavior

The `handler_config` parameter merges into existing configuration rather than replacing it entirely. This allows targeted updates:

**Existing config:**
```json
{
  "post_type": "post",
  "post_status": "publish",
  "taxonomy_category_selection": "15"
}
```

**Update with:**
```json
{
  "handler_config": {
    "taxonomy_category_selection": "skip"
  }
}
```

**Result:**
```json
{
  "post_type": "post",
  "post_status": "publish",
  "taxonomy_category_selection": "skip"
}
```

## Integration Workflow

1. Query pipelines with `api_query` to find pipeline ID
2. Use `configure_flow_steps` with `pipeline_id` for bulk updates
3. Or use `flow_step_id` for targeted single-step configuration

## Error Handling

Returns structured error responses for:
- Missing required parameters
- Invalid pipeline_id or flow_step_id
- No matching steps found for bulk criteria
- Handler configuration validation failures
