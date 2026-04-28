# Universal Engine Filters

Reference for the WordPress filters used by the Universal Engine to register directives, tools, and authentication providers, and to validate tool configuration.

> Modes vs contexts: prior to v0.71.0 the directive-targeting field was named `contexts`. It was renamed to `modes` during the AgentMode refactor (#1130). Both `RequestBuilder` and `PromptBuilder` now read `modes`. Old `contexts =>` registrations are silently ignored and treated as `all`.

## Directive System

Directives inject system messages and contextual information into AI requests. They self-register via a single unified filter with priority-based ordering and mode targeting.

### datamachine_directives

Centralized filter for directive registration.

**Hook usage**:

```php
$directives = apply_filters( 'datamachine_directives', array() );
```

**Return shape**: array of directive configurations.

**Directive configuration**:

```php
$directive = [
    'class'    => DirectiveClass::class, // implements DirectiveInterface
    'priority' => 25,                    // lower = applied first
    'modes'    => ['all'],               // 'all', or array of modes (chat, pipeline, system)
];
```

**Implementation example**:

```php
add_filter( 'datamachine_directives', function ( $directives ) {
    $directives[] = [
        'class'    => MyCustomDirective::class,
        'priority' => 30,
        'modes'    => ['chat', 'pipeline'],
    ];
    return $directives;
} );
```

**Built-in directives** are listed in [AI Directives System](../../core-system/ai-directives.md) with current priority and mode assignments. The earlier `GlobalSystemPromptDirective`, `SiteContextDirective`, `PipelineCoreDirective`, `ChatAgentDirective`, and `SystemAgentDirective` classes were removed during the AgentMode refactor — their guidance now lives inline in `AgentModeDirective` and in agent memory files (SITE.md, SOUL.md, MEMORY.md).

### datamachine_agent_mode_{slug}

Per-mode guidance composition hook fired by `AgentModeDirective` (priority 22).

**Hook usage** (one filter per mode slug):

```php
$content = apply_filters( "datamachine_agent_mode_{$mode}", $default_content, $payload );
```

**Parameters**:

- `$default_content` (string) — Built-in guidance for that mode (or empty for unregistered modes).
- `$payload` (array) — Full request payload (`agent_id`, `user_id`, `agent_mode`, etc.).

**Return**: Modified guidance text. Returning an empty string suppresses the directive.

**Built-in modes**: `chat`, `pipeline`, `system`. Extensions can register additional modes (e.g. the editor plugin registers `editor` to inject diff-workflow instructions).

**Implementation example**:

```php
add_filter( 'datamachine_agent_mode_chat', function ( $content, $payload ) {
    if ( empty( $payload['agent_id'] ) ) {
        return $content;
    }
    return $content . "\n\n## Site-specific\n\nAlways prefer existing taxonomies before creating new ones.";
}, 10, 2 );
```

## Tool System

Tools are registered via a single unified filter. Per-mode tool partitioning is handled inside `ToolManager`, not by separate registration filters.

### datamachine_tools

Single registry for all AI tools. Used by `ToolManager::getRawToolsForMode()` to assemble the available tool set for a given execution mode.

**Hook usage**:

```php
$tools = apply_filters( 'datamachine_tools', array() );
```

**Return shape**: associative array keyed by tool ID.

**Tool definition**:

```php
[
    'class'            => 'My\\Plugin\\Tools\\MyTool',
    'method'           => 'handle_tool_call',
    'description'      => 'Clear, AI-readable description.',
    'parameters'       => [
        'query' => [
            'type'        => 'string',
            'required'    => true,
            'description' => 'Search query',
        ],
    ],
    'modes'            => ['chat'],             // which modes can see this tool
    'requires_config'  => true,                 // checked via datamachine_tool_configured
    'category'         => 'search',             // optional grouping
]
```

**Implementation example**:

```php
add_filter( 'datamachine_tools', function ( $tools ) {
    $tools['my_search'] = [
        'class'           => 'My\\Plugin\\Tools\\MySearch',
        'method'          => 'handle_tool_call',
        'description'     => 'Search the My Plugin index.',
        'parameters'      => [
            'query' => [
                'type'        => 'string',
                'required'    => true,
                'description' => 'Search terms',
            ],
        ],
        'modes'           => ['chat'],
        'requires_config' => false,
    ];
    return $tools;
} );
```

Use `pipeline` mode only when a static/global tool is useful inside an automated pipeline AI step. Chat affordances and tools that duplicate engine-level validation should stay `chat`-only; pipeline AI steps already receive step-scoped handler tools plus pipeline/flow memory directives.

> The legacy `datamachine_global_tools` and `datamachine_chat_tools` filters were consolidated into `datamachine_tools` in v0.68.0 (PR #1130). The old per-mode filters no longer exist.

### datamachine_tool_configured

Validates that tools requiring external services (API keys, OAuth credentials) are properly configured.

**Hook usage**:

```php
$configured = apply_filters( 'datamachine_tool_configured', false, $tool_id );
```

**Parameters**:

- `$configured` (bool) — Current configuration status.
- `$tool_id` (string) — Tool identifier.

**Return**: bool — Whether the tool is configured.

**Implementation example**:

```php
add_filter( 'datamachine_tool_configured', function ( $configured, $tool_id ) {
    if ( $tool_id === 'my_search' ) {
        $settings = get_option( 'my_plugin_settings', array() );
        return ! empty( $settings['api_key'] ) && strlen( $settings['api_key'] ) >= 20;
    }
    return $configured;
}, 10, 2 );
```

> Tool *availability* (whether the AI sees the tool in this request) is now resolved by `ToolManager::is_tool_available()`, not by a public filter. The `datamachine_tool_enabled` filter from earlier versions has been removed in favour of `ToolManager`'s direct logic, which combines configuration state, mode membership, and per-step `enabled_tools` settings.

## Handler Registration

Handlers (fetch / publish / upsert) are registered via the `HandlerRegistrationTrait` (`inc/Core/Steps/HandlerRegistrationTrait.php`), which wires multiple filters in one call. The full pattern is documented in [Core Filters](core-filters.md). The trait registers:

| Filter | Purpose |
|--------|---------|
| `datamachine_handlers` | Handler metadata lookup keyed by step type |
| `datamachine_auth_providers` | Auth provider lookup (when `requires_auth=true`) |
| `datamachine_handler_settings` | Settings class lookup keyed by handler slug |
| `datamachine_tools` | Handler tool registration via `_handler_callable` deferred entries |

## Best Practices

### Directive Registration

- Use **priority 10–29** for foundational identity / mode guidance.
- Use **priority 30–49** for contextual information (memory files, inventory).
- Use **priority 50+** for late-stage configuration (workflow visualization, pipeline goals).
- Always declare `modes` explicitly. Default to `['all']` only when the directive truly applies everywhere.

### Tool Registration

- Provide a complete `parameters` schema — the AI relies on it for argument structure.
- Set `requires_config => true` only when the tool genuinely needs configuration. Tools that always work (e.g. `web_fetch`) should leave it `false` so they don't get filtered out.
- Declare `modes` so the tool only appears where it's useful (e.g. workflow-management tools should be `chat`-only, not exposed to pipeline AI steps).

### Configuration Validation

- Validate the actual usability of credentials, not just their presence (e.g. minimum length, expected prefix).
- Keep validation cheap — `datamachine_tool_configured` is called repeatedly during tool listing.
- Do not perform live API calls inside the filter; cache results elsewhere if you need to verify connectivity.

## Related Documentation

- [AI Directives System](../../core-system/ai-directives.md) — Built-in directive list, priorities, modes
- [Tool Manager](../../core-system/tool-manager.md) — Tool availability resolution
- [Tool Execution](../../core-system/tool-execution.md) — Tool dispatch and result handling
- [Core Filters](core-filters.md) — Handler registration filters and OAuth service discovery
