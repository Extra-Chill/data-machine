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

## Daily refresh

The `impact_revenue_refresh` Data Machine system task keeps the ledger current without manual runs. Each daily tick calls `datamachine/impact-reports` with `store=true` for the trailing 45 UTC days (`today-44d` through `today`): one Impact chunk and one request. Because ingest replaces the window, the refresh is idempotent. It also corrects PENDING actions that later become APPROVED or REVERSED.

- The task registers through the `datamachine_tasks` filter. Its schedule is a `datamachine_recurring_schedules` binding on the built-in `daily` interval. Use `network_only` so on multisite only the network main site owns the recurrence.
- The schedule is enabled only when `datamachine_impact_config` has both `account_sid` and `auth_token`. Without credentials, nothing is scheduled, and a manual run completes as a skipped no-op.
- The task is agent-less (`requiresAgentContext() === false`).
- If the Impact read or the ledger ingest fails, the job fails with the error code and the redacted message. The auth token is never logged.

```bash
wp datamachine system run impact_revenue_refresh
wp datamachine jobs list --task=impact_revenue_refresh
```
