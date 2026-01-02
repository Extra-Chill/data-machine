# REST API

Data Machine Events exposes a focused REST surface under the `datamachine/v1` namespace. Each route is registered in `inc/Api/Routes.php` and handled by the controllers in `inc/Api/Controllers` so the Calendar and Event Details blocks plus admin UI stay synchronized with SQL-powered filtering.

## Architecture

- **Base URL**: `/wp-json/datamachine/v1/`
- **Route registration**: `inc/Api/Routes.php` maps resources to `Calendar`, `Filters`, `Venues`, and `Geocoding` controllers.
- **Controller responsibilities**:
  - `Calendar` handles event queries, HTML fragment rendering, pagination, and counter updates used by the Calendar block and JS modules.
  - `Filters` builds taxonomy hierarchies, counts, and dependency hints for the filter modal.
  - `Venues` surfaces venue metadata (and duplicate checks) to admin flows.
  - `Geocoding` proxies OpenStreetMap Nominatim lookups for venue creation.
- **Security & sanitization**: Every route sanitizes inputs using `sanitize_text_field`, `absint`, `sanitize_key`, or custom callbacks, enforces capability checks where required, and returns standardized JSON responses.
- **Progressive enhancement**: Calendar block falls back to server-rendered templates; REST responses simply replace fragments when JavaScript is active.

## Endpoints

### GET `/wp-json/datamachine/v1/events/calendar`
- **Purpose**: Supplies calendar HTML fragments (`html`, `pagination`, `navigation`, `counter`) for the Calendar block while keeping server-side pagination/accounting.
- **Controller**: `Calendar::calendar()`.
- **Arguments**:
  - `event_search` (string): Free text search.
  - `date_start` / `date_end` (YYYY-MM-DD): Bound the query range.
  - `tax_filter` (object): Map of `{ taxonomy: [termId, ...] }`.
  - `archive_taxonomy` (string): Sanitized taxonomy key for archive context.
  - `archive_term_id` (int): Term ID for archive context.
  - `paged` (int): Page number.
  - `past` (string): Past-event toggle.
- **Behavior**: Sanitizes every argument, builds SQL-based WP_Query filters (dates, `_datamachine_event_datetime`, taxonomies), caches taxonomy counts via helper classes, and returns success + fragments for frontend replacement.

### GET `/wp-json/datamachine/v1/events/filters`
- **Purpose**: Provides taxonomy term data (counts, parents, dependencies) for the Calendar filter modal.
- **Controller**: `Filters::get()`. 
- **Arguments**:
  - `active` (object): Map of `{ taxonomy: [termId, ...] }`.
  - `context` (string): Defaults to `modal`.
  - `date_start` / `date_end` (string)
  - `past` (string)
- **Behavior**: Sanitizes keys/values, computes term counts for the current calendar context, respects `datamachine_events_excluded_taxonomies`, and responds with structured metadata used by the Calendar block modal.

### GET `/wp-json/datamachine/v1/events/venues/{id}`
- **Purpose**: Returns venue description plus nine meta fields for admin editors and pipeline components.
- **Controller**: `Venues::get()`. 
- **Permissions**: `current_user_can('manage_options')`.
- **Arguments**:
  - `id` (term ID): Sanitized through `absint`.
- **Behavior**: Loads term via `get_term_by()`, calls `Venue_Taxonomy::get_venue_data()` for address/city/state/zip/country/phone/website/capacity/coordinates, and returns JSON with `venue` object.

### GET `/wp-json/datamachine/v1/events/venues/check-duplicate`
- **Purpose**: Helps admins detect duplicate venues during creation.
- **Controller**: `Venues::check_duplicate()`.
- **Permissions**: `manage_options`.
- **Arguments**:
  - `name` (string) and optional `address` (string): Sanitized via `sanitize_text_field`.
- **Behavior**: Performs duplicate logic across `venue` terms, normalizes inputs, returns `is_duplicate`, `existing_venue_id`, and user-friendly descriptions so UI can suggest existing venues rather than creating duplicates.

### POST `/wp-json/datamachine/v1/events/geocode/search`
- **Purpose**: Admin-facing OpenStreetMap Nominatim proxy for venue autocompletion.
- **Controller**: `Geocoding::search()`.
- **Permissions**: `manage_options`.
- **Arguments**:
  - `query` (string): Sanitized with `sanitize_text_field`.
- **Behavior**: Calls Nominatim (with `DataMachine\Core\HttpClient`), handles rate limiting, returns `display_name`, `lat`, `lon`, and address parts, and surfaces errors when remote services fail.

## Notes

- **Query performance**: Calendar queries rely on `_datamachine_event_datetime` meta and taxonomy joins.
- **Response shape**: Endpoints return JSON suitable for progressive enhancement, but templates still render server-side when JS is absent.
