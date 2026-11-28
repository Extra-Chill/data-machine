# Calendar REST schema

`GET /wp-json/datamachine/v1/events/calendar` returns structured JSON. No `format` parameter is needed; the old HTML envelope is no longer available. The PHP templates still render the initial block and its fallback links.

## Response (version 7)

| Field | Contract |
| --- | --- |
| `success` | `true` for successful requests. |
| `schema` | Schema identifier `calendar-data` and its version. |
| `events` | Event records, deduplicated by post ID. Each includes title, permalink, date/time, venue, organizer, ticket, performer, status, address, taxonomy assignments, and display styling. |
| `grouping` | `ordered_dates` (`YYYY-MM-DD`), `by_date` (date to ordered occurrence list), and `gaps` (date to number of days). Each occurrence references `post_id` and includes `display_context` and server-computed `display` values. |
| `pagination` | `current_page`, `total_pages`, `total_items`, and `page_items`. |
| `counter` | `showing_count`, `total_count`, `page_start_date`, and `page_end_date`. |
| `navigation` | `show_past`, `past_count`, `future_count`, `has_past`, and `has_future`. |

An empty result contains no events or ordered dates; the frontend renders the empty state and decides whether to offer Today recovery based on the current view. Each date occurrence has its own display values, so multi-day events can share one event record and still show different time labels on different days.

`events[].badges` contains `wrapper_classes` and an `items` array. Each item has `classes`, `taxonomy`, `term`, `label`, and `url` (empty when not linked). These values honor server-side badge class and exclusion filters. An empty array means no badges; the unfiltered `taxonomies` map is not a badge fallback. The frontend renders the labels as text. `button_classes` preserves the filtered More Info classes. An affiliate ticket has an empty `ticket.url` and `ticket.is_affiliate: true`; the existing gated flow uses the event ID.

## Requests and caching

The same schema is used for list updates, date-range and taxonomy changes, Load More, deferred-day loading and month navigation. Pass `month=YYYY-MM` to scope to an entire month instead of using day-based pagination. Pass `date_start` and `date_end` with the same value to request one day's events. The block keeps shareable calendar URLs separate from the REST request URL.

The full-response cache is keyed by query scope and schema version. Version 7 replaces the legacy envelope and removes the `empty_html` and `badges_html` fields from the earlier data format. TypeScript response types live in `inc/Blocks/Calendar/src/types.ts`.

## Calendar query-changed event

The calendar root dispatches a bubbling `data-machine-calendar-query-changed` `CustomEvent` after applying a successful user-initiated query. It also emits `initial` once when the server-rendered calendar is initialized, without making another request. To receive `initial`, register the listener before block initialization. Day loading, lazy rendering, history restoration, and failed or superseded requests do not emit this event. The existing content-updated event is unchanged.

| `detail` field | Contract |
| --- | --- |
| `trigger` | `search`, `date_range`, `scope`, `taxonomy_filter`, `clear`, `pagination`, `geo`, or `initial`. `clear` covers a filter reset or removal of a date range. `pagination` covers Load More and month navigation. |
| `query` | Snapshot of the calendar query: `scope`, `date_start` and `date_end` (`YYYY-MM-DD`), `has_search` (boolean), `event_search` (the search term), `tax_filter` (object mapping taxonomy slugs to arrays of numeric term IDs), `paged` (positive integer, default `1`), `month` (`YYYY-MM`), and `past` (boolean). Unset strings are empty; unset taxonomy filters are `{}`. |
| `archive` | `{ taxonomy, term_id }`, with a numeric term ID, or `null` outside an archive. |
| `result_count` | Total matches for this query, not the number added to the page. Uses the response counter total, falling back to the pagination total, then the response event count if neither total is available. The initial event uses the server-rendered total. |

The event includes the search term as entered for the query. Consumers should handle it as user input. The event does not expose coordinates or opaque scope tokens, and the block does not forward the event data anywhere.
