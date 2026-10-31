# DynamicToolParametersTrait

Engine-aware AI tool parameter generation trait that filters tool parameters at definition time based on engine data presence.

## Overview

The `DynamicToolParametersTrait` provides a mechanism to dynamically filter AI tool parameters based on existing engine data. When engine data already contains values for certain parameters, those parameters are excluded from the tool definition so the AI never sees or provides redundant values.

## Location

`inc/Core/DynamicToolParametersTrait.php`

## Key Methods

### `getToolParameters(array $handler_config, array $engine_data = []): array`

Main entry point that returns filtered parameter definitions based on engine data presence.

**Parameters:**
- `$handler_config`: Handler configuration array
- `$engine_data`: Engine data snapshot (optional)

**Returns:** Array of filtered parameter definitions

### `getAllParameters(): array` (Abstract)

Must be implemented by using classes to define all possible tool parameters.

### `getEngineAwareKeys(): array` (Abstract)

Must be implemented by using classes to specify which parameter keys should check engine data.

### `filterByEngineData(array $parameters, array $engine_data): array` (Protected)

Internal method that performs the actual filtering logic, excluding parameters that already have values in engine data.

## Usage Pattern

```php
class MyHandler {
    use DynamicToolParametersTrait;

    protected static function getAllParameters(): array {
        return [
            'title' => ['type' => 'string', 'description' => 'Event title'],
            'venue' => ['type' => 'string', 'description' => 'Venue name'],
            'startDate' => ['type' => 'string', 'description' => 'Start date']
        ];
    }

    protected static function getEngineAwareKeys(): array {
        return ['venue', 'startDate']; // These will be filtered if present in engine data
    }
}

// Usage
$filtered_params = MyHandler::getToolParameters($config, $engine_data);
// If engine_data contains 'venue' and 'startDate', only 'title' will be returned
```

## Integration Points

- Used by event import handlers to prevent AI from providing redundant data
- Integrates with Data Machine's engine data system
- Works with `EventSchemaProvider` for parameter definitions