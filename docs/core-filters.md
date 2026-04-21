# Core Filters & Actions

## Actions

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

---

### `AgentModeDirective`

**Namespace:** `DataMachine\Engine\AI\Directives`  
**Since:** 0.68.0  
**Priority:** 22

Injects execution-mode guidance as a runtime directive. Reads `$payload['agent_mode']` and emits one `system_text` output with composed content.

Built-in defaults for `chat`, `pipeline`, and `system` are shipped as class constants. Custom modes (e.g. `editor`, `bridge`) provide their content exclusively via the `datamachine_agent_mode_{slug}` filter.
