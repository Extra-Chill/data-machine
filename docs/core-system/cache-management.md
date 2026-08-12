# Cache Management

Data Machine uses ability-level `clearCache()` methods to invalidate cached services when handlers, step types, or tools are dynamically registered.

## Overview

Data Machine uses various internal caches to optimize performance for service discovery and tool resolution. These caches are typically static properties within their respective ability/tool classes and are invalidated directly via their `clearCache()` methods.

## Centralized Invalidation

The system relies on WordPress actions to trigger cache clearing. When a new handler or step type is registered, the relevant caches should be cleared via the appropriate ability or tool class.

### Invalidation Hooks

Cache invalidation is typically triggered after ecosystem registration actions, then performed via `HandlerAbilities::clearCache()`, `AuthAbilities::clearCache()`, `StepTypeAbilities::clearCache()`, and `ToolManager::clearCache()`.

## Cache Clearing Methods

Call the static `clearCache()` methods on the relevant classes when registrations change:

- `HandlerAbilities::clearCache()`
- `AuthAbilities::clearCache()`
- `StepTypeAbilities::clearCache()`
- `ToolManager::clearCache()`
- `PluginSettings::clearCache()`

## Site Context Caching

The `SiteContext` directive provides cached WordPress site metadata for AI context injection. This cache is separate from ability-level cache clearing and is automatically invalidated when posts, terms, users, or site settings change.

- **Cache Key**: `datamachine_site_context_data` (WordPress transient)
- **Automatic Invalidation**: Hooks into `save_post`, `delete_post`, `create_term`, `update_option_blogname`, etc.
- **Manual Invalidation**: `SiteContext::clear_cache()`.

## TanStack Query Caching

In the React-based admin UI (Pipelines, Logs, Settings, and Jobs), caching is handled by **TanStack Query**. Mutations (add, delete, update, clear) for pipelines, flows, steps, and jobs automatically trigger invalidations for the relevant query keys to ensure the UI stays in sync with the server state. The Jobs page also utilizes background refetching to maintain real-time status updates without manual page refreshes.

## Implementation Details

Caches are cleared by resetting static properties in the following classes:

- **HandlerAbilities**: `$handlers_cache`, `$settings_cache`, `$config_fields_cache`.
- **AuthAbilities**: cached providers.
- **StepTypeAbilities**: `$cache`.
- **ToolManager**: `$resolved_cache`.
- **HandlerDocumentation**: `$cached_all_handlers`, `$cached_by_step_type`, `$cached_handler_slugs`, and ability class instances.
