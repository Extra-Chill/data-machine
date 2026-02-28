# CLAUDE.md

Technical guidance for Claude Code when working with the **Data Machine Events** WordPress plugin.

**Version**: 0.9.19

## Plugin Bootstrap

- **Entry point**: `data-machine-events.php` defines constants (`DATA_MACHINE_EVENTS_VERSION`, plugin paths), loads `inc/Core/meta-storage.php`, and requires `inc/Api/Routes.php` when the file exists.
- **`DATAMACHINE_Events` class**: Singleton bootstrapped via `init`, it registers the `data_machine_events` post type, `venue`/`promoter` taxonomies, enqueues admin assets, and registers Calendar/Event Details blocks. It also adds root styles, Leaflet assets, and the venue map script whenever a block or singular event is rendered.
- **Block registration**: `register_block_type()` reads `inc/Blocks/Calendar/block.json` and `inc/Blocks/EventDetails/block.json`, enqueues shared `inc/Blocks/root.css`, and hooks `enqueue_root_styles()` to `wp_enqueue_scripts`/`enqueue_block_assets`. Leaflet CSS/JS plus `assets/js/venue-map.js` are enqueued only when the Event Details block or a `data_machine_events` post is present.
- **Query Parameter Sanitization**: `data_machine_events_sanitize_query_params()` recursively sanitizes nested query arrays while preserving structure, enabling safe handling of multi-dimensional filter parameters (e.g., `tax_filter[genre][0]`). Used in `Pagination::sanitize_query_params()` and the navigation template.

## Data Machine Integration

- **`init_data_machine_integration()`**: Runs at priority 25 on `init`. After verifying `DATAMACHINE_VERSION`, it loads `EventImportFilters`, instantiates all import handlers, loads EventUpsert filters, and registers the EventUpsert handler from `inc/Steps/Upsert/Events/EventUpsert.php`.
- **Event import handlers**: `load_event_import_handlers()` instantiates the following `FetchHandler` implementations (all located under `inc/Steps/EventImport/Handlers`):
- **Universal Web Scraper Architecture**: A multi-layered system that prioritizes structured data extraction (Schema.org JSON-LD/Microdata and 22 specialized extractors) before falling back to AI-enhanced HTML section parsing, then AI vision flyer extraction as final fallback (@since 0.9.18). It coordinates fetching, pagination, and normalization via a centralized `StructuredDataProcessor`. The scraper implements a "Smart Fallback" mechanism that retries requests with standard headers if browser-mode spoofing is blocked by captchas (SiteGround/Cloudflare) or encounters 403 errors.
- **VisionExtractor** (@since 0.9.18): Final fallback when structured data and HTML extraction fail. Uses `ImageCandidateFinder` to score potential flyer images based on size, context, and keyword heuristics. `VisionExtractionProcessor` downloads image to persistent storage and stores the path in engine data via `storeEngineData()`. AI processing is delegated to the pipeline's AI step. Pipelines using vision extraction require an AI step configured with event extraction prompts.
- **SquareOnlineExtractor** (@since 0.9.19): Specialized extractor for Square Online sites (*.square.site). Parses embedded `__BOOTSTRAP_STATE__` JSON to find image URLs that aren't visible in standard HTML `<img>` tags. Integrates into vision extraction as an image candidate source, enabling flyer detection on Square Online venue pages.
  - `DiceFm\DiceFm`
  - `DoStuffMediaApi\DoStuffMediaApi`
  - `Eventbrite\Eventbrite`
  - `EventFlyer\EventFlyer`
  - `IcsCalendar\IcsCalendar`
  - `SingleRecurring\SingleRecurring` (@since v0.6.3)
  - `Ticketmaster\Ticketmaster` (with automatic API pagination up to MAX_PAGE=19)
  - `WebScraper\\UniversalWebScraper` (extractor priority: AEG/AXS, RedRocks, Freshtix, Firebase, Embedded Calendar, Squarespace, Craftpeak, SpotHopper, Gigwell, Bandzoogle, GoDaddy, Timely, Elfsight, JSON-LD, WordPress/Tribe, Prekindle, Wix, MusicItem, RHP, OpenDate.io, Microdata; then HTML section fallback; automatic pagination up to MAX_PAGES=20; automatic WordPress API discovery fallback)
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
- `Taxonomy_Helper` and `Taxonomy_Badges`: Build hierarchical filter data, term counts, and render badge HTML. Filters such as `data_machine_events_badge_wrapper_classes`, `data_machine_events_badge_classes`, and `data_machine_events_more_info_button_classes` customize badge markup and CTA styling.

## Blocks & Frontend

- **Calendar block** (`inc/Blocks/Calendar`): CSS-driven Carousel List with day grouping, time-gap separators, pagination, and server-rendered HTML. Templates include `event-item`, `date-group`, `navigation`, `results-counter`, `pagination`, `no-events`, `filter-bar`, `time-gap-separator`, and `modal/taxonomy-filter`.
- **JavaScript modules**: `src/frontend.js` bootstraps `.data-machine-events-calendar` instances and wires `modules/api-client.js`, `modules/carousel.js`, `modules/date-picker.js`, `modules/filter-modal.js`, `modules/filter-state.js`, `modules/navigation.js`, and `modules/state.js` for REST calls, carousel scrolling, Flatpickr integration, modal accessibility, filter state management, navigation updates, and History API-aware state management (including 500ms debounced search). `FilterStateManager` centralizes URL, localStorage, and DOM-based state with regex support for both indexed (`tax_filter[taxonomy][0]`) and non-indexed (`tax_filter[taxonomy][]`) array syntax.
- **Progressive enhancement**: Server-rendered fragments allow the block to work without JavaScript, while REST API responses enrich filtering, pagination, search, and URL state when scripts are active.
- **Event Details block** (`inc/Blocks/EventDetails`): 15+ attributes (dates/times, venue, address, price, priceCurrency, offerAvailability, ticketUrl, performer, performerType, organizer, organizerType, organizerUrl, eventStatus, previousStartDate, showVenue, showPrice, showTicketLink) plus InnerBlocks for rich content. Attributes persist to the block and sync `_datamachine_event_datetime` via `inc/Core/meta-storage.php` for performant queries. InnerBlocks content extracts to plain text for Schema.org `description` field via `wp_strip_all_tags()`, improving structured data quality.
- **Schema & maps**: `enqueue_root_styles()` loads `leaflet.css`, `leaflet.js`, and `assets/js/venue-map.js` when Event Details or `data_machine_events` posts are present so Leaflet maps render with consistent markers and controls.

## REST API

Routes in `inc/Api/Routes.php` register controllers from `inc/Api/Controllers/{Calendar,Venues,Filters,Geocoding}` under the `datamachine/v1` namespace.

- `GET /wp-json/datamachine/v1/events/calendar`: Public endpoint powering Calendar block filtering. Accepts `event_search`, `date_start`, `date_end`, `tax_filter` (object), `archive_taxonomy`, `archive_term_id`, `paged`, and `past`.
- `GET /wp-json/datamachine/v1/events/venues/{id}`: Admin-only (`manage_options`). Returns venue description plus metadata (address, city, state, zip, country, phone, website, capacity, coordinates via `Venue_Taxonomy::get_venue_data`).
- `GET /wp-json/datamachine/v1/events/venues/check-duplicate`: Admin-only duplicate check requiring `name` (and optional `address`), sanitizes input, and responds with `is_duplicate`, `existing_venue_id`, and contextual messaging.
- `GET /wp-json/datamachine/v1/events/filters`: Public taxonomy filters powered by `Filters` controller. Accepts `active`, `context`, `date_start`, `date_end`, `past`, and returns taxonomy term metadata with counts, parent relationships, and dependency hints.
- `POST /wp-json/datamachine/v1/events/geocode/search`: Admin-accessed `Geocoding::search()` calling OpenStreetMap Nominatim with sanitized `query`, returning `display_name`, `lat`, `lon`, and structured address segments.

**Security**: Admin endpoints check `current_user_can('manage_options')`. All args sanitize via `sanitize_text_field`, `absint`, `sanitize_key`, or callback sanitizers. The controllers log and respond with consistent JSON structures.

## Abilities

Abilities in `inc/Abilities` expose functionality via WordPress 6.9 Abilities API. This pattern is becoming a core tenet of Data Machine architecture, ensuring business logic is accessible via REST/WP-CLI/ability callers, while chat tools can wrap abilities for AI consumption.

### Abilities API Integration Pattern

All ability classes follow this standardized pattern:

```php
class ExampleAbilities {
    private static bool $registered = false;  // Prevents duplicate registration

    public function __construct() {
        if ( ! self::$registered ) {
            $this->registerAbility();
            self::$registered = true;
        }
    }

    private function registerAbility(): void {
        add_action(
            'wp_abilities_api_init',
            function () {
                wp_register_ability(
                    'data-machine-events/ability-slug',
                    array(
                        'label'               => __( 'Ability Name', 'data-machine-events' ),
                        'description'         => __( 'What this ability does', 'data-machine-events' ),
                        'category'            => 'datamachine',
                        'input_schema'        => array( /* JSON Schema */ ),
                        'output_schema'       => array( /* JSON Schema */ ),
                        'execute_callback'    => array( $this, 'executeMethod' ),
                        'permission_callback' => function () {
                            return current_user_can( 'manage_options' );
                        },
                        'meta'                => array( 'show_in_rest' => true ),
                    )
                );
            }
        );
    }
}
```

Key integration points:
- **Static registration flag**: Prevents duplicate ability registration when class is instantiated multiple times (e.g., by chat tools and CLI commands).
- **Hook timing**: Abilities register on `wp_abilities_api_init` hook, which fires after WordPress initializes.
- **Permission callback**: Enforces capability requirements (typically `manage_options` for admin operations).
- **Input/output schemas**: Define JSON Schema for validation and documentation.
- **Instantiation**: Classes are instantiated in `DATAMACHINE_Events::load_data_machine_components()` after verifying Data Machine core is active.

### Available Abilities

- **EventScraperTest**: Tests universal web scraper compatibility with a target URL. Ability: `datamachine/test-event-scraper`. Chat tool: `test_event_scraper`.
- **TimezoneAbilities**: Finds events with missing venue timezone and fixes them with geocoding support. Abilities: `data-machine-events/find-broken-timezone-events`, `data-machine-events/fix-event-timezone`. Chat tools: `find_broken_timezone_events`, `fix_event_timezone`.
- **EventQueryAbilities**: Query events by venue with filtering options. Ability: `data-machine-events/get-venue-events`. Chat tool wrapper in `inc/Api/Chat/Tools/GetVenueEvents.php`.
- **EventHealthAbilities**: Scans events for data quality issues (missing time, suspicious midnight, late night times, missing venue, etc.). Ability: `data-machine-events/event-health-check`. CLI wrapper: `wp data-machine-events health-check`.
- **EventUpdateAbilities**: Updates event block attributes and venue assignment, supporting single or batch updates. Ability: `data-machine-events/update-event`. CLI wrapper: `wp data-machine-events update-event`.
- **BatchTimeFixAbilities** (@since 0.9.16): Batch correction for events with systematic timezone/offset issues. Filters by venue (required), date range, and optionally source URL pattern. Supports offset-based fixes (`+6h`, `-1h`) or explicit time replacement. Ability: `data-machine-events/batch-time-fix`. CLI wrapper: `wp data-machine-events batch-time-fix`.

## AI Chat Tools

Chat tools in `inc/Api/Chat/Tools` provide AI-driven venue and event management capabilities via the Data Machine's AI framework. Tools are self-registering via `ToolRegistrationTrait`:

- **VenueHealthCheck**: Scans all venues for data quality issues (missing address, coordinates, or timezone) and returns detailed counts and lists of problematic venues. Optional `limit` parameter controls maximum venues returned per category (default: 25).
- **UpdateVenue**: Updates venue name and/or meta fields (address, city, state, zip, country, phone, website, capacity, coordinates, timezone). Accepts venue identifier (term ID, name, or slug) and any combination of fields to update. Address field changes trigger automatic geocoding via `Venue_Taxonomy::update_venue_meta`.
- **EventHealthCheck**: Scans events for data quality issues and returns detailed reports.
- **UpdateEvent**: Updates event fields and metadata.
- **GetVenueEvents**: Get events attached to a specific venue. Wraps `EventQueryAbilities::executeGetVenueEvents()`. Accepts venue identifier (term ID, name, or slug) and optional parameters for limiting results, status filtering, and date range filtering.

## Templates & Rendering

- `Template_Loader` renders the eight modular Calendar templates and ensures variable hygiene.
- `Taxonomy_Helper` builds taxonomy hierarchies and counts; `Taxonomy_Badges` renders badges with consistent color classes.
- Pagination (five days per page) lives in `inc/Blocks/Calendar/Pagination.php` and respects `data_machine_events_pagination_wrapper_classes` and `data_machine_events_pagination_args` filters.

## Geocoding & Maps

- `assets/js/venue-map.js` initializes Leaflet maps using coordinates from venue term meta, ensuring consistent markers and popups.
- Leaflet CSS/JS assets load via CDN (Leaflet 1.9.4) when needed.
- `Geocoding::search()` provides admin geocoding via OpenStreetMap, powering venue creation/edit interfaces.

## Filters & Hooks

- `data_machine_events_calendar_query_args`: Modify calendar WP_Query arguments before template rendering.
- `data_machine_events_excluded_taxonomies`: Control taxonomies excluded from badge lists or filter modals.
- Badge/action filters: `data_machine_events_badge_wrapper_classes`, `data_machine_events_badge_classes`, `data_machine_events_more_info_button_classes`, `data_machine_events_ticket_button_classes`, and `data_machine_events_action_buttons` customize badge/CTA markup.
- Pagination filters: `data_machine_events_pagination_wrapper_classes`, `data_machine_events_pagination_args` customize pagination markup.

## WP-CLI Commands

- **Test Event Scraper Command** (`wp data-machine-events test-event-scraper`): `inc/Cli/UniversalWebScraperTestCommand.php` runs the `WebScraper\\UniversalWebScraper` handler against a `--target_url`, creates a job record for context, prints a packet summary (structured vs HTML fallback), and can optionally run `EventUpsert` (`--upsert`) to validate end-to-end venue coverage. Aligns with ability (`datamachine/test-event-scraper`) and chat tool (`test_event_scraper`) naming.
- **Get Venue Events Command**: `inc/Cli/GetVenueEventsCommand.php` queries events for a specific venue. Wraps `EventQueryAbilities::executeGetVenueEvents()`. Usage: `wp data-machine-events get-venue-events <venue>` or `--venue=<venue>`. Options: `--limit` (default 25, max 100), `--status` (any/publish/future/draft/pending/private), `--published_before`, `--published_after`.
- **Health Check Command**: `inc/Cli/HealthCheckCommand.php` scans events for data quality issues. Wraps `EventHealthCheck` chat tool. Usage: `wp data-machine-events health-check`. Options: `--scope` (upcoming/all/past, default: upcoming), `--days_ahead` (default: 90), `--limit` (default: 25), `--category` (late_night_time/midnight_time/missing_time/suspicious_end_time/missing_venue/missing_description/broken_timezone), `--format` (table/json, default: table).
- **Update Event Command**: `inc/Cli/UpdateEventCommand.php` updates event details. Wraps `UpdateEvent` chat tool. Usage: `wp data-machine-events update-event <event_ids> [--startTime=<time>]`. Accepts single ID or comma-separated IDs. Options: `--startDate`, `--startTime`, `--endDate`, `--endTime`, `--venue`, `--price`, `--ticketUrl`, `--performer`, `--performerType`, `--eventStatus`, `--eventType`, `--description`, `--format` (table/json, default: table).
- **Batch Time Fix Command** (@since 0.9.16): `inc/Cli/BatchTimeFixCommand.php` batch-fixes event times with offset correction or explicit replacement. Wraps `BatchTimeFixAbilities`. Usage: `wp data-machine-events batch-time-fix --venue=<venues> --offset=<offset>`. Required: `--venue` (comma-separated), at least one of `--before`/`--after`. Fix modes: `--offset` (e.g., `+6h`, `-1h`, `+30m`) or `--new-time` (requires `--where-time`). Options: `--source-pattern` (SQL LIKE, e.g., `%.ics`), `--where-time` (filter by current time), `--limit` (default: 100), `--dry-run` (preview, default), `--execute` (apply changes), `--format` (table/json).

## Build Commands

```bash
composer install --no-dev --optimize-autoloader
cd inc/Blocks/Calendar && npm ci && npm run build
cd ../EventDetails && npm ci && npm run build
./build.sh
```

## Removed Features (Completed)

- **Circuit Grid/past-grid modes** were removed in favor of the Carousel List; no branching logic remains.
- **AJAX calendar filtering/FilterManager** have been replaced entirely by REST endpoints.

## Observability Notes

- `inc/Core/meta-storage.php` keeps `_datamachine_event_datetime` synced for SQL queries.
- EventUpsert logs debug/info messages for creation/update skip paths.
