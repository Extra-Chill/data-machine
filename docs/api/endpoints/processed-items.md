# Processed Items Abilities

**Implementation**: `inc/Abilities/ProcessedItemsAbilities.php`

The `datamachine/v1/processed-items` REST route was retired in #3456. Processed-items deduplication tracking is cleared through the REST-visible Data Machine ability, executed through WordPress core's ability runner:

```
POST /wp-json/wp-abilities/v1/abilities/datamachine/clear-processed-items/run
Content-Type: application/json

{ "input": { "clear_type": "pipeline", "target_id": 12 } }
```

The admin Jobs page calls this through the shared `executeAbility()` client (`inc/Core/Admin/shared/utils/api.js`).

## Authentication

The ability's permission callback enforces the Data Machine management surface (`PermissionHelper::can_manage()`). The core ability runner also requires `show_in_rest` and a valid REST nonce for cookie-authenticated callers.

## Processed Items Abilities

| Ability slug | Purpose |
| --- | --- |
| `datamachine/clear-processed-items` | Clear tracking rows by `clear_type` (`pipeline` or `flow`) and `target_id`. Returns `deleted_count`. |
