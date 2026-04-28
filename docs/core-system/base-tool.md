# BaseTool Class

**Location**: `/inc/Engine/AI/Tools/BaseTool.php`
**Since**: v0.14.10
**Purpose**: Unified abstract base class for all AI tools (global and chat). Provides standardized error handling and tool registration through inheritance.

## Overview

The BaseTool class provides one registration path for tools in the unified `datamachine_tools` registry. Each tool declares the agent modes where it is visible, such as `chat` or `pipeline`.

## Key Features

- **Unified Inheritance**: Single base class for all tools (global and chat)
- **Mode-Aware Registration**: `registerTool()` declares where each tool is visible
- **Unified Registry**: Registers tools through the single `datamachine_tools` filter
- **Extensible Architecture**: Supports current and future agent modes (chat, pipeline, system, etc.)
- **Configuration Management**: Built-in support for tool configuration handlers
- **Error Handling**: Standardized error response building with classification

## Methods

### Tool Registration Methods

#### `registerTool(string $toolName, array|callable $toolDefinition, array $modes = [], array $meta = [])`

Core registration method that adds a tool to the unified registry with explicit mode visibility.

**Parameters:**
- `$toolName`: Tool identifier
- `$toolDefinition`: Tool definition array OR callable that returns it
- `$modes`: Agent modes where this tool is visible, such as `['chat']` or `['chat', 'pipeline']`
- `$meta`: Permission metadata, such as `ability`, `abilities`, or `access_level`

**Example:**
```php
$this->registerTool('create_pipeline', [$this, 'getToolDefinition'], ['chat']);
```

#### `registerConfigurationHandlers(string $tool_id)`

Registers configuration management handlers for tools that require setup.

### Error Handling Methods

#### `isAbilitySuccess($result): bool`

Check if ability result indicates success. Handles WP_Error, non-array results, and missing success key.

#### `getAbilityError($result, string $fallback): string`

Extract error message from ability result with fallback.

#### `classifyErrorType(string $error): string`

Classify error type for AI agent guidance:
- `not_found`: Resource doesn't exist, do not retry
- `validation`: Fix parameters and retry
- `permission`: Access denied, do not retry
- `system`: May retry once if error suggests fixable cause

#### `buildErrorResponse(string $error, string $tool_name): array`

Build standardized error response with classification.

## Usage Patterns

### Pipeline-Capable Tool

```php
<?php
namespace MyExtension\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;

class GoogleSearch extends BaseTool {

    public function __construct() {
        $this->registerTool('google_search', [$this, 'getToolDefinition'], ['chat', 'pipeline'], ['access_level' => 'admin']);
        $this->registerConfigurationHandlers('google_search');
    }

    public function getToolDefinition(): array {
        return [
            'class' => self::class,
            'method' => 'handle_tool_call',
            'description' => 'Search the web using Google Custom Search API',
            'parameters' => [
                'query' => ['type' => 'string', 'description' => 'Search query'],
            ]
        ];
    }

    public function handle_tool_call(array $parameters): array {
        // Tool implementation
    }
}
```

### Chat Tool

```php
<?php
namespace DataMachine\Api\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;

class CreatePipeline extends BaseTool {

    public function __construct() {
        $this->registerTool('create_pipeline', [$this, 'getToolDefinition'], ['chat'], ['access_level' => 'admin']);
    }

    public function getToolDefinition(): array {
        return [
            'class' => self::class,
            'method' => 'handle_tool_call',
            'description' => 'Create a new pipeline with optional steps',
            'parameters' => [
                'name' => ['type' => 'string', 'description' => 'Pipeline name'],
            ]
        ];
    }

    public function handle_tool_call(array $parameters): array {
        // With error handling
        $result = $this->executeAbility($parameters);

        if (!$this->isAbilitySuccess($result)) {
            return $this->buildErrorResponse(
                $this->getAbilityError($result, 'Pipeline creation failed'),
                'create_pipeline'
            );
        }

        return [
            'success' => true,
            'data' => $result['data'],
            'tool_name' => 'create_pipeline',
        ];
    }
}
```

## Benefits

- **Code Reduction**: Eliminates repetitive registration code across tool implementations
- **Consistency**: Ensures uniform registration and error handling patterns across all AI tools
- **Extensibility**: Supports additional agent modes without code changes
- **Maintainability**: Centralized registration and error handling logic in one base class
- **Future-Proof**: Tool definitions are mode-tagged before lazy definitions are resolved

## Integration with ToolManager

The BaseTool class integrates seamlessly with the ToolManager system:

- **Filter-Based Discovery**: Registered tools are automatically discovered by ToolManager
- **Configuration Validation**: `check_configuration()` methods are called during tool enablement checks
- **Lazy Evaluation**: Tool definitions support callable format for deferred loading

## Mode Support

The class supports any mode string declared in the tool's `modes` array. Built-in modes are `chat`, `pipeline`, and `system`; third-party callers may resolve custom modes through `ToolPolicyResolver`.

Only declare `pipeline` when the tool is useful inside automated pipeline AI steps. Chat affordances and tools that duplicate engine-level pipeline behavior should stay `chat`-only.
