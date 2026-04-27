# Tool Manager

Centralized tool management system introduced in v0.2.1 that replaces distributed tool discovery and validation logic.

## Overview

ToolManager (`/inc/Engine/AI/Tools/ToolManager.php`) provides centralized methods for tool discovery, enablement validation, and configuration management across the entire system.

## Architecture

- **Location**: `/inc/Engine/AI/Tools/ToolManager.php`
- **Purpose**: Centralize all tool-related operations
- **Replaces**: Distributed filter logic for tool availability
- **Benefits**:
  - Single source of truth for tool availability
  - Consistent validation patterns
  - Performance optimization through centralized logic
  - Easy extensibility for new validation rules

## Key Methods

### get_all_tools()

Returns all global tools available system-wide.

```php
public function get_all_tools(): array
```

**Returns**: Array of global tool definitions

**Usage**:
```php
$tool_manager = new ToolManager();
$global_tools = $tool_manager->get_all_tools();
```

### is_tool_available()

Validates if a tool is available for use based on global enablement, step configuration, and configuration status.

```php
public function is_tool_available(string $tool_id, ?string $context_id = null): bool
```

**Parameters**:
- `$tool_id`: Tool identifier to validate
- `$context_id`: Optional step context ID for step-specific validation

**Returns**: Boolean indicating tool availability

**Validation Layers**:
1. Global settings check (tool must be enabled system-wide)
2. Step configuration check (tool must be selected for specific step, if context provided)
3. Configuration check (tool must have required credentials/API keys)

### is_tool_configured()

Checks if a tool has required configuration (API keys, OAuth, etc).

```php
public function is_tool_configured(string $tool_id): bool
```

**Parameters**:
- `$tool_id`: Tool identifier to check

**Returns**: Boolean indicating configuration status

**Configuration Checks**:
- API keys for third-party services
- OAuth account credentials
- Required settings validation
- Auto-passes for WordPress-native tools

### get_opt_out_defaults()

Returns tools that don't require configuration (WordPress-native functionality).

```php
public function get_opt_out_defaults(): array
```

**Returns**: Array of tool IDs that are always considered "configured" if enabled

**Default Opt-Out Tools**:
- `local_search` - WordPress internal search
- `wordpress_post_reader` - WordPress post content retrieval
- `web_fetch` - Web page content fetching (no API required)

**Usage**:
```php
$tool_manager = new ToolManager();
$opt_out_tools = $tool_manager->get_opt_out_defaults();
// Returns: ['local_search', 'wordpress_post_reader', 'web_fetch']
```

### get_tools_for_settings_page()

Aggregates tool data for admin interface display.

```php
public function get_tools_for_settings_page(): array
```

**Returns**: Array of tool metadata for settings page

**Usage**:
```php
$tool_manager = new ToolManager();
$settings_data = $tool_manager->get_tools_for_settings_page();
```

## Tool Enablement Architecture

### Three-Layer Validation

ToolManager implements a three-layer validation system for tool availability:

```
┌─────────────────────────────────────────────┐
│ Layer 1: Global Settings                    │
│ - System-wide tool enablement toggle        │
│ - Tools can be disabled globally            │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│ Layer 2: Step Configuration                 │
│ - Per-step tool selection in builder        │
│ - Only selected tools available in step     │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│ Layer 3: Runtime Validation                 │
│ - Configuration requirements check          │
│ - API key/OAuth credential validation       │
│ - Tool execution readiness verification     │
└─────────────────────────────────────────────┘
```

### Validation Flow

```php
// Complete validation flow
$tool_manager = new ToolManager();

// 1. Check tool availability (includes all validation layers)
$is_available = $tool_manager->is_tool_available('google_search', $step_context_id);

// 2. Check configuration requirements specifically
$is_configured = $tool_manager->is_tool_configured('google_search');

// 4. Final availability determination
$is_available = $is_globally_enabled && $is_step_enabled && $is_configured;
```

## Opt-Out Pattern

WordPress-native tools use an "opt-out" pattern for configuration:

```php
// Opt-out tools are always considered "configured"
$tool_manager = new ToolManager();
$opt_out_tools = $tool_manager->get_opt_out_defaults();

// Configuration check auto-passes for these tools
$is_configured = $tool_manager->is_tool_configured('local_search');
// Returns: true (WordPress-native, no configuration needed)

$is_configured = $tool_manager->is_tool_configured('google_search');
// Returns: false (requires API key and Search Engine ID)
```

**Rationale**:
- WordPress-native tools use built-in functionality
- No external API keys or services required
- Always available when WordPress is running
- Reduces configuration burden for common tools

## Usage in Components

### ToolExecutor Integration

```php
use DataMachine\Engine\AI\Tools\ToolManager;

class ToolExecutor {
    private ToolManager $tool_manager;

    public function __construct() {
        $this->tool_manager = new ToolManager();
    }

    public function execute_tool(string $tool_id, array $parameters): array {
        // Validate tool is available (includes enablement and configuration)
        if (!$this->tool_manager->is_tool_available($tool_id)) {
            return $this->error_response('Tool not available');
        }

        // Execute tool...
    }
}
```

### Settings UI Integration

```php
use DataMachine\Engine\AI\Tools\ToolManager;

class SettingsPage {
    public function render_tools_section(): void {
        $tool_manager = new ToolManager();
        $tools = $tool_manager->get_tools_for_settings_page();

        foreach ($tools as $tool_id => $tool_data) {
            // Render UI with:
            // - Enable toggle (based on is_enabled)
            // - Config button (if requires_config && !is_configured)
            // - Status indicator (configured vs. needs setup)
        }
    }
}
```

### Pipeline Builder Integration

```php
use DataMachine\Engine\AI\Tools\ToolManager;

class AIStepModal {
    public function get_available_tools(string $handler_slug): array {
        $tool_manager = new ToolManager();

        // Get global tools
        $global_tools = $tool_manager->get_all_tools();

        // Filter to only available tools for this context
        return array_filter($global_tools, function($tool_id) use ($tool_manager) {
            return $tool_manager->is_tool_available($tool_id);
        });
    }
}
```

## Extension Integration

Extensions can integrate with ToolManager through standard filter patterns:

```php
// Register custom tool
add_filter('datamachine_global_tools', function($tools) {
    $tools['my_custom_tool'] = [
        'id' => 'my_custom_tool',
        'class' => MyCustomTool::class,
        'method' => 'execute',
        'description' => 'Custom tool description',
        'parameters' => [/* ... */]
    ];
    return $tools;
});

// ToolManager automatically discovers and validates
$tool_manager = new ToolManager();
$is_available = $tool_manager->is_tool_available('my_custom_tool');
$is_configured = $tool_manager->is_tool_configured('my_custom_tool');
```

## Error Handling

ToolManager provides consistent error handling:

```php
try {
    $tools = $tool_manager->getAvailableTools('pipeline');
} catch (Exception $e) {
    do_action('datamachine_log', 'error', 'Tool discovery failed', [
        'error' => $e->getMessage()
    ]);
    return [];
}
```

## Migration from Legacy Patterns

### Before (Distributed Logic)

```php
// Old pattern: scattered tool validation
$enabled_tools = get_option('datamachine_enabled_tools', []);
$is_enabled = in_array('google_search', $enabled_tools);

$google_config = get_option('datamachine_google_search_config', []);
$is_configured = !empty($google_config['api_key']);

// Validation logic duplicated across multiple files
```

### After (Centralized ToolManager)

```php
// New pattern: centralized validation
$tool_manager = new ToolManager();
$is_enabled = $tool_manager->is_globally_enabled('google_search');
$is_configured = $tool_manager->is_tool_configured('google_search');

// Single source of truth, consistent behavior
```

## Benefits

### Code Reduction

- Eliminates ~60% of tool validation code throughout codebase
- Centralizes tool discovery logic from multiple components
- Reduces maintenance burden for tool-related operations

### Consistency

- Ensures uniform tool validation across all components
- Prevents inconsistent enablement checking
- Standardizes configuration validation patterns

### Performance

- Implements caching for tool discovery
- Reduces redundant filter calls
- Optimizes database queries for tool data

### Maintainability

- Single location for tool management logic
- Easy to add new validation requirements
- Simplified debugging of tool availability issues

### Extensibility

- Filter-based architecture for custom tools
- Clear integration points for extensions
- Supports future tool types and validation patterns

## See Also

- Universal Engine - Engine architecture overview
- Tool Execution Architecture - Tool execution patterns
- AI Tools Overview - Available tools catalog
- Core Filters - Filter reference
