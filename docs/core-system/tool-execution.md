# Tool Execution Architecture

**Files**:
- `/inc/Engine/AI/Tools/ToolExecutor.php`
- `/inc/Engine/AI/Tools/ToolParameters.php`

**Since**: 0.2.0

Universal tool discovery, enablement, and execution infrastructure shared by Pipeline AI and Chat API agents. Provides centralized tool management with filter-based registration and configuration validation.

## Overview

The tool execution architecture consists of two core components:

1. **ToolExecutor** - Tool discovery, validation, and execution
2. **ToolParameters** - Centralized parameter building and merging

Together, these components ensure consistent tool behavior across all AI agents while supporting flexible, filter-based tool registration.

## ToolExecutor

**File**: `/inc/Engine/AI/Tools/ToolExecutor.php`

Handles tool discovery via filters, enablement validation, and execution with comprehensive error handling.

### Tool Discovery

Tools are discovered through three filter-based registration patterns, and
resolved via `ToolPolicyResolver::resolve()` — the single entry point for
both chat and pipeline modes:

```php
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;

$resolver = new ToolPolicyResolver();
```

**Pipeline Agent Usage** (with step context):
```php
$tools = $resolver->resolve( array(
    'mode'                 => ToolPolicyResolver::MODE_PIPELINE,
    'previous_step_config' => $previous_step_config,
    'next_step_config'     => $next_step_config,
    'pipeline_step_id'     => $current_pipeline_step_id,
    'engine_data'          => $engine_data,
) );
```

**Chat Agent Usage** (global tools only):
```php
$tools = $resolver->resolve( array(
    'mode' => ToolPolicyResolver::MODE_CHAT,
) );
```

### Tool Registration Patterns

#### 1. Handler Tools (Step-Specific)

Tools registered into the unified `datamachine_tools` registry as
`_handler_callable` entries — runtime-resolved with the adjacent step's
handler config so parameter shapes can adapt to per-step settings (e.g.
AI-decides taxonomies, character limits, etc.). The preferred path is
`HandlerRegistrationTrait::registerHandler()`; manual shape:

```php
add_filter('datamachine_tools', function($tools) {
    $tools['__handler_tools_twitter'] = [
        '_handler_callable' => function($handler_slug, $handler_config, $engine_data) {
            return [
                'twitter_publish' => [
                    'class'          => 'DataMachine\\Core\\Steps\\Publish\\Handlers\\Twitter\\Twitter',
                    'method'         => 'handle_tool_call',
                    'handler'        => $handler_slug,
                    'description'    => 'Post content to Twitter (280 character limit)',
                    'parameters'     => [
                        'content' => [
                            'type'        => 'string',
                            'required'    => true,
                            'description' => 'Tweet content (max 280 chars)',
                        ],
                    ],
                    'handler_config' => $handler_config,
                ],
            ];
        },
        'handler'      => 'twitter',
        'modes'        => ['pipeline'],
        'access_level' => 'admin',
    ];
    return $tools;
});
```

**Key**: `ToolPolicyResolver::gatherPipelineTools()` resolves these entries
against the adjacent pipeline step's `handler_slug` (or `handler_types` for
cross-cutting tools like `skip_item`).

#### 2. Global Tools (All Agents)

Tools registered via `datamachine_global_tools` filter, available to all AI agents:

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
            ]
        ],
        'requires_config' => true
    ];
    return $tools;
});
```

**Location**: `/inc/Engine/AI/Tools/`

**Global Tools**:
- `google_search` - Web search via Google Custom Search API
- `local_search` - WordPress content search
- `web_fetch` - Retrieve web page content
- `wordpress_post_reader` - Read specific WordPress post by URL

#### 3. Chat-Only Tools

Tools registered via `datamachine_chat_tools` filter, available only to Chat agent. Since v0.4.3, these are specialized operation-specific tools:

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

**Chat Tools** (@since v0.4.3):
- `execute_workflow` - Execute complete multi-step workflows
- `add_pipeline_step` - Add steps to existing pipelines
- `api_query` - REST API query for discovery
- `configure_flow_step` - Configure flow step handlers and AI messages
- `configure_pipeline_step` - Configure pipeline AI settings
- `create_flow` - Create flow instances from pipelines
- `create_pipeline` - Create pipelines with optional steps
- `run_flow` - Execute or schedule flows
- `update_flow` - Update flow properties

### Tool Enablement Control

Tools must pass enablement validation before being made available to AI agents:

```php
private static function getAllowedTools(
    array $all_tools,
    ?string $handler_slug,
    ?string $pipeline_step_id = null
): array
```

**Enablement Logic**:

1. **Handler Tools**: Automatically enabled if handler matches
2. **Global/Chat Tools**: Must pass two checks:
   - `datamachine_tool_enabled` filter returns true
   - `datamachine_tool_configured` filter returns true (if tool requires configuration)

**Pipeline Agent Enablement** (step-specific):
```php
add_filter('datamachine_tool_enabled', function($enabled, $tool_name, $tool_config, $pipeline_step_id) {
    if ($pipeline_step_id) {
        // Check if tool is enabled for this specific pipeline step
        $step_config = apply_filters('datamachine_get_flow_step_config', [], $pipeline_step_id);
        $enabled_tools = $step_config['enabled_tools'] ?? [];
        return in_array($tool_name, $enabled_tools);
    }
    return $enabled;
}, 10, 4);
```

**Chat Agent Enablement** (global):
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

### Configuration Validation

Tools requiring external services or API keys use the `requires_config` flag:

```php
$tools['google_search'] = [
    'class' => 'DataMachine\\Engine\\AI\\Tools\\GoogleSearch',
    'method' => 'handle_tool_call',
    'description' => 'Search the web using Google Custom Search',
    'parameters' => [...],
    'requires_config' => true  // Requires API key + search engine ID
];
```

**Configuration Check**:
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

### Tool Execution

Execute tools with automatic parameter building and error handling:

```php
public static function executeTool(
    string $tool_name,
    array $tool_parameters,
    array $available_tools,
    array $data,
    ?string $flow_step_id,
    array $payload
): array
```

**Pipeline Agent Execution**:
```php
$result = ToolExecutor::executeTool(
    $tool_name,           // 'twitter_publish'
    $tool_parameters,     // ['content' => 'Tweet text']
    $available_tools,     // All available tools array
    $data,                // Data packets from previous steps
    $flow_step_id,        // 'step_uuid_flow_123'
    [
        'job_id' => $job_id,
        'data' => $data,
        'handler_config' => $handler_config,
        // ... additional engine parameters
    ]
);
```

**Chat Agent Execution**:
```php
$result = ToolExecutor::executeTool(
    $tool_name,           // 'create_pipeline'
    $tool_parameters,     // ['name' => 'My Pipeline', 'steps' => [...]]
    $available_tools,     // All available tools array
    [],                   // Empty data packets for chat
    null,                 // No flow_step_id for chat
    [
        'session_id' => $session_id
    ]
);
```

**Execution Process**:

1. Validate tool exists in available tools
2. Build complete parameters via `ToolParameters::buildParameters()`
3. Instantiate tool class
4. Call `handle_tool_call()` method with complete parameters
5. Return standardized result array

**Result Structure**:
```php
[
    'success' => true,           // Execution status
    'data' => [...],             // Tool-specific result data
    'tool_name' => 'tool_name',  // Tool identifier
    'error' => 'Error message'   // Only present if success=false
]
```

### Error Handling

Comprehensive error handling for tool execution failures:

**Tool Not Found**:
```php
if (!$tool_def) {
    return [
        'success' => false,
        'error' => "Tool '{$tool_name}' not found",
        'tool_name' => $tool_name
    ];
}
```

**Class Not Found**:
```php
if (!class_exists($class_name)) {
    return [
        'success' => false,
        'error' => "Tool class '{$class_name}' not found",
        'tool_name' => $tool_name
    ];
}
```

**Execution Exception**:
```php
try {
    $tool_handler = new $class_name();
    $tool_result = $tool_handler->handle_tool_call($complete_parameters, $tool_def);
    return $tool_result;
} catch (\Exception $e) {
    do_action('datamachine_log', 'error', 'ToolExecutor: Tool execution exception', [
        'flow_step_id' => $flow_step_id,
        'tool_name' => $tool_name,
        'exception' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    return [
        'success' => false,
        'error' => 'Tool execution exception: ' . $e->getMessage(),
        'tool_name' => $tool_name
    ];
}
```

## ToolParameters

**File**: `/inc/Engine/AI/Tools/ToolParameters.php`

Centralized parameter building for AI tool execution. Merges AI-provided parameters with engine context to create complete parameter sets.

### Standard Parameter Building

Build unified flat parameter structure for tool execution:

```php
public static function buildParameters(
    array $ai_tool_parameters,
    array $payload,
    array $tool_definition
): array
```

**Usage**:
```php
$complete_parameters = ToolParameters::buildParameters(
    ['query' => 'WordPress SEO tips'],  // AI parameters
    ['session_id' => $session_id],      // Unified context
    $tool_definition                     // Tool definition array
);

// Result:
// [
//     'session_id' => 'session_123',
//     'content' => null,
//     'title' => null,
//     'tool_definition' => [...],
//     'tool_name' => 'google_search',
//     'handler_config' => [],
//     'query' => 'WordPress SEO tips'
// ]
```

**Parameter Building Process**:

1. Start with unified parameters (session_id, job_id, data, etc.)
2. Extract `content` from data packet if tool requires it
3. Extract `title` from data packet if tool requires it
4. Add tool metadata (tool_definition, tool_name, handler_config)
5. Merge AI-provided parameters (overwrites defaults)

### Content Extraction

Automatic content extraction from data packets for tools requiring content:

```php
private static function extractContent(array $data_packet, array $tool_definition): ?string
{
    $tool_params = $tool_definition['parameters'] ?? [];
    if (!isset($tool_params['content'])) {
        return null; // Tool doesn't require content
    }

    $latest_entry = !empty($data_packet) ? $data_packet[0] : [];
    $content_data = $latest_entry['content'] ?? [];
    return $content_data['body'] ?? null;
}
```

**Example**: Twitter publish tool receives content from AI step's data packet automatically.

### Title Extraction

Automatic title extraction from data packets for tools requiring title:

```php
private static function extractTitle(array $data_packet, array $tool_definition): ?string
{
    $tool_params = $tool_definition['parameters'] ?? [];
    if (!isset($tool_params['title'])) {
        return null; // Tool doesn't require title
    }

    $latest_entry = !empty($data_packet) ? $data_packet[0] : [];
    $content_data = $latest_entry['content'] ?? [];
    return $content_data['title'] ?? null;
}
```

### Handler Tool Parameter Building

Build parameters for handler tools with engine data integration:

```php
public static function buildForHandlerTool(
    array $ai_tool_parameters,
    array $data,
    array $tool_definition,
    array $engine_parameters,
    array $handler_config
): array
```

**Usage**:
```php
$complete_parameters = ToolParameters::buildForHandlerTool(
    ['content' => 'Tweet text'],           // AI parameters
    $data,                                  // Data packets
    $tool_definition,                       // Tool definition
    [
        'source_url' => 'https://example.com/post',
        'image_url' => 'https://example.com/image.jpg'
    ],                                      // Engine parameters
    $handler_config                         // Handler configuration
);

// Result:
// [
//     'data' => [...],
//     'handler_config' => [...],
//     'content' => 'Generated content from AI step',
//     'title' => 'Generated title from AI step',
//     'tool_definition' => [...],
//     'tool_name' => 'twitter_publish',
//     'content' => 'Tweet text',  // AI parameter overwrites default
//     'source_url' => 'https://example.com/post',
//     'image_url' => 'https://example.com/image.jpg'
// ]
```

**Engine Data Integration**:

Handler tools receive engine parameters (source_url, image_url) from centralized storage:

```php
// Merge engine data (source_url, image_url) from centralized access
foreach ($engine_parameters as $key => $value) {
    $parameters[$key] = $value;
}
```

## Tool Categories

### Handler Tools

**Registration**: `datamachine_tools` filter — `_handler_callable` entries
**Scope**: Step-specific (publish, upsert handlers)
**Enablement**: Automatic when adjacent step's handler slug or type matches
**Examples**: `twitter_publish`, `wordpress_publish`, `bluesky_publish`

**Characteristics**:
- Registry entry carries `'handler' => 'slug'` (exact match) or
  `'handler_types' => [...]` (cross-cutting tools like `skip_item`)
- Resolved at pipeline execution time with the adjacent step's handler config
- Receive data packets and engine parameters
- Execute final workflow actions (publishing, updating)

### Global Tools

**Registration**: `datamachine_global_tools` filter
**Scope**: All AI agents (pipeline + chat)
**Enablement**: Via `datamachine_tool_enabled` filter + configuration check
**Examples**: `google_search`, `local_search`, `web_fetch`, `wordpress_post_reader`

**Characteristics**:
- Available to all agents if enabled
- May require configuration (`requires_config` flag)
- Used for information gathering and research
- Do not modify external systems

### Chat-Only Tools

**Registration**: `datamachine_chat_tools` filter
**Scope**: Chat agent only
**Enablement**: Via `datamachine_tool_enabled` filter
**Examples** (@since v0.4.3): `execute_workflow`, `create_pipeline`, `run_flow`, `configure_flow_step`, etc.

**Characteristics**:
- Only available to chat agent
- Enable conversational workflow building
- Specialized operation-specific tools for pipeline/flow management
- Bridge chat interface to system operations

## Best Practices

### Tool Registration

**Handler Tools** (preferred path is `HandlerRegistrationTrait::registerHandler()`):
```php
add_filter('datamachine_tools', function($tools) {
    $tools['__handler_tools_my_handler'] = [
        '_handler_callable' => function($handler_slug, $handler_config, $engine_data) {
            return [
                'my_tool' => [
                    'class'          => 'MyNamespace\\MyHandler',
                    'method'         => 'handle_tool_call',
                    'handler'        => $handler_slug,
                    'description'    => 'Clear, concise tool description',
                    'parameters'     => [
                        'param_name' => [
                            'type'        => 'string',
                            'required'    => true,
                            'description' => 'Parameter description for AI',
                        ],
                    ],
                    'handler_config' => $handler_config,
                ],
            ];
        },
        'handler'      => 'my_handler',
        'modes'        => ['pipeline'],
        'access_level' => 'admin',
    ];
    return $tools;
});
```

**Global Tools**:
```php
add_filter('datamachine_global_tools', function($tools) {
    $tools['my_global_tool'] = [
        'class' => 'DataMachine\\Engine\\AI\\Tools\\MyTool',
        'method' => 'handle_tool_call',
        'description' => 'Tool description visible to all agents',
        'parameters' => [...],
        'requires_config' => true  // If tool needs API keys/configuration
    ];
    return $tools;
});
```

### Parameter Building

**AI-Provided Parameters Override Defaults**:
```php
// AI provides: ['content' => 'Custom content']
// Data packet has: ['content' => ['body' => 'Default content']]
// Result: 'Custom content' (AI parameter wins)
```

**Access Engine Data in Tool Handler**:
```php
public function handle_tool_call(array $parameters, array $tool_def = []): array {
    $source_url = $parameters['source_url'] ?? null;
    $image_url = $parameters['image_url'] ?? null;
    $handler_config = $parameters['handler_config'] ?? [];

    // Use engine data for operations
    return ['success' => true, 'data' => [...]];
}
```

### Tool Execution

**Always Check Tool Result**:
```php
$result = ToolExecutor::executeTool(...);

if ($result['success']) {
    $data = $result['data'];
    // Process successful result
} else {
    $error = $result['error'];
    // Handle failure
}
```

**Provide Complete Payload Parameters**:
```php
// Pipeline
$payload = [
    'job_id' => $job_id,
    'data' => $data,
    'handler_config' => $handler_config,
    // ... additional context
];

// Chat
$payload = [
    'session_id' => $session_id
];
```

## Related Components

- Universal Engine Architecture - Overall engine structure
- AI Conversation Loop - Tool execution integration
- RequestBuilder Pattern - AI request construction
- Universal Engine Filters - Filter hook reference
