# SettingsHandler Base Classes

## Overview

The SettingsHandler classes provide auto-sanitization and standardized field management for all handler settings in the Data Machine system. Introduced in version 0.2.1, they eliminate code duplication and ensure consistent field handling across fetch and publish handlers.

## Architecture

**Base Class**: `/inc/Core/Steps/Settings/SettingsHandler.php`
**Display Service**: `/inc/Core/Steps/Settings/SettingsDisplayService.php`
**Fetch Settings**: `/inc/Core/Steps/Fetch/Handlers/FetchHandlerSettings.php`
**Publish Settings**: `/inc/Core/Steps/Publish/Handlers/PublishHandlerSettings.php`
**Since**: 0.2.1

## Core Functionality

### Auto-Sanitization

All field values are automatically sanitized based on field schema:

```php
// Automatic sanitization based on field type
$text_value = sanitize_text_field($input);
$url_value = esc_url_raw($input);
$int_value = intval($input);
```

### Field Schema Definition

Standardized field definition pattern:

```php
[
    'type' => 'text|url|number|textarea|select|checkbox|password',
    'label' => __('Field Label', 'datamachine'),
    'default' => 'default_value',
    'required' => true|false,
    'description' => __('Field description', 'datamachine'),
    'options' => ['key' => 'value'] // For select fields
]
```

## FetchHandlerSettings

Base class for all fetch handler settings with common fields:

```php
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandlerSettings;

class MyFetchHandlerSettings extends FetchHandlerSettings {
    public static function get_fields(): array {
        return array_merge(
            self::get_common_fields(), // timeframe_limit, search
            [
                'custom_field' => [
                    'type' => 'text',
                    'label' => __('Custom Setting', 'datamachine'),
                    'default' => ''
                ]
            ]
        );
    }
}
```

### Common Fetch Fields

```php
public static function get_common_fields(): array {
    return [
        'timeframe_limit' => [
            'type' => 'number',
            'label' => __('Timeframe Limit (hours)', 'datamachine'),
            'default' => 24,
            'description' => __('Only process items from the last N hours', 'datamachine')
        ],
        'search' => [
            'type' => 'text',
            'label' => __('Search Keywords', 'datamachine'),
            'default' => '',
            'description' => __('Filter items containing these keywords', 'datamachine')
        ]
    ];
}
```

## PublishHandlerSettings

Base class for all publish handler settings with common fields:

```php
use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;

class MyPublishHandlerSettings extends PublishHandlerSettings {
    public static function get_fields(): array {
        return array_merge(
            self::get_common_fields(), // status, author, etc.
            [
                'custom_field' => [
                    'type' => 'text',
                    'label' => __('Custom Setting', 'datamachine'),
                    'default' => ''
                ]
            ]
        );
    }
}
```

### Common Publish Fields

```php
public static function get_common_fields(): array {
    return [
        'status' => [
            'type' => 'select',
            'label' => __('Post Status', 'datamachine'),
            'default' => 'publish',
            'options' => [
                'publish' => __('Published', 'datamachine'),
                'draft' => __('Draft', 'datamachine'),
                'pending' => __('Pending Review', 'datamachine')
            ]
        ],
        'author' => [
            'type' => 'number',
            'label' => __('Author ID', 'datamachine'),
            'default' => 1,
            'description' => __('WordPress user ID for post author', 'datamachine')
        ]
    ];
}
```

## Field Types

### Text Input
```php
'field_name' => [
    'type' => 'text',
    'label' => __('Text Field', 'datamachine'),
    'default' => '',
    'required' => false
]
```

### URL Input
```php
'api_url' => [
    'type' => 'url',
    'label' => __('API URL', 'datamachine'),
    'default' => '',
    'required' => true
]
```

### Number Input
```php
'limit' => [
    'type' => 'number',
    'label' => __('Limit', 'datamachine'),
    'default' => 10,
    'required' => false
]
```

### Textarea
```php
'description' => [
    'type' => 'textarea',
    'label' => __('Description', 'datamachine'),
    'default' => '',
    'required' => false
]
```

### Select Dropdown
```php
'format' => [
    'type' => 'select',
    'label' => __('Format', 'datamachine'),
    'default' => 'json',
    'options' => [
        'json' => __('JSON', 'datamachine'),
        'xml' => __('XML', 'datamachine'),
        'csv' => __('CSV', 'datamachine')
    ]
]
```

### Checkbox
```php
'enabled' => [
    'type' => 'checkbox',
    'label' => __('Enabled', 'datamachine'),
    'default' => true
]
```

### Password Field
```php
'api_key' => [
    'type' => 'password',
    'label' => __('API Key', 'datamachine'),
    'default' => '',
    'required' => true
]
```

## Sanitization Rules

| Field Type | Sanitization Method |
|------------|-------------------|
| text | `sanitize_text_field()` |
| url | `esc_url_raw()` |
| number | `intval()` |
| textarea | `sanitize_textarea_field()` |
| select | Whitelist validation + `sanitize_text_field()` |
| checkbox | `boolval()` |
| password | `sanitize_text_field()` (no logging) |

## Configuration Hierarchy

Settings follow a hierarchy where system defaults can override handler configuration:

```php
// Handler config (lowest priority)
$handler_config = ['status' => 'draft'];

// System defaults (highest priority)
$system_defaults = ['status' => 'publish'];

// Result: 'publish' (system default wins)
```

## Benefits

- **Auto-Sanitization**: Automatic security validation based on field types
- **Code Deduplication**: Common fields defined once, reused everywhere
- **Consistency**: Standardized field definitions across all handlers
- **Security**: Built-in sanitization prevents XSS and injection attacks
- **Maintainability**: Centralized field management and validation

## SettingsDisplayService (@since v0.2.1)

**Location**: `/inc/Core/Steps/Settings/SettingsDisplayService.php`

Centralized service for processing handler settings for UI display with smart formatting and label generation.

### Key Methods

#### getDisplaySettings()

Processes raw settings into display-ready format for admin interface.

```php
public static function getDisplaySettings(
    array $settings_config,
    string $handler_slug
): array
```

**Parameters**:
- `$settings_config`: Raw settings configuration array
- `$handler_slug`: Handler identifier for context

**Returns**: Array of processed settings ready for React components

**Usage**:
```php
use DataMachine\Core\Steps\Settings\SettingsDisplayService;

$raw_settings = [
    'api_key' => 'abc123',
    'post_type' => 'post',
    'include_featured_image' => true
];

$display_settings = SettingsDisplayService::getDisplaySettings(
    $raw_settings,
    'wordpress_publish'
);

// Returns:
// [
//     [
//         'key' => 'api_key',
//         'label' => 'API Key',
//         'value' => '***123', // Masked for security
//         'type' => 'password'
//     ],
//     [
//         'key' => 'post_type',
//         'label' => 'Post Type',
//         'value' => 'Post',
//         'type' => 'select'
//     ],
//     [
//         'key' => 'include_featured_image',
//         'label' => 'Include Featured Image',
//         'value' => 'Yes',
//         'type' => 'checkbox'
//     ]
// ]
```

**Features**:
- Smart label generation with acronym handling (API, URL, AI remain uppercase)
- Display value formatting for select fields (shows label instead of value)
- Password field masking for security
- Checkbox value conversion (true → "Yes", false → "No")
- Field grouping and organization
- Handles complex field types (select, textarea, checkbox)

#### getFieldState()

Extracts field metadata for React components.

```php
public static function getFieldState(
    string $field_key,
    array $field_schema
): array
```

**Parameters**:
- `$field_key`: Field identifier
- `$field_schema`: Field definition from handler settings

**Returns**: Array with field type, options, validation rules, and display labels

**Usage**:
```php
$field_schema = [
    'type' => 'select',
    'label' => __('Post Status', 'datamachine'),
    'default' => 'publish',
    'options' => [
        'publish' => 'Published',
        'draft' => 'Draft'
    ]
];

$field_state = SettingsDisplayService::getFieldState('status', $field_schema);

// Returns:
// [
//     'type' => 'select',
//     'options' => ['publish' => 'Published', 'draft' => 'Draft'],
//     'default' => 'publish',
//     'label' => 'Post Status',
//     'required' => false
// ]
```

### Label Generation

SettingsDisplayService uses smart label generation from field keys:

```php
// Smart acronym handling
'api_key' → 'API Key'
'api_url' → 'API URL'
'enable_ai' → 'Enable AI'
'post_id' → 'Post ID'

// Standard conversion
'post_type' → 'Post Type'
'author_name' → 'Author Name'
'include_featured_image' → 'Include Featured Image'
```

**Acronym Preservation**:
- API (Application Programming Interface)
- URL (Uniform Resource Locator)
- AI (Artificial Intelligence)
- ID (Identifier)
- SEO (Search Engine Optimization)
- RSS (Really Simple Syndication)
- JSON (JavaScript Object Notation)
- XML (Extensible Markup Language)
- CSV (Comma-Separated Values)
- HTTP (Hypertext Transfer Protocol)
- HTTPS (HTTP Secure)

### Display Value Formatting

#### Select Fields
```php
// Field configuration
'post_type' => [
    'type' => 'select',
    'value' => 'post',
    'options' => [
        'post' => 'Post',
        'page' => 'Page'
    ]
]

// Display output: "Post" (not "post")
```

#### Checkbox Fields
```php
// Field value: true
// Display output: "Yes"

// Field value: false
// Display output: "No"
```

#### Password Fields
```php
// Field value: "secret_api_key_12345"
// Display output: "***12345" (last 5 chars visible)
```

### Usage in Admin Interface

#### Settings Display Component
```javascript
// React component receives processed settings
const SettingsDisplay = ({ handlerSlug }) => {
    const displaySettings = useFetchDisplaySettings(handlerSlug);

    return (
        <div className="settings-display">
            {displaySettings.map(setting => (
                <div key={setting.key} className="setting-row">
                    <span className="setting-label">{setting.label}:</span>
                    <span className="setting-value">{setting.value}</span>
                </div>
            ))}
        </div>
    );
};
```

#### Field Editor Component
```javascript
// React component receives field state
const FieldEditor = ({ fieldKey, fieldSchema }) => {
    const fieldState = SettingsDisplayService.getFieldState(fieldKey, fieldSchema);

    return (
        <div className="field-editor">
            <label>{fieldState.label}</label>
            {fieldState.type === 'select' ? (
                <select defaultValue={fieldState.default}>
                    {Object.entries(fieldState.options).map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </select>
            ) : (
                <input type={fieldState.type} defaultValue={fieldState.default} />
            )}
        </div>
    );
};
```

### Benefits

- **Consistency**: Standardized formatting across all handlers
- **Maintainability**: Single location for display logic
- **Extensibility**: Easy to add new field type support
- **Performance**: Optimized for frontend consumption
- **Security**: Automatic password masking and sensitive data protection
- **User Experience**: Human-readable labels and values for better UX

### See Also

- Handler Registration Trait - Handler registration patterns
- Fetch Handler - Fetch handler base class
- Publish Handler - Publish handler base class