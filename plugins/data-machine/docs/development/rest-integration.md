# REST API Extensions Guide

This guide explains how Data Machine extensions integrate with the REST API ecosystem, covering both filter-based integration (preferred) and custom REST endpoint patterns.

## Integration Philosophy

**Core Principle**: Extensions primarily consume Data Machine's core REST endpoints via filter-based handler registration rather than implementing custom REST APIs.

**Two Integration Paths**:
1. **Filter-Based Integration** - Register handlers that use core REST endpoints (recommended)
2. **Custom REST Endpoints** - Create extension-specific endpoints when needed (rare)

## Pattern 1: Filter-Based Integration (Recommended)

### When to Use

Use filter-based integration when your extension:
- Provides handlers for Data Machine pipelines (fetch, publish, upsert)
- Adds AI tools to existing workflows
- Extends handler types with new capabilities
- Integrates with Data Machine's execution engine

**Extensions Using This Pattern**:
- DM Recipes (recipe publishing)
- DM Structured Data (semantic analysis)
- DM Multisite (network-wide AI tools)

### Handler Registration Pattern

Extensions register handlers via WordPress filters - no custom REST endpoints needed.

```php
// Register publish handler
add_filter('datamachine_handlers', function($handlers) {
    $handlers['recipe'] = [
        'type' => 'publish',
        'class' => 'DMRecipes\\Handler\\RecipeHandler',
        'label' => __('Recipe', 'dm-recipes'),
        'description' => __('Publish recipes with Schema.org structured data', 'dm-recipes')
    ];
    return $handlers;
});
```

### Handler Implementation

Handlers implement `handle_tool_call()` for AI-driven execution via core REST endpoints:

```php
namespace DMRecipes\Handler;

class RecipeHandler {
    /**
     * Handle recipe publishing via AI tool call
     * Automatically invoked by /datamachine/v1/execute endpoint
     */
    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        $job_id = $parameters['job_id'] ?? null;
        $handler_config = $tool_def['handler_config'] ?? [];

        // Access engine data via centralized filter
        $engine_data = apply_filters('datamachine_engine_data', [], $job_id);
        $source_url = $engine_data['source_url'] ?? null;
        $image_url = $engine_data['image_url'] ?? null;

        // Publish recipe using WordPress functions
        $post_id = $this->create_recipe_post($parameters, $handler_config);

        return [
            'success' => true,
            'data' => [
                'id' => $post_id,
                'url' => get_permalink($post_id)
            ],
            'tool_name' => 'recipe_publish'
        ];
    }

    private function create_recipe_post($parameters, $config) {
        // Recipe publishing logic
        // No custom REST endpoint needed
        // Uses WordPress core functions
    }
}
```

### Core REST Endpoint Integration

**Handler automatically uses**:
```
POST /datamachine/v1/execute
```

**Flow execution**:
1. User triggers flow via admin or REST API
2. Data Machine Engine calls handler's `handle_tool_call()` method
3. Handler returns success/failure response
4. Engine processes next step or completes flow

**No custom REST endpoints required** - handlers integrate seamlessly with core execution engine.

## Pattern 2: Custom REST Endpoints (When Needed)

### When to Use

Create custom REST endpoints when your extension:
- Provides frontend-accessible functionality independent of pipelines
- Needs public-facing data APIs
- Offers extension-specific data access separate from Data Machine flows

**Extensions Using This Pattern**:
- DM Events (public calendar filtering)

### DM Events Calendar Pattern (Reference Implementation)

**Namespace Convention**: Use `datamachine-{extension}` for consistency

```php
namespace DmEvents\Core;

class DMEventsRestApi {
    public static function register() {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route('datamachine-events/v1', '/calendar', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_calendar_events'],
            'permission_callback' => '__return_true', // Public endpoint
            'args' => [
                'event_search' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'date_start' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'date_end' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'tax_filter' => [
                    'type' => 'object'
                ],
                'paged' => [
                    'type' => 'integer',
                    'default' => 1,
                    'sanitize_callback' => 'absint'
                ],
                'past' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);
    }

    public static function get_calendar_events($request) {
        // Server-side SQL filtering
        $query_args = [
            'post_type' => 'datamachine_events',
            'post_status' => 'publish',
            'posts_per_page' => get_option('posts_per_page', 10),
            'paged' => $request->get_param('paged'),
            'meta_key' => '_datamachine_event_datetime',
            'orderby' => 'meta_value',
            'order' => 'ASC'
        ];

        // Add filtering logic
        $events_query = new \WP_Query($query_args);

        // Return structured response
        return [
            'success' => true,
            'events' => $events_query->posts,
            'pagination' => [
                'total_pages' => $events_query->max_num_pages,
                'current_page' => $request->get_param('paged')
            ]
        ];
    }
}

// Register in main plugin file
DMEventsRestApi::register();
```

## Authentication Patterns

### Public Endpoints (DM Events Pattern)

**Use For**: Frontend-accessible data, public calendars, read-only APIs

```php
'permission_callback' => '__return_true'
```

**Example**:
```php
register_rest_route('datamachine-events/v1', '/calendar', [
    'methods' => 'GET',
    'callback' => [self::class, 'get_calendar_events'],
    'permission_callback' => '__return_true', // Anyone can access
]);
```

### Admin-Only Endpoints (Core Pattern)

**Use For**: Pipeline management, settings, administrative operations

```php
'permission_callback' => function() {
    return current_user_can('manage_options');
}
```

**Example**:
```php
register_rest_route('datamachine/v1', '/pipelines', [
    'methods' => 'POST',
    'callback' => [self::class, 'create_pipeline'],
    'permission_callback' => function() {
        return current_user_can('manage_options');
    }
]);
```

### User-Scoped Endpoints (Planned for Data Machine Theme)

**Use For**: User dashboards, personal preferences, user-specific data

```php
'permission_callback' => function() {
    return is_user_logged_in();
}
```

**Example**:
```php
register_rest_route('datamachine/v1', '/users/me', [
    'methods' => 'GET',
    'callback' => [self::class, 'get_current_user_preferences'],
    'permission_callback' => function() {
        return is_user_logged_in(); // Any logged-in user
    }
]);
```

### Custom Permission Logic

**Use For**: Role-based access, ownership validation, complex authorization

```php
'permission_callback' => function($request) {
    $flow_id = $request->get_param('flow_id');

    // Check if user owns the flow or is admin
    if (current_user_can('manage_options')) {
        return true;
    }

    $flow_owner = get_post_meta($flow_id, '_owner_id', true);
    return get_current_user_id() === (int) $flow_owner;
}
```

## Integration Examples

### Example 1: DM Recipes (Filter-Based)

**No Custom REST Endpoints**:

```php
// File: dm-recipes/inc/handler/recipe-handler.php

add_filter('datamachine_handlers', function($handlers) {
    $handlers['recipe'] = [
        'type' => 'publish',
        'class' => 'DMRecipes\\Handler\\RecipeHandler',
        'label' => __('Recipe', 'dm-recipes')
    ];
    return $handlers;
});

class RecipeHandler {
    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        // Recipe publishing logic
        // Integrates with /datamachine/v1/execute automatically
        return ['success' => true, 'data' => ['id' => $post_id]];
    }
}
```

**Integration Flow**:
1. User creates Data Machine flow with Recipe handler
2. Flow execution triggers via `/datamachine/v1/execute`
3. Engine calls `RecipeHandler::handle_tool_call()`
4. Recipe published to WordPress
5. Flow continues to next step

**Benefits**:
- Zero custom REST code
- Automatic integration with Data Machine Engine
- Consistent execution patterns
- Simplified architecture

### Example 2: DM Events Calendar (Custom Endpoint)

**Custom REST Endpoint Required**:

```php
// File: dm-events/inc/core/rest-api.php

register_rest_route('datamachine-events/v1', '/calendar', [
    'methods' => 'GET',
    'callback' => 'datamachine_events_calendar_endpoint',
    'permission_callback' => '__return_true', // Public access
    'args' => [
        'date_start' => ['type' => 'string'],
        'date_end' => ['type' => 'string'],
        'venue' => ['type' => 'integer'],
        'paged' => ['type' => 'integer', 'default' => 1]
    ]
]);
```

**Frontend Integration**:
```javascript
// Public calendar filtering
const params = new URLSearchParams({
    date_start: '2024-01-01',
    date_end: '2024-12-31',
    venue: 42,
    paged: 1
});

const response = await fetch(`/wp-json/datamachine-events/v1/calendar?${params}`);
const data = await response.json();

// Update calendar display
renderCalendar(data.events);
```

**Use Case**: Public calendar filtering independent of Data Machine pipelines

### Example 3: DM Structured Data (Filter-Based)

**Handler Registration Only**:

```php
// File: dm-structured-data/inc/handler/semantic-analysis-handler.php

add_filter('datamachine_handlers', function($handlers) {
    $handlers['semantic_analysis'] = [
        'type' => 'update',
        'class' => 'DMStructuredData\\Handler\\SemanticAnalysisHandler',
        'label' => __('Semantic Analysis', 'dm-structured-data')
    ];
    return $handlers;
});

class SemanticAnalysisHandler {
    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        // AI-powered semantic analysis
        // Updates existing WordPress content
        // Uses /datamachine/v1/execute endpoint
        return ['success' => true, 'data' => ['analyzed' => true]];
    }
}
```

**Integration**: Automatically uses core REST API - no custom endpoints needed

## Extension Best Practices

### 1. Prefer Core Endpoints

**Do**: Use Data Machine core REST API via filter registration
```php
add_filter('datamachine_handlers', function($handlers) {
    $handlers['my_handler'] = [/* handler config */];
    return $handlers;
});
```

**Don't**: Create custom REST endpoints unless absolutely necessary
```php
// Only when providing public-facing functionality separate from pipelines
register_rest_route('my-extension/v1', '/custom', [/* ... */]);
```

### 2. Namespace Consistency

**Pattern**: `datamachine-{extension}` for REST namespaces

**Examples**:
- `datamachine-events/v1`
- `datamachine-recipes/v1`
- `datamachine-analytics/v1` (hypothetical)

**Don't Use**:
- `dm-events/v1` (inconsistent with REST conventions)
- `events/v1` (too generic, potential conflicts)

### 3. Progressive Enhancement

Make REST endpoints work with/without JavaScript:

```javascript
// History API for shareable URLs
const url = new URL(window.location);
url.searchParams.set('filter', value);
window.history.pushState({}, '', url);

// Server-side fallback
// Calendar renders on page load with URL parameters
```

### 4. Server-Side Logic

Move filtering/processing to server for performance:

**DM Events Example**:
```php
// Server-side SQL filtering
$meta_query = [
    [
        'key' => '_datamachine_event_datetime',
        'value' => [$date_start, $date_end],
        'compare' => 'BETWEEN',
        'type' => 'DATETIME'
    ]
];

$events_query = new WP_Query([
    'meta_query' => $meta_query,
    // ... other args
]);
```

**Benefits**:
- Database-level optimization
- Reduced client-side bundle size
- Faster response times
- Single source of truth

### 5. Document Migrations

When adding REST endpoints, update documentation:

**CLAUDE.md**:
```markdown
## REST API Integration

DM MyExtension uses Data Machine core REST endpoints via filter-based handler registration.

Handler integrates with `/datamachine/v1/execute` automatically through the `datamachine_handlers` filter pattern.
```

**Extension README**:
```markdown
## API Integration

This extension integrates with Data Machine's REST API for seamless workflow automation.

No custom REST endpoints required - handlers register via WordPress filters.
```

## Comparison: Filter-Based vs Custom Endpoints

### Filter-Based Integration

**Advantages**:
- Zero REST code required
- Automatic Data Machine Engine integration
- Consistent execution patterns
- Simplified maintenance
- Follows single responsibility principle

**Use Cases**:
- Publish handlers (DM Recipes, DM Events publish)
- Update handlers (DM Structured Data)
- Fetch handlers (custom data sources)
- AI tools (semantic analysis, data extraction)

**Example Extensions**:
- DM Recipes
- DM Structured Data
- DM Multisite

### Custom REST Endpoints

**Advantages**:
- Public API access
- Independent of pipeline execution
- Frontend-driven functionality
- Direct data access

**Use Cases**:
- Public calendar filtering (DM Events)
- Frontend data displays
- Standalone extension features
- Third-party integrations

**Example Extensions**:
- DM Events (calendar endpoint)

## REST API Integration

Extensions should use REST API endpoints with proper authentication.

**Implementation Path**:
1. Create REST endpoint with proper authentication
2. Implement server-side logic
3. Update frontend to use fetch()
4. Add progressive enhancement
6. Remove AJAX files
7. Update documentation



## Error Handling

### Standard WordPress REST Error Format

```php
return new WP_Error(
    'operation_failed',
    __('Operation failed description', 'extension-textdomain'),
    ['status' => 400]
);
```

### HTTP Status Codes

**Success**:
- `200 OK` - Successful operation
- `201 Created` - Resource created

**Client Errors**:
- `400 Bad Request` - Invalid parameters
- `403 Forbidden` - Insufficient permissions
- `404 Not Found` - Resource not found

**Server Errors**:
- `500 Internal Server Error` - Server-side failure

### Client-Side Error Handling

```javascript
try {
    const response = await fetch('/wp-json/datamachine-events/v1/calendar');

    if (!response.ok) {
        const error = await response.json();
        console.error(`Error ${error.code}: ${error.message}`);
        return;
    }

    const data = await response.json();
    // Handle success
} catch (error) {
    console.error('Network error:', error);
}
```

## Related Documentation

- REST API Reference - Complete core endpoint documentation

- Core Filters - WordPress filter hooks for handler registration
- Core Actions - WordPress action hooks for execution

## Extension Development Guidelines

### Creating a New Extension

**Step 1: Choose Integration Pattern**
- Does your extension provide Data Machine handlers? → Use filter-based integration
- Does your extension need public-facing APIs? → Consider custom REST endpoints

**Step 2: Implement Handler (Filter-Based)**
```php
add_filter('datamachine_handlers', function($handlers) {
    $handlers['your_handler'] = [
        'type' => 'publish', // or 'fetch', 'update'
        'class' => 'YourExtension\\Handler\\YourHandler',
        'label' => __('Your Handler', 'your-extension')
    ];
    return $handlers;
});
```

**Step 3: Implement REST Endpoint (If Needed)**
```php
register_rest_route('datamachine-yourextension/v1', '/endpoint', [
    'methods' => 'GET',
    'callback' => [self::class, 'handle_request'],
    'permission_callback' => '__return_true'
]);
```

**Step 4: Document Integration**
- Update extension CLAUDE.md
- Add REST API examples to README
- Document authentication requirements

**Step 5: Follow Ecosystem Patterns**
- Use `datamachine-{extension}` namespace
- Implement proper authentication
- Return structured JSON responses
- Add comprehensive error handling
