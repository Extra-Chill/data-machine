# Calendar Data-Only REST Schema

The canonical calendar REST response remains the legacy HTML-string envelope
returned by `/wp-json/datamachine/v1/events/calendar`. This document describes
the **opt-in** data-only envelope introduced in phase 1: a server-rendered
JSON shape with structured event data and one canonical server-rendered
empty-state recovery fragment, intended to become the canonical
contract once the consumers in `inc/Blocks/Calendar/src/` are ported in
subsequent phases.

## Activation

The route is registered only on sites where Data Machine Events is active.
On other sites, the REST API can return `rest_no_route`.

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

## Taxonomy filters

Use `tax_filter[<taxonomy>][]=<term_id>` with a taxonomy slug and numeric
term IDs from the site serving the calendar. Repeat the parameter to match
any of several terms within one taxonomy. Filters across different taxonomies
must all match. For example, to match either event type `12` or `34`:

```bash
curl --globoff 'https://example.com/wp-json/datamachine/v1/events/calendar?format=data&tax_filter[event_type][]=12&tax_filter[event_type][]=34'
```

## Envelope

```jsonc
{
  "success": true,
  "schema": {
    "name": "calendar-data",
    "version": 7,
    "phase": 1,
    "issue": 298
  },
  "events":     [ /* CalendarEventItem[] — see below */ ],
  "grouping": {
    "ordered_dates": [ "2026-06-12", "2026-06-13", "..." ],
    "by_date": {
      "2026-06-12": [
        { "post_id": 12345, "display_context": { /* ... */ }, "display": { /* ... */ } }
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
  },
  "empty_html": ""
}
```

### `schema`

Identifies the schema for forward compatibility. Clients should check
`schema.name === 'calendar-data'` and check that they support the returned
`schema.version` before reading the rest. Version 7 adds `event.source`.

`schema.version` is also folded into the full-response cache key (as
`data_schema` — see [Caching](#caching)), so a deploy that bumps the
version can never serve a stale older-shape envelope to the newer client
for the cache TTL window.

**v2:** each `grouping.by_date`
occurrence now carries a server-computed `display` block. The client-side
event renderer consumes those values verbatim instead of re-deriving
time / date / unicode formatting in JavaScript, making
`DisplayVars::build()` the single source of truth for every render path
(PHP template, lazy-render hydration, and the client event renderer).
This closed the badge- and time-format drift bugs where client-rendered
(Load More) cards diverged from server-rendered ones.

**v3:** empty
responses carry `empty_html`, rendered from the existing `no-events.php`
template. Non-empty responses carry an empty string.

**v5:** the `venue` subobject adds `tier`, a venue tier slug or an empty
string when the venue is unclassified.

**v6:** `ticket.is_affiliate` indicates that the ticket link requires a
redirect gate. In this case `ticket.url` is empty; clients can use `event.id`
as the reference for a gated button instead of rendering a direct link.

**v7:** `source` adds the original listing URL and attribution label.

### `events`

Array of structured event objects, **deduplicated on `id`**. A multi-day
event appears once here, regardless of how many dates it spans on this
page — its multi-day expansion is represented in `grouping.by_date`.
`id` is the event's WordPress post ID on this installation. It stays the
same when an upsert updates the same event, including when a stable source
identity matches a rescheduled event whose date or time changed. Do not
use dates, times, or source URLs as event IDs.

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
    "name":    "Example Hall",
    "slug":    "example-hall",
    "address": "100 Main Street",
    "formatted_address": "100 Main Street, Example City",
    "city": "Example City",
    "state": "",
    "zip": "",
    "country": "US",
    "coordinates": "",
    "timezone": "America/New_York",
    "tier": "club"
  },
  "organizer": {
    "name": "Local Promoter",
    "url":  "https://example.com",
    "type": "Organization"
  },
  "source":    { "url": "https://example.com/listings/some-show", "name": "Local Promoter" },
  "ticket":    { "url": "https://example.com/tickets/some-show", "is_affiliate": false },
  "performer": { "name": "Headliner Name", "type": "MusicGroup" },
  "status": "EventScheduled",
  "address":   "100 Main Street, Example City",
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

- `source` is always an object in v7. Use `source.url` to link back to the
  original event or listing page, not to buy tickets. `source.name` prefers
  the organizer, then the venue, then the URL hostname.
  Both fields are `""` when no public listing URL is known. Existing events
  are not backfilled automatically. A later upsert can populate the URL.
- `venue` and `organizer` are `null` when their data is unavailable. The legacy
  HTML templates rendered an empty slot in that case; clients should do
  the same.
- `ticket.url` is `""` and `ticket.is_affiliate` is `true` when the
  ticket URL is a known affiliate/redirect wrapper (see
  `data_machine_events_is_affiliate_ticket_url()`). Clients must not
  fall back to any other field to recover the affiliate URL — render a
  gated button keyed on `event.id` instead (see #816).
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

#### `display` (v2)

Each occurrence also carries a server-computed `display` block, produced
by `DisplayVars::build( $event_data, $display_context )` — the single
source of truth for every render path's display strings. It lives
**per-occurrence** (not on the event) because the formatted time string
varies by occurrence for multi-day events: the same `post_id` reads as a
timed range on its start day and `"Ongoing · ends Mar 22"` on
continuation days.

```jsonc
{
  "formatted_time_display": "7:30 - 10:00 PM",   // ready-to-print, e.g. "Ongoing · ends Mar 22"
  "multi_day_label":        "",                  // "through Mar 22" on a multi-day start day, else ""
  "iso_start_date":         "2026-06-12T20:30:00-04:00", // timezone-aware, for the data-date attribute
  "venue_name":             "Example Hall",       // unicode-decoded, for data-venue
  "performer_name":         "Headliner Name",      // unicode-decoded, for data-performer
  "show_performer":         false,
  "show_ticket_link":       true,
  "is_continuation":        false,
  "is_multi_day":           false
}
```

Clients should render these verbatim and must **not** re-derive time,
date, or unicode formatting locally — doing so reintroduces the
server/client drift this block exists to eliminate.

### `pagination`, `counter`, `navigation`, `empty_html`

Pagination, counter, and navigation are pure metadata. Field meanings mirror the legacy
envelope's metadata fields (`pagination.current_page`,
`navigation.past_count`, etc.), with the HTML-rendering fields
(`pagination.html`, `counter` as string, `navigation.html`) removed.
`empty_html` is empty for non-empty results. For an empty result it contains
the translated output of the canonical `no-events.php` template, including
the existing Today recovery control, so clients do not duplicate that copy.

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

The cache key also includes the
`month` envelope field, so month-grid responses scoped to a specific
`YYYY-MM` window don't collide with list-mode responses for the same
archive.

The cache key also includes
`data_schema` (the data-envelope `schema.version`, set only for
`format=data` requests). Bumping `Calendar::DATA_SCHEMA_VERSION` therefore
isolates the new envelope shape into fresh cache buckets, so a deploy that
changes the shape never serves a stale older-shape response to the newer
client for the cache TTL window.

## Month-grid scoping (#318)

Pass `month=YYYY-MM` to scope the response to a single calendar month
(used by the month-grid display mode):

```bash
curl 'https://example.com/wp-json/datamachine/v1/events/calendar?format=data&month=2026-09'
```

Semantics:
- The ability collapses pagination to a single page and includes BOTH
  past AND future events that fall within the month (grid mode renders
  past dates with reduced opacity rather than gating them behind a
  toggle).
- `paged` and `past` are effectively ignored when `month` is set —
  callers should drop them from the URL to keep the cache key clean.
- An invalid `month` value falls back as if the param were absent.

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
