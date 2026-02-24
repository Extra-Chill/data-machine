# BaseTool Class

**Location**: `/inc/Engine/AI/Tools/BaseTool.php`
**Since**: v0.14.10
**Purpose**: Unified abstract base class for all AI tools (global and chat). Provides standardized error handling and tool registration through inheritance.

## Overview

The BaseTool class provides agent-agnostic tool registration patterns that dynamically create appropriate filters based on agent type. This enables unlimited agent specialization while maintaining consistent registration patterns across the entire AI tool ecosystem.

## Key Features

- **Unified Inheritance**: Single base class for all tools (global and chat)
- **Agent-Agnostic Registration**: `registerTool()` method handles all agent types
- **Dynamic Filter Creation**: Automatically creates `datamachine_{agentType}_tools` filters
- **Extensible Architecture**: Supports current and future agent types (global, chat, frontend, etc.)
- **Configuration Management**: Built-in support for tool configuration handlers
- **Error Handling**: Standardized error response building with classification

## Methods

### Tool Registration Methods

#### `registerTool(string $agentType, string $toolName, array|callable $toolDefinition)`

Core registration method that dynamically creates the appropriate filter based on agent type.

**Parameters:**
- `$agentType`: Agent type identifier (global, chat, frontend, etc.)
- `$toolName`: Tool identifier
- `$toolDefinition`: Tool definition array OR callable that returns it

**Example:**
```php
$this->registerTool('chat', 'create_pipeline', [$this, 'getToolDefinition']);
```

#### `registerGlobalTool(string $tool_name, array|callable $tool_definition)`

Convenience method for registering tools available to all AI agents (pipeline + chat).

#### `registerChatTool(string $tool_name, array|callable $tool_definition)`

Convenience method for registering chat-specific tools.

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

### Global Tool

```php
<?php
namespace MyExtension\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;

class GoogleSearch extends BaseTool {

    public function __construct() {
        $this->registerGlobalTool('google_search', [$this, 'getToolDefinition']);
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
        $this->registerChatTool('create_pipeline', [$this, 'getToolDefinition']);
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
- **Extensibility**: Supports unlimited agent types without code changes
- **Maintainability**: Centralized registration and error handling logic in one base class
- **Future-Proof**: Automatic support for new agent types through dynamic filter creation

## Integration with ToolManager

The BaseTool class integrates seamlessly with the ToolManager system:

- **Filter-Based Discovery**: Registered tools are automatically discovered by ToolManager
- **Configuration Validation**: `check_configuration()` methods are called during tool enablement checks
- **Lazy Evaluation**: Tool definitions support callable format for deferred loading

## Agent Type Support

The class supports multiple agent types through dynamic filter creation:

- **global**: `datamachine_global_tools` - Available to all agents
- **chat**: `datamachine_chat_tools` - Chat agent specific
- **pipeline**: `datamachine_pipeline_tools` - Pipeline agent specific
- **frontend**: `datamachine_frontend_tools` - Frontend agent specific
- **Custom**: Any agent type automatically gets `datamachine_{type}_tools` filter
