# Services Layer Architecture

**Abilities-First Architecture** (@since v0.11.7)

The Services Layer has been fully migrated to the WordPress 6.9 Abilities API. The legacy Services directory has been deleted. All business logic now resides in ability classes under `inc/Abilities/`.

## Migration Status

The migration from OOP service managers to WordPress Abilities API is **complete**:

| Former Service | Replacement | Location |
|----------------|-------------|----------|
| `FlowManager` | `FlowAbilities` | `inc/Abilities/FlowAbilities.php` |
| `PipelineManager` | `PipelineAbilities` | `inc/Abilities/PipelineAbilities.php` |
| `PipelineStepManager` | `PipelineStepAbilities` | `inc/Abilities/PipelineStepAbilities.php` |
| `FlowStepManager` | `FlowStepAbilities` | `inc/Abilities/FlowStepAbilities.php` |
| `JobManager` | `JobAbilities` | `inc/Abilities/JobAbilities.php` |
| `ProcessedItemsManager` | `ProcessedItemsAbilities` | `inc/Abilities/ProcessedItemsAbilities.php` |
| `HandlerService` | `HandlerAbilities` | `inc/Abilities/HandlerAbilities.php` |
| `StepTypeService` | `StepTypeAbilities` | `inc/Abilities/StepTypeAbilities.php` |
| `LogsManager` | `LogAbilities` | `inc/Abilities/LogAbilities.php` |
| `CacheManager` | Ability-level `clearCache()` methods (legacy name) | Per-ability class |
| `AuthProviderService` | `AuthAbilities` | `inc/Abilities/AuthAbilities.php` |

## Abilities Overview

22 ability classes provide 79 registered abilities covering all Data Machine operations:

- **PipelineAbilities** - 7 abilities for pipeline CRUD, import/export
- **PipelineStepAbilities** - 5 abilities for pipeline step management
- **FlowAbilities** - 5 abilities for flow CRUD and duplication
- **FlowStepAbilities** - 4 abilities for flow step configuration and validation
- **JobAbilities** - 6 abilities for workflow execution, job management, health monitoring, summary
- **RecoverStuckJobsAbility** - 1 ability for stuck job recovery
- **FileAbilities** - 5 abilities for file management and uploads
- **ProcessedItemsAbilities** - 3 abilities for deduplication tracking
- **SettingsAbilities** - 7 abilities for plugin and handler settings
- **AuthAbilities** - 3 abilities for OAuth authentication management
- **LogAbilities** - 6 abilities for logging operations
- **HandlerAbilities** - 5 abilities for handler discovery and configuration
- **StepTypeAbilities** - 2 abilities for step type discovery and validation
- **PostQueryAbilities** - 1 ability for querying Data Machine-created posts
- **LocalSearchAbilities** - 1 ability for WordPress site search
- **SystemAbilities** - 2 abilities for session titles and health checks
- **TaxonomyAbilities** - 5 abilities for taxonomy term CRUD and resolution
- **QueueAbility** - 8 abilities for flow queue management
- **AltTextAbilities** - 2 abilities for media alt text generation and diagnostics
- **SendPingAbility** - 1 ability for agent ping notifications

## Architecture Principles

- **Standardized Capability Discovery**: All operations exposed via `wp_register_ability()`
- **Single Responsibility**: Each ability class handles one domain
- **Centralized Business Logic**: Consistent validation and error handling
- **Permission Integration**: All abilities check `manage_options` or WP_CLI context
- **Cache Management**: Each ability class provides its own `clearCache()` method

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
