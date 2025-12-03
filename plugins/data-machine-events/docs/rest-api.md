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
  - `event_search` (string): Searches titles, venue names, and taxonomy badges.
  - `date_start` / `date_end` (YYYY-MM-DD): Bound the query range for day grouping.
  - `tax_filter[taxonomy][]` (array): Term IDs per taxonomy.
  - `paged` (int): Pagination page number (5 days per page enforced by `inc/Blocks/Calendar/Pagination.php`).
  - `past` (0/1): Toggle past events.
- **Behavior**: Sanitizes every argument, builds SQL-based WP_Query filters (dates, `_datamachine_event_datetime`, taxonomies), caches taxonomy counts via helper classes, and returns success + fragments for frontend replacement.

### GET `/wp-json/datamachine/v1/events/filters`
- **Purpose**: Provides taxonomy term data (counts, parents, dependencies) for the Calendar filter modal.
- **Controller**: `Filters::filters()`.
- **Arguments**:
  - `active` or `active_filters[taxonomy][]`: Currently selected term IDs.
  - `context` (string): Optional block context (e.g., `calendar`).
  - `date_start` / `date_end`: Date window to keep counts accurate.
  - `past` (0/1): Past event mode influence.
- **Behavior**: Sanitizes keys/values, streams SQL queries to compute term counts, respects `datamachine_events_excluded_taxonomies`, and responds with structured metadata used by `modules/filter-modal.js`.

### GET `/wp-json/datamachine/v1/events/venues/{id}`
- **Purpose**: Returns venue description plus nine meta fields for admin editors and pipeline components.
- **Controller**: `Venues::venue()`.
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

## Shared Features
- **SQL-powered queries**: Calendar queries rely on `_datamachine_event_datetime` meta and taxonomy joins, ensuring pagination/filter fragments return accurate, performant results.
- **Caching**: REST responses reuse taxonomy counts computed via helpers to avoid redundant queries when the Calendar block changes filters rapidly.
- **Standard JSON structure**: Controllers always return `success` plus payload/`data`, making the JS modules and admin hooks predictable.
- **Graceful degradation**: The Calendar block renders identical templates on the server side so REST failures simply fall back to PHP rendering.
