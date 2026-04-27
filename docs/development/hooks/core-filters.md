# Core Filters Reference

Comprehensive reference for all WordPress filters used by Data Machine for service discovery, configuration, and data processing.

## Service Discovery Filters

### `datamachine_handlers`

**Purpose**: Register fetch, publish, and upsert handlers

**Parameters**:
- `$handlers` (array) - Current handlers array

**Return**: Array of handler definitions

**Handler Structure**:
```php
$handlers['handler_slug'] = [
    'type' => 'fetch|publish|upsert',
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

#### datamachine_tools (handler tools)
AI tool registration via callback (conditional on tools_callback provided). The
trait wires the callback into the unified `datamachine_tools` registry as a
deferred `_handler_callable` entry resolved at pipeline execution time.

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
            function($handler_slug, $handler_config, $engine_data) {
                return [
                    'twitter_publish' => datamachine_get_twitter_tool($handler_config),
                ];
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

### `datamachine_tools`

**Purpose**: Unified registry for every AI tool — static global tools AND per-handler
runtime-generated tools. Consumed by `ToolPolicyResolver` when gathering the
available tool set for a pipeline or chat context.

**Parameters**:
- `$tools` (array) - Current tools registry (keyed by tool name or internal wrapper key)

**Return**: Modified tools array

#### Static tool entry (global tools)

```php
add_filter('datamachine_tools', function($tools) {
    $tools['my_tool'] = [
        '_callable'     => [$this, 'getToolDefinition'],  // Lazy resolution
        'modes'         => ['chat', 'pipeline'],
        'ability'       => 'datamachine/my-ability',      // Links to an ability for permission resolution
        'access_level'  => 'admin',                       // Fallback when no ability is linked
    ];
    return $tools;
});
```

The `_callable` resolves to the full tool definition array (name, description,
parameters, etc.) at first access. See
[Tool Registration](../../core-system/tool-execution.md) for the resolved
definition contract.

#### Handler tool entry (dynamic, runtime-generated)

Handler tools are shaped by the runtime handler configuration of the adjacent
pipeline step (e.g. `ai_decides` taxonomy choices produce different tool
parameter schemas). The registry entry contains a `_handler_callable` that
receives runtime context and returns one or more tool definitions.

```php
add_filter('datamachine_tools', function($tools) {
    $tools['__handler_tools_wordpress_publish'] = [
        '_handler_callable' => function($handler_slug, $handler_config, $engine_data) {
            return [
                'wordpress_publish' => [
                    'class'       => WordPressPublishTool::class,
                    'method'      => 'handle_tool_call',
                    'handler'     => $handler_slug,
                    'description' => 'Publish content to WordPress',
                    'parameters'  => build_params_from_config($handler_config),
                ],
            ];
        },
        'handler'      => 'wordpress_publish',  // Exact slug match against adjacent step
        'modes'        => ['pipeline'],
        'access_level' => 'admin',
    ];
    return $tools;
});
```

Matching modes:
- `'handler' => 'slug'` — entry applies only when the adjacent step's handler
  slug equals `'slug'`.
- `'handler_types' => ['fetch', 'event_import']` — entry applies to any
  handler whose registered `type` is in the list. Used for cross-cutting
  tools (e.g. `skip_item` exposed to every fetch-type handler).

The callback signature is `(string $handler_slug, array $handler_config, array $engine_data): array`.
Returned array is `['tool_name' => $tool_definition]` (empty array to opt out).

**Preferred pattern**: use `HandlerRegistrationTrait::registerHandler()` — the
trait wires the callback into this filter with the correct wrapper shape. Manual
registration is only needed for cross-cutting tools that register against
`handler_types`.

#### Resolved tool definition contract

Whether a tool comes from a static `_callable` or a handler `_handler_callable`,
the resolved definition follows the same shape:

```php
[
    'class'          => 'ToolClassName',
    'method'         => 'handle_tool_call',
    'description'    => 'Tool description for AI',
    'parameters'     => [
        'param_name' => [
            'type'        => 'string|integer|boolean',
            'required'    => true|false,
            'description' => 'Parameter description',
        ],
    ],
    'handler'        => 'handler_slug',      // Optional: handler-owned tool
    'requires_config' => true|false,         // Optional: UI configuration indicator
    'handler_config' => $handler_config,     // Optional: passed to tool execution
    'modes'          => ['pipeline'],        // Filled by registry wrapper if absent
    'ability'        => 'datamachine/...',   // Optional: permission link
    'access_level'   => 'admin',             // Optional: permission fallback
]
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

## Preview & Approval Filters

Data Machine ships **one** preview/approve primitive: `PendingActionStore`
plus `ResolvePendingActionAbility`. Any tool that wants the user to see a
change before it takes effect stages its invocation via
`PendingActionHelper::stage()` and registers an apply callback on
`datamachine_pending_action_handlers`. The core content abilities
(`edit_post_blocks`, `replace_post_blocks`, `insert_content`), the socials
publishers, and anything else opting into `action_policy=preview` all route
through the same lane.

> **Which preview primitive should I use?** There is only one. Call
> `PendingActionHelper::stage()` to stage a pending invocation and register
> your apply callback on `datamachine_pending_action_handlers`. The
> `ResolvePendingActionAbility` (ability slug
> `datamachine/resolve-pending-action`, REST route
> `POST /datamachine/v1/actions/resolve`, chat tool
> `resolve_pending_action`) finalizes every kind.

### `datamachine_pending_action_handlers`

**Purpose**: Register the apply + permission callbacks for a pending-action
kind.

```php
add_filter( 'datamachine_pending_action_handlers', function ( $handlers ) {
    $handlers['my_kind'] = array(
        'apply'       => array( MyAbility::class, 'execute' ),
        'can_resolve' => function ( array $payload, string $decision, int $user_id ) {
            // Return true, false, or a WP_Error. Optional — defaults to
            // "any user who can call resolve_pending_action".
            return current_user_can( 'edit_posts' );
        },
    );
    return $handlers;
} );
```

`apply` receives the stored `apply_input` array and must return either a
value (which is wrapped into the resolver response) or a `WP_Error` to
surface failure.

### `datamachine_pending_action_staged`

**Purpose**: Fires when a tool invocation has been staged and is awaiting
user resolution. Use this to notify users, log audit trails, or mirror the
payload into a visible queue.

### `datamachine_pending_action_resolved`

**Purpose**: Fires after a staged action is accepted or rejected. Receives
`$decision, $action_id, $kind, $payload, $result`.

### `datamachine_tool_action_policy`

**Purpose**: Last-layer override of the resolved action policy
(`direct | preview | forbidden`) for a single tool invocation. Runs after
`ActionPolicyResolver` has consulted deny lists, per-agent overrides, tool
declarations, and mode presets.

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

### `datamachine_should_reprocess_item`

**Since**: v0.71.0

**Purpose**: Opt into time-windowed revisit semantics for fetch-side deduplication without every handler growing its own `--revisit-days` flag.

**Wire point**: `ExecutionContext::isItemProcessed()` — applied after the default seen/not-seen check runs. The filter is **not** invoked in `direct` or `standalone` execution modes, or when `flow_step_id` is empty.

**Parameters**:
- `$skip` (bool) — Current skip decision. `true` means "skip — already processed"; `false` means "process".
- `$context` (array):
  - `flow_step_id` (string)
  - `source_type` (string)
  - `item_identifier` (string)
  - `job_id` (int) — 0 when unavailable.

**Return**: Boolean. `true` to skip (default seen-before behavior). `false` to process anyway (revisit).

**Default behavior (no filter)**: The filter never returns a different value than was passed in; existing deployments behave identically to pre-0.71 installs.

**Example — reprocess stale wiki posts**:

```php
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;

add_filter( 'datamachine_should_reprocess_item', function ( $skip, $ctx ) {
    if ( ! $skip ) {
        return false;
    }

    if ( 'wiki_post' !== $ctx['source_type'] ) {
        return $skip;
    }

    $fresh = ( new ProcessedItems() )->has_been_processed_within(
        $ctx['flow_step_id'],
        $ctx['source_type'],
        $ctx['item_identifier'],
        7
    );

    // skip=false means "process"; return true to keep skipping when still fresh.
    return $fresh;
}, 10, 2 );
```

**See also**: `ProcessedItems::get_processed_at()`, `ProcessedItems::has_been_processed_within()`, `ProcessedItems::find_stale()`, `ProcessedItems::find_never_processed()` — the time-windowed read API introduced in the same release.


## Duplicate Detection Filters

### `datamachine_duplicate_strategies`

**Since**: v0.39.0

**Purpose**: Register domain-specific duplicate detection strategies for the `datamachine/check-duplicate` ability. Extensions use this to add post-type-specific matching logic (e.g., event identity via venue + date + ticket URL) that runs before core's generic title/source-URL strategies.

**Parameters**:
- `$strategies` (array) - Array of strategy definitions (see structure below)
- `$post_type` (string) - The post type being checked

**Return**: Array of strategy definitions

**Strategy Definition Structure**:
```php
[
    'id'        => 'event_identity_index',      // string, required. Stable id, surfaced as `strategy` in the ability result.
    'post_type' => 'data_machine_events',       // string, required. Specific post type or '*' for all types.
    'callback'  => [Strategy::class, 'check'],  // callable, required. See callback contract below.
    'priority'  => 5,                           // int, optional (default: 50). Lower runs first.
]
```

**Cascade Order**:
1. Extension strategies registered on this filter (sorted by `priority`, lowest first).
2. Core `published_post_source_url` match (exact source URL via `PostIdentityIndex`).
3. Core `published_post` title match (similarity engine).
4. Core `queue_item` Jaccard match (only when `scope` includes `queue`).

First strategy to return a `duplicate` verdict short-circuits the cascade.

**Callback Contract**:

The callback receives the full ability input merged with normalized `title`, `post_type`, and `context`:

```php
function(array $input): ?array {
    // $input['title']      string — incoming title
    // $input['post_type']  string — resolved post type
    // $input['context']    array  — domain-specific payload (venue, startDate, ticketUrl, ...)
    // $input['source_url'] string — optional canonical source URL
    // ...plus any other fields the caller passed to datamachine/check-duplicate
}
```

Return `null` to pass (let the cascade continue), or an array with:

```php
[
    'verdict'  => 'duplicate',              // string, required — must be 'duplicate' to short-circuit
    'source'   => 'identity_index',         // string, optional — origin of the match
    'match'    => [                         // array, required — match details
        'post_id' => 123,
        'title'   => 'Existing Post',
        'url'     => 'https://example.com/existing',
        // strategy-specific fields are allowed
    ],
    'reason'   => 'Matched existing ...',   // string, optional — human-readable explanation
    'strategy' => 'event_identity_index',   // string, optional — overrides `id` in the final result
]
```

Any non-`duplicate` verdict (or missing `verdict`) is treated as a pass.

**Usage Example** (from `data-machine-events`):

```php
namespace DataMachineEvents\Core\DuplicateDetection;

class EventDuplicateStrategy {

    public static function register(): void {
        add_filter( 'datamachine_duplicate_strategies', [ static::class, 'addStrategy' ] );
    }

    public static function addStrategy( array $strategies ): array {
        $strategies[] = [
            'id'        => 'event_identity_index',
            'post_type' => 'data_machine_events',
            'callback'  => [ static::class, 'check' ],
            'priority'  => 5, // Run before core strategies.
        ];
        return $strategies;
    }

    public static function check( array $input ): ?array {
        $title   = $input['title'] ?? '';
        $context = $input['context'] ?? [];
        $venue   = $context['venue'] ?? '';
        $date    = $context['startDate'] ?? '';

        if ( empty( $title ) || empty( $date ) ) {
            return null;
        }

        // ... domain-specific lookup against PostIdentityIndex ...
        $post_id = $this->lookup( $title, $venue, $date );

        if ( ! $post_id ) {
            return null;
        }

        return [
            'verdict' => 'duplicate',
            'source'  => 'identity_index',
            'match'   => [
                'post_id' => $post_id,
                'title'   => get_the_title( $post_id ),
                'url'     => get_permalink( $post_id ),
            ],
            'reason'  => 'Matched existing event via venue + date.',
        ];
    }
}
```

**Working with `PostIdentityIndex`**:

Core ships `DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex` — an indexed lookup table (post_id, source_url, title_hash, event-related columns) used by the core source-URL strategy. Extensions have three options:

1. **Use the index** for lookups (indexed columns → fast). Safe for reading. See `EventDuplicateStrategy::findByTicketUrl()` for a canonical example.
2. **Write to the index** via the same writers core uses (e.g., `EventIdentityWriter::syncIdentityRow()` in `data-machine-events`). Recommended when your extension owns a custom post type and wants fast identity lookups.
3. **Maintain your own lookup** (e.g., an existing indexed column on `wp_posts` like `post_name` + `post_parent`). Valid for cases where the identity index would be redundant.

There is no requirement to use `PostIdentityIndex` — the filter accepts any callback. Choose based on what's already indexed for your post type.

**Stability**:

This filter, the strategy definition shape, the callback signature, and the return array shape are considered a public API as of 0.39.0. They will not change in a backward-incompatible way without a deprecation cycle.

**See Also**:
- Source: `inc/Abilities/DuplicateCheck/DuplicateCheckAbility.php::getStrategies()`
- Canonical consumer: the event duplicate strategy in the `data-machine-events` extension plugin
- Ability docs: [datamachine/check-duplicate](../../ai-tools/) in ai-tools reference

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

#### `ToolPolicyResolver::resolve()`

Tool discovery moved from `ToolExecutor::getAvailableTools()` (removed in 0.79)
to `ToolPolicyResolver::resolve()`. Single entry point for chat and pipeline
modes.

```php
$resolver = new \DataMachine\Engine\AI\Tools\ToolPolicyResolver();

$tools = $resolver->resolve( array(
    'mode'                 => ToolPolicyResolver::MODE_PIPELINE, // or MODE_CHAT, MODE_SYSTEM
    'previous_step_config' => $previous_step_config,
    'next_step_config'     => $next_step_config,
    'pipeline_step_id'     => $flow_step_id,
    'engine_data'          => $engine_data,
) );
```

**Discovery Process**:
1. Handler Tools - Retrieved via `datamachine_tools` filter (runtime-resolved `_handler_callable` entries)
2. Global Tools - Retrieved via `datamachine_global_tools` filter
3. Chat Tools - Retrieved via `datamachine_chat_tools` filter (chat only)
4. Enablement Check - Each tool filtered through `datamachine_tool_enabled`

### AIConversationLoop (`/inc/Engine/AI/AIConversationLoop.php`)

**Purpose**: Multi-turn conversation execution with automatic tool calling.

**Canonical entry point**:

```php
$final_response = \DataMachine\Engine\AI\AIConversationLoop::run(
    array $messages,
    array $tools,
    string $provider,
    string $model,
    string $context,         // 'pipeline', 'chat', etc.
    array $payload = [],
    int $max_turns = 25,
    bool $single_turn = false
): array
```

`run()` internally applies the `datamachine_conversation_runner` filter, giving
a registered runtime adapter the chance to short-circuit the built-in loop. If
no adapter returns an array, Data Machine's built-in `execute()` runs.

**Filter: `datamachine_conversation_runner`**

```php
apply_filters(
    'datamachine_conversation_runner',
    null,           // Return non-null array to short-circuit
    $messages, $tools, $provider, $model,
    $context, $payload, $max_turns, $single_turn
);
```

Return an array matching `execute()`'s documented return shape to replace the
built-in loop. Return `null` (the default) to let Data Machine run the
conversation. See [ai-conversation-loop.md](../../core-system/ai-conversation-loop.md#runtime-adapters)
for the full adapter contract.

**Features**:
- Automatic tool execution during conversation turns
- Conversation completion detection
- Turn-based state management with chronological ordering
- Duplicate message prevention
- Maximum turn limiting (default: 25)
- Runtime-swappable via `datamachine_conversation_runner`

### ConversationStoreInterface (`/inc/Core/Database/Chat/ConversationStoreInterface.php`)

**Purpose**: Single seam between chat session persistence and the underlying
storage backend. The default implementation ([`Chat`](../../../inc/Core/Database/Chat/Chat.php))
preserves byte-for-byte the MySQL-table behavior the codebase used before
this seam was introduced — self-hosted users see no change.

**Filter: `datamachine_conversation_store`**

```php
apply_filters(
    'datamachine_conversation_store',
    ConversationStoreInterface $default  // the built-in MySQL-table Chat store
);
```

Return a different `ConversationStoreInterface` implementation to swap the
backend. Return the default (or anything not implementing the interface) to
keep the built-in store. Misuse falls back to the default and logs via
`datamachine_log`.

**Use case**: managed-host environments where chat sessions should live in
a framework-provided conversation store rather than the site DB (e.g.
Intelligence on WordPress.com routing through `\WPCOM\AI\Services\Conversation_Storage`).
A consumer plugin ships an adapter and registers it conditionally:

```php
add_filter( 'datamachine_conversation_store', function ( $store ) {
    if ( $store instanceof My_AIFramework_Conversation_Store ) {
        return $store; // already swapped
    }
    if ( ! function_exists( 'my_host_is_wpcom' ) || ! my_host_is_wpcom() ) {
        return $store; // self-hosted — keep MySQL default
    }
    return new My_AIFramework_Conversation_Store();
}, 10, 1 );
```

**Single consumer of the store**: `\DataMachine\Core\Database\Chat\ConversationStoreFactory::get()`.

Every core caller — `ChatOrchestrator`, the five Chat Session abilities
(via `ChatSessionHelpers`), `ChatCommand`, `SystemAbilities`, the
scheduled cleanup action — resolves the store through the factory. The
factory caches the store per request and applies the filter exactly once.

**Message shape contract**

Stores MUST normalize messages on read to Data Machine message shape:

```php
[
    'id'         => string,                 // Stable message identifier
    'role'       => 'user'|'assistant'|'system'|'tool',
    'content'    => string|array,
    'metadata'   => array,                  // Tool calls, tokens, provider-specific fields
    'created_at' => string,                 // MySQL DATETIME (UTC)
    'updated_at' => string,                 // MySQL DATETIME (UTC)
]
```

The five Chat Session abilities and the DM chat UI consume this shape.
Adapter stores (e.g. around `WPCOM\AI\Message` with `data` instead of
`metadata`) are responsible for aliasing on the way out.

**Swap boundary**

- ✅ What stays stable: all 5 chat abilities, REST endpoints, the DM chat
  UI, the session switcher, title generation, unread counts, last-read logic.
- 🔄 What swaps: concrete storage (MySQL table vs. framework-managed store
  vs. in-memory test fixture).
- ❌ What is NOT a replacement point: session ownership checks, agent
  adoption, token resolution, title generation. Those stay in the higher-
  level callers.

**Contract summary** (full signatures in [`ConversationStoreInterface.php`](../../../inc/Core/Database/Chat/ConversationStoreInterface.php)):

- `create_session / get_session / update_session / delete_session`
- `get_user_sessions / get_user_session_count` — switcher data
- `get_recent_pending_session` — timeout-retry dedup
- `update_title / mark_session_read` — UI state
- `count_unread` — pure derivation from a messages array
- `cleanup_expired_sessions / cleanup_old_sessions / cleanup_orphaned_sessions` — scheduled cleanup
- `list_sessions_for_day` — day-scoped summary rows for the Daily Memory Task
- `get_storage_metrics` — row count + on-disk size for the `wp datamachine retention status` CLI; return `null` to opt out

### AgentMemoryStoreInterface (`/inc/Core/FilesRepository/AgentMemoryStoreInterface.php`)

**Purpose**: Single seam between agent memory operations and the underlying
persistence backend. The disk default ([`DiskAgentMemoryStore`](../../../inc/Core/FilesRepository/DiskAgentMemoryStore.php))
preserves byte-for-byte the filesystem behavior the codebase used before this
seam was introduced.

**Filter: `datamachine_memory_store`**

```php
apply_filters(
    'datamachine_memory_store',
    null,                       // Return AgentMemoryStoreInterface to short-circuit
    AgentMemoryScope $scope     // Identifies (layer, user_id, agent_id, filename)
);
```

Return an [`AgentMemoryStoreInterface`](../../../inc/Core/FilesRepository/AgentMemoryStoreInterface.php)
implementation to replace the disk default for this scope. Return `null` (the
default) to let Data Machine read and write through the filesystem.

**Use case**: managed-host environments where the local filesystem is not
writable (e.g. WordPress.com, VIP). A consumer plugin (e.g. Intelligence)
ships a DB-backed implementation and registers it conditionally:

```php
add_filter( 'datamachine_memory_store', function ( $store, $scope ) {
    if ( $store instanceof AgentMemoryStoreInterface ) {
        return $store;  // someone else already swapped
    }
    if ( filesystem_is_writable_here() ) {
        return $store;  // disk default wins
    }
    return new \My_Plugin\DB_Agent_Memory_Store();
}, 10, 2 );
```

**Contract**:
- `read( $scope )` → `AgentMemoryReadResult { exists, content, hash, bytes, updated_at }`
- `write( $scope, $content, $if_match = null )` → `AgentMemoryWriteResult`
  (implementations supporting concurrency MUST honor `$if_match` and return
  `error = 'conflict'` on hash mismatch)
- `exists( $scope )` → `bool`
- `delete( $scope )` → `AgentMemoryWriteResult` (idempotent)
- `list_layer( $scope_query )` → `AgentMemoryListEntry[]` (enumerates one layer)

Section parsing, scaffolding, editability gating, and registry-driven
convention-path semantics stay in `AgentMemory` (the high-level facade).
The store is the dumb persistence layer underneath.

**Single consumer of the store**: `\DataMachine\Core\FilesRepository\AgentMemory`.

`AgentMemory` is the only class in core that talks to `AgentMemoryStoreFactory`. It exposes:

- Section-level ops: `get_section()`, `set_section()`, `append_to_section()`, `get_sections()`, `search()`
- Whole-file ops: `read()` (returns `AgentMemoryReadResult`), `get_all()`, `replace_all()`, `exists()`, `delete()`
- Static layer enumerator: `AgentMemory::list_layer( $layer, $user_id, $agent_id )` → `AgentMemoryListEntry[]`

Higher-level consumers all go through this facade rather than instantiating store types directly:

- `\DataMachine\Abilities\File\AgentFileAbilities` — whole-file ops backing the `/datamachine/v1/files/agent` REST routes (the React Agent UI)
- `\DataMachine\Engine\AI\Directives\CoreMemoryFilesDirective` — file content injected into every AI conversation
- `\DataMachine\Engine\AI\System\Tasks\DailyMemoryTask` — full-file rewrite during scheduled compaction
- `\DataMachine\Abilities\AgentMemoryAbilities` — Abilities API surface for memory operations

Outside plugins and extensions should follow the same pattern: instantiate `AgentMemory`, never reach for `AgentMemoryStoreFactory` directly.

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
