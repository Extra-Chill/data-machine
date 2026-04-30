# Agent Registration

Declarative agent registration via the `wp_agents_api_init` action. Plugins (and Data Machine itself) declare agent roles once; the side-effect-free registry collects those declarations, and Data Machine's materializer reconciles them against the `datamachine_agents` table on `init` while Data Machine hosts the in-place substrate.

**Since:** 0.71.0
**Source:** `agents-api/inc/class-wp-agent.php`, `agents-api/inc/class-wp-agents-registry.php`, `agents-api/inc/register-agents.php`, `inc/Engine/Agents/AgentRegistry.php`, `inc/Engine/Agents/datamachine-register-agents.php`

## Why

Agents were previously materialized imperatively — either via `AgentAbilities::createAgent()` from CLI/REST, or lazily via `datamachine_resolve_or_create_agent_id()` on first chat turn. That works for per-user personal agents but doesn't give extensions a clean way to ship a bundled agent role (e.g. a wiki-generator, a support-triage bot, a content-reviewer).

The registry mirrors the `register_post_type()` / `register_taxonomy()` pattern: plugins declare the role, Data Machine owns today's runtime. The public vocabulary mirrors the Abilities API direction (`wp_register_agent()`, `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`, `wp_unregister_agent()`, `WP_Agent`, `WP_Agents_Registry`, `wp_agents_api_init`) so the generic registry can move cleanly if Agents API is extracted later. Data Machine dogfoods it — the default site administrator agent is registered through the same hook.

## Declaring an agent

Inside your plugin:

```php
add_action( 'wp_agents_api_init', function () {
    wp_register_agent( 'wiki-generator', array(
        'label'        => __( 'Wiki Generator', 'my-plugin' ),
        'description'  => __( 'Fetches sources, distills into wiki articles, cross-links.', 'my-plugin' ),
        'memory_seeds' => array(
            'SOUL.md'   => MY_PLUGIN_DIR . 'agents/wiki-generator/SOUL.md',
            'MEMORY.md' => MY_PLUGIN_DIR . 'agents/wiki-generator/MEMORY.md',
        ),
    ) );
} );
```

That's it. On the next request where `init` fires, DM reconciles the registration:

- If a row with `agent_slug = 'wiki-generator'` already exists in `datamachine_agents`, nothing happens. Mutable state is DB-owned; the registration never overwrites it.
- If the row is missing, DM creates it (owner resolved via `owner_resolver` or falls back to the default admin user), bootstraps owner access, ensures the agent directory exists, and runs the scaffold ability for every registered agent-layer memory file.
- For each `memory_seeds` entry whose target file does not yet exist on disk, the bundled file's contents become the initial scaffold. Generic site-context defaults apply to any filename without a seed entry.

## Registration arguments

`wp_register_agent( string|WP_Agent $agent, array $args = array() )`

| Key | Type | Description |
|---|---|---|
| `label` | string | Display name. Defaults to the slug when omitted. |
| `description` | string | Short description for admin UI / CLI listings. |
| `memory_seeds` | array<string,string> | Map of `filename => absolute path`. Each entry surfaces the bundled file as scaffold content for that filename when the target file does not yet exist on disk. Works for any filename registered via `MemoryFileRegistry::register()` — `SOUL.md` and `MEMORY.md` are common, but plugins can seed custom agent-layer files through the same primitive. See [Memory seed resolution](#memory-seed-resolution). |
| `owner_resolver` | callable | Returns `int user_id`. Called once at row-creation time. Defaults to `DirectoryManager::get_default_agent_user_id()`. |
| `default_config` | array | Initial `agent_config` persisted on creation. Subsequent config changes go through the DB — the registration never overrides user-edited config. |
| `meta` | array | Optional registry metadata for future consumers. Data Machine's current materializer ignores it. |

### Category and metadata policy

The current registry intentionally does **not** implement Abilities API-style categories.

Abilities need first-class categories because abilities are many small executable actions exposed through REST discovery and filtering. Agents are runtime definitions; their executable surface should be discovered through abilities/runtime tool declarations and explicit policy, not through a second category hierarchy on the agent object.

Agents API v1 should therefore keep category semantics out of `WP_Agent`:

- No `WP_Agent_Category` registry in the first standalone extraction.
- No category-derived permissions, REST visibility, tool policy, or memory policy.
- Data Machine admin grouping remains Data Machine product behavior.
- Future descriptive `metadata`, `type`, `capabilities`, or `annotations` fields can be added after the public class contract is finalized, but they must be non-authoritative until a separate issue defines their semantics.

See `docs/development/agents-api-pre-extraction-audit.md` for the extraction checklist and the comparison to Abilities API categories.

### Slug semantics

Slugs are passed through `sanitize_title()`. Empty slugs are rejected. They must be unique across a site (DB column has a UNIQUE constraint on `agent_slug`). Two plugins registering the same slug is resolved by **last-wins** — this intentionally diverges from the Abilities API's duplicate rejection. Data Machine relies on hook priority as a fresh-install override mechanism while the registry lives in-repo.

`WP_Agent` is a prepared definition object, not a database row. It validates property types, normalizes the slug and memory seed filenames, and exposes getters (`get_slug()`, `get_label()`, `get_description()`, `get_memory_seeds()`, `get_owner_resolver()`, `get_default_config()`, `get_meta()`).

Invalid property types reject the definition with a `_doing_it_wrong()` notice when WordPress provides that function. Unknown properties are ignored with the same notice style so future registry fields do not accidentally become materializer inputs.

## Lifecycle compared to Abilities API

Agents API deliberately follows the Abilities API vocabulary without copying every lifecycle constraint yet:

| Surface | Abilities API | Agents API in Data Machine |
|---|---|---|
| Init action | `wp_abilities_api_init` | `wp_agents_api_init` |
| Registry initialization | Lazy singleton after `init` | Lazy singleton on first registry read or registration |
| Registration timing | Must happen during `wp_abilities_api_init` | Should happen during `wp_agents_api_init`, but direct registration remains supported |
| Duplicate registration | Rejected with `_doing_it_wrong()` | Last registration wins |
| Lookup helpers | `wp_get_ability()`, `wp_get_abilities()`, `wp_has_ability()`, `wp_unregister_ability()` | `wp_get_agent()`, `wp_get_agents()`, `wp_has_agent()`, `wp_unregister_agent()` |

The timing divergence is intentional for v1. Data Machine's materializer fires registry reads from `init` priority 15, and some tests/consumers register definitions directly before that lazy collection path. Rejecting outside-hook registration would be more core-shaped, but it would be a behavior change for the in-repo substrate. New code should still prefer the hook form so extraction can tighten timing later.

## Reconciliation

Reconciliation runs on `init` at priority 15:

- Priority 10: `wp_abilities_api_init` fires. Abilities register.
- **Priority 15: `AgentRegistry::reconcile()` fires the `wp_agents_api_init` action, collects registrations, creates missing DB rows, scaffolds agent-layer memory files.**
- Priority 20: existing `datamachine_needs_scaffold` transient check. No-op when the registry has already scaffolded.

The `wp_agents_api_init` action is also fired lazily by `AgentRegistry::get_all()` / `get()` / `reconcile()` — so any caller can query the registry regardless of hook ordering. The legacy `datamachine_register_agents` hook and `datamachine_register_agent()` wrapper still fire while this surface lives in Data Machine; new code should use the WordPress-shaped names.

## Memory seed resolution

Registered `memory_seeds` entries flow into scaffold content via the existing `datamachine_scaffold_content` filter chain:

1. **Priority 5** — registry's generator. For any filename being scaffolded, checks `AgentRegistry::get($agent_slug)['memory_seeds'][$filename]`. If a readable bundle path is registered, its contents become the scaffold content.
2. **Priority 10** — DM's default generators (`datamachine_scaffold_soul_content`, `datamachine_scaffold_memory_content`, etc.). Produce generic site-context content using agent display name + site metadata.

Registered agents with a `memory_seeds` entry for a filename win at priority 5. Filenames without a seed entry fall through to priority 10. Agents created imperatively via `AgentAbilities::createAgent()` (with no registry entry) likewise fall through for every filename.

The scaffold ability never overwrites existing files. Once a seeded file exists on disk, its content is user-editable and plugin updates don't rewrite it. To reseed from an updated bundled version, delete the file and run the scaffold ability again.

Seeds apply to any filename registered via `MemoryFileRegistry::register()`. `SOUL.md` and `MEMORY.md` ship registered by default, so they work out of the box. Custom agent-layer files need a one-line `MemoryFileRegistry::register()` call somewhere in the plugin's bootstrap before a `memory_seeds` entry can be surfaced for them.

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
add_action( 'wp_agents_api_init', function () {
    $default_user_id = DirectoryManager::get_default_agent_user_id();
    $user            = get_user_by( 'id', $default_user_id );

    wp_register_agent(
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
| A role bundled with a plugin, same on every install | **Register** via `wp_agents_api_init` |
| A user-created agent with install-specific name, owner, config | **Create imperatively** via `AgentAbilities::createAgent()` |
| Lazy provisioning of a per-user agent on first chat turn | **Use** `datamachine_resolve_or_create_agent_id($user_id)` |

Registered agents and imperatively-created agents coexist cleanly — they're all just rows in `datamachine_agents` keyed by slug. The registry is an additive declarative path, not a replacement for the imperative API.

## Overriding a registered agent

Two override paths. Pick based on what you're trying to change.

### 1. Override registration intent (fresh installs only)

Hook at a higher priority and re-register with the same slug:

```php
add_action( 'wp_agents_api_init', function () {
    wp_register_agent( 'wiki-generator', array(
        'label'        => __( 'Custom Wiki Generator', 'my-override' ),
        'memory_seeds' => array(
            'SOUL.md' => __DIR__ . '/custom-wiki-soul.md',
        ),
    ) );
}, 20 ); // Higher than the original plugin's priority 10.
```

Last registration wins at the registry level. Because reconciliation is create-if-missing and the scaffold ability never overwrites existing files, an override only affects **fresh creation**:

| State | Override applies? |
|---|---|
| Agent row doesn't exist yet | ✅ Yes — your registration creates the row with your label + scaffolds from your `memory_seeds` |
| Agent row exists, seeded file doesn't | ✅ Partially — `label`/`description` are ignored (DB-owned), but the next scaffold cycle picks up your `memory_seeds` for any still-missing files |
| Agent row exists and seeded files exist | ❌ No — registration changes don't propagate to existing DB rows, and scaffold never overwrites existing files |

To reseed SOUL.md on an existing install, delete the file and let the scaffold ability regenerate it. To change `agent_name` or `agent_config`, go through the DB (`wp datamachine pipeline update`, admin UI, or direct `Agents::update_agent()` call) — those are DB-owned, user-editable fields.

### 2. Suppress a default registration entirely

Every DM core registration is a **named function** — callers can remove it cleanly:

```php
remove_action(
    'wp_agents_api_init',
    'datamachine_register_default_admin_agent',
    10
);
```

This prevents the registration from contributing to the registry at all. Useful for deployments that want full control over which agents exist on their site.

Plugins that bundle their own default registrations should follow the same convention — use a named function, document the handle in their README so site operators can suppress them.

### 3. Change SOUL.md content on an existing agent

Neither path 1 nor path 2 touches SOUL.md content once it exists on disk. To replace content for an already-materialized agent, the clean options are:

- **Delete and reseed** — remove the existing `SOUL.md` file, let the scaffold ability regenerate on the next read path (scaffold is idempotent + never overwrites extant files, so deletion is the trigger).
- **Hook `datamachine_scaffold_content` directly** — for conditional overrides based on agent context (e.g. Intelligence's `intelligence_kit` agent_config flag already does this at priority 20).

Registry-level overrides are the right tool for declaring defaults; content-level overrides are the right tool for active SOUL.md substitution.

## Related

- `docs/core-system/multi-agent-architecture.md` — agents table schema, access control, filesystem layout
- `docs/core-filters.md` — the `wp_agents_api_init` action, `datamachine_registered_agent_reconciled` action, `datamachine_scaffold_content` filter
- `inc/Abilities/File/ScaffoldAbilities.php` — scaffold ability that honors registered `memory_seeds` content
- `inc/migrations/scaffolding.php` — default `datamachine_scaffold_content` generators
