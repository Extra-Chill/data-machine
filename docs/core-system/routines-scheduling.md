# Routines Scheduling

All recurring scheduling in Data Machine — flow schedules and built-in system
task schedules — runs on **Agents API Routines** (v0.11.1+). Data Machine no
longer owns a bespoke scheduler; the adapter lives at
`inc/Engine/Scheduling/FlowRoutines.php`. Registration idempotency is owned by
the substrate: the Agents API Action Scheduler bridge's `register()` is itself
idempotent (Automattic/agents-api#555), so DM does not layer its own
schedule-fingerprint gate on top (Extra-Chill/data-machine#3497 removed the
consumer-side decorator that used to do this).

## Identity contract

| | Before (bespoke scheduler) | After (routines) |
|---|---|---|
| Hook | `datamachine_run_flow_now` | `wp_agent_routine_run_scheduled` |
| Args | `[flow_id, null, {generation}]` | `['routine_id' => 'flow-N']` |
| Group | `data-machine` | `agents-api` |
| Fencing | DM generation options | substrate generations (`agents_routine_generation_<id>`) |
| One-time | single action, same hook | single action under `datamachine_run_flow_once` (group `data-machine`) |
| Reconcile | `FlowScheduleReconciler` + lock | `Registry::reconcile()` via `FlowRoutines::reconcile()` |

## How it works

- **Flows:** every enabled, non-manual, non-one-time flow is a routine
  `flow-<flow_id>` whose wake target is the `datamachine/run-flow` ability.
  Interval aliases resolve to seconds through `datamachine_scheduler_intervals`;
  cron expressions pass through as routine `expression` triggers.
- **System schedules:** each entry from the `datamachine_recurring_schedules`
  filter becomes a routine `system-<schedule_id>` targeting the
  `datamachine/dispatch-system-task` ability, which fans ticks out into DM jobs
  (per-agent schedules fan out one job per active agent) and records rejection
  telemetry via `RecurringRejectionTracker`.
- **Boot:** the registry is in-memory per request, so `FlowRoutines::boot()`
  re-declares every persisted flow and active system schedule on `init`. The
  substrate's own `register()` is idempotent — it compares a pending action's
  recurrence (interval seconds / cron expression) against the routine and is a
  read-only no-op when nothing changed — so unchanged routines never reach
  Action Scheduler and timers are never reset by unrelated updates.
- **Permission:** the substrate defaults to deny for scheduled ability
  execution. `FlowRoutines::filter_ability_permission()` allows only routines
  actually registered this request, targeting `datamachine/run-flow` or
  `datamachine/dispatch-system-task`.

## Migration

The first boot after upgrade cancels every pending legacy generated action
(hook `datamachine_run_flow_now`, args `[flow_id, null, {generation}]`) once,
guarded by the `datamachine_routines_migrated_v1` option. Inspect it first:

```bash
wp datamachine flows migrate-routines            # dry run: prints the plan
wp datamachine flows migrate-routines --apply    # cancel legacy chains now
wp datamachine flows reconcile-schedules         # audit routine coverage
wp datamachine flows reconcile-schedules --apply # repair missing coverage
```

First runs after the migration shift by up to `min(interval, 3600)`s because
the substrate staggers the new chains deterministically per routine id.
`datamachine_run_flow_now` remains a live hook for queue backpressure deferral
ticks and is scheduled to retire one release after this migration.
