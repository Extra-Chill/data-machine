# Universal Engine Filters

**Since**: 0.2.0

Comprehensive reference for filter hooks used by the Universal Engine to apply directives, register tools, and control tool enablement across Pipeline AI and Chat API agents.

## Overview

The Universal Engine uses WordPress filters for extensible integration, allowing custom directives, tools, and agent behaviors without modifying core code. All filters follow consistent patterns with clear parameter structures.

## Directive System

Directives inject system messages, tool definitions, and contextual information into AI requests. Since v0.2.5, directives use a unified registration system with priority-based ordering and agent targeting.

### datamachine_directives

Centralized filter for directive registration with priority and agent type targeting.

**Hook Usage**:
```php
apply_filters('datamachine_directives', $directives);
```

**Parameters**:
- `$directives` (array) - Array of directive configurations

**Return**: Modified `$directives` array

**Directive Configuration**:
```php
$directive_config = [
    'class' => DirectiveClass::class,        // Directive class name
    'priority' => 20,                        // Priority (lower = applied first)
    'agent_types' => ['all']                 // 'all', 'pipeline', 'chat', or array
];
```

**Implementation Example**:
```php
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class' => MyDirective::class,
        'priority' => 25,
        'agent_types' => ['all']
    ];

    $directives[] = [
        'class' => PipelineDirective::class,
        'priority' => 30,
        'agent_types' => ['pipeline']
    ];

    return $directives;
});
```

**Registered Directives**:

#### GlobalSystemPromptDirective (Priority 20)
**File**: `/inc/Engine/AI/Directives/GlobalSystemPromptDirective.php`
**Purpose**: Injects user-defined global system prompt from settings
**Configuration**: Settings → AI Configuration → Global System Prompt
**Agent Types**: `['all']`

#### SiteContextDirective (Priority 50)
**File**: `/inc/Engine/AI/Directives/SiteContextDirective.php`
**Purpose**: Injects WordPress site context (site name, URL, description, post types, taxonomies)
**Configuration**: Settings → AI Configuration → Include Site Context in AI Requests (enabled by default)
**Agent Types**: `['all']`

#### PipelineCoreDirective (Priority 10)
**File**: `/inc/Core/Steps/AI/Directives/PipelineCoreDirective.php`
**Purpose**: Foundational pipeline agent identity with tool instructions
**Agent Types**: `['pipeline']`

#### PipelineSystemPromptDirective (Priority 30)
**File**: `/inc/Core/Steps/AI/Directives/PipelineSystemPromptDirective.php`
**Purpose**: User-defined pipeline system prompts
**Agent Types**: `['pipeline']`

#### ChatAgentDirective (Priority 10)
**File**: `/inc/Api/Chat/ChatAgentDirective.php`
**Purpose**: Chat agent identity and capabilities
**Agent Types**: `['chat']`

**Hook Usage (LEGACY — use datamachine_directives instead)**:
```php
// Legacy usage — not used in core since v0.2.5. Use datamachine_directives with agent_types instead.
apply_filters(
    'datamachine_agent_directives',
    $request,
    $agent_type,
    $provider,
    $tools,
    $context
);
```

**Recommended (current)**:
```php
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class' => MyChatDirective::class,
        'priority' => 15,
        'agent_types' => ['chat']
    ];
    return $directives;
});
```

**Parameters**:
- `$request` (array) - AI request array (model, messages)
- `$agent_type` (string) - Agent type ('pipeline' or 'chat')
- `$provider` (string) - AI provider name
- `$tools` (array) - Structured tools array
- `$context` (array) - Agent-specific context (step_id/payload for pipeline, session_id for chat)

**Return**: Modified `$request` array

**Pipeline Implementation Example**:
```php
add_filter('datamachine_agent_directives', function($request, $agent_type, $provider, $tools, $context) {
    if ($agent_type === 'pipeline') {
        // Apply pipeline-specific directives
        $request = PipelineCoreDirective::inject(
            $request,
            $provider,
            $tools,
            $context['step_id'] ?? null,
            $context['payload'] ?? []
        );
    }
    return $request;
}, 10, 5);
```

**Chat Implementation Example**:
```php
add_filter('datamachine_agent_directives', function($request, $agent_type, $provider, $tools, $context) {
    if ($agent_type === 'chat') {
        // Apply chat-specific directives
        $request = ChatAgentDirective::inject($request, $provider, $tools, $context);
    }
    return $request;
}, 10, 5);
```

**Registered Directives**:

#### Pipeline Directives

**PipelineCoreDirective**
**File**: `/inc/Core/Steps/AI/Directives/PipelineCoreDirective.php`
**Purpose**: Foundational pipeline agent identity and operational principles
**Content**: Agent role, workflow approach, data packet structure

```php
add_filter('datamachine_agent_directives', function($request, $agent_type, $provider, $tools, $context) {
    if ($agent_type === 'pipeline') {
        $request = PipelineCoreDirective::inject(
            $request,
            $provider,
            $tools,
            $context['step_id'] ?? null,
            $context['payload'] ?? []
        );
    }
    return $request;
}, 10, 5);
```

**PipelineSystemPromptDirective**
**File**: `/inc/Core/Steps/AI/Directives/PipelineSystemPromptDirective.php`
**Purpose**: User-defined pipeline-level system prompt from pipeline configuration
**Configuration**: Pipeline Builder → System Prompt (template level)

```php
add_filter('datamachine_agent_directives', function($request, $agent_type, $provider, $tools, $context) {
    if ($agent_type === 'pipeline') {
        $request = PipelineSystemPromptDirective::inject(
            $request,
            $provider,
            $tools,
            $context['step_id'] ?? null,
            $context['payload'] ?? []
        );
    }
    return $request;
}, 20, 5);
```

#### Chat Directives

**ChatAgentDirective**
**File**: `/inc/Api/Chat/ChatAgentDirective.php`
**Purpose**: Chat agent identity, capabilities, and available REST API endpoints
**Content**: Agent role, workflow building capabilities, API documentation

```php
add_filter('datamachine_agent_directives', function($request, $agent_type, $provider, $tools, $context) {
    if ($agent_type === 'chat') {
        $request = ChatAgentDirective::inject($request, $provider, $tools, $context);
    }
    return $request;
}, 10, 5);
```



## Tool Filters

Control tool registration, enablement, and configuration validation.

### datamachine_tool_enabled

Universal tool enablement control. Determines which tools are available to AI agents.

**Note**: Direct filter usage for tool availability is replaced by **ToolManager** (@since v0.2.1). The ToolManager provides centralized methods (`is_tool_available()`, `is_tool_configured()`) that internally use these filters but add additional validation layers. Components should use ToolManager methods rather than calling filters directly.

See Tool Manager for the modern tool management approach.

**Hook Usage**:
```php
apply_filters(
    'datamachine_tool_enabled',
    $enabled,
    $tool_name,
    $tool_config,
    $context_id
);
```

**Parameters**:
- `$enabled` (bool) - Current enablement status
- `$tool_name` (string) - Tool identifier
- `$tool_config` (array) - Tool configuration array
- `$context_id` (string|null) - Pipeline step ID or null for chat

**Return**: (bool) Whether tool is enabled

**Pipeline Implementation** (step-specific enablement):
```php
add_filter('datamachine_tool_enabled', function($enabled, $tool_name, $tool_config, $pipeline_step_id) {
    if ($pipeline_step_id) {
        // Pipeline agent: check step-specific enablement
        $step_config = apply_filters('datamachine_get_flow_step_config', [], $pipeline_step_id);
        $enabled_tools = $step_config['enabled_tools'] ?? [];
        return in_array($tool_name, $enabled_tools);
    }
    return $enabled;
}, 10, 4);
```

**Chat Implementation** (global enablement):
```php
add_filter('datamachine_tool_enabled', function($enabled, $tool_name, $tool_config, $context_id) {
    if ($context_id === null) {
        // Chat agent: use global tool enablement
        $tool_configured = apply_filters('datamachine_tool_configured', false, $tool_name);
        $requires_config = !empty($tool_config['requires_config']);
        return !$requires_config || $tool_configured;
    }
    return $enabled;
}, 5, 4); // Priority 5 so pipeline (priority 10) can override
```

### datamachine_tool_configured

Validates tool configuration. Used to check if tools requiring external services (API keys, credentials) are properly configured.

**Hook Usage**:
```php
apply_filters(
    'datamachine_tool_configured',
    $configured,
    $tool_name
);
```

**Parameters**:
- `$configured` (bool) - Current configuration status
- `$tool_name` (string) - Tool identifier

**Return**: (bool) Whether tool is configured

**Implementation Example**:
```php
add_filter('datamachine_tool_configured', function($configured, $tool_name) {
    if ($tool_name === 'google_search') {
        $settings = datamachine_get_data_machine_settings();
        $api_key = $settings['google_search_api_key'] ?? '';
        $search_engine_id = $settings['google_search_engine_id'] ?? '';
        return !empty($api_key) && !empty($search_engine_id);
    }
    return $configured;
}, 10, 2);
```

**Tools Requiring Configuration**:
- `google_search` - API key + Custom Search Engine ID
- OAuth-based handler tools (validated via separate OAuth filters)

### datamachine_global_tools

Registers tools available to all AI agents (pipeline + chat).

**Hook Usage**:
```php
apply_filters('datamachine_global_tools', $tools);
```

**Parameters**:
- `$tools` (array) - Current global tools array

**Return**: Modified `$tools` array

**Implementation Example**:
```php
add_filter('datamachine_global_tools', function($tools) {
    $tools['google_search'] = [
        'class' => 'DataMachine\\Engine\\AI\\Tools\\GoogleSearch',
        'method' => 'handle_tool_call',
        'description' => 'Search the web using Google Custom Search',
        'parameters' => [
            'query' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Search query'
            ],
            'num_results' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Number of results (1-10, default 5)'
            ]
        ],
        'requires_config' => true
    ];
    return $tools;
});
```

**Registered Global Tools**:
- `google_search` - Web search via Google Custom Search API
- `local_search` - WordPress content search
- `web_fetch` - Retrieve web page content
- `wordpress_post_reader` - Read specific WordPress post by URL

**Tool Structure**:
```php
[
    'class' => 'Namespace\\ClassName',     // Tool handler class
    'method' => 'handle_tool_call',        // Handler method
    'description' => 'Tool description',   // Visible to AI
    'parameters' => [                      // Tool parameters
        'param_name' => [
            'type' => 'string|integer|boolean|array',
            'required' => true|false,
            'description' => 'Parameter description'
        ]
    ],
    'requires_config' => true|false        // Whether tool needs configuration
]
```

### datamachine_chat_tools

Registers tools available exclusively to chat agents.

**Hook Usage**:
```php
apply_filters('datamachine_chat_tools', $tools);
```

**Parameters**:
- `$tools` (array) - Current chat tools array

**Return**: Modified `$tools` array

**Implementation Example**:
```php
add_filter('datamachine_chat_tools', function($tools) {
    $tools['create_pipeline'] = [
        'class' => 'DataMachine\\Api\\Chat\\Tools\\CreatePipeline',
        'method' => 'handle_tool_call',
        'description' => 'Create a new pipeline with optional steps',
        'parameters' => [
            'name' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Pipeline name'
            ],
            'steps' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Optional initial steps'
            ]
        ]
    ];
    return $tools;
});
```

**Registered Chat Tools** (@since v0.4.3 specialized tools):
- `execute_workflow` - Execute complete multi-step workflows
- `add_pipeline_step` - Add steps to existing pipelines
- `api_query` - REST API query for discovery
- `configure_flow_step` - Configure flow step handlers and AI messages
- `configure_pipeline_step` - Configure pipeline AI settings
- `create_flow` - Create flow instances from pipelines
- `create_pipeline` - Create pipelines with optional steps
- `run_flow` - Execute or schedule flows
- `update_flow` - Update flow properties

## Directive Application Order

Directives are applied in priority-based order by PromptBuilder (@since v0.2.5):

```
Unified Directive System (datamachine_directives filter):
├── Priority 10-19: Core agent identity
│   ├── PipelineCoreDirective (Priority 10, pipeline only)
│   └── ChatAgentDirective (Priority 10, chat only)
│
├── Priority 20-29: Global system prompts
│   ├── GlobalSystemPromptDirective (Priority 20, all agents)
│   └── GlobalToolsDirective (Priority 25, all agents)
│
├── Priority 30-39: Agent-specific prompts
│   └── PipelineSystemPromptDirective (Priority 30, pipeline only)
│
└── Priority 50+: Site context
    └── SiteContextDirective (Priority 50, all agents)
```

**Migration Note**: Prior to v0.2.5, directives used separate `datamachine_global_directives` and `datamachine_agent_directives` filters. These are now deprecated in favor of the unified `datamachine_directives` filter with priority-based ordering and agent type targeting.

## Best Practices

### Directive Registration

**Use Appropriate Filter Priority**:
```php
// Earlier priority (10-30) for foundational directives
add_filter('datamachine_global_directives', function($request, ...) {
    // Critical system directive
    return $request;
}, 15, 5);

// Later priority (40-50) for contextual information
add_filter('datamachine_global_directives', function($request, ...) {
    // Site context, tool definitions
    return $request;
}, 40, 5);
```

**Always Return Modified Request**:
```php
add_filter('datamachine_global_directives', function($request, $provider, $tools, $step_id, $payload) {
    $request['messages'][] = [
        'role' => 'system',
        'content' => 'Directive content'
    ];

    return $request; // Critical: always return
}, 10, 5);
```

**Check Agent Type for Agent Directives**:
```php
add_filter('datamachine_agent_directives', function($request, $agent_type, $provider, $tools, $context) {
    if ($agent_type === 'pipeline') {
        // Pipeline-specific logic
    } elseif ($agent_type === 'chat') {
        // Chat-specific logic
    }
    return $request;
}, 10, 5);
```

### Tool Filters

**Provide Complete Tool Definitions**:
```php
add_filter('datamachine_global_tools', function($tools) {
    $tools['my_tool'] = [
        'class' => 'MyNamespace\\MyTool',
        'method' => 'handle_tool_call',
        'description' => 'Clear, concise description for AI',
        'parameters' => [
            'required_param' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Parameter description'
            ]
        ],
        'requires_config' => false
    ];
    return $tools;
});
```

**Validate Configuration Properly**:
```php
add_filter('datamachine_tool_configured', function($configured, $tool_name) {
    if ($tool_name === 'my_tool') {
        $settings = datamachine_get_data_machine_settings();
        $api_key = $settings['my_tool_api_key'] ?? '';
        return !empty($api_key) && strlen($api_key) >= 20; // Validate length
    }
    return $configured;
}, 10, 2);
```

**Respect Context ID in Tool Enablement**:
```php
add_filter('datamachine_tool_enabled', function($enabled, $tool_name, $tool_config, $context_id) {
    // context_id = pipeline_step_id for pipeline, null for chat
    if ($context_id === null) {
        // Chat agent logic
    } else {
        // Pipeline agent logic
    }
    return $enabled;
}, 10, 4);
```

## Related Documentation

- Universal Engine Architecture - Overall engine structure
- RequestBuilder Pattern - Directive application system
- Tool Execution Architecture - Tool discovery and execution
- Tool Manager - Centralized tool management (@since v0.2.1)
- AI Conversation Loop - Multi-turn conversation execution
