# Core Filters Reference

Comprehensive reference for all WordPress filters used by Data Machine for service discovery, configuration, and data processing.

## Service Discovery Filters

### `datamachine_handlers`

**Purpose**: Register fetch, publish, and update handlers

**Parameters**:
- `$handlers` (array) - Current handlers array

**Return**: Array of handler definitions

**Handler Structure**:
```php
$handlers['handler_slug'] = [
    'type' => 'fetch|publish|update',
    'class' => 'HandlerClassName',
    'label' => __('Human Readable Name', 'data-machine'),
    'description' => __('Handler description', 'data-machine'),
    'requires_auth' => true  // Optional: Metadata flag for auth detection
];
```

**Usage Example**:
```php
add_filter('datamachine_handlers', function($handlers) {
    $handlers['twitter'] = [
        'type' => 'publish',
        'class' => 'DataMachine\\Core\\Steps\\Publish\\Handlers\\Twitter\\Twitter',
        'label' => __('Twitter', 'data-machine'),
        'description' => __('Post content to Twitter with media support', 'data-machine'),
        'requires_auth' => true  // Eliminates auth provider instantiation overhead
    ];
    return $handlers;
});
```

**Handler Metadata**:
- `requires_auth` (boolean): Optional metadata flag for performance optimization
- Eliminates auth provider instantiation during handler settings modal load
- Auth-enabled handlers: Twitter, Bluesky, Facebook, Threads, Google Sheets (publish & fetch), Reddit (fetch)

### `datamachine_step_types`

**Purpose**: Register step types for pipeline execution

**Parameters**:
- `$steps` (array) - Current steps array

**Return**: Array of step definitions

**Step Structure**:
```php
$steps['step_type'] = [
    'name' => __('Step Display Name', 'data-machine'),
    'class' => 'StepClassName',
    'position' => 50 // Display order
];
```

### `datamachine_get_oauth1_handler`

**Purpose**: Service discovery for OAuth 1.0a handler

**Parameters**:
- `$handler` (OAuth1Handler|null) - Current handler instance

**Return**: OAuth1Handler instance

**Location**: `/inc/Core/OAuth/OAuth1Handler.php`

**Usage Example**:
```php
$oauth1 = apply_filters('datamachine_get_oauth1_handler', null);
$request_token = $oauth1->get_request_token($url, $key, $secret, $callback, 'twitter');
$auth_url = $oauth1->get_authorization_url($authorize_url, $oauth_token, 'twitter');
$result = $oauth1->handle_callback('twitter', $access_url, $key, $secret, $account_fn);
```

**Methods**:
- `get_request_token()` - Obtain OAuth request token (step 1)
- `get_authorization_url()` - Build authorization URL (step 2)
- `handle_callback()` - Complete OAuth flow (step 3)

**Providers**: Twitter

### `datamachine_get_oauth2_handler`

**Purpose**: Service discovery for OAuth 2.0 handler

**Parameters**:
- `$handler` (OAuth2Handler|null) - Current handler instance

**Return**: OAuth2Handler instance

**Location**: `/inc/Core/OAuth/OAuth2Handler.php`

**Usage Example**:
```php
$oauth2 = apply_filters('datamachine_get_oauth2_handler', null);
$state = $oauth2->create_state('provider_key');
$auth_url = $oauth2->get_authorization_url($base_url, $params);
$result = $oauth2->handle_callback($provider_key, $token_url, $token_params, $account_fn);
```

**Methods**:
- `create_state()` - Generate OAuth state nonce
- `verify_state()` - Verify OAuth state nonce
- `get_authorization_url()` - Build authorization URL
- `handle_callback()` - Complete OAuth flow with token exchange

**Providers**: Reddit, Facebook, Threads, Google Sheets

## Handler Registration (via HandlerRegistrationTrait @since v0.2.2)

Modern handler registration uses **HandlerRegistrationTrait** which automatically registers with all required filters.

### Filters Registered by Trait

The HandlerRegistrationTrait (`/inc/Core/Steps/HandlerRegistrationTrait.php`) automatically registers handlers with the following filters:

#### datamachine_handlers
Handler metadata registration (always registered)

#### datamachine_auth_providers
Authentication provider registration (conditional on `requires_auth=true`)

#### datamachine_handler_settings
Settings class registration (always registered if settings_class provided)

#### chubes_ai_tools
AI tool registration via callback (conditional on tools_callback provided)

### Usage Pattern

```php
use DataMachine\Core\Steps\HandlerRegistrationTrait;

class MyHandlerFilters {
    use HandlerRegistrationTrait;

    public static function register(): void {
        self::registerHandler(
            $handler_slug,        // 'my_handler'
            $handler_type,        // 'fetch', 'publish', or 'update'
            $handler_class,       // MyHandler::class
            $label,               // __('My Handler', 'textdomain')
            $description,         // __('Handler description', 'textdomain')
            $requires_auth,       // true or false
            $auth_class,          // MyHandlerAuth::class or null
            $settings_class,      // MyHandlerSettings::class
            $tools_callback       // Callback function or null
        );
    }
}

function datamachine_register_my_handler_filters() {
    MyHandlerFilters::register();
}
datamachine_register_my_handler_filters();
```

### Example Implementation

**Publish Handler with OAuth**:
```php
use DataMachine\Core\Steps\HandlerRegistrationTrait;

class TwitterFilters {
    use HandlerRegistrationTrait;

    public static function register(): void {
        self::registerHandler(
            'twitter',
            'publish',
            Twitter::class,
            __('Twitter', 'datamachine'),
            __('Post content to Twitter with media support', 'datamachine'),
            true,  // Requires OAuth
            TwitterAuth::class,
            TwitterSettings::class,
            function($tools, $handler_slug, $handler_config) {
                if ($handler_slug === 'twitter') {
                    $tools['twitter_publish'] = datamachine_get_twitter_tool($handler_config);
                }
                return $tools;
            }
        );
    }
}
```

**Fetch Handler without Auth**:
```php
use DataMachine\Core\Steps\HandlerRegistrationTrait;

class RSSFilters {
    use HandlerRegistrationTrait;

    public static function register(): void {
        self::registerHandler(
            'rss',
            'fetch',
            RSS::class,
            __('RSS Feed', 'datamachine'),
            __('Fetch content from RSS/Atom feeds', 'datamachine'),
            false,  // No auth required
            null,
            RSSSettings::class,
            null  // No AI tools for fetch handlers
        );
    }
}
```

### Benefits

- **Code Reduction**: Reduces handler registration code by ~70%
- **Consistency**: Ensures uniform registration patterns across all handlers
- **Maintainability**: Centralizes filter registration logic
- **Type Safety**: Method signature provides clear parameter requirements

See Handler Registration Trait for complete documentation.

## AI Integration Filters

### `chubes_ai_tools`

**Purpose**: Register AI tools for agentic execution

**Parameters**:
- `$tools` (array) - Current tools array
- `$handler_slug` (string|null) - Target handler slug (for handler-specific tools)
- `$handler_config` (array) - Handler configuration

**Return**: Array of tool definitions

**Tool Structure**:
```php
$tools['tool_name'] = [
    'class' => 'ToolClassName',
    'method' => 'handle_tool_call',
    'description' => 'Tool description for AI',
    'parameters' => [
        'param_name' => [
            'type' => 'string|integer|boolean',
            'required' => true|false,
            'description' => 'Parameter description'
        ]
    ],
    'handler' => 'handler_slug', // Optional: makes tool handler-specific
    'requires_config' => true|false, // Optional: UI configuration indicator
    'handler_config' => $handler_config // Optional: passed to tool execution
];
```

### `chubes_ai_request`

**Purpose**: Process AI requests with provider routing and modular directive system message injection

**Parameters**:
- `$request` (array) - AI request data
- `$provider` (string) - AI provider slug
- `$streaming_callback` (mixed) - Streaming callback function
- `$tools` (array) - Available tools array
- `$pipeline_step_id` (string|null) - Pipeline step ID for context

**Return**: Array with AI response

**Universal Engine Directive System** (@since v0.2.0): Centralized AI request construction via `RequestBuilder` with hierarchical directive application through filter-based architecture.

**Directive Application via RequestBuilder**:
All AI requests now use `RequestBuilder::build()` which integrates with `PromptBuilder` for unified directive management with priority-based ordering:

1. **Unified Directives** (`datamachine_directives` filter) - Centralized directive registration with priority and agent targeting

**Request Structure**:
```php
$ai_response = RequestBuilder::build(
    $messages,      // Messages array with role/content
    $provider,      // AI provider name
    $model,         // Model identifier
    $tools,         // Raw tools array from filters
    $agent_type,    // 'chat' or 'pipeline'
    $context        // Agent-specific context
);
```

**Current Directive Implementations**:

**Global Directives** (all agents):
- `GlobalSystemPromptDirective` - Background guidance for all AI agents
- `SiteContextDirective` - WordPress environment information (optional)

**Pipeline Agent Directives**:
- `PipelineCoreDirective` - Foundational agent identity with tool instructions (priority 10)
- `PipelineSystemPromptDirective` - User-defined system prompts (priority 20)
- `PipelineContextDirective` - Pipeline context files (priority 35)

**Chat Agent Directives**:
- `ChatAgentDirective` - Chat agent identity and capabilities

**Unified Directive Registration**:
```php
// Register directives with priority and agent targeting
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class' => MyGlobalDirective::class,
        'priority' => 20,  // Lower = applied first
        'agent_types' => ['all']  // 'all', 'pipeline', 'chat', or array
    ];

    $directives[] = [
        'class' => MyPipelineDirective::class,
        'priority' => 30,
        'agent_types' => ['pipeline']
    ];

    return $directives;
});
```

**Note**: All AI request building now uses `RequestBuilder::build()` to ensure consistent request structure and directive application. Direct calls to `chubes_ai_request` filter are deprecated - use RequestBuilder instead.

### `datamachine_session_title_prompt`

**Purpose**: Customize or replace the AI prompt used for generating session titles.

**Parameters**:
- `$default_prompt` (string) - The default prompt for title generation
- `$context` (array) - Conversation context with the following keys:
  - `first_user_message` (string) - The first message from the user
  - `first_assistant_response` (string) - The assistant's first response
  - `conversation_context` (string) - Combined conversation context

**Return**: String - The prompt to use for title generation

**Location**: `/inc/Abilities/SystemAbilities.php`

**Usage Example**:
```php
// Generate code names instead of descriptive titles
add_filter('datamachine_session_title_prompt', function($prompt, $context) {
    return "Generate a two-word code name like 'cosmic-owl' or 'azure-phoenix'. " .
           "Return ONLY the code name, nothing else.";
}, 10, 2);

// Add custom context to the default prompt
add_filter('datamachine_session_title_prompt', function($prompt, $context) {
    return $prompt . "\n\nAdditional instruction: Keep titles under 5 words.";
}, 10, 2);

// Generate privacy-safe titles without chat content
add_filter('datamachine_session_title_prompt', function($prompt, $context) {
    $words = ['cosmic', 'azure', 'golden', 'silent', 'swift'];
    $nouns = ['owl', 'phoenix', 'river', 'mountain', 'forest'];
    return sprintf(
        "Return exactly this title: %s-%s",
        $words[array_rand($words)],
        $nouns[array_rand($nouns)]
    );
}, 10, 2);
```

**Use Cases**:
- Generate code names instead of descriptive titles
- Add custom instructions to title generation
- Create privacy-safe titles that don't expose chat content
- Customize title style per site or plugin

## Pipeline Operations Filters

### `datamachine_create_pipeline`

**Purpose**: Create new pipeline

**Abilities Integration**: Handled by `datamachine/create-pipeline` ability.

**Parameters**:
- `$pipeline_id` (null) - Placeholder for return value
- `$data` (array) - Pipeline creation data

**Return**: Integer pipeline ID or false

**Data Structure**:
```php
$data = [
    'pipeline_name' => 'Pipeline Name',
    'pipeline_config' => $config_array
];
```

**Usage**:
```php
// Abilities API
$ability = wp_get_ability( 'datamachine/create-pipeline' );
$result = $ability->execute( [ 'pipeline_name' => 'Pipeline Name', 'options' => $options ] );

// Filter Hook (for extensibility)
$pipeline_id = apply_filters('datamachine_create_pipeline', null, $data);
```

### `datamachine_create_flow`

**Purpose**: Create new flow instance

**Abilities Integration**: Handled by `datamachine/create-flow` ability.

**Parameters**:
- `$flow_id` (null) - Placeholder for return value
- `$data` (array) - Flow creation data

**Return**: Integer flow ID or false

**Usage**:
```php
// Abilities API
$ability = wp_get_ability( 'datamachine/create-flow' );
$result = $ability->execute( [ 'pipeline_id' => $pipeline_id, 'flow_name' => 'Flow Name' ] );

// Filter Hook (for extensibility)
$flow_id = apply_filters('datamachine_create_flow', null, $data);
```

### `datamachine_get_pipelines`

**Purpose**: Retrieve pipeline data

**Parameters**:
- `$pipelines` (array) - Empty array for return data
- `$pipeline_id` (int|null) - Specific pipeline ID or null for all

**Return**: Array of pipeline data

### `datamachine_get_flow_config`

**Purpose**: Get flow configuration

**Parameters**:
- `$config` (array) - Empty array for return data
- `$flow_id` (int) - Flow ID

**Return**: Array of flow configuration

### `datamachine_get_flow_step_config`

**Purpose**: Get specific flow step configuration

**Parameters**:
- `$config` (array) - Empty array for return data
- `$flow_step_id` (string) - Composite flow step ID

**Return**: Array containing flow step configuration

## Authentication Filters

### `datamachine_auth_providers`

**Purpose**: Register OAuth authentication providers

**Parameters**:
- `$providers` (array) - Current auth providers

**Return**: Array of authentication provider instances

**Structure**:
```php
$providers['provider_slug'] = new AuthProviderClass();
```

### `datamachine_retrieve_oauth_account`

**Purpose**: Get stored OAuth account data

**Parameters**:
- `$account` (array) - Empty array for return data
- `$handler` (string) - Handler slug

**Return**: Array of account information

### `datamachine_oauth_callback`

**Purpose**: Generate OAuth authorization URL

**Parameters**:
- `$url` (string) - Empty string for return data
- `$provider` (string) - Provider slug

**Return**: OAuth authorization URL string

## Configuration Filters

### `datamachine_tool_configured`

**Purpose**: Check if tool is properly configured

**Parameters**:
- `$configured` (bool) - Default configuration status
- `$tool_id` (string) - Tool identifier

**Return**: Boolean configuration status

### `datamachine_get_tool_config`

**Purpose**: Retrieve tool configuration data

**Parameters**:
- `$config` (array) - Empty array for return data
- `$tool_id` (string) - Tool identifier

**Return**: Array of tool configuration

### `datamachine_handler_settings`

**Purpose**: Register handler settings classes

**Parameters**:
- `$settings` (array) - Current settings array

**Return**: Array of settings class instances

## Parameter Processing Filters

### `datamachine_engine_data`

**Purpose**: Centralized engine data access filter for retrieving stored engine parameters

**Parameters**:
- `$engine_data` (array) - Default empty array for return data
- `$job_id` (int) - Job ID to retrieve engine data for

**Return**: Array containing engine data (source_url, image_url, etc.)

**Engine Data Structure**:
```php
$engine_data = [
    'source_url' => $source_url,    // For link attribution and content updates
    'image_url' => $image_url,      // For media handling
    // Additional engine parameters as needed
];
```

**Core Implementation (EngineData.php)**:
```php
add_filter('datamachine_engine_data', function($engine_data, $job_id) {
    if (empty($job_id)) {
        return [];
    }

    // Use direct database class instantiation
    $db_jobs = new \DataMachine\Core\Database\Jobs\Jobs();

    $retrieved_data = $db_jobs->retrieve_engine_data($job_id);
    return $retrieved_data ?: [];
}, 10, 2);
```

**Usage by Steps**:
```php
// Steps access engine data as needed
$engine_data = apply_filters('datamachine_engine_data', [], $job_id);
$source_url = $engine_data['source_url'] ?? null;
$image_url = $engine_data['image_url'] ?? null;
```

**Engine Data Storage (by Fetch Handlers)**:
```php
// Fetch handlers store engine parameters in database via centralized filter (array storage)
if ($job_id) {
    apply_filters('datamachine_engine_data', null, $job_id, [
        'source_url' => $source_url,
        'image_url' => $image_url
    ]);
}
```

**Benefits**:
- ✅ **Centralized Access**: Single filter for all engine data retrieval
- ✅ **Filter-Based Discovery**: Uses established database service discovery pattern
- ✅ **Clean Separation**: Engine data separate from AI data packets
- ✅ **Flexible**: Steps access only what they need via filter call

## Centralized Handler Filters

### `datamachine_timeframe_limit`

**Purpose**: Shared timeframe parsing across fetch handlers with discovery and conversion modes

**Parameters**:
- `$default` (mixed) - Default value (null or timestamp)
- `$timeframe_limit` (string|null) - Timeframe specification

**Return**: Array of options (discovery mode) or timestamp (conversion mode) or null

**Discovery Mode** (when `$timeframe_limit` is null):
```php
$timeframe_options = apply_filters('datamachine_timeframe_limit', null, null);
// Returns:
[
    'all_time' => __('All Time', 'data-machine'),
    '24_hours' => __('Last 24 Hours', 'data-machine'),
    '72_hours' => __('Last 72 Hours', 'data-machine'),
    '7_days'   => __('Last 7 Days', 'data-machine'),
    '30_days'  => __('Last 30 Days', 'data-machine'),
]
```

**Conversion Mode** (when `$timeframe_limit` is a string):
```php
$cutoff_timestamp = apply_filters('datamachine_timeframe_limit', null, '24_hours');
// Returns: Unix timestamp for 24 hours ago or null for 'all_time'
```

### `datamachine_keyword_search_match`

**Purpose**: Universal keyword matching with OR logic for all fetch handlers

**Parameters**:
- `$default` (bool) - Default match result
- `$content` (string) - Content to search in
- `$search_term` (string) - Comma-separated keywords

**Return**: Boolean indicating if any keyword matches

**Usage**:
```php
$matches = apply_filters('datamachine_keyword_search_match', true, $content, 'wordpress,ai,automation');
// Returns true if content contains 'wordpress' OR 'ai' OR 'automation'
```

**Features**:
- **OR Logic**: Any keyword match passes the filter
- **Case Insensitive**: Uses `mb_stripos()` for Unicode-safe matching
- **Comma Separated**: Supports multiple keywords separated by commas
- **Empty Filter**: Returns true when no search term provided (match all)

### `datamachine_data_packet`

**Purpose**: Centralized data packet creation with standardized structure

**Parameters**:
- `$data` (array) - Current data packet array
- `$packet_data` (array) - Packet data to add
- `$flow_step_id` (string) - Flow step identifier
- `$step_type` (string) - Step type

**Return**: Array with new packet added to front

**Usage**:
```php
$data = apply_filters('datamachine_data_packet', $data, $packet_data, $flow_step_id, $step_type);
```

**Features**:
- **Standardized Structure**: Ensures type and timestamp fields are present
- **Preserves All Fields**: Merges packet_data while adding missing structure
- **Front Addition**: Uses `array_unshift()` to add new packets to the beginning

## Data Processing Filters

### `datamachine_is_item_processed`

**Purpose**: Check if item was already processed

**Parameters**:
- `$processed` (bool) - Default processed status
- `$flow_step_id` (string) - Flow step identifier
- `$source_type` (string) - Handler source type
- `$item_id` (mixed) - Item identifier

**Return**: Boolean processed status


## Files Repository Filters

### `datamachine_files_repository`

**Purpose**: Access files repository service

**Parameters**:
- `$repositories` (array) - Empty array for repository services

**Return**: Array with 'files' key containing repository instance

## Directive System Filters

### `datamachine_directives`

**Since**: v0.2.5

**Purpose**: Unified directive registration with priority-based ordering and agent type targeting

**Parameters**:
- `$directives` (array) - Array of directive configurations

**Return**: Modified directives array

**Directive Configuration Structure**:
```php
[
    'class' => DirectiveClass::class,   // Directive class name
    'priority' => 20,                    // Priority (lower = applied first)
    'agent_types' => ['all']             // 'all', 'pipeline', 'chat', or array
]
```

**Usage Example**:
```php
add_filter('datamachine_directives', function($directives) {
    // Global directive (all agents)
    $directives[] = [
        'class' => MyGlobalDirective::class,
        'priority' => 25,
        'agent_types' => ['all']
    ];

    // Pipeline-specific directive
    $directives[] = [
        'class' => MyPipelineDirective::class,
        'priority' => 35,
        'agent_types' => ['pipeline']
    ];

    return $directives;
});
```

**Priority Guidelines**:
- **10-19**: Core agent identity and foundational instructions
- **20-29**: Global system prompts and universal behavior
- **30-39**: Agent-specific system prompts and context
- **40-49**: Workflow and execution context directives
- **50+**: Environmental and site-specific directives

### `datamachine_global_directives` (LEGACY — use `datamachine_directives`)

**Deprecated**: v0.2.5
**Replacement**: Use `datamachine_directives` with `agent_types => ['all']`

**Purpose**: Modify global AI system directives applied across all AI interactions (pipeline + chat)

**Migration Example**:
```php
// LEGACY (pre-v0.2.5)
add_filter('datamachine_global_directives', function($directives) {
    $directives[] = [
        'priority' => 25,
        'content' => 'Custom global directive'
    ];
    return $directives;
});

// CURRENT (v0.2.5+)
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class' => MyGlobalDirective::class,
        'priority' => 25,
        'agent_types' => ['all']
    ];
    return $directives;
});
```

### `datamachine_agent_directives` (LEGACY — use `datamachine_directives`)

**Deprecated**: v0.2.5
**Replacement**: Use `datamachine_directives` with agent-specific `agent_types` targeting

**Purpose**: Modify AI system directives for specific agent types (pipeline or chat)

**Parameters**:
- `$request` (array) - Current AI request being built
- `$agent_type` (string) - Agent type ('pipeline' or 'chat')
- `$provider` (string) - AI provider (openai, anthropic, etc.)
- `$tools` (array) - Available tools for the agent
- `$context` (array) - Agent-specific context data

**Return**: Modified request array

**Migration Example**:
```php
// LEGACY (pre-v0.2.5)
add_filter('datamachine_agent_directives', function($request, $agent_type, $provider, $tools, $context) {
    if ($agent_type === 'pipeline') {
        $request['messages'][] = [
            'role' => 'system',
            'content' => 'Pipeline-specific directive'
        ];
    }
    return $request;
}, 10, 5);

// CURRENT (v0.2.5+)
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class' => MyPipelineDirective::class,
        'priority' => 30,
        'agent_types' => ['pipeline']
    ];
    return $directives;
});
```

## Navigation Filters

### `datamachine_get_next_flow_step_id`

**Purpose**: Find next step in flow execution sequence

**Parameters**:
- `$next_id` (null) - Placeholder for return value
- `$current_flow_step_id` (string) - Current step ID

**Return**: String next flow step ID or null if last step

## Universal Engine Architecture

**Since**: 0.2.0
**Location**: `/inc/Engine/AI/`

Data Machine's Universal Engine provides shared AI infrastructure serving both Pipeline and Chat agents. See `/docs/core-system/universal-engine.md` for complete architecture documentation.

### ToolParameters (`/inc/Engine/AI/Tools/ToolParameters.php`)

**Purpose**: Centralized parameter building for all AI tools with unified flat structure.

**Core Methods**:

#### `buildParameters()`
```php
\DataMachine\Engine\AI\ToolParameters::buildParameters(array $data, ?string $job_id, ?string $flow_step_id): array
```
Builds flat parameter structure for standard AI tools with content extraction and job context.

**Returns**:
```php
[
    'content_string' => 'Clean content text',
    'title' => 'Original title',
    'job_id' => '123',
    'flow_step_id' => 'step_uuid_flow_123'
]
```

#### `buildForHandlerTool()`
```php
\DataMachine\Engine\AI\ToolParameters::buildForHandlerTool(array $data, array $tool_def, ?string $job_id, ?string $flow_step_id): array
```
Builds parameters for handler-specific tools with engine data merging (source_url, image_url).

**Returns**:
```php
[
    // Standard parameters
    'content_string' => 'Clean content',
    'title' => 'Title',
    'job_id' => '123',
    'flow_step_id' => 'step_uuid_flow_123',

    // Tool metadata
    'tool_definition' => [...],
    'tool_name' => 'twitter_publish',
    'handler_config' => [...],

    // Engine parameters (from database)
    'source_url' => 'https://example.com/post',
    'image_url' => 'https://example.com/image.jpg'
]
```

**Key Features**:
- Content/title extraction from data packets
- Flat parameter structure for AI simplicity
- Tool metadata integration
- Engine parameter injection for handlers (source_url for link attribution, image_url for media handling)

### ToolExecutor (`/inc/Engine/AI/Tools/ToolExecutor.php`)

**Purpose**: Universal tool discovery and execution infrastructure.

**Core Method**:

#### `getAvailableTools()`
```php
\DataMachine\Engine\AI\ToolExecutor::getAvailableTools(
    string $agent_type,      // 'pipeline' or 'chat'
    ?string $handler_slug,   // Handler identifier
    array $handler_config,   // Handler configuration
    ?string $flow_step_id    // Flow step identifier
): array
```

**Discovery Process**:
1. Handler Tools - Retrieved via `chubes_ai_tools` filter
2. Global Tools - Retrieved via `datamachine_global_tools` filter
3. Chat Tools - Retrieved via `datamachine_chat_tools` filter (chat only)
4. Enablement Check - Each tool filtered through `datamachine_tool_enabled`

### AIConversationLoop (`/inc/Engine/AI/AIConversationLoop.php`)

**Purpose**: Multi-turn conversation execution with automatic tool calling.

**Core Method**:

#### `execute()`
```php
$loop = new \DataMachine\Engine\AI\AIConversationLoop();
$final_response = $loop->execute(
    array $initial_messages,
    array $available_tools,
    string $provider_name,
    string $model,
    string $agent_type,      // 'pipeline' or 'chat'
    array $context
): array
```

**Features**:
- Automatic tool execution during conversation turns
- Conversation completion detection
- Turn-based state management with chronological ordering
- Duplicate message prevention
- Maximum turn limiting (default: 10)

### ConversationManager (`/inc/Engine/AI/ConversationManager.php`)

**Purpose**: Message formatting utilities for AI requests.

**Key Features**:
- Message formatting for AI providers
- Tool call recording and tracking
- Conversation message normalization
- Chronological message ordering

### RequestBuilder (`/inc/Engine/AI/RequestBuilder.php`)

**Purpose**: Centralized AI request construction for all agents.

**Core Method**:

#### `build()`
```php
$response = \DataMachine\Engine\AI\RequestBuilder::build(
    array $messages,
    string $provider,
    string $model,
    array $tools,
    string $agent_type,      // 'pipeline' or 'chat'
    array $context
): array
```

**Features**:
- Directive application system (global, agent-specific, pipeline, chat)
- Tool restructuring for AI provider compatibility
- Integration with ai-http-client library
- Unified request format across all providers