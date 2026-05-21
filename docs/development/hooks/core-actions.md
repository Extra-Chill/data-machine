# Core Actions Reference

Comprehensive reference for all WordPress actions used by Data Machine for pipeline execution, data processing, and system operations.

**Note**: Most core operations use ability classes under `inc/Abilities/` for direct method calls. These actions remain primarily for extensibility and backward compatibility.

## Abilities API Integration

Direct ability calls are preferred over actions for system operations:
- Pipeline abilities such as `datamachine/create-pipeline`, `datamachine/delete-pipeline`, and `datamachine/duplicate-pipeline`
- Flow abilities such as `datamachine/create-flow`, `datamachine/delete-flow`, and `datamachine/duplicate-flow`
- Job abilities such as `datamachine/fail-job` and `datamachine/retry-job`
- `LogAbilities` -> `write_to_log()`, `read_logs()`, `clear_logs()`

## Pipeline Execution Actions

### `datamachine_run_flow_now`

**Purpose**: Entry point for all pipeline execution

**Parameters**:
- `$flow_id` (int) - Flow ID to execute
- `$context` (string, optional) - Execution context ('manual', 'scheduled', etc.)

**Usage**:
```php
do_action('datamachine_run_flow_now', $flow_id, 'manual');
```

**Process**:
1. Retrieves flow data from database
2. Creates job record for tracking
3. Identifies first step (execution_order = 0)
4. Schedules initial step execution

### `datamachine_execute_step`

**Purpose**: Core step execution orchestration

**Parameters**:
- `$job_id` (string) - Job identifier
- `$flow_step_id` (string) - Flow step identifier
- `$dataPackets` (array|null) - Data packets or storage reference

**Usage**:
```php
do_action('datamachine_execute_step', $job_id, $flow_step_id, $dataPackets);
```

**Internal Process**:
1. Retrieves data from storage if reference provided
2. Loads flow step configuration
3. Instantiates and executes step class
4. Schedules next step or completes pipeline

### `datamachine_schedule_next_step`

**Purpose**: Action Scheduler integration for step transitions

**Parameters**:
- `$job_id` (string) - Job identifier
- `$flow_step_id` (string) - Next step to execute
- `$dataPackets` (array) - Data packets to pass

**Usage**:
```php
do_action('datamachine_schedule_next_step', $job_id, $next_flow_step_id, $dataPackets);
```

**Process**:
1. Stores data packet in files repository
2. Creates Action Scheduler task
3. Schedules immediate execution

## Data Processing Actions

### `datamachine_mark_item_processed`

**Purpose**: Mark items as processed for deduplication

**Parameters**:
- `$flow_step_id` (string) - Flow step identifier
- `$source_type` (string) - Handler source type
- `$item_id` (mixed) - Item identifier
- `$job_id` (string) - Job identifier

**Usage**:
```php
do_action('datamachine_mark_item_processed', $flow_step_id, 'wordpress_local', $post_id, $job_id);
```

**Database Operation**:
- Inserts record into `wp_datamachine_processed_items` table
- Creates unique constraint on flow_step_id + source_type + item_identifier

## Job Management Actions

### `datamachine_update_job_status`

**Purpose**: Update job execution status

**Abilities Integration**: Handled by `datamachine/retry-job` and `datamachine/fail-job` abilities.

**Parameters**:
- `$job_id` (string) - Job identifier
- `$status` (string) - New status ('pending', 'processing', 'completed', 'failed', 'completed_no_items', 'agent_skipped' or compound `agent_skipped - {reason}`)
- `$message` (string, optional) - Status message

**Usage**:
```php
// Abilities API
$ability = wp_get_ability( 'datamachine/retry-job' );
$ability->execute( [ 'job_id' => $job_id ] );

// Action Hook (for extensibility)
do_action('datamachine_update_job_status', $job_id, 'completed', 'Pipeline executed successfully');
```

### `datamachine_fail_job`

**Purpose**: Mark job as failed with detailed error information

**Abilities Integration**: Handled by `datamachine/fail-job` ability.

**Parameters**:
- `$job_id` (string) - Job identifier
- `$reason` (string) - Failure reason category
- `$context_data` (array) - Additional failure context

**Usage**:
```php
// Abilities API
$ability = wp_get_ability( 'datamachine/fail-job' );
$ability->execute( [
    'job_id' => $job_id,
    'reason' => 'step_execution_failure',
] );

// Action Hook (for extensibility)
do_action('datamachine_fail_job', $job_id, 'step_execution_failure', [
    'flow_step_id' => $flow_step_id,
    'exception_message' => $e->getMessage(),
    'reason' => 'detailed_error_reason'
]);
```

**Process**:
1. Updates job status to 'failed'
2. Logs detailed error information
3. Optionally cleans up job data files
4. Stops pipeline execution

## Configuration Hooks

Pipeline and flow-step configuration writes are ability-backed, not action-backed. Use `datamachine/update-pipeline-step`, `datamachine/update-flow-step`, or `datamachine/configure-flow-steps` through the Abilities API, REST API, WP-CLI, or chat tools.

Tool configuration uses the `datamachine_save_tool_config` **filter** from `BaseTool`; see [Core Filters](core-filters.md) for filter-oriented extension points.

## System Maintenance Actions

### `datamachine_cleanup_old_files`

**Purpose**: File repository maintenance

**Usage**:
```php
do_action('datamachine_cleanup_old_files');
```

**Process**:
- Removes data packets from completed jobs
- Cleans up orphaned files
- Runs via Action Scheduler

## Import/Export Actions

### `datamachine_import`

**Purpose**: Import pipeline or flow data

**Parameters**:
- `$type` (string) - Import type ('pipelines', 'flows')
- `$data` (array) - Import data

**Usage**:
```php
do_action('datamachine_import', 'pipelines', $csv_data);
```

### `datamachine_export`

**Purpose**: Export pipeline or flow data

**Parameters**:
- `$type` (string) - Export type ('pipelines', 'flows')
- `$ids` (array) - IDs to export

**Usage**:
```php
do_action('datamachine_export', 'pipelines', [$pipeline_id]);
```

### `datamachine_bundle_install_succeeded`

**Purpose**: Notify consumers that an agent bundle install/upgrade succeeded and the transaction has committed. The full extras payload is included so consumers do not need to re-read the bundle from disk.

**Fires**:
- After `commit_transaction()` succeeds, just before `AgentBundler::import()` returns success.
- For both fresh installs and upgrades; the `is_upgrade` flag distinguishes.
- **Never** on dry-run.
- **Never** on failure.

**Parameters**:
- `$agent_id` (int) - Newly installed/upgraded agent ID.
- `$slug` (string) - Agent slug.
- `$bundle_metadata` (array) - `bundle_slug`, `bundle_version`, `source_ref`, `source_revision`.
- `$extras` (array) - Validated extras payload, keyed by top-level directory name. Each value is a map of `<key>/path` => string contents.
- `$context` (array) - `is_upgrade` (bool), `summary` (array).

**Listener contract**:
- Listeners are fire-and-forget; their failures do not roll back the install.
- PHP exceptions thrown by listeners are caught, logged, and suppressed.
- Data Machine does NOT auto-extract extras to disk. Consumers handle persistence.

**Usage**:
```php
add_action(
    'datamachine_bundle_install_succeeded',
    function ( int $agent_id, string $slug, array $bundle_metadata, array $extras, array $context ): void {
        if ( empty( $extras['wiki'] ) ) {
            return;
        }
        my_plugin_import_wiki_files( $agent_id, $extras['wiki'], $context['is_upgrade'] );
    },
    10,
    5
);
```

See [Agent Bundles → Extras](../../core-system/agent-bundles.md#reserved-trees-and-extras) for the full contract and the paired `datamachine_bundle_export_extras` filter.

## Logging Action

### `datamachine_log`

**Purpose**: Centralized logging for all system operations

**Parameters**:
- `$level` (string) - Log level ('debug', 'info', 'warning', 'error')
- `$message` (string) - Log message
- `$context` (array) - Additional context data

**Usage**:
```php
do_action('datamachine_log', 'debug', 'AI Step Directive: Injected system directive', [
    'tool_count' => count($tools),
    'available_tools' => array_keys($tools),
    'directive_length' => strlen($directive)
]);
```

**Log Levels**:
- **debug** - Development and troubleshooting information
- **info** - General operational information  
- **warning** - Non-critical issues that should be noted
- **error** - Critical errors that affect functionality

## Action Scheduler Integration

### Action Types

**Primary Actions**:
- `datamachine_execute_step` - Step execution
- `datamachine_cleanup_old_files` - Maintenance
- Custom actions for scheduled flows

**Queue Management**:
- Group: `data-machine`
- Immediate scheduling: `time()` timestamp
- WordPress cron integration

### Usage Pattern

```php
// Schedule action
$action_id = as_schedule_single_action(
    time(), // Immediate execution
    'datamachine_execute_step',
    [
        'job_id' => $job_id,
        'flow_step_id' => $flow_step_id,
        'data' => $data_reference
    ],
    'data-machine'
);
```

## Error Handling Actions

### Exception Handling Pattern

```php
try {
    // Step execution
    $payload = [
        'job_id' => $job_id,
        'flow_step_id' => $flow_step_id,
        'flow_step_config' => $config,
        'data' => $data,
        'engine_data' => apply_filters('datamachine_engine_data', [], $job_id)
    ];

    $result = $step->execute($payload);
    
    if (!empty($result)) {
        // Success - schedule next step
        do_action('datamachine_schedule_next_step', $job_id, $next_step_id, $result);
    } else {
        // Failure - fail job
        do_action('datamachine_fail_job', $job_id, 'empty_result', $context);
    }
} catch (\Throwable $e) {
    // Exception - log and fail
    do_action('datamachine_log', 'error', 'Step execution exception', [
        'exception' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    do_action('datamachine_fail_job', $job_id, 'exception', $context);
}
```

### Logging Integration

All actions integrate with centralized logging:

```php
do_action('datamachine_log', 'info', 'Flow execution started successfully', [
    'flow_id' => $flow_id,
    'job_id' => $job_id,
    'first_step' => $first_flow_step_id
]);
```

## Security Considerations

### Capability Requirements

Callbacks that expose admin-only behavior should use the scoped Data Machine capability for the operation:

```php
if ( ! \DataMachine\Abilities\PermissionHelper::can( 'manage_flows' ) ) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
}
```

### Data Sanitization

Input data is sanitized before processing:

```php
$pipeline_name = sanitize_text_field($data['pipeline_name'] ?? '');
$config_json = wp_json_encode($config_data);
```
