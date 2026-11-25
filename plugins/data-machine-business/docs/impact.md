# Impact affiliate reports

`datamachine/impact-reports` reads Impact (impact.com) Media Partner **Actions** and aggregates them into day-grain commission rows, one per UTC event day, campaign, and currency. Ticketmaster's affiliate program runs on Impact.

## Configuration

Credentials live server-side in the `datamachine_impact_config` option: `account_sid` and `auth_token`, from Impact → Settings → API. Requests use HTTP Basic auth (`SID:token`) against `GET https://api.impact.com/Mediapartners/{SID}/Actions`.

## Reading

```bash
wp datamachine analytics impact fetch --start=2026-10-01 --end=2026-10-31
wp datamachine analytics impact fetch --start=2026-10-01 --end=2026-10-31 --format=json
```

- The API rejects `ActionDateStart`/`ActionDateEnd` spans longer than 45 days, so the reader splits the window into contiguous chunks of 45 days or less and follows `@nextpageuri` within each chunk. A request covers at most 400 days.
- Each row's `amount` is the summed `Payout` of APPROVED and PENDING actions. REVERSED actions earn nothing. They are only counted in `metrics.states`, along with per-state action counts and payouts. `metrics.sale_amount` is the earning sales volume.
- Actions are deduplicated by `Id`. Actions whose UTC event day falls outside the window are counted in `counts.outside_window`; they are not stored.

## Storing in the revenue ledger

```bash
wp datamachine analytics impact fetch --start=2025-11-25 --end=2026-11-24 --store --dry-run
wp datamachine analytics impact fetch --start=2025-11-25 --end=2026-11-24 --store
```

`--store` ingests through `datamachine/revenue-ingest` with source `impact`, kind `affiliate_commission`, the account SID as the account, and the campaign name as `object_ref`. Ingest replaces that window, so re-running a window updates pending/approved changes without duplicating rows. Read the stored data back with `wp datamachine analytics revenue` (`datamachine/revenue-query`).
