# Pipeline Builder Interface

The Pipelines admin page is a React builder for Data Machine pipelines, flows, flow-step handlers, prompt queues, memory/context files, and the integrated chat sidebar. The current client source lives under `inc/Core/Admin/Pages/Pipelines/assets/react/` and all server operations are routed through `utils/api.js` unless noted.

## Source Map

| Area | Primary files |
| --- | --- |
| REST wrapper | `utils/api.js` |
| Pipeline data | `queries/pipelines.js`, `components/pipelines/` |
| Flow data | `queries/flows.js`, `components/flows/` |
| Queue data | `queries/queue.js`, `components/modals/FlowQueueModal.jsx` |
| Handler data | `queries/handlers.js`, `components/modals/HandlerSettingsModal.jsx`, `components/flows/FlowStepHandler.jsx` |
| Config data | `queries/config.js`, `components/modals/StepSelectionModal.jsx`, `components/modals/HandlerSelectionModal.jsx` |
| UI state | `stores/uiStore.js`, `components/shared/ModalSwitch.jsx`, `components/shared/ModalManager.jsx` |
| Chat sidebar | `components/chat/`, `queries/chat.js` |

## State Model

**TanStack Query** owns server state and cache invalidation for pipelines, flows, handlers, queues, config, memory files, and chat data.

**Zustand** owns local UI state in `stores/uiStore.js`. The persisted keys are intentionally small:

- `selectedPipelineId`
- `isChatOpen`
- `chatSessionId`

Modal state (`activeModal`, `modalData`) stays in the store but is not persisted.

## Selected Agent Payload

Pipeline and flow creation include the selected admin agent when one is active. `utils/api.js` reads `selectedAgentId` from `@shared/stores/agentStore` and adds `{ agent_id: selectedAgentId }` through `getAgentPayload()`.

Current client mutations that include the selected agent payload:

- `createPipeline(name)` sends `pipeline_name` and optional `agent_id` to the `create-pipeline` ability.
- `createFlow(pipelineId, flowName)` sends `pipeline_id`, `flow_name`, and optional `agent_id` to the `create-flow` ability.

Flow and pipeline list reads (`fetchFlows`, `fetchPipelines`, and the Jobs page flow dropdown) also include the selected agent payload so the AgentSwitcher filter keeps applying to ability-backed queries.

## Pipeline Operations

| Operation | Client function | Endpoint |
| --- | --- | --- |
| List pipelines | `fetchPipelines(null, { perPage, offset, outputMode, includeFlows, search })` | `POST /wp-abilities/v1/abilities/datamachine/get-pipelines/run` with `input.output_mode=list`, `input.include_flows=false` |
| Fetch one pipeline | `fetchPipelines(pipelineId, { outputMode, includeFlows })` | `POST /wp-abilities/v1/abilities/datamachine/get-pipelines/run` with `input.pipeline_id` |
| Create pipeline | `createPipeline(name)` | `POST /wp-abilities/v1/abilities/datamachine/create-pipeline/run` with `input.pipeline_name` |
| Rename pipeline | `updatePipelineTitle(pipelineId, name)` | `POST /wp-abilities/v1/abilities/datamachine/update-pipeline/run` |
| Delete pipeline | `deletePipeline(pipelineId)` | `POST /wp-abilities/v1/abilities/datamachine/delete-pipeline/run` |
| Export pipelines | `exportPipelines(pipelineIds)` | `POST /wp-abilities/v1/abilities/datamachine/export-pipelines/run` with `input.pipeline_ids` |
| Import pipelines | `importPipelines(csvContent)` | `POST /wp-abilities/v1/abilities/datamachine/import-pipelines/run` with `input.data` and `input.format=csv` |

List mode requests `output_mode=list` with `include_flows=false`; selected pipeline flows are loaded separately.

## Pipeline Step Operations

| Operation | Client function | Endpoint |
| --- | --- | --- |
| Add step | `addPipelineStep(pipelineId, stepType, executionOrder)` | `POST /wp-abilities/v1/abilities/datamachine/add-pipeline-step/run` with `input.pipeline_id`, `input.step_type` |
| Delete step | `deletePipelineStep(pipelineId, stepId)` | `POST /wp-abilities/v1/abilities/datamachine/delete-pipeline-step/run` |
| Reorder steps | `reorderPipelineSteps(pipelineId, steps)` | `POST /wp-abilities/v1/abilities/datamachine/reorder-pipeline-steps/run` with `input.step_order` |
| Update AI step system prompt | `updateSystemPrompt(stepId, prompt, stepType, pipelineId)` | `POST /wp-abilities/v1/abilities/datamachine/update-pipeline-step/run` with `input.system_prompt` |

`updateSystemPrompt()` only sends `pipeline_step_id` and `system_prompt`; provider, model, and tool policy are resolved by the current mode system rather than the pipeline builder client. The step's pipeline context is resolved server-side from the step ID.

## Flow Operations

| Operation | Client function | Endpoint |
| --- | --- | --- |
| List flows for pipeline | `fetchFlows(pipelineId, { page, perPage, outputMode })` | `POST /wp-abilities/v1/abilities/datamachine/get-flows/run` with `input.pipeline_id` |
| Fetch one flow | `fetchFlow(flowId)` | `POST /wp-abilities/v1/abilities/datamachine/get-flows/run` with `input.flow_id` |
| Create flow | `createFlow(pipelineId, flowName)` | `POST /wp-abilities/v1/abilities/datamachine/create-flow/run` with `input.pipeline_id`, `input.flow_name` |
| Rename flow | `updateFlowTitle(flowId, name)` | `POST /wp-abilities/v1/abilities/datamachine/update-flow/run` with `input.flow_name`, then `get-flows` for the fresh record |
| Delete flow | `deleteFlow(flowId)` | `POST /wp-abilities/v1/abilities/datamachine/delete-flow/run` with `input.flow_id` |
| Duplicate flow | `duplicateFlow(flowId)` | `POST /wp-abilities/v1/abilities/datamachine/duplicate-flow/run` with `input.source_flow_id` |
| Run flow now | `runFlow(flowId)` | `POST /execute` with `flow_id` (transport route, later tranche) |
| Update schedule | `updateFlowSchedule(flowId, schedulingConfig)` | `POST /wp-abilities/v1/abilities/datamachine/update-flow/run` with `input.scheduling_config`, then `get-flows` for the fresh record |

Flow duplication invalidates the selected pipeline's flow query; the client sends only `source_flow_id` and the ability builds the copy.

## Pagination

Pipeline lists use offset pagination through `fetchPipelines()` with `per_page` and `offset`.

Flow lists use 1-indexed page state in React and convert it to REST offset in `fetchFlows()`:

```js
offset = ( page - 1 ) * perPage;
```

`FlowsSection.jsx` renders `@shared/components/Pagination` with `page`, `perPage`, `total`, and `onPageChange`. The default flow page size is 20.

## Flow Step Configuration

Flow steps render through `FlowStepCard.jsx`. Step type metadata comes from `GET /step-types` via `useStepTypes()`.

The card distinguishes these cases:

- AI steps show a queueable user-message field.
- Handler-backed steps show handler badges and configure controls.
- Non-handler step types render inline fields through `InlineStepConfig` and the handler details API fallback.

`updateFlowStepConfig(flowStepId, config)` sends partial config patches to the `update-flow-step` ability run route.

## Queue CRUD And Modes

Prompt queues are flow-step scoped. `FlowQueueModal.jsx` and `queries/queue.js` expose these operations:

| Operation | Client function | Endpoint |
| --- | --- | --- |
| Read queue | `fetchFlowQueue(flowId, flowStepId)` | `POST /wp-abilities/v1/abilities/datamachine/queue-list/run` |
| Add prompt(s) | `addToFlowQueue(flowId, flowStepId, prompts)` | `POST /wp-abilities/v1/abilities/datamachine/queue-add/run` (one call per prompt) |
| Clear queue | `clearFlowQueue(flowId, flowStepId)` | `POST /wp-abilities/v1/abilities/datamachine/queue-clear/run` |
| Remove item | `removeFromFlowQueue(flowId, flowStepId, index)` | `POST /wp-abilities/v1/abilities/datamachine/queue-remove/run` |
| Update item | `updateFlowQueueItem(flowId, flowStepId, index, prompt)` | `POST /wp-abilities/v1/abilities/datamachine/queue-update/run` |
| Update mode | `updateFlowQueueMode(flowId, flowStepId, mode)` | `POST /wp-abilities/v1/abilities/datamachine/queue-mode/run` |

Queue modes are:

- `static`: peek the head prompt on each run without mutating the queue.
- `drain`: pop the head prompt on each run and discard it.
- `loop`: pop the head prompt on each run and append it to the tail.

The queue modal supports adding text, removing individual items, clearing all items after confirmation, and changing mode. It documents FIFO behavior in the UI.

## Handler Details And Editing

Handler discovery and details use:

- `getHandlers(stepType)` -> `GET /handlers`, optionally filtered by `step_type`.
- `fetchHandlerDetails(handlerSlug)` -> `GET /handlers/{handler_slug}`.

`HandlerSettingsModal.jsx` receives full handler metadata and the selected handler details as props. It renders schema fields through `HandlerSettingField`, normalizes/sanitizes values through `useHandlerModel()`, and saves through `useUpdateFlowHandler()`.

The save path calls `updateFlowHandler(flowStepId, handlerSlug, settings)` and sends to the `update-flow-step` ability run route:

```json
{
  "flow_step_id": "3_12",
  "handler_slug": "example_handler",
  "handler_config": {}
}
```

The flow, pipeline, and step-type context is derived server-side from the flow step ID. The client then re-reads the step through `get-flow-steps` so the caller can patch its cache with the fresh step config and settings display data.

Handler settings can be enriched by plugins through these client-side filters:

- `datamachine.handlerSettings.init`
- `datamachine.handlerSettings.fieldChange`

OAuth-capable handlers surface connection state in the settings modal and open `OAuthAuthenticationModal.jsx` for connection flows.

## Multi-Handler Editing

Multi-handler steps are driven by step type metadata where `multi_handler === true`.

`FlowStepCard.jsx` derives:

- `handler_slugs` for the ordered handler list.
- `handler_configs` for per-handler config.
- `handler_settings_displays` for per-handler display rows.

`FlowStepHandler.jsx` renders one badge/configure button per handler when multiple handlers are present and shows a `+` badge when another handler can be added.

`HandlerSettingsModal.jsx` supports per-handler editing by receiving `handlerSlugs` and `onRemoveHandler`. The destructive remove button is only shown when more than one handler is attached.

Add/remove multi-handler operations go through the shared `executeAbility()` client:

| Operation | Client function | Ability endpoint | Payload |
| --- | --- | --- | --- |
| Add handler | `addFlowHandler(flowStepId, handlerSlug, settings)` | `POST /wp-abilities/v1/abilities/datamachine/update-flow-step/run` | `flow_step_id`, `add_handler`, `add_handler_config` |
| Remove handler | `removeFlowHandler(flowStepId, handlerSlug)` | `POST /wp-abilities/v1/abilities/datamachine/update-flow-step/run` | `flow_step_id`, `remove_handler` |

## Memory, Context, And Agent Files

The builder exposes three file surfaces:

| Surface | Client functions | Endpoints |
| --- | --- | --- |
| Pipeline context files | `fetchContextFiles`, `uploadContextFile`, `deleteContextFile` | `POST /files` (multipart upload, retained); listing/deletion route the `list-flow-files`/`delete-flow-file` abilities |
| Pipeline memory files | `fetchPipelineMemoryFiles`, `updatePipelineMemoryFiles` | `POST /wp-abilities/v1/abilities/datamachine/{get,update}-pipeline-memory-files/run` |
| Flow memory files | `fetchFlowMemoryFiles`, `updateFlowMemoryFiles` | `POST /wp-abilities/v1/abilities/datamachine/{get,update}-flow-memory-files/run` |
| Available agent files | `fetchAgentFiles` | `GET /files/agent` |

Context files are uploaded against a pipeline. Memory files are selected by filename at either pipeline or flow scope. Agent files are read as an inventory for the selector UI.

## Handler And Tool Inventory Endpoints

The builder also reads current configuration inventories:

- `getStepTypes()` -> `GET /step-types`.
- `getTools(context)` -> `GET /tools`, optionally filtered by `context` (`pipeline`, `chat`, or `system`).
- `getHandlers(stepType)` -> `GET /handlers`, optionally filtered by step type.
- `getSchedulingIntervals()` -> `GET /settings/scheduling-intervals`.

## Current Modal Components

The modal system is centralized through `ModalSwitch.jsx` and `ModalManager.jsx`. Current modal components include:

- `ContextFilesModal.jsx`
- `FlowMemoryFilesModal.jsx`
- `FlowQueueModal.jsx`
- `FlowScheduleModal.jsx`
- `HandlerSelectionModal.jsx`
- `HandlerSettingsModal.jsx`
- `ImportExportModal.jsx`
- `MemoryFilesModal.jsx`
- `OAuthAuthenticationModal.jsx`
- `StepSelectionModal.jsx`

## Operational Notes

- The builder is REST-first and avoids full page reloads.
- Query hooks update or invalidate the smallest practical cache slice after mutations.
- Flow rows are paginated independently of pipeline rows to keep large admin installs usable.
- Handler UI is schema-driven; handler-specific rendering should be added through field metadata, handler models, or the handler settings filters before adding new bespoke modal branches.
