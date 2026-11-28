# Publish Handler Settings

**File Location**: `inc/Core/Steps/Publish/Handlers/PublishHandlerSettings.php`

**Since**: 0.2.1

Base settings class for all publish handlers providing common fields and standardized configuration patterns.

## Overview

PublishHandlerSettings extends the base SettingsHandler class and provides common settings fields shared across all publish handlers. Individual publish handlers extend this class to add platform-specific customizations.

## Architecture

**Inheritance**: `PublishHandlerSettings extends SettingsHandler`
**Location**: `/inc/Core/Steps/Publish/Handlers/PublishHandlerSettings.php`
**Purpose**: Common publish handler configuration fields

## Common Fields

### link_handling

Controls how source URLs are handled when publishing content.

```php
'link_handling' => [
    'type' => 'select',
    'label' => __('Source URL Handling', 'datamachine'),
    'description' => __('Choose how to handle source URLs when publishing.', 'datamachine'),
    'options' => [
        'none' => __('No URL - exclude source link entirely', 'datamachine'),
        'append' => __('Append to content - add URL to post content', 'datamachine')
    ],
    'default' => 'append'
]
```

**Options**:
- **`'none'`**: No source URL processing - content posted as-is
- **`'append'`**: Source URL appended to content (default)

**Integration**: Uses `source_url` from engine data filter for attribution

### include_images

Controls whether images are included when publishing.

```php
'include_images' => [
    'type' => 'checkbox',
    'label' => __('Enable Image Posting', 'datamachine'),
    'description' => __('Include images when available in source data.', 'datamachine'),
    'default' => false
]
```

**Features**:
- Boolean toggle for image inclusion
- Integrates with image validation in PublishHandler base class
- Uses `image_url` from engine data filter

## Usage Pattern

Publish handlers extend PublishHandlerSettings and add their specific fields:

```php
use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

class MyPublishHandlerSettings extends PublishHandlerSettings {
    public static function get_fields(): array {
        return array_merge(
            self::get_common_fields(), // link_handling, include_images
            [
                'platform_specific_field' => [
                    'type' => 'text',
                    'label' => __('Platform Setting', 'datamachine'),
                    'default' => ''
                ]
            ]
        );
    }
}
```

## Integration with PublishHandler Base Class

The common fields integrate with the PublishHandler base class methods:

### Source URL Handling

```php
// PublishHandler base class retrieves source URL
$source_url = $this->getSourceUrl($parameters['job_id'] ?? null);

// Settings determine how to handle it
$link_handling = $handler_config['link_handling'] ?? 'append';
if ($link_handling === 'append' && $source_url) {
    $content .= ' ' . $source_url;
}
```

### Image Processing

```php
// PublishHandler base class retrieves image path
$image_file_path = $this->getImageFilePath($parameters['job_id'] ?? null);

// Settings determine if images should be included
$include_images = $handler_config['include_images'] ?? false;
if ($include_images && $image_file_path) {
    // Process and include image
}
```

## Benefits

- **Code Deduplication**: Common fields defined once, used by all publish handlers
- **Consistency**: Uniform URL and image handling behavior across platforms
- **Maintainability**: Centralized field definitions
- **Extensibility**: Easy to add new common fields for all publish handlers

## Handlers Using This Base Class

All publish handlers extend PublishHandlerSettings:

- WordPress Publish
- Google Sheets Output

## Platform-Specific Extensions

Individual handlers add platform-specific fields:

### WordPress
- Post type, taxonomy, author, status settings
- Featured image and content processing options

## See Also

- SettingsHandler - Base settings class
- FetchHandlerSettings - Fetch handler base settings
- SettingsDisplayService - UI display logic
- PublishHandler - Base publish handler class