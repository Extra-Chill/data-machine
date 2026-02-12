# Abilities API

WordPress 6.9 Abilities API provides standardized capability discovery and execution for Data Machine operations. All REST API, CLI, and Chat tool operations delegate to registered abilities.

## Overview

The Abilities API in `inc/Abilities/` provides a unified interface for Data Machine operations. Each ability implements `execute_callback` with `permission_callback` for consistent access control across REST API, CLI commands, and Chat tools.

**Total registered abilities**: 79

## Registered Abilities

### Pipeline Management (7 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-pipelines` | List pipelines with pagination, or get single by ID | `PipelineAbilities.php` |
| `datamachine/create-pipeline` | Create new pipeline | `PipelineAbilities.php` |
| `datamachine/update-pipeline` | Update pipeline properties | `PipelineAbilities.php` |
| `datamachine/delete-pipeline` | Delete pipeline and associated flows | `PipelineAbilities.php` |
| `datamachine/duplicate-pipeline` | Duplicate pipeline with flows | `PipelineAbilities.php` |
| `datamachine/import-pipelines` | Import pipelines from JSON | `PipelineAbilities.php` |
| `datamachine/export-pipelines` | Export pipelines to JSON | `PipelineAbilities.php` |

### Pipeline Steps (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-pipeline-steps` | List steps for a pipeline, or get single by ID | `PipelineStepAbilities.php` |
| `datamachine/add-pipeline-step` | Add step to pipeline | `PipelineStepAbilities.php` |
| `datamachine/update-pipeline-step` | Update pipeline step config | `PipelineStepAbilities.php` |
| `datamachine/delete-pipeline-step` | Remove step from pipeline | `PipelineStepAbilities.php` |
| `datamachine/reorder-pipeline-steps` | Reorder pipeline steps | `PipelineStepAbilities.php` |

### Flow Management (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/create-flow` | Create new flow from pipeline | `FlowAbilities.php` |
| `datamachine/get-flows` | List flows with filtering, or get single by ID | `FlowAbilities.php` |
| `datamachine/update-flow` | Update flow properties | `FlowAbilities.php` |
| `datamachine/delete-flow` | Delete flow and associated jobs | `FlowAbilities.php` |
| `datamachine/duplicate-flow` | Duplicate flow within pipeline | `FlowAbilities.php` |

### Flow Steps (3 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-flow-steps` | List steps for a flow, or get single by ID | `FlowStepAbilities.php` |
| `datamachine/update-flow-step` | Update flow step config | `FlowStepAbilities.php` |
| `datamachine/configure-flow-steps` | Bulk configure flow steps | `FlowStepAbilities.php` |

### Job Execution (6 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-jobs` | List jobs with filtering, or get single by ID | `JobAbilities.php` |
| `datamachine/delete-jobs` | Delete jobs by criteria | `JobAbilities.php` |
| `datamachine/execute-workflow` | Execute workflow | `JobAbilities.php` |
| `datamachine/get-flow-health` | Get flow health metrics | `JobAbilities.php` |
| `datamachine/get-problem-flows` | List flows exceeding failure threshold | `JobAbilities.php` |
| `datamachine/get-jobs-summary` | Get job status summary counts | `JobAbilities.php` |

### File Management (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/list-files` | List files for a flow | `FileAbilities.php` |
| `datamachine/get-file` | Get single file details | `FileAbilities.php` |
| `datamachine/delete-file` | Delete specific file | `FileAbilities.php` |
| `datamachine/cleanup-files` | Clean up orphaned files | `FileAbilities.php` |
| `datamachine/upload-file` | Upload file to flow | `FileAbilities.php` |

### Processed Items (3 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/clear-processed-items` | Clear processed items for flow | `ProcessedItemsAbilities.php` |
| `datamachine/check-processed-item` | Check if item was processed | `ProcessedItemsAbilities.php` |
| `datamachine/has-processed-history` | Check if flow has processed history | `ProcessedItemsAbilities.php` |

### Settings (7 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-settings` | Get plugin settings | `SettingsAbilities.php` |
| `datamachine/update-settings` | Update plugin settings | `SettingsAbilities.php` |
| `datamachine/get-scheduling-intervals` | Get available scheduling intervals | `SettingsAbilities.php` |
| `datamachine/get-tool-config` | Get AI tool configuration | `SettingsAbilities.php` |
| `datamachine/save-tool-config` | Save AI tool configuration | `SettingsAbilities.php` |
| `datamachine/get-handler-defaults` | Get handler default settings | `SettingsAbilities.php` |
| `datamachine/update-handler-defaults` | Update handler default settings | `SettingsAbilities.php` |

### Authentication (3 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-auth-status` | Get OAuth connection status | `AuthAbilities.php` |
| `datamachine/disconnect-auth` | Disconnect OAuth provider | `AuthAbilities.php` |
| `datamachine/save-auth-config` | Save OAuth API configuration | `AuthAbilities.php` |

### Logging (6 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/write-to-log` | Write log entry with level routing | `LogAbilities.php` |
| `datamachine/clear-logs` | Clear logs by agent type | `LogAbilities.php` |
| `datamachine/read-logs` | Read logs with filtering | `LogAbilities.php` |
| `datamachine/get-log-metadata` | Get log file metadata | `LogAbilities.php` |
| `datamachine/set-log-level` | Set logging level | `LogAbilities.php` |
| `datamachine/get-log-level` | Get current logging level | `LogAbilities.php` |

### Post Query (1 ability)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/query-posts` | Query posts by handler, flow, or pipeline | `PostQueryAbilities.php` |

### Handler Discovery (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-handlers` | List available handlers, or get single by slug | `HandlerAbilities.php` |
| `datamachine/validate-handler` | Validate handler configuration | `HandlerAbilities.php` |
| `datamachine/get-handler-config-fields` | Get handler configuration fields | `HandlerAbilities.php` |
| `datamachine/apply-handler-defaults` | Apply default settings to handler | `HandlerAbilities.php` |
| `datamachine/get-handler-site-defaults` | Get site-wide handler defaults | `HandlerAbilities.php` |

### Step Types (2 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-step-types` | List available step types, or get single by slug | `StepTypeAbilities.php` |
| `datamachine/validate-step-type` | Validate step type configuration | `StepTypeAbilities.php` |

### Local Search (1 ability)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/local-search` | Search WordPress site for posts by title or content | `LocalSearchAbilities.php` |

### Taxonomy (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-taxonomy-terms` | List taxonomy terms | `TaxonomyAbilities.php` |
| `datamachine/create-taxonomy-term` | Create a taxonomy term | `TaxonomyAbilities.php` |
| `datamachine/update-taxonomy-term` | Update a taxonomy term | `TaxonomyAbilities.php` |
| `datamachine/delete-taxonomy-term` | Delete a taxonomy term | `TaxonomyAbilities.php` |
| `datamachine/resolve-term` | Resolve a term by name or slug | `TaxonomyAbilities.php` |

### Queue Management (8 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/queue-list` | List queue entries | `FlowAbilities.php` |
| `datamachine/queue-add` | Add item to queue | `FlowAbilities.php` |
| `datamachine/queue-remove` | Remove item from queue | `FlowAbilities.php` |
| `datamachine/queue-move` | Reorder queue item | `FlowAbilities.php` |
| `datamachine/queue-clear` | Clear queue | `FlowAbilities.php` |
| `datamachine/queue-update` | Update queue item | `FlowAbilities.php` |
| `datamachine/queue-settings` | Get/set queue settings | `FlowAbilities.php` |
| `datamachine/queue-validate` | Validate queue configuration | `FlowAbilities.php` |

### Media (2 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/generate-alt-text` | Generate alt text for media | `AltTextAbilities.php` |
| `datamachine/diagnose-alt-text` | Diagnose alt text issues | `AltTextAbilities.php` |

### Agent Ping (1 ability)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/send-ping` | Send agent ping notification | `AgentPingAbilities.php` |

### Job Recovery (1 ability)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/recover-stuck-jobs` | Recover jobs stuck in processing state | `RecoverStuckJobsAbility.php` |

### Flow Steps — Additional (1 ability)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/validate-flow-steps-config` | Validate flow steps configuration | `FlowStepAbilities.php` |

### System Infrastructure (2 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/generate-session-title` | Generate descriptive titles for chat sessions | `SystemAbilities.php` |
| `datamachine/system-health-check` | Run system health diagnostics | `SystemAbilities.php` |

## Category Registration

The `datamachine` category is registered via `wp_register_ability_category()` on the `wp_abilities_api_categories_init` hook:

```php
wp_register_ability_category(
    'datamachine',
    array(
        'label' => 'Data Machine',
        'description' => 'Data Machine flow and pipeline operations',
    )
);
```

## Permission Model

All abilities support both WordPress admin and WP-CLI contexts:

```php
'permission_callback' => function () {
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        return true;
    }
    return current_user_can( 'manage_options' );
}
```

## Architecture

### Delegation Pattern

REST API endpoints, CLI commands, and Chat tools delegate to abilities for business logic. Abilities are the canonical, public-facing primitive; service classes are considered an internal implementation detail and are being phased out as abilities become fully self-contained.

```
REST API Endpoint → Ability → (Service layer used during migration) → Database
CLI Command → Ability → (Service layer used during migration) → Database
Chat Tool → Ability → (Service layer used during migration) → Database
```

Note: many ability implementations are already self-contained and do not call service managers. Where services remain, they are transitional and will be migrated into abilities per the migration plan.

### Ability Registration

Each abilities class registers abilities on the `wp_abilities_api_init` hook:

```php
public function register(): void {
    add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
}
```

## Testing

Unit tests in `tests/Unit/Abilities/` verify ability registration, schema validation, permission checks, and execution logic:

- `AuthAbilitiesTest.php` - Authentication abilities
- `FileAbilitiesTest.php` - File management abilities
- `FlowAbilitiesTest.php` - Flow CRUD abilities
- `FlowStepAbilitiesTest.php` - Flow step abilities
- `JobAbilitiesTest.php` - Job execution abilities
- `LogAbilitiesTest.php` - Logging abilities
- `PipelineAbilitiesTest.php` - Pipeline CRUD abilities
- `PipelineStepAbilitiesTest.php` - Pipeline step abilities
- `PostQueryAbilitiesTest.php` - Post query abilities
- `ProcessedItemsAbilitiesTest.php` - Processed items abilities
- `SettingsAbilitiesTest.php` - Settings abilities

## WP-CLI Integration

CLI commands execute abilities directly. See individual command files in `inc/Cli/Commands/` for available commands.

## Post Tracking

The `PostTrackingTrait` in `inc/Core/WordPress/PostTrackingTrait.php` provides post tracking functionality for handlers creating WordPress posts.

**Meta Keys**:
- `_datamachine_post_handler`: Handler slug that created the post
- `_datamachine_post_flow_id`: Flow ID associated with the post
- `_datamachine_post_pipeline_id`: Pipeline ID associated with the post

**Usage**:
```php
use PostTrackingTrait;

// After creating a post
$this->storePostTrackingMeta($post_id, $handler_config);
```
