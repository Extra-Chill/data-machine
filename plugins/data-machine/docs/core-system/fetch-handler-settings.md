# Fetch Handler Settings

**File Location**: `inc/Core/Steps/Fetch/Handlers/FetchHandlerSettings.php`

**Since**: 0.2.1

Base settings class for all fetch handlers providing common fields and standardized configuration patterns.

## Overview

FetchHandlerSettings extends the base SettingsHandler class and provides common settings fields shared across all fetch handlers. Individual fetch handlers extend this class to add handler-specific customizations.

## Architecture

**Inheritance**: `FetchHandlerSettings extends SettingsHandler`
**Location**: `/inc/Core/Steps/Fetch/Handlers/FetchHandlerSettings.php`
**Purpose**: Common fetch handler configuration fields

## Common Fields

### timeframe_limit

Timeframe filtering for content processing.

```php
'timeframe_limit' => [
    'type' => 'select',
    'label' => __('Process Items Within', 'datamachine'),
    'description' => __('Only consider items published within this timeframe.', 'datamachine'),
    'options' => apply_filters('datamachine_timeframe_limit', [], null),
]
```

**Options**: Dynamically provided via `datamachine_timeframe_limit` filter
**Common Values**:
- `all_time` - No time restriction
- `24_hours` - Last 24 hours
- `72_hours` - Last 3 days
- `7_days` - Last week
- `30_days` - Last month

### search

Keyword-based content filtering.

```php
'search' => [
    'type' => 'text',
    'label' => __('Search Term Filter', 'datamachine'),
    'description' => __('Filter items by keywords (comma-separated). Items containing any keyword in their title or content will be included.', 'datamachine'),
]
```

**Features**:
- Comma-separated keyword support
- Case-insensitive matching
- OR logic (any keyword match includes item)
- Applied to title and content fields

## Usage Pattern

Fetch handlers extend FetchHandlerSettings and add their specific fields:

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

## Integration with Centralized Filters

The common fields integrate with centralized handler filter system:

### Timeframe Processing

```php
// Centralized timeframe conversion
$cutoff_timestamp = apply_filters('datamachine_timeframe_limit', null, '24_hours');

// Used in database queries
$date_query = $cutoff_timestamp ? ['after' => gmdate('Y-m-d H:i:s', $cutoff_timestamp)] : [];
```

### Keyword Matching

```php
// Centralized keyword filtering
$matches = apply_filters('datamachine_keyword_search_match', true, $content, $search_keywords);
if (!$matches) continue; // Skip non-matching items
```

## Benefits

- **Code Deduplication**: Common fields defined once, used by all fetch handlers
- **Consistency**: Uniform timeframe and search behavior across handlers
- **Maintainability**: Centralized field definitions
- **Extensibility**: Easy to add new common fields for all fetch handlers

## Handlers Using This Base Class

All fetch handlers extend FetchHandlerSettings:

- WordPress Local
- WordPress Media
- WordPress API
- RSS
- Reddit
- Google Sheets Fetch
- Files

## See Also

- SettingsHandler - Base settings class
- PublishHandlerSettings - Publish handler base settings
- SettingsDisplayService - UI display logic
- [Centralized Handler Filters](../architecture.md#centralized-handler-filters) - Filter integration