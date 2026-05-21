# Processed Items Endpoint

**Implementation**: `inc/Api/ProcessedItems.php`

**Base URL**: `/wp-json/datamachine/v1/processed-items`

## Overview

Processed items are the deduplication records Data Machine uses to avoid re-processing the same source item across flow executions. Fetch handlers and engine code write these records during execution; the REST surface currently exposes an operator cleanup endpoint for resetting deduplication by pipeline or flow.

For richer read/check/stale-history operations, use the `datamachine/*` abilities in `ProcessedItemsAbilities` or the WP-CLI `wp datamachine processed-items` command.

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
