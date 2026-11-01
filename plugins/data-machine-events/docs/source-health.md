# Source health

`wp data-machine-events check source-health` is read-only; it does not fetch sources or change flows/events.

Examples:

```sh
wp data-machine-events check source-health --pipeline=12
wp data-machine-events check source-health --status=blocked,broken_url,extraction_incomplete --format=json
wp data-machine-events check source-health --format=csv > sources.csv
```

JSON contains `summary` (counts before status filtering) and `flows`. CSV writes only flow rows to stdout and summary counts to stderr. Rows prioritize failures and incomplete extraction over quiet/healthy calendars.

The report selects enabled `event_import` steps. Publication history comes from published event posts with `_datamachine_post_flow_id`; upcoming counts come from `datamachine_event_dates`. Recent run totals count retained **root** jobs, not children. Retention therefore bounds the reporting window; these totals are not lifetime totals. The latest root's `fetch_stages` and `run_metrics.counts` distinguish empty sources, incomplete extraction and already-imported calendars. Missing telemetry is unknown, not a zero-materialized source.

- `empty_source`: API emptiness is coverage information; scraper emptiness sets `needs_attention`.
- `extraction_incomplete`: materialized items with rejection/deferral and no successful publication. Rejection/diagnostic reasons are included when retained.
- `filtered_non_music`: all retained children explicitly rejected for non-music content. Generic rejection is not assumed intentional.
- `saturated`: all materialized items were already processed.
- `blocked`, `broken_url`, `failing`: latest root failed; latest retained root exception class is included. A historical exception does not override a recovered latest run.
- `healthy`, `quiet`, `dark`, `never_produced`: fallback for flows without conclusive recent telemetry; quiet requires upcoming events, dark requires historical publication and no upcoming events or recent failures.

The implementation uses five set queries regardless of flow count, hydrating engine data only for latest roots and their immediate children. No per-flow WordPress queries or network calls occur. Production MySQL performance and WordPress integration still need validation on the deployed dataset.

Portable tests:

```sh
phpunit -c phpunit.contracts.xml --filter SourceHealth
```
