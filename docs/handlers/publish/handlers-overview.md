# Publish Handlers Overview

Publish handlers distribute processed content to external platforms using AI tool calling architecture. All handlers implement the `handle_tool_call()` method for agentic execution.

## Available Handlers

### Content Platforms

**WordPress** (`wordpress_publish`)
- **Character Limit**: No limit
- **Authentication**: None (local installation)
- **Features**: Modular handler architecture with `WordPressPublishHelper`, `TaxonomyHandler`, `WordPressSettingsResolver`, configuration hierarchy, Gutenberg blocks
- **API**: WordPress core functions

**Google Sheets** (`googlesheets_output`)
- **Character Limit**: No limit
- **Authentication**: OAuth2 (Google)
- **Features**: Row insertion, cell targeting, spreadsheet creation
- **API**: Google Sheets API

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
| WordPress | Gutenberg blocks | No limit | Source attribution blocks |
| Google Sheets | Cell-based | No limit | Data rows |

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

All publish handlers extend `PublishHandler` base class (`/inc/Core/Steps/Publish/Handlers/PublishHandler.php`) which provides:
- Engine data retrieval via `getEngineData()`, `getSourceUrl()`, `getImageFilePath()`
- Image validation via `validateImage()`
- Response formatting via `successResponse()` and `errorResponse()`
- Centralized logging via `log()`
- Standardized error handling

The base class provides `handle_tool_call()` as the final public entry point, calling abstract `executePublish()` method in child classes.

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
- `$tool_def` - Tool definition with handler configuration

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

Each handler registers its tool via filters:

```php
add_filter('chubes_ai_tools', function($tools, $handler_slug = null, $handler_config = []) {
    if ($handler_slug === 'wordpress_publish') {
        $tools['wordpress_publish'] = [
            'class' => 'DataMachine\\Core\\Steps\\Publish\\Handlers\\WordPress\\WordPress',
            'method' => 'handle_tool_call',
            'handler' => 'wordpress_publish',
            'description' => 'Publish content to WordPress',
            'parameters' => [
                'content' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Content to publish'
                ]
            ],
            'handler_config' => $handler_config
        ];
    }
    return $tools;
}, 10, 3);
```

## Common Features

### Handler Configuration

**Configuration Structure**:
```php
$handler_config = [
    'handler_name' => [
        'setting1' => 'value1',
        'enable_feature' => true,
        'platform_specific_option' => 'option_value'
    ]
];
```

**Tool Definition Integration**:
```php
$handler_config = $tool_def['handler_config'] ?? [];
$platform_config = $handler_config['platform_name'] ?? $handler_config;
```

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
- Automatic Gutenberg block generation for source attribution
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
class CustomPublishHandler {
    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        // Validate parameters
        if (empty($parameters['content'])) {
            return [
                'success' => false,
                'error' => 'Missing content parameter',
                'tool_name' => 'custom_publish'
            ];
        }

        // Get configuration
        $handler_config = $tool_def['handler_config'] ?? [];
        $custom_config = $handler_config['custom_platform'] ?? [];

        // Publish to platform
        try {
            $result = $this->publish_to_platform($parameters['content'], $custom_config);

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
add_filter('chubes_ai_tools', function($tools, $handler_slug = null, $handler_config = []) {
    if ($handler_slug === 'custom_platform') {
        $tools['custom_publish'] = [
            'class' => 'CustomPublishHandler',
            'method' => 'handle_tool_call',
            'handler' => 'custom_platform',
            'description' => 'Publish content to custom platform',
            'parameters' => [
                'content' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Content to publish'
                ]
            ],
            'handler_config' => $handler_config
        ];
    }
    return $tools;
}, 10, 3);
```
