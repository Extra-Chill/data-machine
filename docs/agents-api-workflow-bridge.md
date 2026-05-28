# Agents API Workflow Bridge

Data Machine exposes `datamachine/execute-agent-workflow` as the supported bridge for simple Agents API workflow specs.

The bridge accepts a `WP_Agent_Workflow_Spec` array, passes execution to `WP_Agent_Workflow_Runner`, and records the run as a Data Machine direct job. It does not convert the spec into a Data Machine pipeline or implement a second workflow runner.

## Supported Scope

Supported workflow shape:

- top-level `ability` steps;
- top-level `agent` steps, via the Agents API `agents/chat` ability;
- empty triggers or `on_demand` triggers;
- runner inputs, metadata, run IDs, and `continue_on_error`.

Unsupported workflow features fail before execution with a typed error. Data Machine does not bridge `foreach`, cron triggers, action triggers, branching, parallel execution, nested workflows, or consumer-defined step types through this ability.

## Job Mapping

Each run creates one Data Machine job with:

- `pipeline_id`: `direct`;
- `flow_id`: `direct`;
- `source`: `agents_api_workflow`;
- `label`: caller-provided `label` or `Agents API Workflow: <workflow_id>`.

The job `engine_data` stores:

- `agents_api_workflow.workflow_id` and `agents_api_workflow.run_id`;
- the source `spec`, runner `inputs`, and runner `metadata`;
- `workflow_run_result`, mirroring `WP_Agent_Workflow_Run_Result::to_array()`;
- `step_outcomes`, `output`, and `error` for job reporting;
- `artifacts` and `logs`, copied from `metadata.artifacts` and `metadata.logs` when the caller supplies them because `WP_Agent_Workflow_Run_Result` has no separate first-class artifact/log fields;
- `provenance` identifying Agents API as the source, `WP_Agent_Workflow_Runner` as the executor, and Data Machine jobs as the recording surface.

Runner status maps to Data Machine job status as follows:

- `running` remains `processing`;
- `succeeded` becomes `completed`;
- `failed` becomes `failed - <error_code>`;
- `skipped` becomes `failed - agents_api_workflow_skipped`.
