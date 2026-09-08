# Logs Abilities

**Implementation**: `inc/Abilities/LogAbilities.php`

The `datamachine/v1/logs` REST routes were retired in #3456. Logs are read and cleared through the REST-visible Data Machine abilities, executed through WordPress core's ability runner:

```
POST /wp-json/wp-abilities/v1/abilities/datamachine/<slug>/run
Content-Type: application/json

{ "input": { ... } }
```

The admin Logs page calls these through the shared `executeAbility()` client (`inc/Core/Admin/shared/utils/api.js`).

## Authentication

The log-read and log-clear abilities allow the Data Machine `view_logs` permission, matching the retired wrapper routes so log-page viewers (for example the editor role) keep access. The core ability runner also requires `show_in_rest` and a valid REST nonce for cookie-authenticated callers.

## Response Envelope

The ability runner returns the ability's output directly. Errors return standard REST error objects (`code`, `message`, `data.status`).

## Log Abilities

| Ability slug | Purpose |
| --- | --- |
| `datamachine/read-logs` | Read log entries with filters (`agent_id`, `level`, `since`, `before`, `job_id`, `flow_id`, `pipeline_id`, `search`) and pagination (`per_page`, `page`). Returns `items`, `total`, `page`, `pages`. |
| `datamachine/get-log-metadata` | Log counts, time range (`oldest`, `newest`), and `level_counts`; optionally scoped by `agent_id`. |
| `datamachine/clear-logs` | Clear log entries, all or by `agent_id`. Returns `deleted` row count. |
| `datamachine/read-debug-log` | Read `wp-content/debug.log` entries (level/time/search filters). |
