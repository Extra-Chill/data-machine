# Merged event source identity repair

Merges now link every primary/linked loser identity to the winner and move the
loser's durable post identity reservations before trashing it. Failed trash
restores the newly added links and moved reservations. Existing winner links
and its primary source provenance are preserved.

For historical trashed losers, execute the write-authorized ability
`data-machine-events/backfill-merged-identities`:

```json
{"limit":50,"after_id":0}
```

This previews up to 50 merged losers without writing. To apply the batch, repeat
with `"dry_run":false`. Use the returned `next_after_id` as `after_id` for the
next batch, until `results` is empty. The maximum batch size is 100. Repeating a
batch is safe. Invalid/deleted/unpublished winners are skipped; transfer errors
are reported per loser. Merge chains resolve to their published final winner.
Run this repair before permanently deleting historical merged losers: identities
already deleted with no surviving metadata cannot be reconstructed by this scan.
