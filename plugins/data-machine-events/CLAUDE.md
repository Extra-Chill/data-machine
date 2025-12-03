# CLAUDE.md

Technical guidance for Claude Code when working with the **Data Machine Events** WordPress plugin.

**Version**: 0.5.0

## Plugin Bootstrap

- **Main file**: `datamachine-events.php` defines core constants, loads `inc/Core/meta-storage.php`, and requires `inc/Api/Routes.php` once REST API routing is available.
- **DATAMACHINE_Events class**: Singleton that registers post types, taxonomies, blocks (`Calendar` and `EventDetails`), block assets (`root.css`, Leaflet map scripts/styles), REST API routes, and conditional admin components (settings page, admin bar).
- **Block registration**: `register_block_type()` loads block.json definitions, enqueues root CSS for any datamachine events block or singular event post, and enqueues Leaflet assets plus the venue map helper when the Event Details block or event post is present.
- **Data Machine bootstrap**: `init_data_machine_integration()` runs during `init`, loads `EventImportFilters`, instantiates `EventImportStep` handlers, and registers `EventUpsert` plus its filters.

## Data Machine Import Pipeline

- **EventImportStep** extends `DataMachine\Core\Steps\Step`, discovers handlers via registration traits, and delegates to `FetchHandler` implementations.
- **Handlers**:
  - `DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\Ticketmaster`
  - `...DiceFm\DiceFm`
  - `...GoogleCalendar\GoogleCalendar` (with `GoogleCalendarUtils` for ID/URL resolution)
  - `...IcsCalendar\IcsCalendar`
  - `...SpotHopper\SpotHopper`
  - `...WebScraper\UniversalWebScraper`
  - `...WordPressEventsAPI\WordPressEventsAPI`
  - `...EventFlyer\EventFlyer`
  - `...Eventbrite\Eventbrite`
  - `...DoStuffMediaApi\DoStuffMediaApi`
- Each handler performs single-item processing, checks `datamachine_is_item_processed`, marks processed items, maps raw payloads to the shared schema, and returns immediately once a valid event is found.
- **EventUpsert** (`Steps\Upsert\Events\EventUpsert`) extends `UpdateHandler`, finds existing events by title/venue/startDate, performs field-by-field change detection, uses `Venue_Taxonomy`/`VenueService` to find-or-create venues, and routes final data into Event Details blocks and venue taxonomy fields.
- **EventUpsertSettings** exposes Data Machine configuration fields (post status, image handling, taxonomy overrides) and sanitizes inputs through `WordPressSettingsHandler`.

## Shared Utilities & Schema

- `DataMachine\Core\WordPress\TaxonomyHandler` centralizes taxonomy assignments and exposes custom handlers for venue taxonomies.
- `VenueService` normalizes venue data, finds existing terms, and creates new ones with full meta support.
- `WordPressPublishHelper` handles image downloads, attachment creation, and publishing workflow required by EventUpsert.
- `EventIdentifierGenerator` normalizes title/date/venue, removes articles, collapses whitespace, and hashes the combination to keep all handlers aligned.
- `EventSchemaProvider` exposes `getCoreToolParameters()`, `getSchemaToolParameters()`, and `generateSchemaOrg()` so Schema.org JSON-LD derives from block attributes + venue meta.
- `Template_Loader` orchestrates template rendering, `Taxonomy_Helper` calculates term hierarchies/post counts for filters, and `Taxonomy_Badges` renders consistent badge HTML with adjusters for colors and classes.

## Blocks & Assets

- **Calendar block** (`inc/Blocks/Calendar`): Carousel List display via CSS-only horizontal scrolling, progressive enhancement filtering, and modular templates (`event-item`, `date-group`, `pagination`, `navigation`, `results-counter`, `no-events`, `filter-bar`, `time-gap-separator`, `modal/taxonomy-filter`).
- **Calendar JS modules**: `src/frontend.js` wires up calendar instances and initializes `modules/api-client.js`, `modules/carousel.js`, `modules/date-picker.js`, `modules/filter-modal.js`, `modules/navigation.js`, and `modules/state.js`.
- **Event Details block** (`inc/Blocks/EventDetails`): 15+ attributes (dates, venue, pricing, performer/organizer meta, status, display toggles) with InnerBlocks support, Leaflet-driven venue map (Leaflet CSS/JS + `assets/js/venue-map.js`), and `_datamachine_event_datetime` meta synced for performant queries.
- **Root styles**: `inc/Blocks/root.css` provides shared design tokens consumed by both blocks and enqueued whenever a datamachine block or event post is present.

## REST API Overview

Routes are registered via `inc/Api/Routes.php`, which loads controllers from `inc/Api/Controllers/{Calendar,Venues,Filters,Geocoding}`.

- `GET /wp-json/datamachine/v1/events/calendar`: Public calendar filtering; accepts `event_search`, `date_start`, `date_end`, mapped `tax_filter[taxonomy][]`, `paged`, and `past`. Returns rendered HTML plus pagination/navigation/counter fragments for progressive enhancement.
- `GET /wp-json/datamachine/v1/events/venues/{id}`: Admin-only (`manage_options`) venue retrieval, returns venue meta (address, city, state, zip, country, phone, website, capacity, coordinates) plus description.
- `GET /wp-json/datamachine/v1/events/venues/check-duplicate`: Admin-only duplicate check using `name`/`address`; responds with `is_duplicate`, `existing_venue_id`, and context message.
- `GET /wp-json/datamachine/v1/events/filters`: Public taxonomy filters; supports `active` filters, `context`, `date_start`, `date_end`, and `past`, returning term lists with counts and hierarchy metadata.
- `POST /wp-json/datamachine/v1/events/geocode/search`: Admin-only OpenStreetMap Nominatim lookup accepting `query` and returning place details (lat/lon, display_name, structured address).
- Controllers expose permission callbacks, sanitize arguments, and rely on `Calendar`, `Venues`, `Filters`, and `Geocoding` classes for SQL-based filtering, taxonomy processing, and remote lookups.

**Removed features** such as AJAX calendar handlers, client-side FilterManager, and legacy status detection are fully retired; references are historical notes only when discussing past migrations.

## Template & Venue Details

- `Template_Loader` provides `get_template()`, `include_template()`, `template_exists()`, and variable hygiene for Carousel templates.
- `Taxonomy_Badges` renders badges for non-venue taxonomies with dynamic color assignments and exposes filters for wrapper/classes.
- Venue taxonomy includes 9 meta fields (address, city, state, zip, country, phone, website, capacity, coordinates) and uses `Venue_Taxonomy`, `VenueService`, and `VenueParameterProvider` for admin UI, AI parameter generation, and REST integrations.
- Leaflet map assets are enqueued with `enqueue_root_styles()`, ensuring the Event Details block and singular events load `leaflet.css`, `leaflet.js`, and `assets/js/venue-map.js`.

## Filters & Hooks

- `datamachine_events_calendar_query_args`: Alter calendar WP_Query before rendering.
- `datamachine_events_ticket_button_classes`, `datamachine_events_action_buttons`: Filter action button classes and insert hooks around ticket buttons.
- `datamachine_events_excluded_taxonomies`: Remove taxonomies from badge/modal displays.
- `datamachine_events_badge_wrapper_classes`, `datamachine_events_badge_classes`, `datamachine_events_more_info_button_classes`: Customize badge markup.
- `datamachine_events_pagination_wrapper_classes`, `datamachine_events_pagination_args`: Customize pagination output.

## Security & Standards

- Capability checks guard admin routes (venues, geocoding).
- Inputs are sanitized via `sanitize_text_field`, `absint`, and custom callbacks (e.g., `sanitize_key` for taxonomies).
- Nonce verification and standard WordPress authentication apply to form submissions and API requests.
- Build commands rely on Composer (PHP dependencies) plus npm for Calendar/EventDetails (`npm run build`, `npm run start`, `npm run lint:js`, `npm run lint:css`), and `./build.sh` produces `/dist/datamachine-events.zip` with production assets.

