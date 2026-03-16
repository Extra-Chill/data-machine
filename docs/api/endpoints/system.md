# System Endpoint

**Implementation**: `inc/Api/System/System.php`

**Base URL**: `/wp-json/datamachine/v1/system/`

## Overview

The System endpoint provides infrastructure operations, monitoring, system task management, and editable prompt definitions for Data Machine. System tasks are background AI operations (alt text generation, daily memory, image optimization, etc.) registered via the `TaskRegistry` and executed through Action Scheduler.

## Authentication

Requires `manage_settings` permission (checked via `PermissionHelper`).

## Endpoints

### GET /system/status

Get system status and operational information.

**Permission**: `manage_settings` capability required

**Parameters**: None

**Response**:
```json
{
  "success": true,
  "data": {
    "status": "operational",
    "version": "0.41.0",
    "timestamp": "2026-03-15T10:30:00Z"
  }
}
```

**Example Request**:
```bash
curl -X GET https://example.com/wp-json/datamachine/v1/system/status \
  -u username:application_password
```

### GET /system/tasks

List all registered system tasks with metadata and last-run information.

**Permission**: `manage_settings` capability required

**Parameters**: None

**Example Request**:
```bash
curl https://example.com/wp-json/datamachine/v1/system/tasks \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": [
    {
      "task_type": "alt_text_generation",
      "label": "Alt Text Generation",
      "description": "Generate descriptive alt text for images using AI",
      "setting_key": "system_task_alt_text_enabled",
      "default_enabled": true,
      "last_run_at": "2026-03-15 08:00:00",
      "last_status": "completed",
      "run_count": 142
    },
    {
      "task_type": "daily_memory_generation",
      "label": "Daily Memory Generation",
      "description": "Generate daily memory summaries from session activity",
      "setting_key": "system_task_daily_memory_enabled",
      "default_enabled": true,
      "last_run_at": "2026-03-15 00:00:00",
      "last_status": "completed",
      "run_count": 45
    },
    {
      "task_type": "image_generation",
      "label": "Image Generation",
      "description": "Generate images using AI models via Replicate",
      "setting_key": null,
      "default_enabled": true,
      "last_run_at": "2026-03-14 16:30:00",
      "last_status": "completed",
      "run_count": 89
    }
  ]
}
```

**Task Object Fields**:
- `task_type` (string): Task type identifier (used in API calls)
- `label` (string): Human-readable task name
- `description` (string): Task description
- `setting_key` (string|null): WordPress option key for enable/disable toggle (null if always enabled)
- `default_enabled` (boolean): Whether the task is enabled by default
- `last_run_at` (string|null): Timestamp of the most recent execution
- `last_status` (string|null): Status of the most recent execution
- `run_count` (integer): Total number of times the task has been executed

**Registered Task Types**:

| Task Type | Class | Description |
|---|---|---|
| `image_generation` | `ImageGenerationTask` | Generate images using AI models via Replicate |
| `image_optimization` | `ImageOptimizationTask` | Optimize images for web delivery |
| `alt_text_generation` | `AltTextTask` | Generate descriptive alt text for images |
| `github_create_issue` | `GitHubIssueTask` | Create GitHub issues from AI workflows |
| `internal_linking` | `InternalLinkingTask` | Analyze and suggest internal link improvements |
| `daily_memory_generation` | `DailyMemoryTask` | Generate daily memory summaries |
| `meta_description_generation` | `MetaDescriptionTask` | Generate meta descriptions for SEO |

### POST /system/tasks/{task_type}/run

Run a system task immediately via the `datamachine/run-task` ability.

**Permission**: `manage_settings` capability required

**Parameters**:
- `task_type` (string, required): Task type identifier (e.g., `alt_text_generation`, `daily_memory_generation`)

**Example Request**:
```bash
# Run daily memory generation
curl -X POST https://example.com/wp-json/datamachine/v1/system/tasks/daily_memory_generation/run \
  -u username:application_password

# Run alt text generation
curl -X POST https://example.com/wp-json/datamachine/v1/system/tasks/alt_text_generation/run \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "success": true,
    "task_type": "daily_memory_generation",
    "job_id": 1567,
    "message": "Task scheduled for execution"
  }
}
```

**Error Response (400 Bad Request)**:

```json
{
  "code": "run_task_failed",
  "message": "Unknown task type: invalid_task",
  "data": {"status": 400}
}
```

> **Note**: Tasks are executed asynchronously via Action Scheduler. The endpoint schedules the task and returns immediately with a job ID. Use the Jobs API to monitor execution status.

## Task Prompts API

**Since**: v0.41.0

System tasks that use AI prompts expose editable prompt definitions. Each prompt has a default template with `{{variable}}` placeholders that can be overridden per-task without modifying code. Overrides are stored in the `datamachine_task_prompts` WordPress option.

### GET /system/tasks/prompts

List all editable prompts across all system tasks.

**Permission**: `manage_settings` capability required

**Parameters**: None

**Example Request**:
```bash
curl https://example.com/wp-json/datamachine/v1/system/tasks/prompts \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": [
    {
      "task_type": "alt_text_generation",
      "prompt_key": "system_prompt",
      "label": "Alt Text System Prompt",
      "description": "System prompt for alt text generation AI calls",
      "default": "You are an expert at writing descriptive alt text for images...",
      "variables": {
        "site_name": "The WordPress site name",
        "site_url": "The WordPress site URL"
      },
      "has_override": false,
      "override": null
    },
    {
      "task_type": "meta_description_generation",
      "prompt_key": "user_prompt",
      "label": "Meta Description User Prompt",
      "description": "User prompt template for meta description generation",
      "default": "Write a compelling meta description for this post:\n\nTitle: {{title}}\nContent: {{content}}",
      "variables": {
        "title": "The post title",
        "content": "The post content (truncated)"
      },
      "has_override": true,
      "override": "Write a 155-char meta description optimized for {{title}}"
    }
  ]
}
```

**Prompt Object Fields**:
- `task_type` (string): Task type identifier
- `prompt_key` (string): Prompt key within the task (e.g., `system_prompt`, `user_prompt`)
- `label` (string): Human-readable prompt name
- `description` (string): What this prompt is used for
- `default` (string): Default prompt template
- `variables` (object): Available `{{variable}}` placeholders with descriptions
- `has_override` (boolean): Whether a custom override exists
- `override` (string|null): The override text, if any

### GET /system/tasks/prompts/{task_type}/{prompt_key}

Get a specific prompt's definition and current effective value.

**Permission**: `manage_settings` capability required

**Parameters**:
- `task_type` (string, required): Task type identifier
- `prompt_key` (string, required): Prompt key

**Example Request**:
```bash
curl https://example.com/wp-json/datamachine/v1/system/tasks/prompts/alt_text_generation/system_prompt \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "task_type": "alt_text_generation",
    "prompt_key": "system_prompt",
    "label": "Alt Text System Prompt",
    "description": "System prompt for alt text generation AI calls",
    "default": "You are an expert at writing descriptive alt text...",
    "variables": {
      "site_name": "The WordPress site name"
    },
    "has_override": false,
    "override": null,
    "effective": "You are an expert at writing descriptive alt text..."
  }
}
```

The `effective` field returns the currently active prompt text — the override if one exists, otherwise the default.

### PUT /system/tasks/prompts/{task_type}/{prompt_key}

Set a prompt override for a specific task prompt.

**Permission**: `manage_settings` capability required

**Parameters**:
- `task_type` (string, required): Task type identifier
- `prompt_key` (string, required): Prompt key
- `prompt` (string, required): The override prompt text

**Example Request**:
```bash
curl -X PUT https://example.com/wp-json/datamachine/v1/system/tasks/prompts/alt_text_generation/system_prompt \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"prompt": "You are an SEO specialist. Write concise, keyword-rich alt text for {{site_name}}..."}'
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "task_type": "alt_text_generation",
    "prompt_key": "system_prompt",
    "override": "You are an SEO specialist. Write concise, keyword-rich alt text for {{site_name}}..."
  }
}
```

**Error Response (400 Bad Request)**:

```json
{
  "code": "empty_prompt",
  "message": "Prompt text cannot be empty. Use DELETE to reset to default.",
  "data": {"status": 400}
}
```

### DELETE /system/tasks/prompts/{task_type}/{prompt_key}

Reset a prompt override to its default value (removes the override).

**Permission**: `manage_settings` capability required

**Parameters**:
- `task_type` (string, required): Task type identifier
- `prompt_key` (string, required): Prompt key

**Example Request**:
```bash
curl -X DELETE https://example.com/wp-json/datamachine/v1/system/tasks/prompts/alt_text_generation/system_prompt \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "task_type": "alt_text_generation",
    "prompt_key": "system_prompt",
    "default": "You are an expert at writing descriptive alt text..."
  }
}
```

## Prompt Template Variables

Prompt templates support `{{variable}}` placeholders that are interpolated at execution time. Each prompt definition declares its available variables and descriptions.

**Example template**:
```
Write a meta description for this post on {{site_name}}.

Title: {{title}}
Content excerpt: {{content}}

Keep it under 155 characters.
```

**Interpolation behavior**:
- Variables matching `{{key}}` are replaced with runtime values
- Undefined variables are left as-is (not replaced, not removed)
- Templates support any text content including markdown and multi-line

## System Abilities

System operations are exposed through the WordPress Abilities API for programmatic access:

### datamachine/generate-session-title

**Purpose**: Generate a title for a chat session using AI or fallback methods

**Implementation**: `inc/Abilities/SystemAbilities.php`

**Parameters**:
- `session_id` (string, required): UUID of the chat session
- `force` (boolean, optional): Force regeneration even if title exists (default: false)

**Output Schema**:
```json
{
  "type": "object",
  "properties": {
    "success": {
      "type": "boolean",
      "description": "Whether title generation succeeded"
    },
    "title": {
      "type": "string",
      "description": "Generated session title"
    },
    "method": {
      "type": "string",
      "enum": ["ai", "fallback", "existing"],
      "description": "Method used to generate title"
    }
  }
}
```

**Usage Example**:
```php
$ability = wp_get_ability('datamachine/generate-session-title');
$result = $ability->execute([
  'session_id' => '550e8400-e29b-41d4-a716-446655440000'
]);

if ($result['success']) {
  echo "Title: " . $result['title'] . " (method: " . $result['method'] . ")";
}
```

### datamachine/run-task

**Purpose**: Run a system task immediately

**Implementation**: `inc/Abilities/SystemAbilities.php`

**Parameters**:
- `task_type` (string, required): Task type identifier from the TaskRegistry

**Usage Example**:
```php
$ability = wp_get_ability('datamachine/run-task');
$result = $ability->execute([
  'task_type' => 'daily_memory_generation'
]);
```

### datamachine/health-check

**Purpose**: Run system health diagnostics

**Implementation**: `inc/Abilities/SystemAbilities.php`

**Usage Example**:
```php
$ability = wp_get_ability('datamachine/health-check');
$result = $ability->execute([]);
```

## Automatic Title Generation

Chat session titles are automatically generated when:

1. **AI Titles Enabled**: Uses the configured AI provider to generate descriptive titles from conversation content
2. **Fallback**: Uses truncated first user message when AI generation fails or is disabled
3. **Trigger**: Automatically triggered after session persistence when a new session has no title

**Configuration**:
- `chat_ai_titles_enabled` setting controls whether AI generation is used (default: true)
- Falls back gracefully when AI services are unavailable
- Titles are limited to 100 characters maximum

## Task Registration

System tasks are registered through the `datamachine_tasks` filter and managed by the `TaskRegistry`:

```php
// Register a custom system task
add_filter('datamachine_tasks', function($tasks) {
    $tasks['my_custom_task'] = MyCustomTask::class;
    return $tasks;
});
```

**Task class requirements**:
- Must extend `DataMachine\Engine\AI\System\Tasks\SystemTask`
- Must implement `execute(int $jobId, array $params): void`
- Must implement `getTaskType(): string`
- May override `getTaskMeta()` for UI metadata
- May override `getPromptDefinitions()` for editable prompts
- May override `supportsUndo()` for undo support

## Related Documentation

- [Chat Endpoint](chat.md) - Main chat API
- [Chat Sessions](chat-sessions.md) - Session management
- [Jobs Endpoint](jobs.md) - Job execution monitoring and undo
- [Abilities API](../../core-system/abilities-api.md) - WordPress Abilities API usage
- [AI Directives](../../core-system/ai-directives.md) - AI integration patterns

---

**Since**: v0.13.7 (status endpoint), v0.32.0 (tasks registry), v0.41.0 (task prompts API), v0.42.0 (run task endpoint)
**Implementation**: `inc/Api/System/System.php`
**Task Registration**: `inc/Engine/AI/System/SystemAgentServiceProvider.php`
