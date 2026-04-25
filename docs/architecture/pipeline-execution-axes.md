# Pipeline Execution Axes

Data Machine pipelines expand work along four orthogonal axes. Each axis answers a different question, lives in different code, and composes independently of the others. Their names look similar at first read; this doc maps how they fit together.

The four axes:

1. **Queueable fetch** — *which* config the fetch step uses on each tick.
2. **`max_items`** — *how many* items the source returns from one fetch call.
3. **Batch fan-out** — *how many child jobs* run the rest of the pipeline in parallel after fetch.
4. **Multi-handler completion** — *when* the AI conversation loop in pipeline mode is allowed to terminate.

Axes 1–3 expand fetch-side work. Axis 4 governs the AI step's completion boundary. Together they let one pipeline span "this single tick", "this run", "this batch", and "this conversation" without any external orchestrator.

## The four axes

### 1. Queueable fetch — cross-tick scheduling

`inc/Core/Steps/Fetch/FetchStep.php`, gated by the `queue_enabled` flag on `flow_step_config` and consuming via `inc/Core/Steps/QueueableTrait.php::popQueuedConfigPatch()`.

Every tick of the flow:

- If `queue_enabled` is false: use the static handler config as written. Standard fetch.
- If `queue_enabled` is true: pop **one** JSON-encoded patch from the per-flow-step prompt queue. Deep-merge it into the static handler config via `QueueableTrait::mergeQueuedConfigPatch()`. Run the handler with the merged config.
- If `queue_enabled` is true and the queue is empty: the fetch step is a no-op. The job completes with `JobStatus::COMPLETED_NO_ITEMS`. No fetch is attempted.

A "1" case is one queued patch consumed per tick. The "many" case is a queue of N patches drained over N ticks. The axis is **time** — different tick, different config.

Typical use: windowed retroactive backfills (each patch is a date window like `{"after": "2015-05-01", "before": "2015-06-01"}`) and rotating-source forward-ingestion. The queue lets a single recurring flow chew through a backfill plan without an external orchestrator and goes idempotent — clean no-op ticks — once the queue drains.

The popped item is backed up to the parent job's engine data (`queued_prompt_backup`) so a failed tick can be retried without losing the input.

### 2. `max_items` — per-call source cap

`inc/Core/Steps/Fetch/Handlers/FetchHandler.php::get_fetch_data()`, line 88. Read from `$config['max_items']`, with a default supplied by the handler subclass via `getDefaultMaxItems()`. The base `FetchHandlerSettings` exposes the field with a default of 1 and a UI cap of 100; `0` is the documented "unlimited" value.

`max_items` runs **after** dedup (`filterProcessed()`) and **before** the items become `DataPacket`s. Its only job is `array_slice($items, 0, $max_items)` against the new-items list. Items the handler fetched but were already in `wp_datamachine_processed_items` are filtered out first; what remains is then capped.

A "1" case (the default) is "one DataPacket per fetch call." The "many" case is N DataPackets per fetch call. The axis is **breadth of one fetch** — same tick, same config, more items.

Items dropped by the cap are *not* marked as processed. They'll surface in the next fetch call. Together with deferred per-item marking (see `markCompletedItemProcessed`), this is what makes a steady-state recurring flow drain a source without dropping anything.

### 3. Batch fan-out — per-call parallelism

`inc/Abilities/Engine/PipelineBatchScheduler.php::fanOut()`, triggered from `inc/Abilities/Engine/ExecuteStepAbility.php` after any step that produces more than one DataPacket.

Mechanics:

- After a step succeeds, the engine counts the DataPackets it returned.
- ≤ 1 packet: **inline continuation**. The same job continues to the next step. No fan-out.
- &gt; 1 packets (after `filterPacketsForFanOut`): the current job becomes the **batch parent**. `PipelineBatchScheduler::fanOut()` records batch state on the parent's `engine_data` and schedules child-creation in chunks via Action Scheduler. Each chunk is `CHUNK_SIZE = 10` children, with `CHUNK_DELAY = 30` seconds between chunks so the queue doesn't flood.
- Each child job inherits a clone of the parent's `engine_data` plus per-item engine data from its own packet's `metadata['_engine_data']`, plus dedup context (`item_identifier`, `source_type`). Children carry the parent's `agent_id` and `user_id` so downstream consumers (memory directives, permission resolution, model selection) bind to the right agent.
- `PipelineBatchScheduler::onChildComplete()` is wired to `datamachine_job_complete` and decides the parent's final status from the child status counts.

A "1" case is no fan-out — the engine inlines through to the next step on the same job. The "many" case is N child jobs each running the remaining pipeline on their own packet. The axis is **breadth of one run** — same tick, same fetch call, parallel downstream work.

The scheduler is generic. Any step that emits multiple packets fans out, not just fetch. In practice fetch is the dominant emitter.

### 4. Multi-handler completion — AI loop termination

`inc/Engine/AI/AIConversationLoop.php`, line 175 (where `$configured_handlers` is built) and line 359 onward (where it's consumed).

In pipeline mode, the conversation loop tracks which handler tools fire across turns:

- `$configured_handlers = $flow_step_config['handler_slugs'] ?? array()` — read at loop start.
- Each successful handler tool execution appends its handler slug to `$executed_handler_slugs`.
- After each handler tool succeeds:
  - If `$configured_handlers` is non-empty: the loop computes `array_diff($configured_handlers, array_unique($executed_handler_slugs))`. The loop completes when remaining is empty — i.e. **all** configured handlers have fired at least once.
  - If `$configured_handlers` is empty (legacy / no list available): first-handler-wins. The loop completes on the first successful handler tool call.

A "1" case is a step configured with one handler slug — the loop completes when that handler fires. The "many" case is a step configured with multiple handler slugs (e.g. publish to Twitter and Bluesky and Threads in one AI step) — the loop keeps running until all of them have fired. The axis is **breadth of one conversation** — same job, same AI step, multiple handler tools must complete.

Non-handler tool calls (search, fetch, generic abilities) don't move the completion counter at all. Only `$tool_def['handler']` tools count.

## Side-by-side

| Axis | Lives in | Trigger | "1" case | "many" case | Layer |
|------|----------|---------|----------|-------------|-------|
| **1. Queueable fetch** | `Core/Steps/Fetch/FetchStep.php` + `Core/Steps/QueueableTrait.php::popQueuedConfigPatch` | `flow_step_config['queue_enabled']` + a non-empty per-flow-step queue | Static handler config; or one queued patch popped per tick | Many ticks, each popping the next patch | Across ticks |
| **2. `max_items`** | `Core/Steps/Fetch/Handlers/FetchHandler.php::get_fetch_data` | `handler_config['max_items']`, applied after dedup | One DataPacket per fetch call | N DataPackets per fetch call | Inside one fetch call |
| **3. Batch fan-out** | `Abilities/Engine/PipelineBatchScheduler.php::fanOut` (called from `ExecuteStepAbility`) | Any step returning > 1 DataPacket after filtering | Inline continuation on the same job | N child jobs, scheduled in chunks of 10 every 30s | Across child jobs in one run |
| **4. Multi-handler completion** | `Engine/AI/AIConversationLoop.php` (~line 359) | `flow_step_config['handler_slugs']` length on a pipeline-mode AI step | Loop completes after first successful handler tool | Loop runs until every configured handler has fired | Across turns of one conversation |

## Composed example

### One tick

```
flow ticks (e.g. cron) ──┐
                         ▼
              ┌──────────────────────┐
              │  Fetch step          │
              │                      │
              │  (1) queue_enabled?  │ ──► no  → use static config
              │                      │ ──► yes → pop one patch, deep-merge
              │                      │       (empty queue → COMPLETED_NO_ITEMS)
              │                      │
              │  Handler runs        │
              │  → returns N raw     │
              │    items             │
              │                      │
              │  (2) max_items cap   │ ──► slice to first M ≤ N (default 1)
              │                      │
              │  M DataPackets       │
              └──────────┬───────────┘
                         │
                         ▼
              ┌──────────────────────┐
              │  Engine: M packets?  │
              │                      │
              │  M ≤ 1 → inline      │     M > 1 → (3) fan-out
              │  same job continues  │     parent stays as batch parent
              │  to next step        │     M child jobs created in
              │                      │     chunks of 10 (CHUNK_SIZE),
              │                      │     30s apart (CHUNK_DELAY)
              └──────────┬───────────┘
                         │
                         ▼
              ┌──────────────────────┐
              │  AI step (per child) │
              │                      │
              │  Conversation loop   │
              │                      │
              │  (4) configured_     │  empty → first handler ends loop
              │      handlers list?  │  set   → loop runs until ALL
              │                      │          configured handlers fire
              └──────────────────────┘
```

### Many ticks chewing through a queue

A backfill flow with `queue_enabled = true`, a queue seeded with twelve monthly date windows, and a fetch handler configured to return up to `max_items = 50` items per call:

```
tick 0   pop {after:"2015-01-01", before:"2015-02-01"}
         fetch returns 47 items → 47 DataPackets
         engine fans out: 47 children (chunk 1: 10, chunk 2: 10 (+30s),
                                       chunk 3: 10 (+60s), chunk 4: 10 (+90s),
                                       chunk 5: 7  (+120s))
         each child runs AI step → publishes via configured handlers

tick 1   pop {after:"2015-02-01", before:"2015-03-01"}
         fetch returns 50 items (capped from 73 — 23 unmarked, surface next time
         this window pops)
         engine fans out: 50 children
         ...

tick 11  pop {after:"2015-12-01", before:"2016-01-01"}
         fetch returns 31 → 31 children → done

tick 12  queue empty → fetch is a clean no-op (COMPLETED_NO_ITEMS)
         every subsequent tick: same no-op until something queues new patches
```

Axis 1 paces *which* slice gets touched. Axis 2 caps the slice. Axis 3 parallelises within the slice. Axis 4 governs each parallel branch's AI completion boundary.

## Two near-misses

### `max_items` (axis 2) vs. `CHUNK_SIZE` (axis 3) — different layers

Both look like "max N at a time." They aren't the same thing.

| | `max_items` | `CHUNK_SIZE` |
|---|---|---|
| Where | Handler config field, enforced in `FetchHandler::get_fetch_data` | Hard-coded constant on `PipelineBatchScheduler` |
| Cap on | Items returned from the source per fetch call | Child jobs scheduled per Action Scheduler tick |
| Visible to | Pipeline author / agent (`max_items` is a UI field with default 1) | Engine internals only — not configurable per pipeline |
| Purpose | Source-side rate / batch shaping | Action Scheduler queue throughput |
| Relationship to packets | Decides how many packets are produced | Decides how fast existing packets become child jobs |

A flow with `max_items = 50` and 50 packets produces 50 child jobs. They are *all* scheduled — `CHUNK_SIZE` only controls that 10 are created right now and the next 10 30 seconds later. `CHUNK_SIZE` doesn't drop anything; `max_items` does.

### Queueable fetch vs. queueable AI — same primitive, two consumption shapes

Both share `QueueableTrait` and the same `popOnceFromFlowQueue()` pop / backup mechanics. They differ only in how the popped string is interpreted.

| | Queueable AI (`popFromQueueIfEmpty`) | Queueable fetch (`popQueuedConfigPatch`) |
|---|---|---|
| Used by | AI step's `user_message` | Fetch step's handler config |
| Popped value treated as | Scalar prompt string, returned verbatim | JSON object, decoded into a config patch |
| What the consumer does with it | Sets it as the AI step's user message | Deep-merges it into the static handler config (with JSON-encoded sub-fields handled transparently — see `mergeQueuedConfigPatch`) |
| Empty-queue behaviour | Returns empty value with `from_queue = false` — caller decides | `FetchStep` treats it as a clean no-op tick (`COMPLETED_NO_ITEMS`) |

The persistence layer (`QueueAbility`), the per-flow-step FIFO ordering, the `queue_enabled` toggle, and the retry-on-failure backup into engine data are identical. The difference is "config dict" vs "scalar string" and what happens when the queue runs dry. This is intentional shared infrastructure, not duplicated machinery.

## What to reach for when

- **"I want this flow to drain a multi-window backfill over the next N ticks."** Axis 1 (queueable fetch). Seed N JSON patches into the flow step's queue. Each tick pops one.
- **"I want each fetch call to return up to N items instead of 1."** Axis 2 (`max_items`). Set the handler's `max_items` field. Default is 1; `0` means unlimited. Items fetched but capped surface on the next call — nothing is dropped.
- **"My fetch returns N items and I want N parallel runs of the rest of the pipeline."** Axis 3 (batch fan-out). Automatic — no configuration. The engine fans out any time a step emits more than one DataPacket. Tune throughput by adjusting `max_items` upstream, not the (non-configurable) `CHUNK_SIZE`.
- **"My AI step needs to publish to multiple destinations in one conversation."** Axis 4 (multi-handler completion). Set `flow_step_config['handler_slugs']` to the list of handler slugs the loop must satisfy before it can terminate. Empty list falls back to first-handler-wins.

The four axes are independent. A pipeline can use any subset:

- Axis 2 alone: a one-shot fetch flow that returns up to 50 items per run, fanning out (axis 3 follows automatically), with a single-handler AI step.
- Axes 1 + 2 + 3 + 4: a recurring backfill with windowed config patches, source-capped batches, parallel downstream work, and multi-destination publishing.
- Axis 4 alone: a manually-triggered single-item flow with multiple publish destinations and no fetch loop at all.
