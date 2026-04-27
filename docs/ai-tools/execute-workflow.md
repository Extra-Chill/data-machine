# ExecuteWorkflow Tool

**Primary action tool for executing content automation workflows** (@since v0.3.0)

## Overview

The ExecuteWorkflow tool enables AI agents to execute complete multi-step workflows through the chat interface. It provides a simplified interface for creating and running workflows without requiring users to first create pipelines and flows through the admin interface.

## Implementation

**Tool wrapper**: `/inc/Api/Chat/Tools/ExecuteWorkflowTool.php` — registers the `execute_workflow` chat tool and binds it to the `datamachine/execute-workflow` ability via `BaseTool::registerTool()`.

**Backing ability**: `/inc/Abilities/Job/ExecuteWorkflowAbility.php` — owns the actual execution logic for ephemeral workflows.

**Architecture**: Ability-backed pattern. The chat tool is a thin handler that validates `steps`, looks up the ability via `wp_get_ability( 'datamachine/execute-workflow' )`, and forwards the workflow to it. Step type slugs are resolved at runtime from the `datamachine/get-step-types` ability so the description always reflects registered step types.

**Key Responsibilities**:
- Tool registration and definition
- Parameter validation (`steps` required, `dry_run` optional)
- Ability delegation via `wp_get_ability()`
- Error handling and response formatting

## Step Configuration

### Fetch Steps
```json
{
  "step_type": "fetch",
  "handler_slug": "handler_name",
  "handler_config": {
    "required_field": "value",
    "optional_field": "value"
  }
}
```

### AI Steps
```json
{
  "step_type": "ai",
  "provider": "anthropic",
  "model": "claude-sonnet-4-20250514",
  "user_message": "Instruction for AI processing",
  "system_prompt": "Optional system context"
}
```

`user_message` remains the workflow JSON input for AI steps. During execution, Data Machine normalizes it into a one-entry static `prompt_queue`, which is the storage shape AIStep consumes.

### Publish Steps
```json
{
  "step_type": "publish",
  "handler_slug": "handler_name",
  "handler_config": {
    "required_field": "value",
    "optional_field": "value"
  }
}
```

### Upsert Steps
```json
{
  "step_type": "update",
  "handler_slug": "handler_name",
  "handler_config": {
    "required_field": "value",
    "optional_field": "value"
  }
}
```

## Usage Patterns

### Basic Content Syndication
```json
{
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
      "user_message": "Summarize this content and make it engaging for social media"
    },
    {
      "step_type": "publish",
      "handler_slug": "twitter",
      "handler_config": {}
    }
  ]
}
```

### Content Enhancement
```json
{
  "steps": [
    {
      "step_type": "fetch",
      "handler_slug": "wordpress_local",
      "handler_config": {
        "post_type": "post",
        "posts_per_page": 5
      }
    },
    {
      "step_type": "ai",
      "user_message": "Update these posts with better SEO titles and meta descriptions"
    },
    {
      "step_type": "update",
      "handler_slug": "wordpress_update",
      "handler_config": {}
    }
  ]
}
```

### Multi-Platform Publishing
```json
{
  "steps": [
    {
      "step_type": "fetch",
      "handler_slug": "files",
      "handler_config": {
        "file_path": "/content/article.txt"
      }
    },
    {
      "step_type": "ai",
      "user_message": "Adapt this content for different social media platforms"
    },
    {
      "step_type": "publish",
      "handler_slug": "twitter",
      "handler_config": {}
    },
    {
      "step_type": "ai",
      "user_message": "Create a longer version for Facebook"
    },
    {
      "step_type": "publish",
      "handler_slug": "facebook",
      "handler_config": {}
    }
  ]
}
```

## Handler Configuration Examples

### WordPress Publish Handler
```json
{
  "step_type": "publish",
  "handler_slug": "wordpress",
  "handler_config": {
    "post_type": "post",
    "post_status": "publish",
    "post_author": 1,
    "taxonomy_category_selection": "ai_decides",
    "taxonomy_tags_selection": "Technology, AI"
  }
}
```

### Social Media Handlers
```json
{
  "step_type": "publish",
  "handler_slug": "twitter",
  "handler_config": {}
}
```

## Error Handling

The ExecuteWorkflow tool provides comprehensive error handling:

### Validation Errors
- Missing required fields
- Invalid handler slugs
- Incorrect configuration schemas
- Invalid step sequences

### Execution Errors
- Handler authentication failures
- Network connectivity issues
- API rate limiting
- Content processing errors

### Error Response Format
```json
{
  "success": false,
  "error": "Human-readable error message",
  "tool_name": "execute_workflow"
}
```

## Ability Integration

The tool delegates to the `datamachine/execute-workflow` ability:

```php
$ability = wp_get_ability( 'datamachine/execute-workflow' );
$result  = $ability->execute( array(
    'workflow' => array( 'steps' => $workflow_steps ),
    'dry_run'  => $dry_run, // optional
) );
```

The same ability backs the `POST /datamachine/v1/execute` REST endpoint, so the tool path and the REST path produce identical results. See [Execute endpoint](../api/endpoints/execute.md) and [Abilities API](../core-system/abilities-api.md).

## Handler Discovery

Dynamic handler discovery via WordPress filters:

```php
$handlers = apply_filters('datamachine_handlers', [], 'fetch');
$handlers = apply_filters('datamachine_handlers', [], 'publish');
$handlers = apply_filters('datamachine_handlers', [], 'update');
```

## Dynamic Documentation

The tool generates comprehensive documentation dynamically from registered handlers, ensuring AI agents always have current handler configuration information available.

## Security Features

### Input Sanitization
- All user inputs sanitized through WordPress functions
- Configuration validation against schemas
- Handler permission checks

### Execution Isolation
- Separate execution context for each workflow
- Error isolation between steps
- Secure handler communication

### Authentication Integration
- Handler-specific authentication requirements
- OAuth token validation
- API key management

## Extensibility

### Custom Handlers
New handlers automatically available to the tool through registration:

```php
add_filter('datamachine_handlers', function($handlers, $type) {
    $handlers['my_custom_handler'] = [
        'name' => 'My Custom Handler',
        'description' => 'Custom handler description',
        'requires_auth' => false
    ];
    return $handlers;
}, 10, 2);
```

## Performance Considerations

### Execution Efficiency
- Direct REST API delegation
- Minimal data transformation
- Streamlined validation

### Memory Management
- Efficient data structures
- Resource cleanup
- Single-pass processing
