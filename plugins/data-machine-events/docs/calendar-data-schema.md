# Calendar REST schema

`GET /wp-json/datamachine/v1/events/calendar` returns structured JSON. No `format` parameter is needed; the old HTML envelope is no longer available. The PHP templates still render the initial block and its fallback links.

## Response (version 8)

| Field | Contract |
| --- | --- |
| `success` | `true` for successful requests. |
| `schema` | Schema identifier `calendar-data` and its version. |
| `events` | Event records, deduplicated by post ID. Each includes title, permalink, date/time, venue, organizer, source, ticket, performer, status, address, taxonomy assignments, and display styling. |
| `grouping` | `ordered_dates` (`YYYY-MM-DD`), `by_date` (date to ordered occurrence list), and `gaps` (date to number of days). Each occurrence references `post_id` and includes `display_context` and server-computed `display` values. |
| `pagination` | `current_page`, `total_pages`, `total_items`, and `page_items`. |
| `counter` | `showing_count`, `total_count`, `page_start_date`, and `page_end_date`. |
| `navigation` | `show_past`, `past_count`, `future_count`, `has_past`, and `has_future`. |

An empty result contains no events or ordered dates; the frontend renders the empty state and decides whether to offer Today recovery based on the current view. Each date occurrence has its own display values, so multi-day events can share one event record and still show different time labels on different days.

`events[].badges` contains `wrapper_classes` and an `items` array. Each item has `classes`, `taxonomy`, `term`, `label`, and `url` (empty when not linked). These values honor server-side badge class and exclusion filters. An empty array means no badges; the unfiltered `taxonomies` map is not a badge fallback. The frontend renders the labels as text. `button_classes` preserves the filtered More Info classes. An affiliate ticket has an empty `ticket.url` and `ticket.is_affiliate: true`; the existing gated flow uses the event ID.

`events[].source` is an attribution object for the original listing, not a ticket link. `source.url` points to the listing and `source.name` uses the organizer name, then the venue name, then the URL hostname. Both fields are empty when no public listing URL is available. Existing events are not backfilled automatically; a later upsert can supply the URL.

## Requests and caching

The same schema is used for list updates, date-range and taxonomy changes, Load More, deferred-day loading and month navigation. Pass `month=YYYY-MM` to scope to an entire month instead of using day-based pagination. Pass `date_start` and `date_end` with the same value to request one day's events. The block keeps shareable calendar URLs separate from the REST request URL.

The full-response cache is keyed by query scope and schema version. Version 7 replaces the legacy envelope and removes the `empty_html` and `badges_html` fields from the earlier data format. TypeScript response types live in `inc/Blocks/Calendar/src/types.ts`.
