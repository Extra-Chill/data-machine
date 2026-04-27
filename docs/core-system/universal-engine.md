# Universal Engine Architecture

**Since**: 0.2.0

The Universal Engine is a shared AI infrastructure layer that provides consistent request building, tool execution, and conversation management for both Pipeline AI and Chat API agents in Data Machine.

## Overview

Prior to v0.2.0, Pipeline AI and Chat agents maintained separate implementations of conversation loops, tool execution, and request building. This architectural duplication created maintenance overhead and potential behavioral drift between agent types.

The Universal Engine consolidates this shared functionality into a centralized layer at `/inc/Engine/AI/`, enabling both agent types to leverage identical AI infrastructure while maintaining their specialized behaviors through filter-based integration. Since v0.2.2, it includes ToolManager for centralized tool management and BaseTool for unified tool inheritance.

## Architecture

```
┌─────────────────────────────────────────────────────┐
│          Universal Engine (/inc/Engine/AI/)         │
│                                                      │
│  ┌──────────────────┐      ┌──────────────────┐   │
│  │ AIConversationLoop│      │ RequestBuilder   │   │
│  │ Multi-turn loops │      │ Centralized AI   │   │
│  │ Tool coordination│      │ request building │   │
│  └──────────────────┘      └──────────────────┘   │
│                                                      │
│  ┌──────────────────┐      ┌──────────────────┐   │
│  │ ToolManager      │      │ ToolExecutor     │   │
│  │ Centralized tool │      │ Tool discovery   │   │
│  │ management       │      │ Tool execution   │   │
│  └──────────────────┘      └──────────────────┘   │
│                                                      │
│  ┌──────────────────┐      ┌──────────────────┐   │
│  │ ToolParameters   │      │ ToolResultFinder │   │
│  │ Parameter        │      │ Result search    │   │
│  │ building         │      │ utility          │   │
│  └──────────────────┘      └──────────────────┘   │
│                                                      │
│  ┌──────────────────┐      ┌──────────────────┐   │
│  │ConversationManager│      │ BaseTool         │   │
│  │ Message utilities│      │ (@since v0.14.10)│   │
│  │ and validation   │      │                  │   │
│  └──────────────────┘      └──────────────────┘   │
└─────────────────────────────────────────────────────┘
                        │
        ┌───────────────┴───────────────┐
        │                               │
        ▼                               ▼
┌──────────────────┐          ┌──────────────────┐
│  Pipeline Agent  │          │    Chat Agent    │
│  (/inc/Core/     │          │   (/inc/Api/     │
│   Steps/AI/)     │          │    Chat/)        │
│                  │          │                  │
│ • PipelineCore   │          │ • ChatAgent      │
│   Directive      │          │   Directive      │
│ • Pipeline       │          │ • Specialized    │
│   SystemPrompt   │          │   tool           │
│   Directive      │          │ • Session        │
│ • PipelineContext│          │   management     │
│   Directive      │          │                  │
└──────────────────┘          └──────────────────┘
```

## Core Components

The Universal Engine consists of eight core components that provide shared AI infrastructure:

- **AIConversationLoop** - Multi-turn conversation execution with automatic tool coordination
- **RequestBuilder** - Centralized AI request construction with directive application
- **ToolExecutor** - Universal tool discovery, validation, and execution
- **ToolManager** - Centralized tool management and validation (@since v0.2.1)
- **ToolParameters** - Standardized parameter building for tool handlers
- **ConversationManager** - Message formatting, validation, and conversation utilities
- **ToolResultFinder** - Universal tool result search and interpretation
- **BaseTool** - Unified base class for all AI tools with registration and error handling (@since v0.14.10)

Each component is documented individually in the core system documentation.

### ToolManager (@since v0.2.1)

**Location**: `/inc/Engine/AI/Tools/ToolManager.php`

Centralized tool management system that replaces distributed tool discovery and validation logic throughout the codebase.

#### Key Responsibilities

- **Tool Discovery**: Discovers all tools available for a given agent type and execution context
- **Enablement Validation**: Three-layer validation (global settings → step configuration → runtime validation)
- **Configuration Management**: Checks if tools have required configuration (API keys, OAuth credentials)
- **Opt-Out Pattern**: WordPress-native tools without configuration requirements
- **UI Data Aggregation**: Processes tool metadata for admin interface display

#### Core Methods

```php
// Tool discovery and validation
$tool_manager = new ToolManager();
$global_tools = $tool_manager->get_all_tools();

// Check tool availability (includes enablement and configuration)
$is_available = $tool_manager->is_tool_available('google_search', $step_context_id);

// Configuration checking
$is_configured = $tool_manager->is_tool_configured('google_search');

// WordPress-native tools (no config needed)
$opt_out_tools = $tool_manager->get_opt_out_defaults();
// Returns: ['local_search', 'wordpress_post_reader', 'web_fetch']

// UI data aggregation
$ui_data = $tool_manager->getToolsForUI();
```

#### Three-Layer Validation

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

#### Benefits

- **Code Reduction**: Eliminates ~60% of tool validation code throughout codebase
- **Consistency**: Ensures uniform tool validation across all components
- **Performance**: Implements caching for tool discovery and validation
- **Maintainability**: Single location for tool management logic
- **Extensibility**: Filter-based architecture for custom tools

See Tool Manager for complete documentation.

### BaseTool (@since v0.14.10)

**Location**: `/inc/Engine/AI/Tools/BaseTool.php`

Unified abstract base class for all AI tools (global and chat) that provides standardized error handling and tool registration through inheritance.

#### Key Features

- **Unified Inheritance**: Single base class for all tools (global and chat)
- **Agent-Agnostic Registration**: `registerTool()` method handles all agent types
- **Dynamic Filter Creation**: Automatically creates `datamachine_{agentType}_tools` filters
- **Error Handling**: Standardized error response building with classification
- **Configuration Integration**: Automatic configuration handler registration

#### Usage in Tools

```php
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
}
```

#### Tools Using BaseTool

**Global Tools:**
- **GoogleSearch** - Web search with Custom Search API
- **LocalSearch** - WordPress internal search
- **WebFetch** - Web page content retrieval
- **WordPressPostReader** - Single post analysis

**Chat Tools:** All chat tools in `/inc/Api/Chat/Tools/` extend BaseTool.

#### Benefits

- **Unified Architecture**: One base class for all tools eliminates trait usage
- **Agent Agnostic**: Dynamic filter creation per agent type
- **Error Handling**: Standardized error classification (not_found, validation, permission, system)
- **Extensibility**: Easy to add new agent types without updating tools
- **Maintainability**: Centralized registration and error handling logic

See [BaseTool documentation](base-tool.md) for complete details.
