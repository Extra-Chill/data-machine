# Runtime-Gated Abilities Audit

Issue: https://github.com/Extra-Chill/data-machine/issues/2303

Data Machine skips the full runtime on normal frontend page views via `datamachine_should_load_full_runtime()`. Ability constructors inside `datamachine_run_datamachine_plugin()` therefore do not run on those lite requests unless the ability is explicitly hoisted to file-load registration.

This audit records which gated abilities have proven frontend-lite consumers. Only proven reachable abilities should be hoisted; the rest stay gated until a concrete frontend-lite call site exists.

## Disposition Table

| Area | Abilities | Frontend-lite consumer evidence | Disposition |
|---|---|---|---|
| Auth | `datamachine/list-auth-providers`, `datamachine/get-auth-status`, `datamachine/connect-auth`, `datamachine/disconnect-auth`, `datamachine/set-auth-token`, `datamachine/revoke-auth-token`, `datamachine/refresh-auth-token` | Admin/API auth management only. No frontend-lite `wp_get_ability()` consumer found in the Data Machine repo or local Extra Chill extension checkouts during #2303. | Keep gated. |
| AI diagnostics | `datamachine/inspect-ai-request` | Diagnostic/admin surface. No frontend-lite consumer found. | Keep gated. |
| Files | Agent, flow, and scaffold file abilities | Agent/runtime file management. No frontend-lite consumer found. | Keep gated. |
| Flow | `datamachine/get-flows`, `datamachine/create-flow`, `datamachine/update-flow`, `datamachine/delete-flow`, `datamachine/duplicate-flow`, `datamachine/pause-flow`, `datamachine/resume-flow`, queue/config/webhook flow abilities | Local Data Machine consumers are REST/CLI/tests. Issue #2303 noted Extra Chill Events references to `create-flow`, but those are REST-shaped ability classes rather than page-render helpers. | Keep gated. |
| Flow steps | `datamachine/get-flow-steps`, `datamachine/update-flow-step`, `datamachine/configure-flow-steps`, `datamachine/validate-flow-steps-config` | Flow-builder/API surface. No frontend-lite consumer found. | Keep gated. |
| Jobs | `datamachine/get-jobs`, `datamachine/delete-jobs`, `datamachine/execute-workflow`, `datamachine/flow-health`, `datamachine/problem-flows`, `datamachine/recover-stuck-jobs`, `datamachine/jobs-summary`, `datamachine/run-metrics`, `datamachine/fail-job`, `datamachine/retry-job` | Local Data Machine consumers are CLI, REST, cron, admin, or tests. Issue #2303 noted Extra Chill Events references to `execute-workflow`, but those are REST-shaped ability classes rather than page-render helpers. | Keep gated. |
| Logs | `datamachine/write-to-log`, `datamachine/clear-logs`, `datamachine/read-logs`, `datamachine/get-log-metadata`, `datamachine/read-debug-log` | Runtime diagnostics/admin surface. No frontend-lite consumer found. | Keep gated. |
| Post queries | `datamachine/query-posts`, `datamachine/list-posts` | Admin/chat/runtime query surface. No frontend-lite consumer found. | Keep gated. |
| Pipeline | `datamachine/get-pipelines`, `datamachine/create-pipeline`, `datamachine/update-pipeline`, `datamachine/delete-pipeline`, `datamachine/duplicate-pipeline`, import/export pipeline abilities | Local Data Machine consumers are REST/CLI/tests. Issue #2303 noted Extra Chill Events references to `create-pipeline`, but those are REST-shaped ability classes rather than page-render helpers. | Keep gated. |
| Pipeline steps | `datamachine/get-pipeline-steps`, `datamachine/add-pipeline-step`, `datamachine/update-pipeline-step`, `datamachine/delete-pipeline-step`, `datamachine/reorder-pipeline-steps` | Flow-builder/API surface. No frontend-lite consumer found. | Keep gated. |
| Duplicate checks | `datamachine/check-duplicate`, duplicate title matching | Local Extra Chill Events use is inside a Data Machine upsert step handler, which runs under the full runtime. | Keep gated. |
| Processed items | Processed-item clear/check/history/stale/never-processed helpers | Pipeline/runtime state surface. No frontend-lite consumer found. | Keep gated. |
| Tracked items | `datamachine/upsert-tracked-item`, `datamachine/get-tracked-item`, `datamachine/list-tracked-items`, `datamachine/tracked-items-summary` | Pipeline/system-task coverage surface. No frontend-lite consumer found. | Keep gated. |
| Settings | Settings and handler-default abilities | Admin/CLI/settings surface. No frontend-lite consumer found. | Keep gated. |
| Handlers and step types | Handler discovery/test/defaults and step-type listing/validation | Admin/runtime discovery surface. No frontend-lite consumer found. | Keep gated. |
| Local search | `datamachine/local-search` | Chat/runtime search surface. No frontend-lite consumer found. | Keep gated. |
| Source inventory | `datamachine/source-inventory`, `datamachine/source-aggregate` | System-task/source coverage surface. No frontend-lite consumer found. | Keep gated. |
| System | Session title, health, and system-task abilities | Admin/chat/system-task surface. No frontend-lite consumer found. | Keep gated. |
| Media | `datamachine/generate-alt-text`, `datamachine/diagnose-alt-text`, image generation, media upload/validation/video metadata | Admin/pipeline media surface. No frontend-lite consumer found. | Keep gated. |
| Image templates | `datamachine/render-image-template`, `datamachine/list-image-templates` | Proven frontend-lite consumers: Extra Chill OG-card and event-roundup generation. | Already hoisted by #2291. |
| SEO | IndexNow and meta-description abilities | Pipeline/admin SEO surface. No frontend-lite consumer found. | Keep gated. |
| Agent calls | `datamachine/agent-call`, `datamachine/agent-remote-call` | Agent/runtime surface. No frontend-lite consumer found. | Keep gated. |
| Taxonomy | Resolve, merge meta, get/create/update/delete term abilities | Admin/pipeline taxonomy surface. No frontend-lite consumer found. | Keep gated. |
| Agent identity and tokens | Agent CRUD/import/export/access/token abilities | Admin/agent management surface. No frontend-lite consumer found. | Keep gated. |
| Agent memory and daily memory | Agent memory and daily memory read/write/list/search/delete abilities | Chat/agent runtime surface. No frontend-lite consumer found. | Keep gated. |
| Chat | Chat session and message abilities | REST/frontend-chat requests hit `/wp-json/` and load the full runtime. No lite page-render consumer found. | Keep gated. |
| Internal linking | Diagnostics, audit, backlinks, broken links, opportunities | Admin/runtime content analysis surface. No frontend-lite consumer found. | Keep gated. |
| Content/block editing | `datamachine/get-post-blocks`, `datamachine/edit-post-blocks`, `datamachine/replace-post-blocks`, `datamachine/insert-content`, `datamachine/upsert-post` | Local Extra Chill Events use of `upsert-post` is inside a Data Machine upsert step handler, which runs under the full runtime. | Keep gated. |
| Pending actions | Pending-action inspection/sign/resolve abilities | Agent approval/runtime surface. No frontend-lite consumer found. | Keep gated. |
| Fetch | File, email, RSS, WordPress API/media/post query fetch abilities | Pipeline/runtime fetch surface. No frontend-lite consumer found. | Keep gated. |
| Email mailbox | Email reply/delete/move/flag/batch/unsubscribe/test abilities | Admin/CLI/mailbox surface. No frontend-lite consumer found. | Keep gated. |
| Publish | `datamachine/publish-wordpress` | Pipeline/runtime publish surface. No frontend-lite consumer found. | Keep gated. |
| Publish email | `datamachine/send-email`, `datamachine/send-email-queued` | Proven frontend-lite consumers: issue #2303 documents `extrachill-multisite` generic `ec_send_email()` and `ec_send_email_queued()` helpers as callable from normal frontend hooks/form handlers. Data Machine core also uses these from REST/CLI/worker contexts. | Hoisted in #2303. |
| Update | `datamachine/update-wordpress` | Pipeline/runtime update surface. No frontend-lite consumer found. | Keep gated. |
| Handler test | `datamachine/test-handler` | Admin/test surface. No frontend-lite consumer found. | Keep gated. |

## Rule For Future Hoists

Hoist an ability only when there is a concrete consumer that can call `wp_get_ability()` during a lite frontend page view or another request shape that does not satisfy `datamachine_should_load_full_runtime()`. REST, Ajax, admin, cron, CLI, pipeline, and Action Scheduler consumers are already covered by the full runtime gate.
