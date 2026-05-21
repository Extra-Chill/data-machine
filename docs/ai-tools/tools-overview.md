# AI Tools Overview

AI tools are registered by `inc/Engine/AI/Tools/ToolServiceProvider.php` into the unified Data Machine tool registry. Tools declare where they can run (`chat`, `pipeline`, or pipeline-policy mode) and the ability or access level required to execute them.

## Registered Tool Inventory

Mutation risk means what the tool can change when called successfully:

- **Read-only**: reads data or runs diagnostics without changing site content/config.
- **Low mutation**: writes agent memory, logs, queues, generated media, or external notifications.
- **Config mutation**: changes pipeline, flow, handler, schedule, auth, or default configuration.
- **Content mutation**: creates, updates, assigns, merges, or deletes WordPress content/taxonomy/files.
- **Destructive**: deletes durable workflow objects, files, logs, jobs, or terms.

### Chat And Pipeline Tools

| Tool | Modes | Mutation risk | Purpose | Docs |
| --- | --- | --- | --- | --- |
| `image_generation` | chat, pipeline | Low mutation | Create image-generation jobs and sideload generated media. | [Image Generation](image-generation.md) |
| `internal_link_audit` | chat, pipeline | Low mutation | Audit internal links, backlinks, orphans, and broken URLs. | [Internal Link Audit](internal-link-audit.md) |
| `local_search` | chat, pipeline | Read-only | Search local WordPress posts/pages. | [Local Search](local-search.md) |
| `web_fetch` | chat, pipeline | Read-only | Fetch readable content from a URL. | [Web Fetch](web-fetch.md) |
| `wordpress_post_reader` | chat, pipeline | Read-only | Read a WordPress post by permalink URL. | [WordPress Post Reader](wordpress-post-reader.md) |

### Chat And Pipeline-Policy Tools

Pipeline-policy tools are available while resolving pipeline tool policy, not as regular adjacent handler tools.

| Tool | Modes | Mutation risk | Purpose | Docs |
| --- | --- | --- | --- | --- |
| `agent_daily_memory` | chat, pipeline policy | Low mutation | Read, write, list, and search daily memory journal files. | [Agent Daily Memory](agent-daily-memory.md) |
| `agent_memory` | chat, pipeline policy | Low mutation | Read and update agent markdown files by section. | [Agent Memory](agent-memory.md) |

### Chat-Only Read And Diagnostics Tools

| Tool | Mutation risk | Purpose | Docs |
| --- | --- | --- | --- |
| `api_query` | Read-only | Query documented Data Machine REST endpoints with mutation operations restricted. | [API Query](api-query.md) |
| `get_handler_defaults` | Read-only | Read site-wide handler defaults for one handler or all handlers. | [Get Handler Defaults](get-handler-defaults.md) |
| `list_flows` | Read-only | List flows with pipeline, handler, and pagination filters. | [List Flows](list-flows.md) |
| `read_logs` | Read-only | Read Data Machine logs with agent/job/pipeline/flow filters. | [Read Logs](read-logs.md) |
| `search_taxonomy_terms` | Read-only | Search taxonomy terms before create/update/assign operations. | [Search Taxonomy Terms](search-taxonomy-terms.md) |
| `system_health_check` | Read-only | Run unified Data Machine and extension diagnostics. | [System Health Check](system-health-check.md) |

### Chat-Only Workflow Configuration Tools

| Tool | Mutation risk | Purpose | Docs |
| --- | --- | --- | --- |
| `add_pipeline_step` | Config mutation | Add a step to a pipeline and sync flows. | [Add Pipeline Step](add-pipeline-step.md) |
| `authenticate_handler` | Config mutation | Manage handler credentials and OAuth status. | [Authenticate Handler](authenticate-handler.md) |
| `configure_flow_steps` | Config mutation | Configure handler settings and AI user messages for one or many flow steps. | [Configure Flow Steps](configure-flow-steps.md) |
| `configure_pipeline_step` | Config mutation | Configure pipeline-level AI system prompt and tool policy. | [Configure Pipeline Step](configure-pipeline-step.md) |
| `copy_flow` | Config mutation | Duplicate a flow within or across compatible pipelines. | [Copy Flow](copy-flow.md) |
| `create_flow` | Config mutation | Create one or more flows with optional schedules and step configs. | [Create Flow](create-flow.md) |
| `create_pipeline` | Config mutation | Create one or more pipelines and their initial flow. | [Create Pipeline](create-pipeline.md) |
| `manage_queue` | Config mutation | Add/list/clear/remove/update/move prompt queue items and set queue mode. | [Manage Queue](manage-queue.md) |
| `reorder_pipeline_steps` | Config mutation | Reorder pipeline steps. | [Reorder Pipeline Steps](reorder-pipeline-steps.md) |
| `run_flow` | Low mutation | Run a flow now or schedule a future run. | [Run Flow](run-flow.md) |
| `set_handler_defaults` | Config mutation | Update site-wide defaults for handler configs. | [Set Handler Defaults](set-handler-defaults.md) |
| `update_flow` | Config mutation | Update flow title and/or schedule. | [Update Flow](update-flow.md) |

### Chat-Only Content And File Mutation Tools

| Tool | Mutation risk | Purpose | Docs |
| --- | --- | --- | --- |
| `assign_taxonomy_term` | Content mutation | Assign a term to one or more posts. | [Assign Taxonomy Term](assign-taxonomy-term.md) |
| `create_taxonomy_term` | Content mutation | Create a taxonomy term if it does not exist. | [Create Taxonomy Term](create-taxonomy-term.md) |
| `delete_file` | Destructive | Delete an uploaded file scoped to a flow step. | [Delete File](delete-file.md) |
| `merge_taxonomy_terms` | Destructive | Reassign posts from a source term to a target term and delete the source term. | [Merge Taxonomy Terms](merge-taxonomy-terms.md) |
| `update_taxonomy_term` | Content mutation | Update taxonomy term fields and meta. | [Update Taxonomy Term](update-taxonomy-term.md) |

### Chat-Only Destructive Workflow Tools

| Tool | Mutation risk | Purpose | Docs |
| --- | --- | --- | --- |
| `delete_flow` | Destructive | Delete a flow. | [Delete Flow](delete-flow.md) |
| `delete_pipeline` | Destructive | Delete a pipeline and its associated flows. | [Delete Pipeline](delete-pipeline.md) |
| `delete_pipeline_step` | Destructive | Remove a pipeline step and cascade to flows. | [Delete Pipeline Step](delete-pipeline-step.md) |
| `manage_jobs` | Destructive | List/summarize jobs and delete, fail, retry, or recover jobs. | [Manage Jobs](manage-jobs.md) |
| `manage_logs` | Destructive | Clear logs or read log metadata. | [Manage Logs](manage-logs.md) |

### Chat-Only External Execution Tools

| Tool | Mutation risk | Purpose | Docs |
| --- | --- | --- | --- |
| `execute_workflow` | Content mutation | Execute an ephemeral workflow through the Execute API. | [Execute Workflow](execute-workflow.md) |
| `send_ping` | Low mutation | POST a prompt/context payload to one or more webhook URLs. | [Send Ping](send-ping.md) |

### Chat-Only Duplicate Prevention Tool

| Tool | Mutation risk | Purpose | Docs |
| --- | --- | --- | --- |
| `queue_validator` | Read-only | Check if a topic exists in published content or a flow queue. | [Queue Validator](queue-validator.md) |

## Handler-Specific Tools

Handler tools are registered by handlers into the unified `datamachine_tools` registry as deferred `_handler_callable` entries. They become available at runtime when an adjacent step uses the matching handler or step type.

Examples include:

- `wordpress_publish`: create WordPress posts; accepts `content_format` (`markdown`, `html`, or `blocks`).
- `wordpress_update`: update existing WordPress content.
- `twitter_publish`, `bluesky_publish`, `facebook_publish`, `threads_publish`: publish social posts.
- `google_sheets_publish`: append rows to Google Sheets.

## Extension-Owned Tools

GitHub and workspace tools are not registered by Data Machine core. They are provided by the `data-machine-code` extension when that extension is installed.

Common extension tools include:

- `create_github_issue`, `list_github_issues`, `get_github_issue`, `manage_github_issue`
- `list_github_pulls`, `list_github_repos`
- `workspace_path`, `workspace_list`, `workspace_show`, `workspace_ls`, `workspace_read`

## Unregistered Core Class

`inc/Api/Chat/Tools/GetProblemFlows.php` defines `get_problem_flows`, but `ToolServiceProvider.php` does not instantiate it. It is not part of the registered core inventory until the provider registers it or another bootstrap path instantiates the class.

## Tool Architecture

Tool classes extend `BaseTool` and register themselves with `registerTool()`. Registration declares:

- tool slug
- definition callback
- mode list
- required ability or access level

`ToolPolicyResolver` resolves the active tool set for a context. `ToolExecutor` handles execution. Handler-specific tools are resolved from `_handler_callable` registry entries against adjacent step runtime configuration.

## Content Authoring Formats

For normal prose, AI tools should write markdown and omit `content_format` unless a workflow explicitly asks for HTML or serialized blocks. Raw ability/API callers can still pass `content_format`; omitted raw `datamachine/upsert-post` calls keep the legacy block-markup default.

## Directory Reference

| Directory | Contents |
| --- | --- |
| `inc/Engine/AI/Tools/Global/` | Chat/pipeline global tools such as search, analytics, memory, image generation, and audits. |
| `inc/Api/Chat/Tools/` | Chat-only admin/workflow/content tools. |
| `inc/Engine/AI/Tools/` | Tool registration, policy resolution, execution, and shared base classes. |
| `inc/Abilities/Analytics/` | Analytics ability implementations used by analytics tools. |
