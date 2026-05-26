# Calendar Data-Only REST Schema (phase 1 of #298)

Status: **phase 1 of the refactor in [Extra-Chill/data-machine-events#298](https://github.com/Extra-Chill/data-machine-events/issues/298). Not the canonical response yet.**

The canonical calendar REST response remains the legacy HTML-string envelope
returned by `/wp-json/datamachine/v1/events/calendar`. This document describes
the **opt-in** data-only envelope introduced in phase 1: a server-rendered
JSON shape with **zero HTML strings**, intended to become the canonical
contract once the consumers in `inc/Blocks/Calendar/src/` are ported in
subsequent phases.

## Background

See the umbrella refactor issue ([#298](https://github.com/Extra-Chill/data-machine-events/issues/298)) for
the full architectural rationale. Short version: the current REST contract
ships server-rendered HTML strings (`data.html`, `data.pagination.html`,
`data.counter`, `data.navigation.html`) that the frontend bundle blasts into
the DOM via `innerHTML`. That violates the site-wide headless-React rule,
defeats client-side state, and is the root cause of the family of bugs filed
separately ([#296](https://github.com/Extra-Chill/data-machine-events/issues/296), [#297](https://github.com/Extra-Chill/data-machine-events/issues/297)).

## Activation

Pass `format=data` as a query param. **Any other value (including the
absent param) returns the legacy HTML envelope unchanged.**

```bash
# Legacy HTML envelope (unchanged).
curl 'https://example.com/wp-json/datamachine/v1/events/calendar?paged=1'

# Data-only envelope (phase 1).
curl 'https://example.com/wp-json/datamachine/v1/events/calendar?paged=1&format=data'
```

The two responses are cached independently — `format` is part of the
full-response cache key (`CalendarCache::generate_full_response_key()`).

## Envelope

```jsonc
{
  "success": true,
  "schema": {
    "name": "calendar-data",
    "version": 1,
    "phase": 1,
    "issue": 298
  },
  "events":     [ /* CalendarEventItem[] — see below */ ],
  "grouping": {
    "ordered_dates": [ "2026-06-12", "2026-06-13", "..." ],
    "by_date": {
      "2026-06-12": [
        { "post_id": 12345, "display_context": { /* ... */ } }
      ]
    },
    "gaps": { "2026-06-15": 3 }
  },
  "pagination": {
    "current_page": 1,
    "total_pages":  42,
    "total_items":  834,
    "page_items":   18
  },
  "counter": {
    "showing_count":   18,
    "total_count":     834,
    "page_start_date": "2026-06-12",
    "page_end_date":   "2026-06-19"
  },
  "navigation": {
    "show_past":    false,
    "past_count":   2017,
    "future_count": 834,
    "has_past":     true,
    "has_future":   true
  }
}
```

### `schema`

Identifies the schema for forward compatibility. Clients should check
`schema.name === 'calendar-data'` and `schema.version === 1` before
reading the rest. Future phases bump `phase`; backward-incompatible
shape changes bump `version`.

### `events`

Array of structured event objects, **deduplicated on `id`**. A multi-day
event appears once here, regardless of how many dates it spans on this
page — its multi-day expansion is represented in `grouping.by_date`.

```jsonc
{
  "id":        12345,
  "title":     "Some show",
  "permalink": "https://example.com/events/some-show/",
  "date": {
    "start_date":     "2026-06-12",
    "start_time":     "20:30:00",
    "end_date":       "2026-06-12",
    "end_time":       "23:00:00",
    "venue_timezone": "America/New_York"
  },
  "venue": {
    "term_id": 678,
    "name":    "The Royal American",
    "slug":    "the-royal-american",
    "address": "970 Morrison Dr, Charleston, SC 29403"
  },
  "organizer": {
    "name": "Local Promoter",
    "url":  "https://example.com",
    "type": "promoter"
  },
  "ticket":    { "url": "https://etix.com/..." },
  "performer": { "name": "Headliner Name" },
  "address":   "970 Morrison Dr, Charleston, SC 29403",
  "taxonomies": {
    "artist": [
      { "term_id": 111, "name": "Headliner", "slug": "headliner", "link": "https://..." }
    ],
    "genre":  [
      { "term_id": 222, "name": "Indie",     "slug": "indie",     "link": "https://..." }
    ]
  }
}
```

Notes:

- `venue` and `organizer` are `null` when no term is attached. The legacy
  HTML templates rendered an empty slot in that case; clients should do
  the same.
- `taxonomies` honors the `data_machine_events_excluded_taxonomies`
  filter (context: `'badge'`), so the data envelope matches what the
  legacy badge HTML would have surfaced.
- The `address` field is denormalized from `venue.address` for clients
  that don't want to walk into the venue subobject. It is identical when
  a venue is present.

### `grouping`

Captures the date-bucket structure that the server produced via
`DateGrouper::group_events_by_date()`. Multi-day events are expanded —
the same `post_id` appears under every spanned date, each with its own
`display_context` (continuation flags, day number, total days).

- `ordered_dates`: the canonical order the calendar renders dates in
  (ascending for upcoming, descending for past).
- `by_date`: `Y-m-d => occurrence[]`. Mirrors `ordered_dates` for
  iteration.
- `gaps`: `Y-m-d => gap_days` map for `gap_days >= 2`. Clients render
  the "X days gap" separator between buckets using this.

`display_context` shape:

```jsonc
{
  "is_multi_day":        false,
  "is_start_day":        true,
  "is_end_day":          true,
  "is_continuation":     false,
  "display_date":        "2026-06-12",
  "original_start_date": "2026-06-12",
  "original_end_date":   "2026-06-12",
  "day_number":          1,
  "total_days":          1
}
```

### `pagination`, `counter`, `navigation`

Pure metadata — no HTML strings. Field meanings mirror the legacy
envelope's metadata fields (`pagination.current_page`,
`navigation.past_count`, etc.), with the HTML-rendering fields
(`pagination.html`, `counter` as string, `navigation.html`) removed.

## What this phase does NOT change

- The default REST response shape (i.e. without `format=data`) is
  **byte-for-byte unchanged**. The Calendar block's frontend bundle does
  not send `format=data` yet, so its contract is intact.
- No consumer in `inc/Blocks/Calendar/src/` is ported. Pagination, date
  range, taxonomy filters, and geo-sync still consume the HTML envelope.
- The `data-machine-calendar-content-updated` event lifecycle is
  untouched.
- The progressive-rendering path (`day-loader`) is untouched; on the
  data path `progressive` is forced false because progressive rendering
  is a server-render concern.

## Caching

`CalendarCache::generate_full_response_key()` includes `format` in its
key surface as of this phase. HTML and data responses for the same
envelope are stored in separate cache buckets.

## TypeScript

See `inc/Blocks/Calendar/src/types.ts` for the matching interfaces:
`CalendarDataResponse`, `CalendarEventItem`, `CalendarGrouping`, etc.
They live alongside the existing `CalendarResponse` (HTML envelope) and
are not yet consumed by `api-client.ts`.

## Phase 2+ (out of scope for this PR)

- Port pagination consumer to consume `pagination` from the data
  envelope, drop `pagination.html`.
- Port counter consumer to read `counter.*` numerics directly, drop the
  `counter` string field.
- Port navigation (past / upcoming buttons) to read
  `navigation.{past,future}_count` + `navigation.show_past`, drop
  `navigation.html`.
- Port the event-card renderer in TypeScript so `data.html` and the
  per-day group HTML strings can be removed.
- Remove the `data-machine-calendar-content-updated` re-init ceremony.
- Drop the HTML-string fields from the REST response, retire the
  `format` query param (data becomes canonical).
