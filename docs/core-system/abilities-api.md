# Abilities API

WordPress 6.9 Abilities API provides standardized capability discovery and execution for Data Machine operations. All REST API, CLI, and Chat tool operations delegate to registered abilities.

## Overview

The Abilities API in `inc/Abilities/` provides a unified interface for Data Machine operations. Each ability implements `execute_callback` with `permission_callback` for consistent access control across REST API, CLI commands, and Chat tools.

**Total registered abilities**: 203

This page documents the shape of the current ability surface and the source files that own each domain. For an exact live inventory, run `wp abilities list --category=datamachine-*` in a loaded WordPress install or inspect `wp_register_ability()` callsites under `inc/Abilities/`.

## Multi-Agent Scoping

Data Machine follows a WordPress-shaped identifier model: `agent_id` is the stable numeric row ID, and `agent_slug` is the human-readable, portable identifier. Programmatic ability inputs should use explicit fields (`agent_id` when selecting by row ID, `agent_slug` when selecting by slug). The generic `agent` selector is reserved for CLI and resolver-style boundaries that intentionally accept either ID or slug. The `PermissionHelper` class resolves selectors to scoped agent and user IDs, enforces ownership checks via `owns_resource()` and `owns_agent_resource()`, and controls access grants via `can_access_agent()`.

## Registered Ability Domains

The current core registry has 203 `datamachine/*` abilities across these domains:

| Domain | Count | Source anchor | Examples |
|--------|-------|---------------|----------|
| AI diagnostics | 1 | `inc/Abilities/AI/` | `datamachine/inspect-ai-request` |
| Agent identity | 11 | `inc/Abilities/AgentAbilities.php` | list/get/create/update/delete/rename/import/export agents, active-agent selection, audience access |
| Agent calls | 2 | `inc/Abilities/AgentCall/`, `inc/Abilities/AgentRemoteCall/` | `datamachine/agent-call`, `datamachine/agent-remote-call` |
| Agent memory | 5 | `inc/Abilities/AgentMemoryAbilities.php` | read/update/search sections, self-memory writes |
| Agent tokens | 3 | `inc/Abilities/AgentTokenAbilities.php` | create/list/revoke agent bearer tokens |
| Analytics | 2 | `inc/Abilities/Analytics/` | Google Analytics, Google Search Console |
| Auth | 7 | `inc/Abilities/AuthAbilities.php` | provider listing, status, connect/disconnect, token set/refresh/revoke |
| Chat | 6 | `inc/Abilities/Chat/` | sessions, read state, message sending |
| Content/block editing | 5 | `inc/Abilities/Content/` | upsert posts, get/edit/replace blocks, insert content |
| Daily memory | 5 | `inc/Abilities/DailyMemoryAbilities.php` | read/write/list/search/delete daily memory artifacts |
| Duplicate checks | 2 | `inc/Abilities/DuplicateCheck/` | duplicate detection, title matching |
| Email | 10 | `inc/Abilities/Email/` | reply/delete/move/flag/batch/unsubscribe/test connection |
| Engine | 5 | `inc/Abilities/Engine/` | run flow, drain job, execute/schedule steps |
| Fetch handlers | 7 | `inc/Abilities/Fetch/` | RSS, files, email, WordPress API/media/post queries |
| Files | 11 | `inc/Abilities/File/` | agent files, flow files, memory scaffolds, cleanup |
| Flow | 28 | `inc/Abilities/Flow/` | CRUD, pause/resume, queues, config patches, webhooks |
| Flow steps | 4 | `inc/Abilities/FlowStep/` | get/update/configure/validate flow steps |
| Handlers | 6 | `inc/Abilities/HandlerAbilities.php`, `inc/Abilities/Handler/` | discovery, validation, defaults, test harness |
| Internal linking | 7 | `inc/Abilities/InternalLinkingAbilities.php` | diagnostics, audit, backlinks, broken links, opportunities |
| Jobs | 10 | `inc/Abilities/Job/` | list/summary/delete/execute/retry/fail/recover/metrics/problem flows |
| Local search | 1 | `inc/Abilities/LocalSearchAbilities.php` | site-local post/content search |
| Logs | 5 | `inc/Abilities/LogAbilities.php` | write/read/clear/metadata/debug log |
| Media | 10 | `inc/Abilities/Media/` | alt text, image generation/optimization/templates, upload/validate/video metadata |
| Pipeline | 7 | `inc/Abilities/Pipeline/` | CRUD, duplicate, import/export |
| Pipeline steps | 5 | `inc/Abilities/PipelineStepAbilities.php` | get/add/update/delete/reorder steps |
| Post queries | 2 | `inc/Abilities/PostQueryAbilities.php` | query/list Data Machine-created posts |
| Processed items | 6 | `inc/Abilities/ProcessedItemsAbilities.php` | clear/check/history/stale/never-processed helpers |
| Publish | 3 | `inc/Abilities/Publish/` | WordPress publish, immediate and queued email sends |
| SEO | 6 | `inc/Abilities/SEO/` | IndexNow, meta descriptions |
| Settings | 7 | `inc/Abilities/SettingsAbilities.php` | settings, intervals, tool config, handler defaults |
| Source inventory | 2 | `inc/Abilities/SourceInventoryAbility.php`, `inc/Abilities/SourceAggregateAbility.php` | source inventory and aggregate reporting |
| Step types | 2 | `inc/Abilities/StepTypeAbilities.php` | list/validate step types |
| System | 3 | `inc/Abilities/SystemAbilities.php` | session titles, health checks, system task runs |
| Taxonomy | 6 | `inc/Abilities/Taxonomy/` | get/create/update/delete/resolve terms, merge term meta |
| Update handlers | 1 | `inc/Abilities/Update/` | WordPress update handler |

Workspace, GitHub, and code-review abilities are intentionally not registered by Data Machine core. They live in the `data-machine-code` extension plugin.

`content_format` remains the caller's authoring/source format (`markdown`, `html`, or `blocks`) and is distinct from the stored `post_content` format selected by `datamachine_post_content_format`. Content/block abilities read the post type's canonical stored format through `DataMachine\Core\Content\ContentFormat`, convert to block markup for block edits, then convert back before writing.

## Category Registration

Data Machine registers multiple ability categories via `wp_register_ability_category()` on the `wp_abilities_api_categories_init` hook. Category slugs use the `datamachine-{domain}` format (e.g. `datamachine-content`, `datamachine-flow`, `datamachine-pipeline`):

```php
wp_register_ability_category(
    'datamachine-flow',
    array(
        'label' => 'Flow',
        'description' => 'Flow CRUD, scheduling, queue management, and webhook triggers.',
    )
);
```

See `inc/Abilities/AbilityCategories.php` for the full list of registered categories.

## Permission Model

All abilities support both WordPress admin and WP-CLI contexts via the shared `PermissionHelper`:

```php
// Standard permission check
PermissionHelper::can_manage(); // WP-CLI/background/pre-auth contexts pass; web users need a mapped Data Machine capability.

// Multi-agent scoped permission check. Requests may provide explicit
// `agent_id` or `agent_slug`; resolver boundaries may also accept `agent`.
PermissionHelper::can_access_agent($agent_id);
PermissionHelper::owns_resource($resource_user_id);
PermissionHelper::resolve_scoped_agent_id($params);
PermissionHelper::resolve_scoped_user_id($params);
```

## Architecture

### Delegation Pattern

REST API endpoints, CLI commands, and Chat tools delegate to abilities for business logic. Abilities are the canonical, public-facing primitive; implementation classes below an ability are internal details.

```
REST API Endpoint → Ability → Database / WordPress API
CLI Command → Ability → Database / WordPress API
Chat Tool → Ability → Database / WordPress API
```

### Facade Pattern

Several top-level ability classes serve as facades that instantiate sub-ability classes from subdirectories; other domains are registered directly from their subdirectory classes:

- `inc/Abilities/ChatAbilities.php` → `inc/Abilities/Chat/CreateChatSessionAbility.php`, etc.
- `inc/Abilities/EngineAbilities.php` → `inc/Abilities/Engine/RunFlowAbility.php`, etc.
- Flow abilities are registered from `inc/Abilities/Flow/CreateFlowAbility.php`, `inc/Abilities/Flow/QueueAbility.php`, `inc/Abilities/Flow/WebhookTriggerAbility.php`, etc.

### Ability Registration

Each abilities class registers abilities on the `wp_abilities_api_init` hook:

```php
public function register(): void {
    add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
}
```

## Testing

Ability coverage lives in focused smoke tests under `tests/` rather than a separate `tests/Unit/Abilities/` tree. The ability-related smoke tests cover registration, schema contracts, permission contexts, chat/tool integration, source inventory, queue behavior, taxonomy cleanup, auth scoping, and wp-ai-client runtime boundaries. Run them through Homeboy or directly through the repository's PHP smoke harness:

```bash
homeboy test data-machine
```

## WP-CLI Integration

CLI commands execute abilities directly. See individual command files in `inc/Cli/Commands/` for available commands.

## Post Tracking

The `PostTracking` class in `inc/Core/WordPress/PostTracking.php` provides post tracking functionality for handlers creating WordPress posts.

**Meta Keys**:
- `_datamachine_post_handler`: Handler slug that created the post
- `_datamachine_post_flow_id`: Flow ID associated with the post
- `_datamachine_post_pipeline_id`: Pipeline ID associated with the post

**Usage**:
```php
use DataMachine\Core\WordPress\PostTracking;

// After creating a post
$this->storePostTrackingMeta($post_id, $handler_config);
```
