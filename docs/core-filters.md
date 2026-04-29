# Core Filters & Actions

## Actions

### `wp_agents_api_init`

Fires once per request when the AgentRegistry is first consumed. Plugins declare agent roles inside this callback; DM reconciles declarations against the `datamachine_agents` table on `init` priority 15 while Data Machine hosts the in-place Agents API substrate.

Same API DM itself uses to register the default site administrator agent. Registrations are collected statically; last-wins on slug collision so plugins can override via hook priority. The legacy `datamachine_register_agents` action and `datamachine_register_agent()` wrapper still fire while this surface lives in Data Machine, but new code should use the WordPress-shaped names.

**Since:** 0.99.0

```php
add_action( 'wp_agents_api_init', function () {
    wp_register_agent( 'wiki-generator', array(
        'label'        => 'Wiki Generator',
        'description'  => 'Fetches sources, distills into wiki articles.',
        'memory_seeds' => array(
            'SOUL.md'   => MY_PLUGIN_DIR . 'agents/wiki-generator/SOUL.md',
            'MEMORY.md' => MY_PLUGIN_DIR . 'agents/wiki-generator/MEMORY.md',
        ),
    ) );
} );
```

See `docs/core-system/agent-registration.md` for the full registration contract.

---

### `datamachine_registered_agent_reconciled`

Fires for each registered agent that was just materialized into the `datamachine_agents` table by `AgentRegistry::reconcile()`. Does not fire for registrations whose DB row already existed.

**Since:** 0.71.0

```php
add_action( 'datamachine_registered_agent_reconciled', function ( $agent_id, $slug, $def ) {
    // One-time provisioning for newly-created agents.
}, 10, 3 );
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$agent_id` | `int` | Newly created agent row ID. |
| `$slug` | `string` | Registered slug. |
| `$def` | `array` | Full registration definition. |

---

### `datamachine_agent_modes`

Fires once per request when the AgentModeRegistry is first consumed. Extensions register their execution modes inside this callback.

**Since:** 0.68.0

```php
add_action( 'datamachine_agent_modes', function ( $modes ) {
    \DataMachine\Engine\AI\AgentModeRegistry::register( 'editor', 40, array(
        'label'       => 'Editor Agent',
        'description' => 'Content editing in the Gutenberg block editor.',
    ) );
} );
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$modes` | `array` | Read-only snapshot of currently registered modes. |

---

## Filters

### `datamachine_agent_mode_{slug}`

Filters the guidance text injected for a specific execution mode. Fired by `AgentModeDirective` at priority 22 in the directive chain.

Built-in modes with defaults: `chat`, `pipeline`, `system`. Unknown modes receive an empty string as the default — extension filters are the sole content source for custom modes.

**Since:** 0.68.0

```php
// Append editor-specific guidance to the editor mode.
add_filter( 'datamachine_agent_mode_editor', function ( string $content, array $payload ): string {
    return $content . "\n\n## Editor Tools\n\nYou have diff visualization tools...";
}, 10, 2 );
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$content` | `string` | Current guidance text (built-in default or empty). |
| `$payload` | `array` | Full request payload (agent_id, user_id, step_id, etc.). |

**Return:** `string` — The final guidance text to inject as a system message.

---

### `datamachine_memory_file_content`

Filters memory file content at read time before injection into AI context. Fires for every file read by CoreMemoryFilesDirective.

**Since:** 0.66.0

```php
add_filter( 'datamachine_memory_file_content', function ( string $content, string $filename, ?array $meta ): string {
    if ( 'RULES.md' === $filename ) {
        $content .= "\n\n## Dynamic Rule\nAlways greet users by name.";
    }
    return $content;
}, 10, 3 );
```

---

### `datamachine_resolved_memory_files`

Filters the final set of memory files after MemoryPolicyResolver resolves them for a given context.

**Since:** 0.66.0

---

### `datamachine_oauth_can_handle_callback`

Filters whether the current request is authorized to handle an OAuth callback for the given provider.

This is the **primary authorization gate** for OAuth callback handling. The filter fires BEFORE provider lookup, so unknown-slug requests still receive a 404 (not a 403) regardless of authorization state.

> ⚠️ **Security Warning:** Returning `true` from this filter authorizes the entire OAuth callback flow for this provider, which may write credentials to site/network options. Providers MUST validate authorization specific to their use case (e.g. nonce in state param, ownership of the resource being connected). Do not blanket-return `true` without additional provider-level checks.

**Since:** 0.88.0

```php
add_filter( 'datamachine_oauth_can_handle_callback', function ( bool $can, string $provider_slug, array $request_params ): bool {
    // Allow artist-owned Instagram OAuth callbacks.
    if ( str_starts_with( $provider_slug, 'artist-instagram-' ) ) {
        $artist_id = (int) substr( $provider_slug, strlen( 'artist-instagram-' ) );
        return ec_can_manage_artist( get_current_user_id(), $artist_id );
    }
    return $can;
}, 10, 3 );
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$can` | `bool` | Whether the current user can handle the callback. Default: `current_user_can( 'manage_options' )`. |
| `$provider_slug` | `string` | The provider slug from the URL (e.g. `instagram`, `twitter`). |
| `$request_params` | `array` | The raw `$_GET` parameters for the callback request. |

**Return:** `bool` — `true` to authorize, `false` to reject with 403.

---

## Classes

### `AgentModeRegistry`

**Namespace:** `DataMachine\Engine\AI`  
**Since:** 0.68.0

Central registry for execution modes. Extensions register modes; the settings UI and AI system consume them.

| Method | Description |
|--------|-------------|
| `register( string $id, int $priority, array $args )` | Register a mode. |
| `deregister( string $id )` | Remove a mode. |
| `is_registered( string $id ): bool` | Check if a mode exists. |
| `get( string $id ): ?array` | Get metadata for a mode. |
| `get_all(): array` | Get all modes sorted by priority. |
| `get_ids(): array` | Get sorted mode IDs. |
| `get_for_settings(): array` | Get modes formatted for the admin UI. |
| `reset(): void` | Clear all registrations (testing only). |

**Deprecated:** `ContextRegistry` is a class alias for `AgentModeRegistry`.

---

### `AgentModeDirective`

**Class:** `DataMachine\Engine\AI\Directives\AgentModeDirective`
**Since:** 0.68.0  
**Priority:** 22

Injects execution-mode guidance as a runtime directive. Reads `$payload['agent_mode']` and emits one `system_text` output with composed content.

Built-in defaults for `chat`, `pipeline`, and `system` are shipped as class constants. Custom modes (e.g. `editor`, `bridge`) provide their content exclusively via the `datamachine_agent_mode_{slug}` filter.
