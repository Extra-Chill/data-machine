# WordPress Publish Handler

Creates posts in the local WordPress installation using a modular handler architecture with specialized processing components for featured images, taxonomies, and source URLs.

## Architecture

**Base Class**: Extends PublishHandler (@since v0.2.1)

**Inherited Functionality**:
- Engine data retrieval via `getSourceUrl()` and `getImageFilePath()`
- Image validation via `validateImage()` with comprehensive error checking
- Standardized responses via `successResponse()` and `errorResponse()`
- Centralized logging and error handling

**Implementation**: Tool-first architecture via `handle_tool_call()` method for AI agents

### Handler Components

**Main Handler** (`WordPress.php`):
- Extends `PublishHandler` base class
- Implements `executePublish()` method for WordPress-specific logic
- Coordinates specialized component processing
- Uses configuration hierarchy (system defaults override handler settings)

**Specialized Components**:
- **`WordPressPublishHelper`**: Platform-specific publishing operations (featured images, source attribution)
- **`TaxonomyHandler`**: Dynamic taxonomy assignment with configuration-based selection
- **`WordPressSettingsResolver`**: Configuration resolution with system defaults override

## Featured Image Processing (@since v0.2.7)

**Implementation**: `WordPressPublishHelper::attachImageToPost()` static method

**Purpose**: Attaches images from Files Repository to WordPress posts as featured images with media library integration.

### Configuration

Configuration checked via `include_images` setting:

```php
// WordPressPublishHelper checks configuration
$attachment_id = WordPressPublishHelper::attachImageToPost($post_id, $image_path, $config);
```

### Features

**Image Processing**:
- File path validation with `file_exists()`
- Image type validation with `wp_check_filetype()`
- Media library integration with `media_handle_sideload()`
- Featured image assignment with `set_post_thumbnail()`

**Error Handling**:
- Missing file detection
- Invalid image type rejection
- Attachment creation error handling
- Temporary file cleanup
- Comprehensive logging throughout process

**Usage Example**:
```php
use DataMachine\Core\EngineData;
use DataMachine\Core\WordPress\WordPressPublishHelper;

// Get image path from EngineData
$engine = new EngineData($engine_data, $job_id);
$image_path = $engine->getImagePath();

// Attach image using helper
$attachment_id = WordPressPublishHelper::attachImageToPost($post_id, $image_path, $config);

// Returns: attachment ID (int) or null on failure
```

## TaxonomyHandler

**Purpose**: Configuration-based taxonomy processing with AI-decided and pre-selected term assignment.

### Three Selection Modes

**Per Taxonomy Configuration**:
1. **`'skip'`**: No processing for this taxonomy
2. **`'ai_decides'`**: Use AI-provided parameters for dynamic assignment
3. **Term name or slug**: Pre-selected term assignment (resolved to ID automatically)

### Configuration Format

```php
// Handler configuration per taxonomy
$handler_config = [
    'taxonomy_category_selection' => 'ai_decides',        // AI decides categories
    'taxonomy_post_tag_selection' => 'skip',              // Skip tags processing
    'taxonomy_custom_tax_selection' => 'Technology'       // Pre-selected by term name
];
```

**Note**: Pre-selected values accept term name, slug, or numeric ID. The backend resolves names/slugs to term IDs automatically.

### AI Parameter Mapping

**Standard Parameter Names**:
- `category` → 'category' taxonomy
- `tags` → 'post_tag' taxonomy
- Custom taxonomy name → corresponding taxonomy

### Features

**Dynamic Term Creation**:
- Checks term existence with `get_term_by()`
- Creates missing terms with `wp_insert_term()`
- Assigns terms with `wp_set_object_terms()`

**Taxonomy Discovery**:
- Uses `get_taxonomies(['public' => true], 'objects')`
- Excludes system taxonomies: `post_format`, `nav_menu`, `link_category`

**Validation**:
- Taxonomy existence verification with `taxonomy_exists()`
- Term validation and error handling
- Comprehensive result tracking per taxonomy

**Usage Example**:
```php
$taxonomy_handler = new TaxonomyHandler();
$results = $taxonomy_handler->processTaxonomies($post_id, $parameters, $handler_config);

// AI-provided parameters
$parameters = [
    'category' => 'Technology',
    'tags' => ['AI', 'Machine Learning'],
    'custom_taxonomy' => 'Custom Term'
];

// Result structure
[
    'category' => [
        'success' => true,
        'taxonomy' => 'category',
        'term_count' => 1,
        'terms' => ['Technology']
    ],
    'post_tag' => [
        'success' => true,
        'taxonomy' => 'post_tag',
        'term_count' => 2,
        'terms' => ['AI', 'Machine Learning']
    ]
]
```

## Source URL Attribution (@since v0.2.7)

**Implementation**: `WordPressPublishHelper::applySourceAttribution()` static method

**Purpose**: Appends source URLs in the declared source content format, then lets the content-format boundary convert once to the post type's stored format.

**Engine Data Source**: `source_url` retrieved from fetch handlers via `datamachine_engine_data` filter

### Configuration

Configuration checked via `link_handling` setting:

```php
// WordPressPublishHelper checks configuration
$content = WordPressPublishHelper::applySourceAttribution($content, $source_url, $config);
```

### Features

**Source Processing**:
- URL validation with `filter_var($url, FILTER_VALIDATE_URL)`
- URL sanitization with `esc_url()`
- Format-aware attribution for `html`, `markdown`, and `blocks`
- Single conversion through `DataMachine\Core\Content\ContentFormat` before `wp_insert_post()`

**Usage Example**:
```php
use DataMachine\Core\EngineData;
use DataMachine\Core\WordPress\WordPressPublishHelper;

// Get source URL from EngineData
$engine = new EngineData($engine_data, $job_id);
$source_url = $engine->getSourceUrl();

// Apply source attribution using helper
$content = WordPressPublishHelper::applySourceAttribution($content, $source_url, $config);

// Returns: modified content with source attribution (if configured)
```

## Main Handler Integration

### Tool Call Workflow (@since v0.2.7)

The WordPress handler extends `PublishHandler` and implements the `executePublish()` method:

```php
use DataMachine\Core\EngineData;
use DataMachine\Core\WordPress\WordPressPublishHelper;
use DataMachine\Core\WordPress\TaxonomyHandler;
use DataMachine\Core\WordPress\WordPressSettingsResolver;

class WordPress extends PublishHandler {
    private $taxonomy_handler;

    public function __construct() {
        parent::__construct('wordpress');
        $this->taxonomy_handler = new TaxonomyHandler();
    }

    protected function executePublish(array $parameters, array $handler_config): array {
        // 1. Validate required parameters
        if (empty($parameters['title']) || empty($parameters['content'])) {
            return $this->errorResponse('Missing required parameters');
        }

        // 2. Create EngineData instance for data access
        $job_id = $parameters['job_id'] ?? null;
        $engine = new EngineData($parameters['engine_data'] ?? [], $job_id);

        // 3. Get source URL and apply source attribution using helper
        $source_url = $engine->getSourceUrl();
        $content = WordPressPublishHelper::applySourceAttribution(
            $parameters['content'],
            $source_url,
            $handler_config
        );

        // 4. Resolve configuration with system defaults
        $resolver = new WordPressSettingsResolver();
        $post_status = $resolver->resolvePostStatus($handler_config);
        $post_author = $resolver->resolvePostAuthor($handler_config);

        // 5. Create WordPress post
        $post_data = [
            'post_title' => sanitize_text_field($parameters['title']),
            'post_content' => $content,
            'post_status' => $post_status,
            'post_type' => $handler_config['post_type'],
            'post_author' => $post_author
        ];

        $post_id = wp_insert_post($post_data);

        // 6. Process taxonomies
        $taxonomy_results = $this->taxonomy_handler->processTaxonomies(
            $post_id, 
            $parameters, 
            $handler_config
        );

        // 7. Attach featured image using helper
        $image_path = $engine->getImagePath();
        $attachment_id = WordPressPublishHelper::attachImageToPost(
            $post_id, 
            $image_path, 
            $handler_config
        );

        // 8. Return standardized success response
        return $this->successResponse([
            'post_id' => $post_id,
            'post_url' => get_permalink($post_id),
            'taxonomy_results' => $taxonomy_results,
            'attachment_id' => $attachment_id
        ]);
    }
}
```

The `handle_tool_call()` method is implemented in the base `PublishHandler` class and calls `executePublish()`.

## Required Configuration

All configuration parameters must be provided in handler config:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `post_author` | integer | Yes | WordPress user ID for post authorship |
| `post_status` | string | Yes | Post status: `publish`, `draft`, `private`, `pending` |
| `post_type` | string | Yes | WordPress post type: `post`, `page`, or custom post type |

## Tool Call Parameters

**Required**:
- `title`: Post title (sanitized with `sanitize_text_field`)
- `content`: Post content in the declared `content_format`

**Optional**:
- `content_format`: Source format for `content` (`html`, `markdown`, or `blocks`; defaults to `html` for compatibility). Data Machine converts this to the post type's stored format before insertion.
- `image_url`: Featured image URL (processed by `WordPressPublishHelper`)
- `source_url`: Source attribution URL (processed by `WordPressPublishHelper`)
- `category`: Category assignment for `TaxonomyHandler`
- `tags`: Tags assignment (string or array) for `TaxonomyHandler`
- Custom taxonomy parameters for `TaxonomyHandler`

## Configuration Examples

### Basic WordPress Publishing
```php
$handler_config = [
    'post_author' => 1,
    'post_status' => 'publish',
    'post_type' => 'post',
    'enable_images' => true,
    'include_source' => false,
    'taxonomy_category_selection' => 'ai_decides',
    'taxonomy_post_tag_selection' => 'skip'
];
```

### Advanced Configuration with System Defaults
```php
// Global WordPress settings (system-wide defaults)
$wp_settings = [
    'default_enable_images' => true,      // Overrides handler config
    'default_include_source' => false     // Overrides handler config
];

// Handler config (fallback values)
$handler_config = [
    'post_author' => 1,
    'post_status' => 'publish',
    'post_type' => 'post',
    'enable_images' => false,             // Ignored - system default wins
    'include_source' => true,             // Ignored - system default wins
    'taxonomy_category_selection' => 'Technology', // Pre-selected by term name
    'taxonomy_post_tag_selection' => 'ai_decides'
];
```

## Tool Response Format

**Success Response**:
```php
[
    'success' => true,
    'data' => [
        'post_id' => 123,
        'post_title' => 'Original post title',
        'post_url' => 'https://site.com/post-permalink',
        'taxonomy_results' => [
            'category' => [
                'success' => true,
                'taxonomy' => 'category',
                'term_count' => 1,
                'terms' => ['Technology']
            ],
            'post_tag' => [
                'success' => true,
                'taxonomy' => 'post_tag',
                'term_count' => 2,
                'terms' => ['AI', 'Machine Learning']
            ]
        ],
        'featured_image_result' => [
            'success' => true,
            'attachment_id' => 456,
            'attachment_url' => 'https://site.com/wp-content/uploads/image.jpg'
        ]
    ],
    'tool_name' => 'wordpress_publish'
]
```

**Error Response**:
```php
[
    'success' => false,
    'error' => 'Missing required configuration: post_author',
    'tool_name' => 'wordpress_publish'
]
```

## Error Handling

**Configuration Errors**:
- Missing required handler configuration validation
- Invalid configuration value detection
- Component-specific configuration validation

**Processing Errors**:
- Image download and attachment failures
- Taxonomy assignment errors
- Source URL validation failures
- WordPress post creation errors

**Component Error Isolation**:
- Failed image processing doesn't prevent post creation
- Taxonomy errors are isolated per taxonomy
- Source URL failures don't affect other components
- Comprehensive error logging throughout all components

## Security Features

**Input Sanitization**: Titles and URLs use WordPress security functions (`sanitize_text_field`, `esc_url`). HTML/block-backed post content is filtered after conversion; markdown-backed post types keep their markdown storage shape.

**Permission Respect**: Honors WordPress user capabilities and post type permissions.

**Safe Content**: Components handle user input safely without compromising WordPress security.

**Configuration Validation**: Validates all configuration parameters before processing.

## Performance Features

**Modular Processing**: Components can be bypassed based on configuration to optimize performance.

**Efficient Media Handling**: Uses WordPress native functions for optimal media processing.

**Clean Integration**: Gutenberg block generation maintains WordPress standards and performance.

**Comprehensive Logging**: All components provide detailed debug logging for monitoring and troubleshooting.

The modular WordPress publish handler architecture provides enhanced maintainability, configuration flexibility, and feature separation while maintaining backward compatibility and WordPress integration standards.

## WordPress Shared Components

The WordPress publish handler uses centralized shared components from `/inc/Core/WordPress/` that provide reusable functionality across all WordPress-related handlers (publish, fetch, update).

### WordPressPublishHelper

**Location**: `/inc/Core/WordPress/WordPressPublishHelper.php`
**Purpose**: WordPress-specific publishing operations
**Since**: 0.2.7

Provides static methods for WordPress publishing operations including media attachment and content modification.

**Key Methods**:

#### attachImageToPost()

Attaches image from Files Repository to WordPress post as featured image.

```php
$attachment_id = WordPressPublishHelper::attachImageToPost($post_id, $image_path, $config);
// Returns: int (attachment ID) or null on failure
```

#### applySourceAttribution()

Appends source URL to content with Gutenberg blocks or plain text.

```php
$content = WordPressPublishHelper::applySourceAttribution($content, $source_url, $config);
// Returns: string (modified content)
```

### TaxonomyHandler

**Location**: `/inc/Core/WordPress/TaxonomyHandler.php`
**Purpose**: Dynamic taxonomy assignment with configuration-based processing
**Since**: 0.2.1

Processes taxonomy assignments based on configuration (skip, AI-decided, or pre-selected terms).

### WordPressSettingsResolver

**Location**: `/inc/Core/WordPress/WordPressSettingsResolver.php`
**Purpose**: Configuration resolution with system defaults override
**Since**: 0.2.7

Resolves effective configuration values by checking system defaults first, then handler configuration.

**Key Methods**:

```php
$resolver = new WordPressSettingsResolver();
$post_status = $resolver->resolvePostStatus($handler_config);
$post_author = $resolver->resolvePostAuthor($handler_config);
```

### WordPressSettingsHandler

**Location**: `/inc/Core/WordPress/WordPressSettingsHandler.php`
**Purpose**: Centralized WordPress settings utilities for handler configuration
**Since**: 0.2.1

Provides reusable WordPress-specific settings utilities for taxonomy fields, post type options, and user options.

**Key Methods**:

```php
// Generate taxonomy field definitions
$taxonomy_fields = WordPressSettingsHandler::get_taxonomy_fields([...]);

// Get post type options
$post_types = WordPressSettingsHandler::get_post_type_options();

// Get user options
$users = WordPressSettingsHandler::get_user_options();
```

### WordPressFilters

**Location**: `/inc/Core/WordPress/WordPressFilters.php`
**Purpose**: Self-registration filter system for WordPress components
**Since**: 0.2.0

Handles automatic registration of WordPress handler via filter-based architecture.

### Configuration Hierarchy

System-wide WordPress defaults (from Settings page) override handler-specific configuration across all WordPress components:

**Hierarchy Order**:
1. **System Defaults** (Global WordPress settings from Settings page) - Highest priority
2. **Handler Configuration** (Flow-specific handler settings) - Fallback

**Implementation**: `WordPressSettingsResolver` class handles configuration resolution throughout the handler.

## Related Documentation

WordPress Shared Components - WordPressPublishHelper, TaxonomyHandler, WordPressSettingsResolver
WordPress Fetch Handler
WordPress Update Handler
SettingsHandler Base Class
