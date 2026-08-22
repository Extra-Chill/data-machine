# Import/Export System

Data Machine provides comprehensive import/export functionality for pipeline configurations, enabling backup, migration, and sharing of workflow templates across installations.

## Overview

The import/export system handles pipeline structures including steps, configurations, and associated flow data. Operations use the REST-visible `datamachine/import-pipelines` and `datamachine/export-pipelines` abilities. Data Machine does not register pipeline import/export WP-CLI commands.

## Export Functionality

### Export Process

Pipeline export generates a CSV file containing complete pipeline and flow configuration data:

```csv
format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings
1.0,flow,1,"News Pipeline",,,,10,"Morning News","{""scheduling_config"":{""interval"":""hourly"",""enabled"":true},""portable_slug"":""morning-news""}"
1.0,pipeline_step,1,"News Pipeline",0,"fetch","{""step_type"":""fetch""}",,,
```

### Export Structure

The canonical 1.0 CSV export includes three typed rows:

1. **`pipeline_step` rows**: Define pipeline steps and their configurations.
2. **`flow` rows**: Represent every flow independently of its step settings. The `settings` object contains canonical `scheduling_config` desired state and a stable, per-pipeline `portable_slug` used for idempotent identity even when flows share a name.
3. **`flow_step` rows**: Detail how a flow implements a pipeline step. Portable settings include handler selection/configuration, handler-free settings, tool allow/deny lists, queues and queue mode, completion assertions, tool runtime rules, and enabled/disabled state.

The `format_version` value is `1.0`. Import rejects an unversioned, differently versioned, incorrectly typed, or malformed metadata row rather than guessing and silently dropping behavior. The lossy unversioned header used during pre-1.0 development is intentionally unsupported under the 1.0 baseline.

Export through `POST /wp-json/wp-abilities/v1/abilities/datamachine/export-pipelines/run`. The curated `GET /wp-json/datamachine/v1/pipelines?format=csv&ids=1,2` endpoint is also available when a raw CSV download response is required.

## Import Functionality

### Import Process

Pipeline import processes CSV data to recreate pipeline structures and flow configurations:

1. Validates the complete CSV before making writes.
2. Creates or reuses pipelines and position-matched steps.
3. Creates or reuses every named flow and applies its schedule through the canonical create/update flow abilities.
4. Restores portable flow-step behavior against freshly generated step IDs.

Import through `POST /wp-json/wp-abilities/v1/abilities/datamachine/import-pipelines/run`. Successful ability payloads include the imported pipeline IDs. The curated `datamachine/v1/pipelines` controller does not provide CSV import.

### Import Behavior

- **Pipeline Creation**: Creates pipelines without a synthetic fallback flow; exported flow metadata is authoritative.
- **Step Synchronization**: Reuses position- and type-matched steps on repeated imports instead of duplicating them.
- **Flow Preservation**: Preserves distinct named and handler-free flows in export order.
- **Schedule Reconciliation**: Restores manual, recurring, cron, one-time, paused, webhook, rate-limit, and run-artifact desired state while allowing the scheduling layer to regenerate Action Scheduler metadata.
- **Error Handling**: Rejects malformed canonical rows and logs the reason.
- **Idempotency**: Reimporting the same CSV reuses pipeline steps and flows and reconciles schedules rather than adding duplicates.

## Security & Permissions

All import/export operations require `manage_options` capability, ensuring only administrators can perform these actions.

## Use Cases

### Backup & Restore
Regularly export pipeline configurations for backup purposes, enabling quick restoration after updates or migrations.

### Migration
Export pipelines from development environments and import into production systems.

### Template Sharing
Share pipeline templates between different WordPress installations or team members.

### Version Control
Store pipeline configurations in external version control systems for change tracking.

## Technical Details

- **CSV Format**: Standard CSV with proper escaping for complex JSON configurations
- **Execution Ordering**: Pipeline steps are sorted by `execution_order` during export
- **Flow Isolation**: Each flow's handler configurations are preserved independently
- **Database Integration**: Import/export is implemented by `DataMachine\Engine\Actions\ImportExport` and surfaced through `inc/Abilities/Pipeline/ImportExportAbility.php`; the WordPress Abilities REST runner and curated CSV export route use that same action layer.
- **Portable Boundary**: Database IDs identify relationships inside one CSV but are remapped on import. Installation-scoped `user_id` and `agent_id` ownership is not portable and is resolved by the canonical create-flow ability on the target installation.
- **Scheduler Metadata**: `interval_seconds`, `first_run`, `scheduled_time`, `action_id`, reconciliation records, and last-suppressed-run observations are runtime state, not desired behavior. Export omits them explicitly; create/update flow abilities regenerate current scheduler metadata.
- **Secret Scope**: The canonical action layer currently carries handler and webhook configuration verbatim. Secret projection is a separate contract and is not changed by the lossless flow format.
