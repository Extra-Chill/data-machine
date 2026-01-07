# Data Machine Events

Frontend-focused WordPress events plugin with a **block-first architecture** that ties Event Details data storage to Calendar block progressive enhancement and REST API-driven filtering.

**Version**: 0.8.42

## Architecture Overview

- **Blocks First**: `inc/Blocks/EventDetails` captures authoritative event data while `inc/Blocks/Calendar` renders Carousel List views informed by `_datamachine_event_datetime` post meta and REST responses.
- **Data Machine Imports**: The pipeline runs through `inc/Steps/EventImport/EventImportStep` and registered handlers. Each handler builds a `DataPacket`, normalizes titles/dates/venues via `Utilities/EventIdentifierGenerator`, marks items processed, and returns immediately after a valid event to enable incremental syncing. All handlers automatically skip events with "closed" in the title.
- **EventUpsert Workflow**: `Steps/Upsert/Events/EventUpsert` merges engine data snapshots, runs field-by-field change detection, delegates taxonomy assignments to `DataMachine\Core\WordPress\TaxonomyHandler`, uses `WordPressPublishHelper` for images, and keeps `_datamachine_event_datetime` synced for performant calendar queries. Promoter handling defaults to `skip` unless configured otherwise.

## Import Pipeline

1. `EventImportStep` discovers handlers that register themselves via `HandlerRegistrationTrait` and exposes configuration through handler settings classes.
2. **Handlers**: AEG/AXS, Dice FM, DoStuff Media API, Eventbrite, EventFlyer, Freshtix, ICS Calendar, OpenDate, Prekindle, RedRocks, SingleRecurring, Ticketmaster (with automatic API pagination), and Universal Web Scraper.
3. **Universal Web Scraper**: A high-fidelity extraction engine with specialized extractors for Bandzoogle, GoDaddy, SpotHopper, Google Calendar (Embedded), WordPress (Tribe), Timely, Squarespace, Firebase, and Wix. It includes automatic pagination and WordPress API discovery fallbacks.
4. Each handler applies `EventIdentifierGenerator::generate($title, $startDate, $venue)` to deduplicate, merges venue metadata into `EventEngineData`, and forwards standardized payloads to `EventUpsert`.
4. `VenueService`/`Venue_Taxonomy` find or create venue terms and store nine meta fields (address, city, state, zip, country, phone, website, capacity, coordinates) for use in blocks and REST endpoints.
5. `EventUpsertSettings` exposes status, author, taxonomy, and image download toggles via `WordPressSettingsHandler` so runtime behavior remains configurable.

## REST APIs

Routes live under `/wp-json/datamachine/v1/events/*` and are registered in `inc/Api/Routes.php` with controllers in `inc/Api/Controllers`.

- `GET /events/calendar`: Calendar controller returns fragments (`html`, `pagination`, `navigation`, `counter`) plus success metadata; accepts `event_search`, `date_start`, `date_end`, `tax_filter` (object), `archive_taxonomy`, `archive_term_id`, `paged`, and `past`.
- `GET /events/filters`: Filters controller lists taxonomy terms with counts, hierarchy, and dependency hints; accepts `active`, `context`, `date_start`, `date_end`, and `past` and powers the filter modal in the Calendar block.
- `GET /events/venues/{id}`: Venues controller (capability `manage_options`) returns venue description and nine meta fields including coordinates from `Venue_Taxonomy::get_venue_data()`.
- `GET /events/venues/check-duplicate`: Venues controller checks `name`/`address` combinations, sanitizes input, and returns `is_duplicate`, `existing_venue_id`, and friendly messaging to avoid duplicates during admin venue creation.
- `POST /events/geocode/search`: Geocoding controller validates the Nominatim `query` and returns `display_name`, `lat`, `lon`, and structured address parts for venue creation flows; relies on OpenStreetMap data.

## Blocks & Frontend

- **Calendar Block** (`inc/Blocks/Calendar`): Carousel List display with day grouping, time-gap separators, pagination, filter modal, and server-rendered templates (`event-item`, `date-group`, `pagination`, `navigation`, `results-counter`, `no-events`, `filter-bar`, `time-gap-separator`, `modal/taxonomy-filter`).
- **Templates & Helpers**: `Template_Loader`, `Taxonomy_Helper`, and `Taxonomy_Badges` sanitize variables, build taxonomy hierarchies, and render badges with filters for wrapper/classes and button styles.
- **JavaScript Modules**: `src/frontend.js` initializes `.datamachine-events-calendar` instances and orchestrates `modules/api-client.js`, `modules/carousel.js`, `modules/date-picker.js`, `modules/filter-modal.js`, `modules/navigation.js`, and `modules/state.js` for REST communication, carousel controls, Flatpickr integration, filter modal accessibility, navigation handling, and URL state.
- **Progressive Enhancement**: Server-first rendering works without JavaScript; REST requests enrich filtering and pagination when scripts are active while preserving history state and debounced search.
- **Event Details Block** (`inc/Blocks/EventDetails`): Provides 15+ attributes (dates, venue, pricing, performer/organizer metadata, status, display toggles) plus InnerBlocks. Leaflet assets (`leaflet.css`, `leaflet.js`, `assets/js/venue-map.js`) and root CSS tokens (`inc/Blocks/root.css`) load conditionally via `enqueue_root_styles()` to render venue maps and maintain consistent styling.

## Documentation & Guides

Handler and feature guides live under `/docs`, covering the REST API (`docs/rest-api.md`), block behavior (`docs/calendar-block.md`, `docs/event-details-block.md`), pipeline helpers (`docs/event-identifier-generator.md`, `docs/event-schema-provider.md`, `docs/pipeline-components-js.md`), pagination (`docs/pagination-system.md`), venue management (`docs/venue-management.md`, `docs/venue-parameter-provider.md`), geocoding (`docs/geocoding-integration.md`), and handler-specific notes (e.g., `docs/ticketmaster-handler.md`).

## Project Structure

```
datamachine-events/
├── datamachine-events.php           # Bootstraps constants, loads meta storage, and registers REST routes
├── inc/
│   ├── Admin/                       # Settings page, admin bar, capability checks
│   ├── Api/                         # Routes + controllers (Calendar, Venues, Filters, Geocoding)
│   ├── Blocks/
│   │   ├── Calendar/                # Carousel block templates, JS modules, pagination
│   │   ├── EventDetails/             # Schema-aware block with webpack build
│   │   └── root.css                 # Shared design tokens
│   ├── Core/                        # Post type, taxonomies, meta storage, helpers
│   ├── Steps/
│   │   ├── EventImport/             # EventImportStep + registered handlers
│   │   └── Upsert/Events/            # EventUpsert handler, settings, filters, schema helpers
│   └── Utilities/                   # EventIdentifierGenerator, schema helpers, taxonomy helpers
├── assets/                          # Admin JS/CSS (pipeline components, venue autocomplete/map)
├── docs/                            # Handler and feature documentation
└── build.sh                         # Production packaging script
```

## Commands

```bash
composer install --no-dev --optimize-autoloader  # PHP dependencies (production, matches build.sh)
cd inc/Blocks/Calendar && npm ci && npm run build
cd ../EventDetails && npm ci && npm run build
./build.sh                                       # Creates /dist/datamachine-events.zip
```

Watchers should run inside their respective block directories (`inc/Blocks/Calendar` and `inc/Blocks/EventDetails`).
