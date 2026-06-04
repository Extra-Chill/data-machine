# Processed Items Endpoint

**Implementation**: `inc/Api/ProcessedItems.php`

**Base URL**: `/wp-json/datamachine/v1/processed-items`

## Overview

Processed items are the deduplication records Data Machine uses to avoid re-processing the same source item across flow executions. Fetch handlers and engine code write these records during execution; the REST surface currently exposes an operator cleanup endpoint for resetting deduplication by pipeline or flow.

For richer read/check/stale-history operations, use the `datamachine/*` abilities in `ProcessedItemsAbilities` or the WP-CLI `wp datamachine processed-items` command.

## Revision-Key Conventions

Refresh workloads should use processed items as revision-level dedupe records when the same logical item may be revisited across scheduled runs. Data Machine owns the durable processed-item table and runtime counters; the workload owner owns domain-specific source types and identifier parts.

Use these generic rules for revision keys:

| Field | Convention |
|-------|------------|
| `flow_step_id` | The flow step that owns the refresh boundary. |
| `source_type` | A stable workload-defined namespace, such as a source or artifact kind. |
| `item_identifier` | A stable string assembled from the workload scope, logical item ID, and a content/revision hash. |

Useful identifier shapes for source/index refresh workloads include:

| Shape | Use |
|-------|-----|
| `<scope>|document=<id>|hash=<source-hash>` | Skip unchanged source documents across scheduled refreshes. |
| `<scope>|chunk=<id>|hash=<chunk-hash>` | Skip unchanged derived chunks after source refresh. |
| `<scope>|provider=<provider>|model=<model>|hash=<content-hash>` | Skip unchanged provider/model artifacts for the same content. |

Batch jobs should store refresh progress with the generic run metric count names `selected`, `skipped`, `processed`, `failed`, and `retried`. `RunMetrics::fromJob()` reads those counters from `engine_data.batch_results`, so batch parent jobs can report refresh progress without a workload-specific metrics table.

## Authentication

The REST endpoint requires `PermissionHelper::can( 'manage_flows' )`. Administrators pass through the mapped Data Machine capability fallback.

## Endpoint

### DELETE `/processed-items`

Clear processed-item records for a flow or pipeline.

**Permission**: `manage_flows`

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `clear_type` | string | Yes | Either `pipeline` or `flow`. |
| `target_id` | integer | Yes | Pipeline ID or flow ID matching `clear_type`. |

**Example request**:

```bash
curl -X DELETE https://example.com/wp-json/datamachine/v1/processed-items \
  -u username:application_password \
  -H 'Content-Type: application/json' \
  -d '{"clear_type":"flow","target_id":42}'
```

**Success response**:

```json
{
  "success": true,
  "message": "Cleared processed items for flow 42",
  "cleared_count": 17,
  "clear_type": "flow",
  "target_id": 42
}
```

## Ability Surface

`ProcessedItemsAbilities` registers the current ability surface for programmatic callers:

| Ability | Purpose |
|---------|---------|
| `datamachine/clear-processed-items` | Clear dedupe records by flow or pipeline. |
| `datamachine/check-processed-item` | Check whether an item has been processed. |
| `datamachine/has-processed-history` | Check whether a flow has any processed history. |
| `datamachine/processed-items-get-processed-at` | Return the last processed timestamp for an item. |
| `datamachine/processed-items-find-stale` | Filter candidate items to those processed before a threshold. |
| `datamachine/processed-items-find-never-processed` | Filter candidate items to those with no processed record. |

## Related Documentation

- [API Overview](../index.md)
- [Abilities API](../../core-system/abilities-api.md)
- [WP-CLI Commands](../../core-system/wp-cli.md)
