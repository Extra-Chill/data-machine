# Data Machine REST API

Complete REST API reference for Data Machine

## Overview

**Base URL**: `/wp-json/datamachine/v1/`

**Authentication**: WordPress application password or cookie authentication

**Permissions**: Most endpoints require `manage_options` capability

**Implementation**: All endpoints are implemented in `data-machine/inc/Api/`. The project is migrating business logic to the WordPress 6.9 Abilities API; REST handlers should prefer calling abilities (via `wp_get_ability()` / `wp_ability_execute`) where available. Service managers may still be instantiated as a transitional implementation during migration.

## Endpoint Categories

### Workflow Execution
- [Execute](endpoints/execute.md): Trigger flows and ephemeral workflows
- [Scheduling Intervals](endpoints/intervals.md): Available scheduling intervals and configuration

### Pipeline & Flow Management
- [Pipelines](endpoints/pipelines.md)
- [Flows](endpoints/flows.md)
- [Jobs](endpoints/jobs.md)

### Content & Data
- [Files](endpoints/files.md)
- [Processed Items](endpoints/processed-items.md)

### AI & Chat
- [Chat](endpoints/chat.md)
- [Chat Sessions](endpoints/chat-sessions.md)
- [Handlers](endpoints/handlers.md)
- [Providers](endpoints/providers.md)
- [Tools](endpoints/tools.md)

### Configuration
- [Settings](endpoints/settings.md)
- [Users](endpoints/users.md)
- [Auth](endpoints/auth.md)
- [Step Types](endpoints/step-types.md)
- [System](endpoints/system.md)

### Monitoring
- [Logs](endpoints/logs.md)
- [AI Directives](../core-system/ai-directives.md)
- [Jobs](endpoints/jobs.md)

## Common Patterns

### Authentication

Data Machine supports two authentication methods:

1. **Application Password** (Recommended for external integrations)
2. **Cookie Authentication** (WordPress admin sessions)

See [Authentication](endpoints/authentication.md).

### Error Handling

All endpoints return standardized error responses following WordPress REST API conventions. Common error codes include:

- `rest_forbidden` (403) - Insufficient permissions
- `rest_invalid_param` (400) - Invalid parameters
- Resource-specific errors (404, 500)

See [Error Handling Reference](endpoints/errors.md) for complete error code documentation.

### Pagination

Endpoints returning lists support pagination parameters:
- `per_page` - Number of items per page
- `offset` or `page` - Pagination offset

## Implementation Guide

All endpoints are implemented in `data-machine/inc/Api/` using the services layer architecture for direct method calls, with automatic registration via `rest_api_init`: 

```php
// Example endpoint registration using services layer
register_rest_route('datamachine/v1', '/pipelines', [
    'methods' => 'GET',
    'callback' => [Pipelines::class, 'get_pipelines'],
    'permission_callback' => [Pipelines::class, 'check_permission']
]);

// Abilities API usage in endpoint callbacks
public function create_pipeline($request) {
    $ability = wp_get_ability( 'datamachine/create-pipeline' );
    return $ability->execute( [
        'pipeline_name' => $request['name'],
        'options'       => $request['options'] ?? [],
    ] );
}
```

For detailed implementation patterns, see the [Development](../development/) section for hooks and extension guides.

## Related Documentation

- [Authentication](endpoints/authentication.md)
- [Errors](endpoints/errors.md)
- [Engine Execution](../core-system/engine-execution.md)
- [Settings](endpoints/settings.md)
- [Development Guides](../development/) - Extension development and hooks

---

**API Version**: v1
**Last Updated**: 2026-01-18
