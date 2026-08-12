# Engine Execution

Data Machine executes the `pipeline -> flow -> job -> packets/artifacts` portion of the [canonical architecture](../architecture.md) through engine abilities and Action Scheduler. Business logic lives in `inc/Abilities/Engine/`; `inc/Engine/Actions/Engine.php` registers the action bridges Action Scheduler requires.

## Execution Cycle

| Scheduler hook | Ability | Responsibility |
|---|---|---|
| `datamachine_run_flow_now` | `datamachine/run-flow` | Resolve a flow, create or resume its job, snapshot configuration, and start the first step. |
| `datamachine_execute_step` | `datamachine/execute-step` | Load the job snapshot and packets, resolve the step definition, execute it, and process its explicit result. |
| `datamachine_schedule_next_step` | `datamachine/schedule-next-step` | Persist packets and enqueue the next `datamachine_execute_step` action. |
| `datamachine_run_flow_later` | `datamachine/schedule-flow` | Reconcile manual, one-time, or recurring flow scheduling. |

The hooks are durable queue adapters, not an alternative business-logic layer. Direct consumers should use abilities or a purpose-built REST, CLI, or chat adapter rather than firing scheduler hooks as service discovery.

## Run Flow

`RunFlowAbility` resolves the persisted pipeline and flow configuration, creates or resumes a job, stores an execution snapshot in the job's `engine_data`, finds the first enabled flow step, and dispatches `datamachine_schedule_next_step`. Scheduled callbacks set pipeline agent context and reject stale schedule generations before entering the ability.

Ephemeral workflows use `pipeline_id = direct` and `flow_id = direct`. Their generated workflow configuration is stored in the job snapshot, after which they follow the same step engine. See [Ephemeral Workflows](ephemeral-workflows.md).

## Execute Step

`ExecuteStepAbility` loads the job and its snapshot, retrieves the packet set, resolves the step through `StepTypeAbilities`, instantiates the registered class, and passes the common payload:

```php
$payload = array(
    'job_id'       => $job_id,
    'flow_step_id' => $flow_step_id,
    'data'         => $data_packets,
    'engine'       => $engine_data,
);
```

`DataMachine\Core\Steps\Step` validates and destructures this payload; the `EngineData` object supplies the snapshotted flow-step configuration. Step implementations return packets or an explicit result normalized by `StepExecutionResult`. The engine then schedules the next step, parks the job, fans out work, or commits a terminal state as directed by that result.

## Core Step Types

`datamachine_load_step_types()` instantiates the six core registrations:

| Slug | Class | Runtime behavior |
|---|---|---|
| `fetch` | `Core\Steps\Fetch\FetchStep` | Runs a source handler and produces packets; multiple packets can fan out. |
| `ai` | `Core\Steps\AI\AIStep` | Runs a pipeline agent turn with resolved tools and directives. |
| `publish` | `Core\Steps\Publish\PublishStep` | Completes one or more destination handler operations. |
| `upsert` | `Core\Steps\Upsert\UpsertStep` | Completes identity-aware create/update handler operations. |
| `webhook_gate` | `Core\Steps\WebhookGate\WebhookGateStep` | Persists a waiting state until a verified webhook resumes the job. |
| `system_task` | `Core\Steps\SystemTask\SystemTaskStep` | Executes a registered system task inline. |

Step classes register metadata through `StepTypeRegistrationTrait` and the `datamachine_step_types` extension filter. Consumers retrieve the resolved registry through `StepTypeAbilities`; they should not reproduce the filter call and caching rules.

## Packets and Artifacts

`DataPacket` prepends typed packets so the newest contribution is first. Between actions, `ScheduleNextStepAbility` persists packet data through `FilesRepository` rather than passing large payloads through Action Scheduler. Job `engine_data` stores the immutable execution snapshot plus controlled runtime metadata. Dedicated run-artifact abilities expose durable outputs and hydration.

Packet storage is scoped to the job's pipeline/flow context and cleaned according to job/file retention policy. See [Data Packet](data-packet.md), [Engine Data](engine-data.md), and [Files Repository](files-repository.md).

## Single-Item Isolation and Fan-Out

A child job processes one primary item through the remaining pipeline. A fetch can return multiple eligible packets; `PipelineBatchScheduler` creates child jobs so failures, retries, files, and logs stay isolated per item. The parent job coordinates the batch. This is why "one item per job" and "fetch may return multiple packets" are both true.

Queue consumption, fan-out, AI iterations, and recurring flow schedules are independent. See [Pipeline Execution Axes](../architecture/pipeline-execution-axes.md).

## Scheduling

Action Scheduler is the durable execution queue. `ScheduleFlowAbility`, `Api\Flows\FlowScheduling`, and `Engine\Tasks\RecurringScheduler` own schedule creation, reconciliation, and generation fencing. Supported interval keys come from the scheduling API and `datamachine_scheduler_intervals`; see [Scheduling Intervals](../api/endpoints/intervals.md).

The scheduler also carries recovery metadata and AI-concurrency resume actions. Retry and recovery paths re-enter the same `datamachine/execute-step` ability rather than implementing a parallel engine.

## Terminal States

Common terminal states include `completed`, `completed_no_items`, `agent_skipped`, and `failed`. Waiting, contention, and other nonterminal states remain resumable. Job repository transitions own terminal accounting, cleanup, metrics, and completion hooks; adapters should use job abilities instead of updating rows directly.

## Extension Boundary

- Add reusable operations as abilities.
- Add step or handler definitions through their documented registration contracts.
- Use Action Scheduler hooks only when a durable callback bridge is required.
- Give REST, CLI, and chat consumers purpose-built adapters that own their namespace and presentation semantics.
- Keep domain handlers and tools in their owning extension plugin.
