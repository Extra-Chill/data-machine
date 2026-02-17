# StepNavigator

## Overview

The `StepNavigator` class (`/inc/Engine/StepNavigator.php`) is an engine component responsible for step navigation logic during flow execution. Introduced in version 0.2.1, it centralizes the determination of next and previous steps based on execution order, providing a single source of truth for step traversal logic.

## Architecture

**Location**: `/inc/Engine/StepNavigator.php`
**Namespace**: `DataMachine\Engine`
**Since**: 0.2.1
**Purpose**: Centralized step navigation logic for pipeline execution

## Core Functionality

StepNavigator handles step traversal by analyzing flow configuration and execution order to determine which step should execute next (or previously for rollback scenarios).

### Key Methods

#### get_next_flow_step_id()

Determines the next flow step ID based on execution order.

**Signature**:
```php
public function get_next_flow_step_id(string $flow_step_id, array $context = []): ?string
```

**Parameters**:
- `$flow_step_id` (string) - Current flow step ID
- `$context` (array) - Context containing `engine_data` or `job_id`

**Returns**: Next flow step ID or `null` if no next step exists

**Usage**:
```php
$step_navigator = new StepNavigator();

// Using engine_data from context
$next_step_id = $step_navigator->get_next_flow_step_id($current_flow_step_id, [
    'engine_data' => $engine_data
]);

// Using job_id to fetch engine_data
$next_step_id = $step_navigator->get_next_flow_step_id($current_flow_step_id, [
    'job_id' => $job_id
]);
```

**Process**:
1. Retrieves flow configuration from engine_data or via `datamachine_engine_data` filter
2. Locates current step in flow configuration
3. Calculates next execution order (`current_order + 1`)
4. Searches flow configuration for step with matching execution order
5. Returns next step ID or `null` if pipeline complete

#### get_previous_flow_step_id()

Determines the previous flow step ID based on execution order (for rollback/debugging).

**Signature**:
```php
public function get_previous_flow_step_id(string $flow_step_id, array $context = []): ?string
```

**Parameters**:
- `$flow_step_id` (string) - Current flow step ID
- `$context` (array) - Context containing `engine_data` or `job_id`

**Returns**: Previous flow step ID or `null` if no previous step exists

**Usage**:
```php
$step_navigator = new StepNavigator();

// Useful for rollback or debugging scenarios
$previous_step_id = $step_navigator->get_previous_flow_step_id($current_flow_step_id, [
    'job_id' => $job_id
]);
```

**Process**:
1. Retrieves flow configuration from engine_data or via `datamachine_engine_data` filter
2. Locates current step in flow configuration
3. Calculates previous execution order (`current_order - 1`)
4. Searches flow configuration for step with matching execution order
5. Returns previous step ID or `null` if at pipeline start

## Engine Integration

StepNavigator is used by the engine execution system to determine step transitions:

```php
use DataMachine\Engine\StepNavigator;

// During step execution
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

## Performance Optimization

StepNavigator is designed for optimal performance during execution:

**Engine Data Context**: Accepts pre-loaded `engine_data` to avoid redundant database queries:
```php
// Optimal: Pass engine_data from execution context
$next_step_id = $step_navigator->get_next_flow_step_id($flow_step_id, [
    'engine_data' => $engine_data
]);

// Fallback: Will fetch engine_data via filter if not provided
$next_step_id = $step_navigator->get_next_flow_step_id($flow_step_id, [
    'job_id' => $job_id
]);
```

**Cached Flow Configuration**: Flow configuration is retrieved once from engine_data and reused for step lookups.

**Minimal Memory Footprint**: Only stores current step information, not entire pipeline history.

## Flow Configuration Structure

StepNavigator expects flow configuration in this format:

```php
$flow_config = [
    'step_uuid_1_flow_123' => [
        'flow_step_id' => 'step_uuid_1_flow_123',
        'pipeline_step_id' => 'step_uuid_1',
        'step_type' => 'fetch',
        'execution_order' => 0,
        'handler_slug' => 'rss',
        'enabled' => true
    ],
    'step_uuid_2_flow_123' => [
        'flow_step_id' => 'step_uuid_2_flow_123',
        'pipeline_step_id' => 'step_uuid_2',
        'step_type' => 'ai',
        'execution_order' => 1,
        'enabled' => true
    ],
    'step_uuid_3_flow_123' => [
        'flow_step_id' => 'step_uuid_3_flow_123',
        'pipeline_step_id' => 'step_uuid_3',
        'step_type' => 'publish',
        'execution_order' => 2,
        'handler_slug' => 'twitter',
        'enabled' => true
    ]
];
```

## Edge Cases

**No Next Step**: Returns `null` when current step is final step in pipeline
**No Previous Step**: Returns `null` when current step is first step (execution_order = 0)
**Invalid Step ID**: Returns `null` if provided flow_step_id not found in configuration
**Missing Engine Data**: Returns `null` if flow configuration cannot be retrieved

## Benefits

- **Centralized Logic**: Single source of truth for step navigation
- **Complex Ordering Support**: Handles any execution order sequence
- **Rollback Capability**: Supports backward navigation via `get_previous_flow_step_id()`
- **Performance Optimized**: Minimal database queries via engine_data context
- **Maintainability**: Navigation logic isolated from execution engine
- **Testability**: Pure function design enables easy unit testing

## Related Documentation

- Engine Execution System
- Step Base Class
- Database Schema
