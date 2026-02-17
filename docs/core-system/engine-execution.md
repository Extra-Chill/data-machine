# Engine Execution System

The Data Machine engine utilizes a four-action execution cycle (@since v0.8.0) that orchestrates all pipeline workflows through WordPress Action Scheduler. This system implements a **Single Item Execution Model**, processing exactly one item per job execution to ensure maximum reliability and isolation.

## Execution Cycle

The engine follows a standardized cycle for both database-driven and ephemeral workflows:

1.  **`datamachine_run_flow_now`**: Entry point for execution. Loads configurations and initializes the job.
2.  **`datamachine_execute_step`**: Performs the actual work of a single step (Fetch, AI, Publish, etc.).
3.  **`datamachine_schedule_next_step`**: Persists data packets and schedules the next step in the sequence.
4.  **`datamachine_run_flow_later`**: Handles scheduling logic, queuing the flow for future execution.

## Tool execution

The pipeline engine executes step-specific tools through the AI tool system (tool discovery via filters and cached resolution in `ToolManager`).
## Single Item Execution Model

At its core, the engine is designed for reliability-first processing. Instead of processing batches of items, which can lead to timeouts or cascading failures, the engine processes **exactly one item per job execution cycle**.

- **Isolation**: Each item is processed in its own job context. A failure in one item does not affect others.
- **Reliability**: Minimizes memory usage and execution time per step.
- **Traceability**: Every processed item is linked to a specific job and log history.
- **Consistency**: Steps (Fetch, AI, Publish) are built with the expectation of receiving and returning a single primary data packet.

## 1. Flow Initiation (`datamachine_run_flow_now`)

**Purpose**: entry point for immediate execution of a workflow.

**Process**:
1.  **Context Setting**: Sets `AgentContext` to `PIPELINE`.
2.  **Job Creation**: Uses `JobManager` to create or retrieve a job record.
3.  **Configuration Loading**: Loads `flow_config` and `pipeline_config`.
4.  **Snapshotting**: Stores an engine snapshot in `engine_data` for consistency throughout the job.
5.  **First Step Discovery**: Identifies the step with `execution_order = 0`.
6.  **Scheduling**: Triggers `datamachine_schedule_next_step` for the first step.

**Usage**:
```php
do_action('datamachine_run_flow_now', $flow_id, $job_id);
```

## 2. Step Execution (`datamachine_execute_step`)

**Purpose**: Processes an individual step within the workflow.

**Parameters**:
- `$job_id` (int) - Job identifier.
- `$flow_step_id` (string) - Specific step being executed.

**Process**:
1.  **Data Retrieval**: Loads data packets from `FilesRepository` using the job context.
2.  **Config Resolution**: Retrieves step configuration from the engine snapshot.
3.  **Step Dispatch**: Instantiates the appropriate step class (e.g., `AIStep`) and calls `execute()`.
4.  **Navigation**: Uses `StepNavigator` to determine if a subsequent step exists.
5.  **Completion/Transition**: If a next step exists, it calls `datamachine_schedule_next_step`. Otherwise, it marks the job as `completed` and cleans up temporary files.

## 3. Step Scheduling (`datamachine_schedule_next_step`)

**Purpose**: Transitions between steps using Action Scheduler.

**Parameters**:
- `$job_id` (int) - Job identifier.
- `$flow_step_id` (string) - Next step to execute.
- `$dataPackets` (array) - Content to pass to the next step.

**Process**:
1.  **Data Persistence**: Stores `$dataPackets` in the `FilesRepository` isolated by flow and job.
2.  **Background Queuing**: Schedules `datamachine_execute_step` via `as_schedule_single_action()` for immediate background processing.

## 4. Deferred Execution (`datamachine_run_flow_later`)

**Purpose**: Manages future or recurring execution logic via the [Scheduling System](../api/endpoints/intervals.md).

**Parameters**:
- `$flow_id` (int) - Flow to schedule.
- `$interval_or_timestamp` (string|int) - 'manual', a Unix timestamp, or a recurring interval key (e.g., 'every_5_minutes', 'hourly').

**Process**:
1.  **Cleanup**: Unscheduled any existing actions for the flow using `as_unschedule_action`.
2.  **Manual Mode**: Simply updates the database configuration without scheduling new actions.
3.  **Timestamp Mode**: Schedules a one-time `datamachine_run_flow_now` at the specific Unix timestamp using `as_schedule_single_action`.
4.  **Interval Mode**: Schedules recurring `datamachine_run_flow_now` actions using `as_schedule_recurring_action`.
5.  **Database Sync**: Updates the flow's `scheduling_config` in the database to reflect the new state.

**Supported Intervals**:
- `every_5_minutes`
- `hourly`
- `every_2_hours`
- `every_4_hours`
- `qtrdaily` (Every 6 hours)
- `twicedaily` (Every 12 hours)
- `daily`
- `weekly`

Developers can add custom intervals via the `datamachine_scheduler_intervals` filter.

## Ephemeral Workflows

The engine supports **Ephemeral Workflows** (@since v0.8.0)—workflows executed without being saved to the database. These are triggered via the `/execute` REST endpoint by passing a `workflow` object instead of a `flow_id`.

- **Sentinel Values**: Use `flow_id = 'direct'` and `pipeline_id = 'direct'`.
- **Dynamic Config**: Configurations are generated on-the-fly from the request and stored in the job's `engine_data` snapshot.
- **Execution Flow**: Once initialized, they follow the standard `execute_step` → `schedule_next_step` cycle.

## Step Navigation

The engine uses **StepNavigator** (@since v0.2.1) to determine step transitions during execution:

```php
use DataMachine\Engine\StepNavigator;

$step_navigator = new StepNavigator();

// Determine next step after current step completes
$next_flow_step_id = $step_navigator->get_next_flow_step_id($flow_step_id, [
    'engine_data' => $engine_data
]);

if ($next_flow_step_id) {
    // Schedule next step execution
    do_action('datamachine_schedule_next_step', $job_id, $next_flow_step_id, $data);
} else {
    // Pipeline complete
    do_action('datamachine_update_job_status', $job_id, 'completed');
}
```

**Benefits**:
- Centralized step navigation logic
- Support for complex step ordering
- Rollback capability via `get_previous_flow_step_id()`
- Performance optimized via engine_data context

**See**: StepNavigator Documentation for complete details

## Data Storage

### Files Repository

Step data is persisted per-job using `FilesRepository` (`FileStorage` + `FileRetrieval`) under the `datamachine-files` uploads directory.

See [FilesRepository](files-repository.md) for the current directory structure and component responsibilities.


## Step Discovery

### Step Registration

Steps register via `datamachine_step_types` filter:

```php
add_filter('datamachine_step_types', function($steps) {
    $steps['my_step'] = [
        'name' => __('My Step'),
        'class' => 'MyStep',
        'position' => 50
    ];
    return $steps;
});
```

### Step Implementation

All steps implement the same payload contract:

```php
class MyStep {
    public function execute(array $payload): array {
        $job_id = $payload['job_id'];
        $flow_step_id = $payload['flow_step_id'];
        $data = $payload['data'] ?? [];

        // EngineData value object (not a raw array)
        $engine = $payload['engine'];

        array_unshift($data, [
            'type' => 'my_step',
            'content' => ['title' => $title, 'body' => $content],
            'metadata' => ['source_type' => 'my_source'],
            'timestamp' => time()
        ]);

        return $data;
    }
}
```

## Parameter Passing

### Unified Step Payload

Engine now delivers a documented payload array to every step:

```php
$payload = [
    'job_id' => $job_id,
    'flow_step_id' => $flow_step_id,
    'data' => $data,
    'engine' => $engine_data
];
```

**Benefits**:
- ✅ **Explicit Dependencies**: Steps read everything from a single payload without relying on shared globals
- ✅ **Consistent Evolvability**: New metadata can be appended to the payload without changing method signatures
- ✅ **Pure Testing**: Steps are testable via simple array fixtures, enabling isolated unit tests

**Step Implementation Pattern** remains identical to the example above—extract what you need from `$payload`, process data, and return the updated packet.

## Job Management

### Job Status

- `pending` - Created but not started
- `processing` - Currently executing
- `completed` - Successfully finished (items processed)
- `completed_no_items` - Finished successfully but no new items are found to process
- `agent_skipped` - Finished intentionally without processing the current item (supports compound statuses like `agent_skipped - {reason}`)
- `failed` - Actual execution error occurred

### Flow Monitoring & Problem Flows

The engine tracks execution metrics to identify "Problem Flows" that may require administrative attention:

- **Metrics**: Each flow tracks `consecutive_failures` and `consecutive_no_items`.
- **Threshold**: The `problem_flow_threshold` setting (default: 3) determines when a flow is flagged.
- **Monitoring**: 
    - **REST API**: `GET /datamachine/v1/flows/problems` returns flagged flows.
    - **AI Tools**: `get_problem_flows` allows agents to identify and troubleshoot these flows.
- **Reset**: Metrics are reset upon the next successful `completed` execution.

**See**: [Troubleshooting Problem Flows](troubleshooting-problem-flows.md) for detailed guidance.

### Job Operations

**Create Job**:
```php
$job_id = $db_jobs->create_job([
    'pipeline_id' => $pipeline_id,
    'flow_id' => $flow_id
]);
```

**Update Status**:
```php
$ability = wp_get_ability( 'datamachine/retry-job' );
$ability->execute( [ 'job_id' => $job_id ] );
```

**Fail Job**:
```php
$ability = wp_get_ability( 'datamachine/fail-job' );
$ability->execute( [
    'job_id' => $job_id,
    'reason' => 'step_execution_failure',
] );
```

## Error Handling

### Exception Management

All step execution is wrapped in try-catch blocks:

```php
try {
    $data = $flow_step->execute($parameters);
    return !empty($data); // Success = non-empty data packet
} catch (\Throwable $e) {
    $log_ability = wp_get_ability( 'datamachine/write-to-log' );
    $log_ability->execute( [
        'level'   => 'error',
        'message' => 'Step execution failed: ' . $e->getMessage(),
        'agent'   => 'pipeline',
    ] );

    $fail_ability = wp_get_ability( 'datamachine/fail-job' );
    $fail_ability->execute( [ 'job_id' => $job_id, 'reason' => 'step_execution_failure' ] );
    return false;
}
```

### Failure Actions

**Job Failure**:
- Updates job status to 'failed'
- Logs detailed error information
- Optionally cleans up job data files
- Stops pipeline execution

## Performance Considerations

### Action Scheduler Integration

- **Asynchronous Processing** - Steps run in background via WP cron
- **Immediate Scheduling** - `time()` for next step execution
- **Queue Management** - WordPress handles scheduling and retry logic

### Data Storage Optimization

- **Reference-Based Passing** - Large data stored in files, not database
- **Automatic Cleanup** - Completed jobs cleaned from storage
- **Flow Isolation** - Each flow maintains separate storage directory

### Memory Management

- **Minimal Data Retention** - Only current step data in memory
- **Garbage Collection** - Automatic cleanup after completion
