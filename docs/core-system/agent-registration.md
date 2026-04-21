# Agent Registration

Declarative agent registration via the `datamachine_register_agents` action. Plugins (and Data Machine itself) declare agent roles once; DM's registry reconciles those declarations against the `datamachine_agents` table on `init`.

**Since:** 0.71.0
**Source:** `inc/Engine/Agents/AgentRegistry.php`, `inc/Engine/Agents/register-agents.php`

## Why

Agents were previously materialized imperatively — either via `AgentAbilities::createAgent()` from CLI/REST, or lazily via `datamachine_resolve_or_create_agent_id()` on first chat turn. That works for per-user personal agents but doesn't give extensions a clean way to ship a bundled agent role (e.g. a wiki-generator, a support-triage bot, a content-reviewer).

The registry mirrors the `register_post_type()` / `register_taxonomy()` pattern: plugins declare the role, DM owns the runtime. The same API DM uses internally is the API any plugin uses. DM dogfoods it — the default site administrator agent is now registered through the hook.

## Declaring an agent

Inside your plugin:

```php
add_action( 'datamachine_register_agents', function () {
    datamachine_register_agent( 'wiki-generator', array(
        'label'       => __( 'Wiki Generator', 'my-plugin' ),
        'description' => __( 'Fetches sources, distills into wiki articles, cross-links.', 'my-plugin' ),
        'soul_path'   => MY_PLUGIN_DIR . 'agents/wiki-generator/SOUL.md',
    ) );
} );
```

That's it. On the next request where `init` fires, DM reconciles the registration:

- If a row with `agent_slug = 'wiki-generator'` already exists in `datamachine_agents`, nothing happens. Mutable state is DB-owned; the registration never overwrites it.
- If the row is missing, DM creates it (owner resolved via `owner_resolver` or falls back to the default admin user), bootstraps owner access, ensures the agent directory exists, and runs the scaffold ability for SOUL.md / MEMORY.md.
- If a `soul_path` was registered, its file contents become the initial SOUL.md instead of DM's generic site-context SOUL.

## Registration arguments

`datamachine_register_agent( string $slug, array $args )`

| Key | Type | Description |
|---|---|---|
| `label` | string | Display name. Defaults to the slug when omitted. |
| `description` | string | Short description for admin UI / CLI listings. |
| `soul_path` | string | Absolute path to a bundled `SOUL.md`. Its contents are surfaced through the scaffold ability as the SOUL content for this agent. See [SOUL resolution](#soul-resolution). |
| `owner_resolver` | callable | Returns `int user_id`. Called once at row-creation time. Defaults to `DirectoryManager::get_default_agent_user_id()`. |
| `default_config` | array | Initial `agent_config` persisted on creation. Subsequent config changes go through the DB — the registration never overrides user-edited config. |

### Slug semantics

Slugs are passed through `sanitize_title()`. They must be unique across a site (DB column has a UNIQUE constraint on `agent_slug`). Two plugins registering the same slug is resolved by **last-wins** — this matches WP action/filter semantics, so plugins can override core or other plugins by hooking at a higher priority.

## Reconciliation

Reconciliation runs on `init` at priority 15:

- Priority 10: `wp_abilities_api_init` fires. Abilities register.
- **Priority 15: `AgentRegistry::reconcile()` fires the `datamachine_register_agents` action, collects registrations, creates missing DB rows, scaffolds agent-layer memory files.**
- Priority 20: existing `datamachine_needs_scaffold` transient check. No-op when the registry has already scaffolded.

The `datamachine_register_agents` action is also fired lazily by `AgentRegistry::get_all()` / `get()` / `reconcile()` — so any caller can query the registry regardless of hook ordering.

## SOUL resolution

Registered `soul_path` contents flow into SOUL.md via the existing `datamachine_scaffold_content` filter chain:

1. **Priority 5** — registry's generator. Checks `AgentRegistry::get($agent_slug)` for a `soul_path`. If present and readable, returns the file contents as the SOUL.md scaffold content.
2. **Priority 10** — DM's default `datamachine_scaffold_soul_content` generator. Produces the generic site-context SOUL using agent display name + site metadata.

Registered agents with a `soul_path` win at priority 5. Registered agents without a `soul_path` fall through to priority 10. Agents created imperatively via `AgentAbilities::createAgent()` (with no registry entry) likewise fall through.

The scaffold ability never overwrites existing files. Once SOUL.md exists on disk, its content is user-editable and plugin updates don't rewrite it. To reseed a SOUL.md from an updated bundled version, delete the file and run the scaffold ability again.

## Reconciliation outcomes

`AgentRegistry::reconcile()` returns a summary for logging / testing:

```php
[
    'created'  => [ 'wiki-generator' ],  // newly inserted into datamachine_agents
    'existing' => [ 'chubes' ],          // row already present, skipped
    'skipped'  => [],                    // owner resolution failed or DB insert failed
]
```

The `datamachine_registered_agent_reconciled` action fires for each newly-materialized agent:

```php
do_action( 'datamachine_registered_agent_reconciled', int $agent_id, string $slug, array $definition );
```

## DM core dogfood

Data Machine registers its default site administrator agent through the same hook:

```php
add_action( 'datamachine_register_agents', function () {
    $default_user_id = DirectoryManager::get_default_agent_user_id();
    $user            = get_user_by( 'id', $default_user_id );

    datamachine_register_agent(
        sanitize_title( $user->user_login ),
        array(
            'label'          => $user->display_name,
            'description'    => 'Default site administrator agent.',
            'owner_resolver' => fn() => $default_user_id,
        )
    );
}, 10 );
```

Same API. Same hook priority as any plugin. On existing installs this is a no-op (the per-user agent already exists); on fresh installs the registry is the primary creation path for the default agent.

## When to register vs create imperatively

| Scenario | Pattern |
|---|---|
| A role bundled with a plugin, same on every install | **Register** via `datamachine_register_agents` |
| A user-created agent with install-specific name, owner, config | **Create imperatively** via `AgentAbilities::createAgent()` |
| Lazy provisioning of a per-user agent on first chat turn | **Use** `datamachine_resolve_or_create_agent_id($user_id)` |

Registered agents and imperatively-created agents coexist cleanly — they're all just rows in `datamachine_agents` keyed by slug. The registry is an additive declarative path, not a replacement for the imperative API.

## Overriding a registered agent

Hook at a higher priority and re-register with the same slug:

```php
add_action( 'datamachine_register_agents', function () {
    datamachine_register_agent( 'wiki-generator', array(
        'label'     => __( 'Custom Wiki Generator', 'my-override' ),
        'soul_path' => __DIR__ . '/custom-wiki-soul.md',
    ) );
}, 20 ); // Higher than the original plugin's priority 10.
```

Last registration wins. Because reconciliation never overwrites existing DB rows, an override only affects **fresh creation** — already-materialized rows retain their historical data. To reseed SOUL.md on an existing install, delete the file and let the scaffold ability regenerate it.

## Related

- `docs/core-system/multi-agent-architecture.md` — agents table schema, access control, filesystem layout
- `docs/core-filters.md` — the `datamachine_register_agents` action, `datamachine_registered_agent_reconciled` action, `datamachine_scaffold_content` filter
- `inc/Abilities/File/ScaffoldAbilities.php` — scaffold ability that honors registered `soul_path` content
- `inc/migrations/scaffolding.php` — default `datamachine_scaffold_content` generators
