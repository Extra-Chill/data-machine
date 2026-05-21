# Configure Pipeline Step Tool

Specialized tool for configuring pipeline-level AI step settings including system prompt, provider, model, and enabled tools.

## Overview

The `configure_pipeline_step` tool manages pipeline-level AI configuration that applies to all flows using that pipeline. This includes AI provider settings, system prompts, and tool enablement that affect how AI steps process content across all associated flows.

## Parameters

- **pipeline_step_id** (string, required): Pipeline step ID to configure (format: "123_uuid4")
- **system_prompt** (string, optional): System prompt defining AI persona and instructions
- **provider** (string, optional): AI provider slug (e.g., "anthropic", "openai")
- **model** (string, optional): AI model identifier (e.g., "claude-sonnet-4-20250514", "gpt-5.2")
- **enabled_tools** (array, optional): Array of tool slugs to enable for this AI step

## Usage Examples

### Configure AI Provider and Model
```json
{
  "pipeline_step_id": "123_abc123",
  "provider": "anthropic",
  "model": "claude-sonnet-4-20250514"
}
```

### Set System Prompt
```json
{
  "pipeline_step_id": "123_abc123",
  "system_prompt": "You are a professional content writer specializing in technology news. Always maintain journalistic integrity and fact-check information."
}
```

### Enable Specific Tools
```json
{
  "pipeline_step_id": "123_abc123",
  "enabled_tools": ["local_search", "web_fetch", "wordpress_post_reader"]
}
```

### Complete Configuration
```json
{
  "pipeline_step_id": "123_abc123",
  "system_prompt": "You are a social media expert creating engaging posts.",
  "provider": "openai",
  "model": "gpt-4o",
  "enabled_tools": ["local_search", "web_fetch"]
}
```

## Response

Returns confirmation of configuration updates:

```json
{
  "success": true,
  "data": {
    "pipeline_step_id": "123_abc123",
    "system_prompt_updated": true,
    "provider": "openai",
    "model": "gpt-4o",
    "enabled_tools_updated": true,
    "message": "Pipeline step configured successfully."
  }
}
```

## Configuration Scope

Pipeline-level settings apply to all flows using this pipeline:

- **System Prompt**: Defines AI behavior and persona for all flows
- **Provider/Model**: AI service and model used across all executions
- **Enabled Tools**: Tools available during AI processing for all flows

## Available Tools

Common tools that can be enabled:
- `local_search` - WordPress internal content search
- `web_fetch` - Web page content retrieval (50K limit)
- `wordpress_post_reader` - Single WordPress post content retrieval
- `execute_workflow` - Execute complete workflows
- `api_query` - REST API query tool
- Additional handler-specific tools based on pipeline configuration

## Integration

This tool complements `configure_flow_step` for complete AI step setup:

1. Use `configure_pipeline_step` for shared settings (system prompt, provider, model, tools)
2. Use `configure_flow_step` for flow-specific settings (user messages, handler configurations)

## Validation

- Validates pipeline_step_id format and existence
- Ensures at least one configuration parameter is provided
- Validates provider and model availability
- Checks tool slugs against available tools
- Verifies AI step type for the pipeline step

## Error Handling

Returns structured error responses for:
- Invalid pipeline_step_id
- Non-existent pipeline step
- Invalid provider or model
- Unsupported tools
- Permission issues
- Non-AI step types
