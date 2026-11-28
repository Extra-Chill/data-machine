# Venue Interval Overlap

`data-machine-events/query-venue-interval-overlaps` is the bounded public read
primitive for canonical event ranges at one venue. Its PHP equivalent is
`data_machine_events_query_venue_interval_overlaps()`.

## Semantics

- The requested interval is half-open: `[start, end)`. Exact adjacency does not overlap.
- `start` and `end` are strict RFC3339 instants with seconds, valid calendar dates, and an explicit offset. They are normalized to the canonical venue's IANA timezone, or the site timezone when the venue has no valid timezone.
- The event-date index stores one venue-local wall-clock range per canonical event. Only closed ranges with `end > start` participate. An absent, zero-length, or inverted end is not silently assigned a duration.
- The local `DATETIME` index cannot distinguish the two occurrences of a repeated fall-back hour. Requests whose local projection touches that repeated range are rejected, including locally inverted projections. Exact adjacency before or after the repeated range remains valid.
- A returned indexed endpoint must map to exactly one venue-local instant. Ambiguous fall-back values, nonexistent spring-forward values, malformed indexed values, and normalized calendar values fail the whole read rather than returning a misleading offset.
- A multi-day event's indexed start/end is one continuous interval.
- `occurrenceDates` controls calendar display grouping. It does not create separately indexed intervals, so discrete recurring dates are not inferred by this query.
- The public Ability exposes published events only. Draft, private, pending, future, trashed, and otherwise non-published rows are not public overlap facts.
- Venue identity is the current site's exact `venue` term ID. The query uses the active multisite site's tables and does not switch sites.
- Unknown properties and coercible scalar IDs are rejected. IDs, `page`, and `per_page` must be positive PHP/JSON integers; arrays must use unique schema-valid items. `page` is capped at 10,000, `per_page` at 100, and exclusions at 100 IDs.
- Results are ordered by indexed start then event ID. `has_more` is computed from the same SQL snapshot as the returned page.
- Date-index repair, source-owned date updates, venue reassignment, and post-status transitions affect the next query immediately because the primitive reads the derived date index, current venue relationship, and synchronized status directly.

The response contains only the venue ID and timezone, normalized requested
interval, pagination facts, and canonical event ID/start/end/status facts. It
does not decide whether an overlap is allowed or meaningful to a consumer.
