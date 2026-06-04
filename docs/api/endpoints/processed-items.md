# Processed Items Endpoint

**Implementation**: `inc/Api/ProcessedItems.php`

**Base URL**: `/wp-json/datamachine/v1/processed-items`

## Overview

Processed items are the deduplication records Data Machine uses to avoid re-processing the same source item across flow executions. Fetch handlers and engine code write these records during execution; the REST surface currently exposes an operator cleanup endpoint for resetting deduplication by pipeline or flow.

For richer read/check/stale-history operations, use the `datamachine/*` abilities in `ProcessedItemsAbilities` or the WP-CLI `wp datamachine processed-items` command.

## Corpus Refresh Conventions

Corpus indexing workloads should use processed items as revision-level dedupe records. The generic helper `DataMachine\Core\Corpus\CorpusRefreshConventions` defines shared `source_type` values and stable `item_identifier` builders for document, chunk, and embedding refreshes. These conventions are generic Data Machine contracts and do not depend on Intelligence classes.

Recommended `source_type` values:

| Source type | Use |
|-------------|-----|
| `corpus_document_revision` | A source document revision has been read and normalized. |
| `corpus_chunk_revision` | A chunk revision has been generated from document content. |
| `corpus_embedding_revision` | An embedding has been generated for a provider/model/chunk-hash tuple. |

Recommended processed-item keys:

| Helper | Key parts | Use |
|--------|-----------|-----|
| `CorpusRefreshConventions::document_key()` | corpus ID + document ID + source hash | Skip unchanged source documents across scheduled refreshes. |
| `CorpusRefreshConventions::chunk_key()` | corpus ID + chunk ID + chunk hash | Skip unchanged chunks after document refresh. |
| `CorpusRefreshConventions::embedding_key()` | corpus ID + provider + embedding model + chunk hash | Skip embeddings that already exist for the same model and content. |

Example:

```php
use DataMachine\Core\Corpus\CorpusRefreshConventions;

$processed_items->has_item_been_processed(
	$flow_step_id,
	CorpusRefreshConventions::SOURCE_DOCUMENT,
	CorpusRefreshConventions::document_key( $corpus_id, $document_id, $source_hash )
);
```

Batch jobs should store corpus refresh progress with the generic run metric count names `selected`, `skipped`, `processed`, `failed`, and `retried`. `CorpusRefreshConventions::batch_metadata()` returns a normalized metadata shape with `workload: corpus_refresh` and these counters. `RunMetrics::fromJob()` also reads the same counters from `engine_data.batch_results`, so batch parent jobs can report corpus refresh progress without a corpus-specific metrics table.

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
