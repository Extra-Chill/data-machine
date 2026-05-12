# Tool Manager

`ToolManager` is the Data Machine adapter for the AI tool registry. It owns Data Machine's `datamachine_tools` compatibility registry, lazy definition resolution, handler-tool expansion, configuration checks, and global enablement checks. It does not decide the final per-request tool set by itself. Runtime visibility is resolved by `ToolPolicyResolver` through `ToolSourceRegistry`.

## Current Shape

```text
datamachine_tools filter
        |
        v
ToolManager
  - get_all_tools()
  - get_raw_tools()
  - resolveHandlerTools()
  - is_tool_available()
        |
        v
ToolSourceRegistry
  - static_registry
  - adjacent_handlers
        |
        v
ToolPolicyResolver::resolve()
```

Use `ToolManager` when code needs to read Data Machine's registered tool definitions or resolve handler-owned tools. Use `ToolPolicyResolver::resolve()` when code needs the tool set an agent may actually see for a chat, pipeline, or system request.

## Registry Contract

All product and extension tools register through `datamachine_tools`.

```php
add_filter( 'datamachine_tools', function ( array $tools ): array {
    $tools['my_plugin/search'] = array(
        '_callable'     => array( MyToolDefinitions::class, 'search' ),
        'modes'         => array( 'chat' ),
        'ability'       => 'my-plugin/search',
        'access_level'  => 'admin',
    );

    return $tools;
} );
```

The `_callable` wrapper defers the full definition until a resolver needs it. The resolved definition must include the model-facing shape:

```php
array(
    'description'     => 'Search the My Plugin index.',
    'parameters'      => array(
        'query' => array(
            'type'        => 'string',
            'required'    => true,
            'description' => 'Search terms.',
        ),
    ),
    'class'           => MySearchTool::class,
    'method'          => 'handle_tool_call',
    'modes'           => array( 'chat' ),
    'requires_config' => false,
)
```

Ability-only tools omit `class` and `method` and execute through the linked WordPress Ability:

```php
array(
    'description' => 'Create a wiki note.',
    'parameters'  => array( /* JSON-schema-like parameter map */ ),
    'ability'     => 'intelligence/create-wiki-note',
    'modes'       => array( 'chat' ),
)
```

`ToolExecutionCore` treats a definition with `ability` and no `class`/`method` as ability-only. It checks ability permissions and calls `WP_Ability::execute()` directly.

## Handler Tools

Pipeline handler tools are dynamic because their parameter schemas depend on the adjacent flow-step handler config. Register them as `_handler_callable` entries in `datamachine_tools`, usually through `HandlerRegistrationTrait::registerHandler()`.

```php
add_filter( 'datamachine_tools', function ( array $tools ): array {
    $tools['__handler_tools_wordpress_publish'] = array(
        '_handler_callable' => function ( string $handler_slug, array $handler_config, array $engine_data ): array {
            return array(
                'wordpress_publish' => array(
                    'class'          => WordPressPublishTool::class,
                    'method'         => 'handle_tool_call',
                    'handler'        => $handler_slug,
                    'handler_config' => $handler_config,
                    'description'    => 'Publish the processed item to WordPress.',
                    'parameters'     => build_parameters_from_handler_config( $handler_config ),
                ),
            );
        },
        'handler'      => 'wordpress_publish',
        'modes'        => array( 'pipeline' ),
        'access_level' => 'admin',
    );

    return $tools;
} );
```

`ToolManager::resolveHandlerTools()` supports two matching shapes:

| Entry key | Meaning |
|---|---|
| `handler` | Exact adjacent handler slug match. |
| `handler_types` | Match any adjacent handler whose registered type is in the list. Used by cross-cutting tools such as `skip_item`. |

Resolved handler tools automatically receive `handler`, `handler_config`, inherited `access_level`, inherited `ability`, and `modes` when the callback omits them.

## Source Registry

`ToolSourceRegistry` composes named sources before policy filtering:

| Source | Class | Used by default in |
|---|---|---|
| `static_registry` | `DataMachineToolRegistrySource` | `chat`, `pipeline`, `system`, custom modes |
| `adjacent_handlers` | `AdjacentHandlerToolSource` | `pipeline` |

`DataMachineToolRegistrySource` reads `ToolManager::get_all_tools()`, filters by each tool's `modes`, applies global enablement/configuration checks, and adapts policy-gated pipeline tools.

`AdjacentHandlerToolSource` reads the previous and next step configs, gathers configured handler slugs through `FlowStepConfig`, and asks `ToolManager::resolveHandlerTools()` for each adjacent handler's dynamic tool definitions.

The generic extension hooks are `agents_api_tool_sources` and `agents_api_tool_sources_for_mode`. Data Machine's legacy `datamachine_tool_sources` filters are not mirrored.

## Modes

Tool definitions declare the request modes that may see them:

| Mode | Constant | Use |
|---|---|---|
| `pipeline` | `ToolPolicyResolver::MODE_PIPELINE` | Automated AI pipeline steps. Includes adjacent handler tools plus static registry tools explicitly marked for pipeline. |
| `chat` | `ToolPolicyResolver::MODE_CHAT` | User-present chat sessions. Runs the chat access gate and ability permission checks. |
| `system` | `ToolPolicyResolver::MODE_SYSTEM` | Background/system jobs with a small explicit tool set. |
| `pipeline_policy` | `ToolPolicyResolver::MODE_PIPELINE_POLICY` | Opt-in marker for tools hidden from pipeline by default but grantable through pipeline tool policy. |

`pipeline_policy` does not expose a tool by itself. A pipeline request must also include the tool in `allow_only`, usually from `enabled_tools` in the flow-step snapshot.

## Availability Checks

`ToolManager::is_tool_available( $tool_id, $context_id )` combines global enablement and configuration state. It is a source-level gate, not the full policy resolver.

```php
$manager = new ToolManager();

if ( $manager->is_tool_available( 'google_search' ) ) {
    // The static registry source may include it, subject to mode and policy.
}
```

`requires_config => true` tools must pass `datamachine_tool_configured`:

```php
add_filter( 'datamachine_tool_configured', function ( bool $configured, string $tool_id ): bool {
    if ( 'my_plugin/search' !== $tool_id ) {
        return $configured;
    }

    $settings = get_option( 'my_plugin_settings', array() );
    return ! empty( $settings['api_key'] );
}, 10, 2 );
```

Tools without `requires_config` still pass through the global enablement check. The old public enablement filter is not part of the current runtime path.

## Pipeline Tool Policy Inputs

Pipeline AI steps build resolver policy args from the workflow snapshot through `PipelineToolPolicyArgs::fromConfigs()`:

| Config key | Resolver arg | Effect |
|---|---|---|
| `enabled_tools` on the flow step | `allow_only` | Narrows optional/static tools to the listed names and grants matching `pipeline_policy` tools. |
| `disabled_tools` on the pipeline step | `deny` | Removes listed tools. |
| `disabled_tools` on the flow step | `deny` | Removes listed tools. |

The snapshot is authoritative. Runtime pipeline resolution does not re-read persisted pipeline rows because direct workflows can have synthetic IDs and historical jobs must use the policy captured when they ran.

Adjacent handler tools are mandatory flow plumbing. They are preserved through allow/category policy unless explicitly denied by the high-precedence deny list.

## Related Docs

- [Tool Execution Architecture](tool-execution.md)
- [Policy Resolvers](../architecture/policy-resolvers.md)
- [Engine Filters](../development/hooks/engine-filters.md)
- [Core Filters](../development/hooks/core-filters.md)
