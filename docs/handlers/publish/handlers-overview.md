# Publish Handlers Overview

Publish handlers distribute processed content to external platforms using the AI tool-calling architecture. Custom handlers extend the publish base class and implement `executePublish()`; the base class owns the final `handle_tool_call()` entry point.

## Available Handlers

### Content Platforms

**WordPress** (`wordpress_publish`)
- **Character Limit**: No limit
- **Authentication**: None (local installation)
- **Features**: Modular handler architecture with `WordPressPublishHelper`, `TaxonomyHandler`, `WordPressSettingsResolver`, configuration hierarchy, and storage-aware content format conversion
- **API**: WordPress core functions

## Source URL Attribution

**Purpose**: All publish handlers support automatic source URL attribution for link attribution and content sourcing.

**Engine Data Source**: `source_url` retrieved from fetch handlers via `datamachine_engine_data` filter

### Link Handling Modes

**Append Mode** (`link_handling: 'append'`):
- Default behavior for most handlers
- Source URL appended to content with platform-specific formatting

**None Mode** (`link_handling: 'none'`):
- No source URL processing
- Content posted as-is
- Available on all handlers

### Platform-Specific Implementation

| Platform | Separator | Character Count | Special Features |
|----------|-----------|-----------------|------------------|
| WordPress | Source content format | No limit | Converts content to the post type's stored format before insertion |

### Engine Data Access Pattern

```php
// Standard pattern across all handlers
$job_id = $parameters['job_id'] ?? null;
$engine_data = apply_filters('datamachine_engine_data', [], $job_id);
$source_url = $engine_data['source_url'] ?? null;
$image_url = $engine_data['image_url'] ?? null;

// Conditional URL appending based on link_handling setting
if ($link_handling === 'append' && !empty($source_url) && filter_var($source_url, FILTER_VALIDATE_URL)) {
    $content .= $platform_separator . $source_url;
}
```

## Tool-First Architecture

**Base Class Architecture** (@since v0.2.1):

All publish handlers extend [`PublishHandler`](../../../inc/Core/Steps/Publish/Handlers/PublishHandler.php), which provides:
- Engine data retrieval via `getEngineData()`, `getSourceUrl()`, `getImageFilePath()`
- Image validation via `validateImage()`
- `auth_ref` runtime resolution via `AuthRefHandlerConfig::resolve_runtime_config()`
- Dry-run preview handling
- Response formatting via `successResponse()` and `errorResponse()`
- Centralized logging via `log()`
- Standardized error handling

The base class provides `handle_tool_call()` as the final public entry point and calls the abstract `executePublish()` method in child classes. Do not override `handle_tool_call()` in extension handlers unless the base class is not being used.

### `handle_tool_call()` Interface

All publish handlers use the same tool interface:

```php
public function handle_tool_call(array $parameters, array $tool_def = []): array
```

Internally, the base class calls:
```php
abstract protected function executePublish(array $parameters, array $handler_config): array
```

**Parameters Structure**:
- `$parameters` - AI-provided parameters (content, etc.)
- `$tool_def` - Tool definition with handler configuration and handler slug

Before `executePublish()` runs, the base class:

- Requires `job_id`.
- Loads job engine data and injects an `EngineData` object as `$parameters['engine']`.
- Resolves portable `auth_ref` handler config into runtime credentials when present.
- Returns a dry-run preview when engine data contains `dry_run_mode`.

Post-origin tracking is centralized in [`ToolExecutor`](../../../inc/Engine/AI/Tools/ToolExecutor.php). Handler base classes and extensions should return an extractable post ID in the tool result; they should not call post-tracking helpers directly.

**Return Structure**:
```php
[
    'success' => true|false,
    'data' => [
        'platform_id' => 'published_content_id',
        'platform_url' => 'https://platform.com/content/id',
        'content' => 'final_published_content'
    ],
    'error' => 'error_message', // Only if success = false
    'tool_name' => 'handler_tool_name'
]
```

### AI Tool Registration

Each handler registers its tool through the unified `datamachine_tools` registry. The preferred path is [`HandlerRegistrationTrait::registerHandler()`](../../../inc/Core/Steps/HandlerRegistrationTrait.php), which wires the callback for you. The equivalent manual registration shape is:

```php
add_filter('datamachine_tools', function($tools) {
    $tools['__handler_tools_wordpress_publish'] = [
        '_handler_callable' => function($handler_slug, $handler_config, $engine_data) {
            return [
                'wordpress_publish' => [
                    'class'          => 'DataMachine\\Core\\Steps\\Publish\\Handlers\\WordPress\\WordPress',
                    'method'         => 'handle_tool_call',
                    'handler'        => $handler_slug,
                    'description'    => 'Publish content to WordPress',
                    'parameters'     => [
                        'content' => [
                            'type'        => 'string',
                            'required'    => true,
                            'description' => 'Content to publish',
                        ],
                    ],
                    'handler_config' => $handler_config,
                ],
            ];
        },
        'handler'      => 'wordpress_publish',
        'modes'        => ['pipeline'],
        'access_level' => 'admin',
    ];
    return $tools;
});
```

Handler tool entries are deferred registry entries, not directly executable tools. `ToolManager::resolveHandlerTools()` resolves entries whose `handler` exactly matches the adjacent step handler slug. Cross-cutting tools may use `handler_types`, for example `['fetch', 'event_import']`, which matches against `datamachine_handlers` metadata.

Callback conventions supported by `ToolManager`:

- **Direct-style** callbacks receive `($handler_slug, $handler_config, $engine_data)` and return `['tool_name' => $tool_definition]`.
- **Filter-style** callbacks receive `($tools, $handler_slug, $handler_config, $engine_data)` and return the updated tool map.

The resolver detects filter-style callbacks by reflection: callbacks with four or more parameters, or a first parameter named `$tools` or `$all_tools`, are invoked with the filter-style shape. New code should prefer direct-style callbacks unless it is migrating an existing filter-style tool builder.

## Common Features

### Handler Configuration

**Configuration Structure**:
```php
$handler_config = [
    'setting1' => 'value1',
    'enable_feature' => true,
    'platform_specific_option' => 'option_value',
];
```

**Tool Definition Integration**:
```php
$handler_config = $tool_def['handler_config'] ?? [];
```

Handler config is flat at runtime. `AuthRefHandlerConfig::resolve_runtime_config()` may merge local credentials into that flat config before `executePublish()` receives it.

### Content Processing

**Character Limits**:
- Automatic truncation with ellipsis (...)
- Multi-byte string handling (UTF-8)

**Media Handling**:
- Image upload from URLs
- Format validation (JPEG, PNG, GIF, WebP)
- Accessibility checks before download
- Chunked upload for large files

### URL Handling

**Source URL Options**:
- Append to content (default)
- Include/exclude based on configuration

**URL Processing**:
- Validation via `filter_var(FILTER_VALIDATE_URL)`
- Automatic shortening (platform-specific)

## Authentication Systems

### OAuth 2.0 (Google)

**Required Credentials**:
- Client ID
- Client Secret
- Access Token (obtained via OAuth flow)
- Refresh Token (automatic renewal)

**OAuth Flow**:
1. Authorization URL generation
2. User authorization via popup
3. Token exchange
4. Token storage and refresh

## Error Handling Patterns

### Parameter Validation

```php
if (empty($parameters['content'])) {
    return [
        'success' => false,
        'error' => 'Missing required content parameter',
        'tool_name' => 'handler_publish'
    ];
}
```

### Authentication Errors

```php
$connection = $this->auth->get_connection();
if (is_wp_error($connection)) {
    return [
        'success' => false,
        'error' => 'Authentication failed: ' . $connection->get_error_message(),
        'tool_name' => 'handler_publish'
    ];
}
```

### API Errors

```php
if ($http_code !== 200) {
    do_action('datamachine_log', 'error', 'Platform API error', [
        'http_code' => $http_code,
        'response' => $api_response
    ]);

    return [
        'success' => false,
        'error' => 'Platform API error: ' . $error_message,
        'tool_name' => 'handler_publish'
    ];
}
```

## Platform-Specific Features

### WordPress

**Unique Features**:
- Modular handler architecture with specialized components
- `WordPressPublishHelper` for media attachment and source attribution
- `TaxonomyHandler` with configuration-based processing (skip, AI-decided, pre-selected)
- `WordPressSettingsResolver` for configuration hierarchy
- `content_format` support for markdown, HTML, or serialized block source content
- Storage-format-aware conversion through the post type's canonical `post_content` format
- Configuration hierarchy (system defaults override handler config)
- Post status control (draft, publish, private)
- Author assignment

### Google Sheets

**Unique Features**:
- Row-based data insertion
- Cell targeting and updates
- Spreadsheet creation
- Formula support

## Multi-Platform Workflows

### AI→Publish Pattern

```php
// Pipeline configuration
$pipeline_steps = [
    'fetch_step' => ['handler' => 'rss'],
    'ai_step' => ['handler' => null],
    'publish_step' => ['handler' => 'wordpress_publish']
];
```

**Benefits**:
- AI-optimized content generation
- Consistent publishing workflow
- Configurable output formats

## Performance Considerations

### Media Upload Optimization

**Image Processing**:
- HEAD requests validate accessibility
- Progressive fallbacks (simple→chunked upload)
- Temporary file cleanup
- Format conversion where needed

**Network Efficiency**:
- Connection reuse across requests
- Parallel uploads not implemented (sequential for reliability)
- Timeout handling with WordPress HTTP API defaults

### API Rate Limiting

**Platform Limits**:
- Google: 100 requests per 100 seconds per user

**Handling Strategy**:
- Single request per publish operation
- Error logging for rate limit hits
- No automatic retry (relies on Action Scheduler)

## Extension Development

### Custom Publish Handler

```php
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;

class CustomPublishHandler extends PublishHandler {
    public function __construct() {
        parent::__construct('custom_platform');
    }

    protected function executePublish(array $parameters, array $handler_config): array {
        // Validate parameters
        if (empty($parameters['content'])) {
            return [
                'success' => false,
                'error' => 'Missing content parameter',
                'tool_name' => 'custom_publish'
            ];
        }

        $custom_config = $handler_config;
        $engine = $parameters['engine'];
        $source_url = $engine->getSourceUrl();

        // Publish to platform
        try {
            $result = $this->publish_to_platform($parameters['content'], $custom_config, $source_url);

            return [
                'success' => true,
                'data' => [
                    'platform_id' => $result['id'],
                    'platform_url' => $result['url'],
                    'content' => $parameters['content']
                ],
                'tool_name' => 'custom_publish'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'tool_name' => 'custom_publish'
            ];
        }
    }
}
```

### Tool Registration

```php
add_filter('datamachine_tools', function($tools) {
    $tools['__handler_tools_custom_platform'] = [
        '_handler_callable' => function($handler_slug, $handler_config, $engine_data) {
            return [
                'custom_publish' => [
                    'class'          => 'CustomPublishHandler',
                    'method'         => 'handle_tool_call',
                    'handler'        => $handler_slug,
                    'description'    => 'Publish content to custom platform',
                    'parameters'     => [
                        'content' => [
                            'type'        => 'string',
                            'required'    => true,
                            'description' => 'Content to publish',
                        ],
                    ],
                    'handler_config' => $handler_config,
                ],
            ];
        },
        'handler'      => 'custom_platform',
        'modes'        => ['pipeline'],
        'access_level' => 'admin',
    ];
    return $tools;
});
```

## Core vs Extension Boundaries

- Core owns `PublishHandler::handle_tool_call()`, engine data loading, dry-run previews, `auth_ref` resolution, tool execution, and centralized post-origin tracking.
- Extensions own platform-specific API clients, settings classes, auth providers, and `executePublish()` implementation details.
- Extensions should return standard tool results and allow `ToolExecutor` to apply post tracking when a post ID is present.
- Extensions should register handler tools as deferred `_handler_callable` entries and let `ToolManager` resolve exact-slug or `handler_types` matches at pipeline runtime.
