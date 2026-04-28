# WordPress Shared Components

## Overview

The WordPress Shared Components are a collection of centralized WordPress functionality introduced in version 0.2.1. These components reduce code duplication and provide consistent WordPress integration across handlers, particularly the WordPress publish and upsert handlers.

As of v0.2.7, WordPress-specific publishing operations are provided by WordPressPublishHelper, while EngineData serves as a platform-agnostic data access layer.

## Architecture

**Location**: `/inc/Core/WordPress/`
**Components**: 5 specialized classes
**Since**: 0.2.1 (architecture updated in v0.2.7)

## Components

### WordPressPublishHelper

**File**: `WordPressPublishHelper.php`
**Purpose**: WordPress-specific publishing operations including media attachment and content modification
**Since**: v0.2.7

**Key Features**:
- Image attachment to WordPress posts as featured images
- Source URL attribution in the caller's declared source format
- Centralized WordPress publishing utilities

**Static Methods**:

#### attachImageToPost()
```php
WordPressPublishHelper::attachImageToPost(int $post_id, ?string $image_path, array $config): ?int
```

Attach image from Files Repository to WordPress post as featured image.

**Process**:
1. Check configuration (`include_images`)
2. Validate image path and file type
3. Copy to temp location (preserves repository file)
4. Sideload to Media Library via `media_handle_sideload()`
5. Set as featured image via `set_post_thumbnail()`

**Returns**: Attachment ID on success, null on failure

#### applySourceAttribution()
```php
WordPressPublishHelper::applySourceAttribution(string $content, ?string $source_url, array $config): string
```

Apply source URL attribution to content based on configuration.

**Process**:
1. Check configuration (`link_handling` = 'append')
2. Validate source URL
3. Read the declared `content_format` from handler config (`html`, `markdown`, or `blocks`)
4. Generate source attribution in the same source format
5. Append to content

**Returns**: Modified content with source attribution

**Usage**:
```php
use DataMachine\Core\WordPress\WordPressPublishHelper;
use DataMachine\Core\EngineData;

// Get data from EngineData
$engine = new EngineData($engine_data, $job_id);
$image_path = $engine->getImagePath();
$source_url = $engine->getSourceUrl();

// Use WordPressPublishHelper for WordPress operations
$attachment_id = WordPressPublishHelper::attachImageToPost($post_id, $image_path, $config);
$content = WordPressPublishHelper::applySourceAttribution($content, $source_url, $config);
```

### WordPressSettingsResolver

**File**: `WordPressSettingsResolver.php`
**Purpose**: Centralized utility for WordPress settings resolution with system defaults override
**Since**: v0.2.7

**Key Features**:
- Single source of truth for post status resolution
- Single source of truth for post author resolution
- System defaults always override handler configuration

**Static Methods**:

#### getPostStatus()
```php
WordPressSettingsResolver::getPostStatus(array $handler_config, string $default = 'draft'): string
```

Get effective post status from handler config with system defaults override.

**Returns**: Post status (publish, draft, pending, etc.)

#### getPostAuthor()
```php
WordPressSettingsResolver::getPostAuthor(array $handler_config, int $default = 1): int
```

Get effective post author from handler config with system defaults override.

**Returns**: Post author ID

**Usage**:
```php
use DataMachine\Core\WordPress\WordPressSettingsResolver;

$post_status = WordPressSettingsResolver::getPostStatus($handler_config);
$post_author = WordPressSettingsResolver::getPostAuthor($handler_config);
```

### TaxonomyHandler

**File**: `TaxonomyHandler.php`
**Purpose**: Taxonomy selection and dynamic term creation

**Selection Modes**:
1. **Skip**: No taxonomy processing
2. **AI-Decided**: AI agent determines appropriate terms
3. **Pre-selected**: Use predefined taxonomy terms

**Key Features**:
- Dynamic term creation for non-existent terms
- Support for hierarchical taxonomies (categories, tags)
- Term validation and sanitization
- Bulk taxonomy assignment

**Usage**:
```php
use DataMachine\Core\WordPress\TaxonomyHandler;

$taxonomy_handler = new TaxonomyHandler();
    $result = $taxonomy_handler->processTaxonomies($post_id, $taxonomy_data, $handler_config);

if ($result['success']) {
    $assigned_terms = $result['terms'];
    // Taxonomies assigned successfully
}
```

### WordPressSettingsHandler

**File**: `WordPressSettingsHandler.php`
**Purpose**: Shared WordPress settings fields for publish handlers

**Key Features**:
- Centralized settings field definitions
- Post type, taxonomy, and author selection
- Status and visibility controls
- Configuration validation

**Settings Fields**:
- Post type selection
- Taxonomy term selection
- Author assignment
- Post status (draft, publish, etc.)
- Comment and ping status

### WordPressFilters

**File**: `WordPressFilters.php`
**Purpose**: Service discovery registration for WordPress components

**Key Features**:
- Auto-registration of WordPress handlers
- Filter-based component discovery
- Integration with main handler registration system

## EngineData Integration (v0.2.7)

**Class**: `EngineData`
**Location**: `/inc/Core/EngineData.php`
**Since**: 0.2.1 (platform-agnostic refactoring in v0.2.7)

EngineData provides platform-agnostic data access for all handlers:

**Key Methods**:
- `getSourceUrl(): ?string` - Retrieve validated source URL
- `getImagePath(): ?string` - Retrieve image file path
- `get(string $key, $default = null)` - Retrieve any engine data value
- `getJobContext(): array` - Retrieve job execution context
- `getFlowConfig(): array` - Retrieve flow configuration

**Data Access Pattern**:

```php
// EngineData for data access only (platform-agnostic)
$engine = new EngineData($engine_data, $job_id);
$source_url = $engine->getSourceUrl();
$image_path = $engine->getImagePath();

// WordPressPublishHelper for WordPress operations
use DataMachine\Core\WordPress\WordPressPublishHelper;

$content = WordPressPublishHelper::applySourceAttribution($content, $source_url, $config);
$attachment_id = WordPressPublishHelper::attachImageToPost($post_id, $image_path, $config);
```

## Integration Pattern

The WordPress publish handler integrates shared components following the v0.2.7 architecture:

```php
use DataMachine\Core\EngineData;
use DataMachine\Core\WordPress\WordPressPublishHelper;
use DataMachine\Core\WordPress\WordPressSettingsResolver;
use DataMachine\Core\WordPress\TaxonomyHandler;

class WordPress {
    protected $taxonomy_handler;

    public function __construct() {
        $this->taxonomy_handler = new TaxonomyHandler();
    }

    public function create_wordpress_post($content, $handler_config, $engine_data_array, $job_id) {
        // Create EngineData instance for data access
        $engine = new EngineData($engine_data_array, $job_id);
        
        // Get data from EngineData
        $source_url = $engine->getSourceUrl();
        $image_path = $engine->getImagePath();
        
        // Apply source attribution via WordPressPublishHelper
        $content = WordPressPublishHelper::applySourceAttribution($content, $source_url, $handler_config);

        // Resolve WordPress settings
        $post_status = WordPressSettingsResolver::getPostStatus($handler_config);
        $post_author = WordPressSettingsResolver::getPostAuthor($handler_config);

        $post_id = wp_insert_post($post_data);

        // Process featured image via WordPressPublishHelper
        WordPressPublishHelper::attachImageToPost($post_id, $image_path, $handler_config);

        // Process taxonomies via TaxonomyHandler
        $this->taxonomy_handler->processTaxonomies($post_id, $parameters, $handler_config, $engine->all());

        return $post_id;
    }
}
```

## Configuration Hierarchy

**System Defaults Override Handler Config**: WordPress publish handlers use a hierarchical configuration system where system-wide defaults always take precedence over handler-specific settings.

```php
// System defaults (highest priority)
$system_defaults = [
    'post_type' => 'post',
    'post_status' => 'publish',
    'taxonomies' => ['category' => ['news']]
];

// Handler config (lower priority)
$handler_config = [
    'wordpress_post_type' => 'page',  // Ignored if system default set
    'wordpress_taxonomies' => ['category' => ['blog']]  // Ignored if system default set
];

// Result: System defaults used
```

## Benefits

- **Code Deduplication**: Eliminates repetitive WordPress integration code
- **Consistency**: Standardized WordPress operations across handlers
- **Maintainability**: Centralized WordPress functionality
- **Extensibility**: Easy to add new WordPress features
- **Configuration Management**: Hierarchical settings with clear precedence rules

## Used By

WordPress shared components are used by:
- WordPress Publish Handler - Uses WordPressPublishHelper, WordPressSettingsResolver, TaxonomyHandler, EngineData
- WordPress Update Handler - Uses WordPressPublishHelper, WordPressSettingsResolver, TaxonomyHandler, EngineData
- Handler Settings - WordPressSettingsHandler provides common fields

These components eliminate code duplication across WordPress-related handlers and provide consistent WordPress integration patterns.

## Architecture Evolution

**v0.2.1**: Introduced FeaturedImageHandler, SourceUrlHandler, TaxonomyHandler, WordPressSettingsHandler, and WordPressFilters as separate components.

**v0.2.6**: Consolidated FeaturedImageHandler and SourceUrlHandler functionality into EngineData class to reduce duplication and provide unified engine data operations.

**v0.2.7**: Major architectural refactoring:
- Created WordPressPublishHelper for WordPress-specific publishing operations
- Created WordPressSettingsResolver for centralized settings resolution
- Removed WordPressSharedTrait to eliminate architectural bloat
- Refactored EngineData to be platform-agnostic (data access only, no WordPress operations)
- All handlers now use direct EngineData instantiation for data access and WordPressPublishHelper for WordPress operations

## Related Documentation

EngineData - Platform-agnostic data access (single source of truth)
WordPressPublishHelper - WordPress-specific publishing operations
WordPressSettingsResolver - Settings resolution utilities
WordPress Publish Handler - Integration example
WordPress Update Handler - Integration example
Base Class Architecture
