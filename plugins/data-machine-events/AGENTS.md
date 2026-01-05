# AGENTS.md

Technical guidance for Claude Code when working with the **Data Machine Events** WordPress plugin.

**Version**: 0.8.31

## Plugin Bootstrap

- **Entry point**: `datamachine-events.php` defines constants (`DATAMACHINE_EVENTS_VERSION`, plugin paths), loads `inc/Core/meta-storage.php`, and requires `inc/Api/Routes.php` when the file exists.
- **`DATAMACHINE_Events` class**: Singleton bootstrapped via `init`, it registers the `datamachine_events` post type, `venue`/`promoter` taxonomies, enqueues admin assets, and registers Calendar/Event Details blocks. It also adds root styles, Leaflet assets, and the venue map script whenever a block or singular event is rendered.
- **Block registration**: `register_block_type()` reads `inc/Blocks/Calendar/block.json` and `inc/Blocks/EventDetails/block.json`, enqueues shared `inc/Blocks/root.css`, and hooks `enqueue_root_styles()` to `wp_enqueue_scripts`/`enqueue_block_assets`. Leaflet CSS/JS plus `assets/js/venue-map.js` are enqueued only when the Event Details block or a `datamachine_events` post is present.
- **Query Parameter Sanitization**: `datamachine_events_sanitize_query_params()` recursively sanitizes nested query arrays while preserving structure, enabling safe handling of multi-dimensional filter parameters (e.g., `tax_filter[genre][0]`). Used in `Pagination::sanitize_query_params()` and the navigation template.

## Data Machine Integration

- **`init_data_machine_integration()`**: Runs at priority 25 on `init`. After verifying `DATAMACHINE_VERSION`, it loads `EventImportFilters`, instantiates all import handlers, loads EventUpsert filters, and registers the EventUpsert handler from `inc/Steps/Upsert/Events/EventUpsert.php`.
- **Event import handlers**: `load_event_import_handlers()` instantiates the following `FetchHandler` implementations (all located under `inc/Steps/EventImport/Handlers`):
- **Universal Web Scraper Architecture**: A multi-layered system that prioritizes structured data extraction (Schema.org JSON-LD/Microdata and 19 specialized extractors) before falling back to AI-enhanced HTML section parsing. It coordinates fetching, pagination, and normalization via a centralized `StructuredDataProcessor`. The scraper implements a "Smart Fallback" mechanism that retries requests with standard headers if browser-mode spoofing is blocked by captchas (SiteGround/Cloudflare) or encounters 403 errors.
  - `DiceFm\DiceFm`
  - `DoStuffMediaApi\DoStuffMediaApi`
  - `Eventbrite\Eventbrite`
  - `EventFlyer\EventFlyer`
  - `IcsCalendar\IcsCalendar`
  - `SingleRecurring\SingleRecurring` (@since v0.6.3)
  - `Ticketmaster\Ticketmaster` (with automatic API pagination up to MAX_PAGE=19)
  - `WebScraper\\UniversalWebScraper` (extractor priority: AEG/AXS, RedRocks, Freshtix, Firebase, Embedded Calendar, Squarespace, SpotHopper, Bandzoogle, GoDaddy, Timely, JSON-LD, WordPress/Tribe, Prekindle, Wix, RHP, OpenDate.io, Microdata; then HTML section fallback; automatic pagination up to MAX_PAGES=20; automatic WordPress API discovery fallback)
- **Event Filtering**: All import handlers (via `EventImportHandler`) automatically skip events with "closed" in the title.
- **Handler discovery**: `EventImportStep` (extends `DataMachine\Core\Steps\Step`) reads the configured handler slug, looks it up via `datamachine_handlers`, instantiates the class, and delegates to `get_fetch_data()` on `FetchHandler` (or falls back to legacy `execute()`). It merges returned `DataPacket` results into the pipeline and logs configuration issues.
- **Single-item processing**: Each handler normalizes `(title, startDate, venue)` through `EventIdentifierGenerator::generate()`, checks `datamachine_is_item_processed`, marks the identifier via `datamachine_mark_item_processed`, and returns immediately after pushing a valid event to maintain incremental imports.
- **EventUpsert**: `Steps\Upsert\Events\EventUpsert` extends `DataMachine\Core\Steps\Update\Handlers\UpdateHandler`. It registers custom taxonomy handlers for `venue` and `promoter`, merges `EngineData` snapshots with AI parameters, identifies existing events (title + venue + start date), runs field-by-field change detection, updates or creates events, processes featured images via `WordPressPublishHelper`, and keeps `_datamachine_event_datetime` synced for calendar queries.
- **EventUpsertSettings**: Exposes configuration fields (post status, author fallback, taxonomy handling, image download toggles) using `WordPressSettingsHandler`, while `WordPressSettingsResolver` sanitizes runtime values.
- **Venue services**: `Venue_Taxonomy`, `VenueService`, and `VenueParameterProvider` centralize venue term creation, metadata management, and AI parameter generation. Venue metadata (address, city, state, zip, country, phone, website, capacity, coordinates) flows through REST endpoints and the Event Details block.

## Shared Utilities

- `DataMachine\Core\WordPress\TaxonomyHandler`: Coordinates taxonomy assignment. EventUpsert registers custom handlers to ensure AI-provided venue/promoter data writes the correct term meta.
- `WordPressPublishHelper`: Downloads images, creates attachments, and respects handler settings such as `include_image`.
- `EventIdentifierGenerator`: Normalizes event identity by lowercasing, trimming, collapsing whitespace, removing articles, and hashing `(title, startDate, venue)` to prevent duplicates.
- `EventSchemaProvider`: Defines `getCoreToolParameters()`, `getSchemaToolParameters()`, `getFieldKeys()`, and `generateSchemaOrg()` so Schema.org JSON-LD merges block attributes, venue meta, and taxonomy data.
- `Template_Loader`: Offers helpers for the Calendar templates (`get_template()`, `include_template()`, `template_exists()`, `get_template_path()`).
- `Taxonomy_Helper` and `Taxonomy_Badges`: Build hierarchical filter data, term counts, and render badge HTML. Filters such as `datamachine_events_badge_wrapper_classes`, `datamachine_events_badge_classes`, and `datamachine_events_more_info_button_classes` customize badge markup and CTA styling.

## Blocks & Frontend

- **Calendar block** (`inc/Blocks/Calendar`): CSS-driven Carousel List with day grouping, time-gap separators, pagination, and server-rendered HTML. Templates include `event-item`, `date-group`, `navigation`, `results-counter`, `pagination`, `no-events`, `filter-bar`, `time-gap-separator`, and `modal/taxonomy-filter`.
- **JavaScript modules**: `src/frontend.js` bootstraps `.datamachine-events-calendar` instances and wires `modules/api-client.js`, `modules/carousel.js`, `modules/date-picker.js`, `modules/filter-modal.js`, `modules/filter-state.js`, `modules/navigation.js`, and `modules/state.js` for REST calls, carousel scrolling, Flatpickr integration, modal accessibility, filter state management, navigation updates, and History API-aware state management (including 500ms debounced search). `FilterStateManager` centralizes URL, localStorage, and DOM-based state with regex support for both indexed (`tax_filter[taxonomy][0]`) and non-indexed (`tax_filter[taxonomy][]`) array syntax.
- **Progressive enhancement**: Server-rendered fragments allow the block to work without JavaScript, while REST API responses enrich filtering, pagination, search, and URL state when scripts are active.
- **Event Details block** (`inc/Blocks/EventDetails`): 15+ attributes (dates/times, venue, address, price, priceCurrency, offerAvailability, ticketUrl, performer, performerType, organizer, organizerType, organizerUrl, eventStatus, previousStartDate, showVenue, showPrice, showTicketLink) plus InnerBlocks for rich content. Attributes persist to the block and sync `_datamachine_event_datetime` via `inc/Core/meta-storage.php` for performant queries. InnerBlocks content extracts to plain text for Schema.org `description` field via `wp_strip_all_tags()`, improving structured data quality.
- **Schema & maps**: `enqueue_root_styles()` loads `leaflet.css`, `leaflet.js`, and `assets/js/venue-map.js` when Event Details or `datamachine_events` posts are present so Leaflet maps render with consistent markers and controls.

## REST API

Routes in `inc/Api/Routes.php` register controllers from `inc/Api/Controllers/{Calendar,Venues,Filters,Geocoding}` under the `datamachine/v1` namespace.

- `GET /wp-json/datamachine/v1/events/calendar`: Public endpoint powering Calendar block filtering. Accepts `event_search`, `date_start`, `date_end`, `tax_filter` (object), `archive_taxonomy`, `archive_term_id`, `paged`, and `past`.
- `GET /wp-json/datamachine/v1/events/venues/{id}`: Admin-only (`manage_options`). Returns venue description plus metadata (address, city, state, zip, country, phone, website, capacity, coordinates via `Venue_Taxonomy::get_venue_data`).
- `GET /wp-json/datamachine/v1/events/venues/check-duplicate`: Admin-only duplicate check requiring `name` (and optional `address`), sanitizes input, and responds with `is_duplicate`, `existing_venue_id`, and contextual messaging.
- `GET /wp-json/datamachine/v1/events/filters`: Public taxonomy filters powered by `Filters` controller. Accepts `active`, `context`, `date_start`, `date_end`, `past`, and returns taxonomy term metadata with counts, parent relationships, and dependency hints.
- `POST /wp-json/datamachine/v1/events/geocode/search`: Admin-accessed `Geocoding::search()` calling OpenStreetMap Nominatim with sanitized `query`, returning `display_name`, `lat`, `lon`, and structured address segments.

**Security**: Admin endpoints check `current_user_can('manage_options')`. All args sanitize via `sanitize_text_field`, `absint`, `sanitize_key`, or callback sanitizers. The controllers log and respond with consistent JSON structures.

## AI Chat Tools

Chat tools in `inc/Api/Chat/Tools` provide AI-driven venue management capabilities via the Data Machine's AI framework. Tools are self-registering via `ToolRegistrationTrait`:

- **VenueHealthCheck**: Scans all venues for data quality issues (missing address, coordinates, or timezone) and returns detailed counts and lists of problematic venues. Optional `limit` parameter controls maximum venues returned per category (default: 25).
- **UpdateVenue**: Updates venue name and/or meta fields (address, city, state, zip, country, phone, website, capacity, coordinates, timezone). Accepts venue identifier (term ID, name, or slug) and any combination of fields to update. Address field changes trigger automatic geocoding via `Venue_Taxonomy::update_venue_meta`.
- **GetVenueEvents**: Retrieves upcoming events for a specific venue. Accepts venue identifier (term ID, name, or slug) and optional parameters for limiting results and date range filtering.

## Templates & Rendering

- `Template_Loader` renders the eight modular Calendar templates and ensures variable hygiene.
- `Taxonomy_Helper` builds taxonomy hierarchies and counts; `Taxonomy_Badges` renders badges with consistent color classes.
- Pagination (five days per page) lives in `inc/Blocks/Calendar/Pagination.php` and respects `datamachine_events_pagination_wrapper_classes` and `datamachine_events_pagination_args` filters.

## Geocoding & Maps

- `assets/js/venue-map.js` initializes Leaflet maps using coordinates from venue term meta, ensuring consistent markers and popups.
- Leaflet CSS/JS assets load via CDN (Leaflet 1.9.4) when needed.
- `Geocoding::search()` provides admin geocoding via OpenStreetMap, powering venue creation/edit interfaces.

## Filters & Hooks

- `datamachine_events_calendar_query_args`: Modify calendar WP_Query arguments before template rendering.
- `datamachine_events_excluded_taxonomies`: Control taxonomies excluded from badge lists or filter modals.
- Badge/action filters: `datamachine_events_badge_wrapper_classes`, `datamachine_events_badge_classes`, `datamachine_events_more_info_button_classes`, `datamachine_events_ticket_button_classes`, and `datamachine_events_action_buttons` customize badge/CTA markup.
- Pagination filters: `datamachine_events_pagination_wrapper_classes`, `datamachine_events_pagination_args` customize pagination markup.

## WP-CLI Commands

- **Universal Web Scraper Test Command**: `inc/Cli/UniversalWebScraperTestCommand.php` registers a WP-CLI invokable that runs the `WebScraper\\UniversalWebScraper` handler against a `--target_url`, creates a job record for context, prints a packet summary (structured vs HTML fallback), and can optionally run `EventUpsert` (`--upsert`) to validate end-to-end venue coverage.

## Build Commands

```bash
composer install --no-dev --optimize-autoloader
cd inc/Blocks/Calendar && npm ci && npm run build
cd ../EventDetails && npm ci && npm run build
./build.sh
```

## Removed Features (Completed)

- **Circuit Grid/past-grid modes** were removed in favor of the Carousel List; no branching logic remains.
- **Legacy status detection** (`Admin/Status_Detection.php`) is retained only for backwards compatibility logging and no longer influences scheduling.
- **AJAX calendar filtering/FilterManager** have been replaced entirely by REST endpoints.

## Observability Notes

- `inc/Core/meta-storage.php` keeps `_datamachine_event_datetime` synced for SQL queries.
- EventUpsert logs debug/info messages for creation/update skip paths.
