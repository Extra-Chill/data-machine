# WordPress Settings Handler

**File Location**: `inc/Core/WordPress/WordPressSettingsHandler.php`

**Since**: 0.2.1

Provides reusable WordPress-specific settings utilities for taxonomy fields, post type options, and user options across all WordPress handler Settings classes.

## Overview

The WordPressSettingsHandler centralizes common WordPress settings logic used across fetch, publish, and upsert handlers. It eliminates code duplication by providing shared methods for taxonomy field generation, post type options, and user selection.

## Architecture

**Location**: `/inc/Core/WordPress/WordPressSettingsHandler.php`
**Purpose**: Shared WordPress settings utilities
**Usage**: Static methods for field generation and sanitization

## Key Methods

### get_taxonomy_fields()

Generate dynamic taxonomy fields for all public taxonomies.

```php
public static function get_taxonomy_fields(array $config = []): array
```

**Configuration Options**:
- `field_suffix`: `'_selection'` or `'_filter'` (default: `'selection'`)
- `first_options`: Initial options array (default: `['skip', 'ai_decides']`)
- `description_template`: sprintf template for descriptions
- `default`: Default value for fields

**Returns**: Array of taxonomy field definitions

**Features**:
- Dynamically discovers all public taxonomies
- Excludes system taxonomies via filter
- Includes existing terms as selectable options
- Generates proper field labels and descriptions

### sanitize_taxonomy_fields()

Sanitize dynamic taxonomy field settings.

```php
public static function sanitize_taxonomy_fields(array $raw_settings, array $config = []): array
```

**Configuration Options**:
- `field_suffix`: Field suffix to match generation
- `allowed_values`: Allowed string values (e.g., `['skip', 'ai_decides']`)
- `default_value`: Default when validation fails

**Returns**: Sanitized taxonomy settings array

**Validation**:
- Validates allowed string values
- Checks term ID existence for numeric selections
- Falls back to defaults for invalid values

### get_post_type_options()

Get available WordPress post type options.

```php
public static function get_post_type_options(bool $include_any = false): array
```

**Parameters**:
- `$include_any`: Include "Any" option for fetch handlers

**Returns**: Post type options array (`slug => label`)

**Features**:
- Prioritizes common post types (post, page)
- Excludes attachment post type
- Orders options logically

### get_user_options()

Get available WordPress users for authorship.

```php
public static function get_user_options(): array
```

**Returns**: User options array (`ID => display_name`)

**Features**:
- Uses display_name when available
- Falls back to user_login
- Includes all users

## Taxonomy Field Generation

### Dynamic Field Creation

Generates fields for all public taxonomies:

```php
$taxonomy_fields = WordPressSettingsHandler::get_taxonomy_fields([
    'field_suffix' => '_selection',
    'first_options' => [
        'skip' => __('Skip', 'datamachine'),
        'ai_decides' => __('AI Decides', 'datamachine')
    ]
]);
```

### Field Structure

Each taxonomy field includes:
- **Type**: `'select'`
- **Label**: Taxonomy display name
- **Description**: Contextual help text
- **Options**: System options + existing terms
- **Default**: Configurable default value

### Options Array

```php
$options = [
    'skip' => 'Skip',
    'ai_decides' => 'AI Decides',
    'separator' => '──────────', // Visual separator
    123 => 'Term Name',          // Existing terms
    124 => 'Another Term'
];
```

## Integration with Handler Settings

### Publish Handler Usage

```php
use DataMachine\Core\WordPress\WordPressSettingsHandler;

class WordPressSettings extends PublishHandlerSettings {
    public static function get_fields(): array {
        return array_merge(
            self::get_common_fields(),
            WordPressSettingsHandler::get_taxonomy_fields([
                'field_suffix' => '_selection',
                'first_options' => ['skip', 'ai_decides']
            ]),
            [
                'post_type' => [
                    'type' => 'select',
                    'label' => __('Post Type', 'datamachine'),
                    'options' => WordPressSettingsHandler::get_post_type_options()
                ],
                'post_author' => [
                    'type' => 'select',
                    'label' => __('Post Author', 'datamachine'),
                    'options' => WordPressSettingsHandler::get_user_options()
                ]
            ]
        );
    }
}
```

### Fetch Handler Usage

```php
class WordPressFetchSettings extends FetchHandlerSettings {
    public static function get_fields(): array {
        return array_merge(
            self::get_common_fields(),
            WordPressSettingsHandler::get_taxonomy_fields([
                'field_suffix' => '_filter',
                'first_options' => ['all' => __('All', 'datamachine')]
            ]),
            [
                'post_type' => [
                    'type' => 'select',
                    'label' => __('Post Type', 'datamachine'),
                    'options' => WordPressSettingsHandler::get_post_type_options(true)
                ]
            ]
        );
    }
}
```

## Sanitization Integration

### Taxonomy Sanitization

```php
public static function sanitize(array $raw_settings): array {
    $sanitized = parent::sanitize($raw_settings);

    // Add taxonomy sanitization
    $taxonomy_sanitized = self::sanitize_taxonomy_fields($raw_settings, [
        'field_suffix' => '_selection',
        'allowed_values' => ['skip', 'ai_decides']
    ]);

    return array_merge($sanitized, $taxonomy_sanitized);
}
```

## Benefits

- **Code Deduplication**: Shared logic across all WordPress handlers
- **Consistency**: Uniform taxonomy and post type handling
- **Maintainability**: Centralized WordPress-specific settings logic
- **Extensibility**: Easy to add new shared field types
- **Dynamic**: Automatically adapts to new taxonomies and post types

## See Also

- TaxonomyHandler - Taxonomy processing logic
- WordPress Handlers - Direct instantiation of WordPress utilities