# Recurring Scheduler

Single primitive for all recurring / cron / one-time / manual scheduling in
Data Machine. Lives at `inc/Engine/Tasks/RecurringScheduler.php`. Every
recurring schedule in the plugin — flow schedules, system task schedules,
extension schedules — goes through this class.

## Why it exists

Before this primitive, three separate paths wrapped Action Scheduler with
slightly different features:

- `FlowScheduling::handle_scheduling_update()` — the most complete
  implementation (intervals filter, stagger, verify, alias resolution,
  clear-before-reschedule).
- `SystemAgentServiceProvider::manageDailyMemorySchedule()` — hardcoded
  daily memory schedule with none of the safety features.
- Extension-authored one-offs (e.g. `WorktreeCleanupSchedule.php` in
  data-machine-code) — each re-implementing the same glue.

Collapsing these into one primitive means a bug fix or feature addition
lands in one place and immediately benefits every caller.

## API

```php
use DataMachine\Engine\Tasks\RecurringScheduler;

$result = RecurringScheduler::ensureSchedule(
    string $hook,        // AS hook name
    array  $args,        // AS args (signature)
    ?string $interval,   // 'daily' | 'hourly' | 'manual' | 'one_time' | 'cron' | cron-expr | null
    array  $options = [],
    bool   $enabled = true
);
```

### Options

| Key | Type | Notes |
|---|---|---|
| `cron_expression` | string\|null | Required when `$interval === 'cron'`. |
| `timestamp` | int\|null | Required when `$interval === 'one_time'`. |
| `stagger_seed` | int | Deterministic seed (e.g. flow ID). 0 disables stagger. |
| `first_run_timestamp` | int\|null | Override first-run time for recurring schedules. Wins over stagger. |
| `group` | string | AS group, default `data-machine`. |

### Return shape

Success → `['interval' => ..., 'scheduled' => bool, ...]` with computed
fields like `interval_seconds`, `first_run`, `cron_expression`, `timestamp`,
so callers can persist metadata.

Failure → `WP_Error` with one of: `missing_timestamp`,
`missing_cron_expression`, `invalid_cron_expression`, `invalid_interval`,
`scheduler_unavailable`, `schedule_not_persisted`.

## Behavior

- `$enabled === false` (or `$interval` is null / `'manual'`) → unschedule
  any existing AS action for `(hook, args, group)` and return.
- Always clears existing actions before rescheduling (idempotent
  reschedule).
- After scheduling, verifies via `as_next_scheduled_action()` that AS
  actually persisted the action. AS can silently drop actions when its
  tables aren't ready (e.g. during CLI activation); this check catches
  that condition and returns `schedule_not_persisted`.
- Interval aliases (`every_6_hours` → `qtrdaily`, `every_12_hours` →
  `twicedaily`) are resolved before lookup against the
  `datamachine_scheduler_intervals` filter.
- Cron expressions can be passed as the `$interval` argument directly
  (auto-detected via `looksLikeCronExpression()`), or explicitly as
  `$interval = 'cron'` plus `$options['cron_expression']`.

## Helpers

```php
RecurringScheduler::unschedule( $hook, $args, $group = 'data-machine' );
RecurringScheduler::isScheduled( $hook, $args, $group = 'data-machine' ): bool;
RecurringScheduler::calculateStaggerOffset( int $seed, int $interval_seconds ): int;
RecurringScheduler::isValidCronExpression( string $expression ): bool;
RecurringScheduler::looksLikeCronExpression( string $value ): bool;
RecurringScheduler::describeCronExpression( string $expression ): string;
RecurringScheduler::resolveIntervalAlias( string $interval ): string;
```

## Schedule registry

`RecurringScheduler` is the AS-facing primitive. For code that wants to
declare a recurring task that the core should auto-reconcile, use the
`RecurringScheduleRegistry` instead — it reads the
`datamachine_recurring_schedules` filter and `SystemAgentServiceProvider`
iterates it on `action_scheduler_init`.

### Registering a recurring task schedule

```php
add_filter( 'datamachine_recurring_schedules', function ( $schedules ) {
    $schedules['my_custom_cleanup'] = [
        'task_type'          => 'my_custom_cleanup',   // maps to TaskRegistry handler
        'interval'           => 'daily',               // or cron expression, or interval key
        'enabled_setting'    => 'my_cleanup_enabled',  // PluginSettings key
        'default_enabled'    => false,
        'label'              => 'Daily at midnight UTC',
        'first_run_callback' => 'strtotime',
        'first_run_arg'      => 'tomorrow midnight',
        // Optional: task_params or task_params_callback for AS → job handoff.
    ];
    return $schedules;
} );
```

This registration is enough. The core iterates all schedules on
`action_scheduler_init`, calls `RecurringScheduler::ensureSchedule()` with
the right arguments, and wires the resulting AS hook to a generic closure
that calls `TaskScheduler::schedule($task_type, $params)` when the
schedule fires.

## Relationship to TaskScheduler

Two primitives, not one.

- **`RecurringScheduler`** — *when* something runs. Handles the AS timer.
- **`TaskScheduler::schedule()`** — *what* gets enqueued now. Creates a DM
  Job and fires `datamachine_task_handle` to run a task handler.

The bridge is the closure registered per-schedule in
`SystemAgentServiceProvider::registerActionSchedulerHooks()`: it listens
for `datamachine_recurring_<task_type>`, and its only job is to call
`TaskScheduler::schedule()` with the task params from the schedule
definition.

## Upgrade migration

The only non-additive change for existing installs is the AS hook
rename for daily memory generation
(`datamachine_system_agent_daily_memory` → `datamachine_recurring_daily_memory_generation`).
`SystemAgentServiceProvider::manageRecurringTaskSchedules()` runs on
`action_scheduler_init` and unschedules any pending action queued under
the old hook before the new schedule gets a chance to dispatch, so
upgrading sites don't carry a zombie recurring action. No other contract
changes; `FlowScheduling::handle_scheduling_update()` signature, REST
endpoints, CLI commands, the `datamachine_scheduler_intervals` filter,
and `TaskScheduler::schedule()` are all unchanged.
