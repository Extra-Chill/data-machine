# Settings Display Service

**File Location**: `inc/Core/Steps/Settings/SettingsDisplayService.php`

**Since**: 0.2.1

Handles the complex logic for displaying handler settings in the UI. Moved from filter-based implementation to proper OOP service class for better maintainability and performance.

## Overview

The SettingsDisplayService processes handler settings for UI display with smart formatting, label generation, and value transformation. It provides a clean separation between data storage and presentation logic.

## Architecture

**Location**: `/inc/Core/Steps/Settings/SettingsDisplayService.php`
**Purpose**: UI display logic for handler settings
**Dependencies**: Handler Settings classes, Flow database access

## Key Methods

### getDisplaySettings()

Get formatted settings display for a flow step.

```php
public function getDisplaySettings(string $flow_step_id, string $step_type): array
```

**Parameters**:
- `$flow_step_id`: Flow step ID (format: `{pipeline_step_id}_{flow_id}`)
- `$step_type`: Step type (for future extensibility)

**Returns**: Array of formatted settings for UI display

**Process**:
1. Retrieves flow step configuration from database
2. Gets handler Settings class via filter
3. Builds display array with smart labels and formatted values

### getFieldState()

Get field state for API consumption with current values and schema.

```php
public function getFieldState(string $handler_slug, array $current_settings = []): array
```

**Parameters**:
- `$handler_slug`: Handler identifier
- `$current_settings`: Current saved settings (optional)

**Returns**: Field schema with current values for frontend consumption

**Features**:
- Provides complete field definitions with labels, types, options
- Includes current values (saved or defaults)
- Formats options for frontend compatibility
- Ensures select values are strings for consistent handling

### buildDisplayArray()

Build the display array from field definitions and current settings.

```php
private function buildDisplayArray(array $fields, array $current_settings): array
```

**Features**:
- Respects Settings class field order
- Generates smart labels from field keys
- Formats display values (option labels, etc.)
- Skips empty/unset fields

## Smart Label Generation

The service includes intelligent label generation for fields without explicit labels:

```php
private function generateFieldLabel(string $key, array $field_config, array $acronyms): string
```

**Features**:
- Converts snake_case keys to "Title Case"
- Applies acronym mappings (AI, API, URL, etc.)
- Uses field label if provided
- Example: `api_key` → "API Key"

## Value Formatting

Handles type-flexible option matching for display values:

```php
private function formatDisplayValue($value, array $field_config)
```

**Features**:
- Matches option labels for select fields
- Handles type coercion (int 1 vs string "1")
- Returns raw value if no option match

## Integration

### With Handler Settings Classes

```php
// Handler Settings class defines fields
class MyHandlerSettings extends SettingsHandler {
    public static function get_fields(): array {
        return [
            'api_key' => [
                'type' => 'text',
                'label' => 'API Key',
                'description' => 'Your API key'
            ]
        ];
    }
}

// DisplayService formats for UI
$display_service = new SettingsDisplayService();
$display = $display_service->getDisplaySettings($flow_step_id, 'publish');
```

### With React Frontend

The service provides data for the universal handler settings template:

```javascript
// API response includes field state
const fieldState = await api.getHandlerFields(handlerSlug);

// DisplayService provides formatted display values
const displaySettings = displayService.getDisplaySettings(flowStepId);
```

## Benefits

- **Separation of Concerns**: UI logic separated from data storage
- **Smart Formatting**: Automatic label generation and value formatting
- **Consistency**: Uniform display logic across all handlers
- **Maintainability**: Centralized display logic in single service class
- **Performance**: Efficient field processing and caching

## Usage in Pipeline Builder

The SettingsDisplayService is used throughout the React pipeline builder:

- **Handler Settings Modal**: Displays current configuration
- **Step Cards**: Shows summary of configured settings
- **Validation Feedback**: Provides formatted error messages
- **Import/Export**: Handles settings serialization

## Error Handling

The service gracefully handles missing or invalid configurations:

- Returns empty arrays for missing handlers
- Skips fields without values
- Uses defaults when current settings unavailable
- Logs errors for debugging while maintaining UI stability

## See Also

- SettingsHandler - Base settings class
- FetchHandlerSettings - Fetch handler base settings
- PublishHandlerSettings - Publish handler base settings
- Pipeline Builder - UI integration