# Abilities-First Service Layer

Data Machine's public service layer is the WordPress Abilities API. REST controllers, WP-CLI commands, chat tools, system tasks, and extension integrations call `datamachine/*` abilities instead of reaching into manager classes or controller internals.

The legacy Services directory has been removed. Current business operations live in focused ability classes under `inc/Abilities/`, with lower-level repositories and core classes kept as implementation details behind those abilities.

## Migration Status

The migration from OOP service managers to WordPress Abilities API is complete:

| Former Service | Replacement | Location |
|----------------|-------------|----------|
| `FlowManager` | Flow ability classes | `inc/Abilities/Flow/` |
| `PipelineManager` | Pipeline ability classes | `inc/Abilities/Pipeline/` |
| `PipelineStepManager` | `PipelineStepAbilities` | `inc/Abilities/PipelineStepAbilities.php` |
| `FlowStepManager` | Flow step ability classes | `inc/Abilities/FlowStep/` |
| `JobManager` | Job ability classes | `inc/Abilities/Job/` |
| `ProcessedItemsManager` | `ProcessedItemsAbilities` | `inc/Abilities/ProcessedItemsAbilities.php` |
| `HandlerService` | `HandlerAbilities` | `inc/Abilities/HandlerAbilities.php` |
| `StepTypeService` | `StepTypeAbilities` | `inc/Abilities/StepTypeAbilities.php` |
| `LogsManager` | `LogAbilities` | `inc/Abilities/LogAbilities.php` |
| `CacheManager` | Ability-level/cache-owner invalidation methods | Per-ability class or owning core class |
| `AuthProviderService` | `AuthAbilities` | `inc/Abilities/AuthAbilities.php` |

## Abilities Overview

Ability classes under `inc/Abilities/` register the operations Data Machine exposes to REST, WP-CLI, chat tools, background jobs, and external integrations. The current registry includes 205 `datamachine/*` abilities across domains including:

- Pipeline and pipeline-step CRUD, duplication, import, and export
- Flow CRUD, scheduling, duplication, pause/resume, webhook triggers, and per-step configuration
- Prompt queues, fetch config-patch queues, and queue mode management
- Job execution, retry/fail/delete/recovery, flow health, and summaries
- Agent identity, access grants, tokens, active-agent selection, remote calls, memory, and self-memory writes
- File, scaffold, chat, email, media, SEO, taxonomy CRUD/resolution/merge, settings, auth, logs, source inventory, and analytics operations

## Architecture Principles

- **Standardized Capability Discovery**: Public operations are exposed via `wp_register_ability()`
- **Single Responsibility**: Each ability class handles one domain
- **Centralized Business Logic**: Consistent validation and error handling
- **Permission Integration**: Abilities use `PermissionHelper` for Data Machine capabilities, agent token ceilings, pre-authenticated contexts, Action Scheduler, and WP-CLI
- **Cache Management**: Cache invalidation stays with the ability or core class that owns the cached domain

## Cache Management

Cache invalidation is distributed across ability classes:

```php
// Handler cache invalidation
HandlerAbilities::clearCache();

// Step type cache invalidation
StepTypeAbilities::clearCache();

// Auth provider cache invalidation
AuthAbilities::clearCache();

// Settings cache invalidation
PluginSettings::clearCache();
```

## Integration Points

### REST API Endpoints
REST endpoints delegate to abilities for business logic:

```php
// Get the ability
$ability = wp_get_ability('datamachine/get-pipelines');
if (!$ability) {
    return new \WP_Error('ability_not_found', 'Ability not found', ['status' => 500]);
}

// Execute with input
$result = $ability->execute(['per_page' => 10]);
```

### CLI Commands
WP-CLI commands execute abilities directly:

```php
// CLI handler calls ability
$ability = wp_get_ability('datamachine/execute-workflow');
$result = $ability->execute([
    'pipeline_id' => $pipeline_id,
    'handler_config' => $config,
]);
```

### Chat Tools
Chat tools delegate to abilities:

```php
// Tool execution via ability
$ability = wp_get_ability('datamachine/create-flow');
$result = $ability->execute([
    'pipeline_id' => $pipeline_id,
    'name' => $flow_name,
]);
```

## Error Handling

Abilities provide consistent error handling:

- **Validation**: Input sanitization and validation before operations
- **Logging**: Comprehensive logging via `LogAbilities`
- **Graceful Failures**: Proper error responses without system crashes
- **Permission Checks**: All abilities verify user capabilities

## Related Documentation

- [Abilities API](abilities-api.md) - WordPress 6.9 Abilities API usage
- [Handler Defaults System](handler-defaults.md) - Configuration merging logic
- [Handler Registration Trait](handler-registration-trait.md) - Service integration
