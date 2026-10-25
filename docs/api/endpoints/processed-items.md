# Processed Items Abilities

**Implementation**: `inc/Abilities/ProcessedItemsAbilities.php`

The `datamachine/v1/processed-items` REST route was retired in #3456. Processed-items deduplication tracking is cleared through the REST-visible Data Machine ability, executed through WordPress core's ability runner:

```
POST /wp-json/wp-abilities/v1/abilities/datamachine/clear-processed-items/run
Content-Type: application/json

{ "input": { "clear_type": "pipeline", "target_id": 12 } }
```

The admin Jobs page calls this through the shared `executeAbility()` client (`inc/Core/Admin/shared/utils/api.js`).

## Revision-Key Conventions

Refresh workloads should use processed items as revision-level dedupe records when the same logical item may be revisited across scheduled runs. Data Machine owns the durable processed-item table and runtime counters; the workload owner owns domain-specific source types and identifier parts.

| Field | Convention |
| --- | --- |
| `flow_step_id` | The flow step that owns the refresh boundary. |
| `source_type` | A stable workload-defined namespace, such as a source or artifact kind. |
| `item_identifier` | A stable string assembled from the workload scope, logical item ID, and a content/revision hash. |

For example, `<scope>|document=<id>|hash=<source-hash>` skips unchanged source documents across scheduled refreshes. Include provider and model identifiers when deduplicating model-specific artifacts.

Batch jobs should store refresh progress with the generic run metric count names `selected`, `skipped`, `processed`, `failed`, and `retried`. `RunMetrics::fromJob()` reads those counters from `engine_data.batch_results`, so batch parents can report progress without a workload-specific metrics table.

## Authentication

The ability's permission callback enforces the Data Machine management surface (`PermissionHelper::can_manage()`). The core ability runner also requires `show_in_rest` and a valid REST nonce for cookie-authenticated callers.

## Processed Items Abilities

| Ability slug | Purpose |
| --- | --- |
| `datamachine/clear-processed-items` | Clear tracking rows by `clear_type` (`pipeline` or `flow`) and `target_id`. Returns `deleted_count`. |
