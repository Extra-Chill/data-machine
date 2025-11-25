# Data Machine Events Copilot Instructions

## Architecture Snapshot
- Block-first data flow lives in `datamachine-events.php`: Event Details block → `_datamachine_event_datetime` meta (`inc/Core/meta-storage.php`) → Calendar templates (`inc/Blocks/Calendar/templates`) → theme's `single.php` template.
- All PHP classes sit under the `DataMachineEvents\` namespace with Composer PSR-4 autoloading; never require class files manually unless they are non-class helpers (see handler `*Filters.php`).
- Calendar rendering is centralized through `DataMachineEvents\Blocks\Calendar\Template_Loader`; extend output by adding/including templates there instead of echoing HTML inline.
- Root design tokens in `inc/blocks/root.css` are consumed by both CSS and JS (circuit grid sizing, badge colors) so update variables there before tweaking individual block styles.

## Data Machine Integration
- Custom pipeline pieces register via filters in `inc/Steps/EventImport/EventImportFilters.php` and `inc/Steps/Upsert/Events/EventUpsertFilters.php` (`datamachine_step_types`, `datamachine_handlers`); follow that pattern when adding handlers or step types.
- Import handlers (e.g., `Steps/EventImport/Handlers/Ticketmaster/Ticketmaster.php`) must single-item process, use `EventIdentifierGenerator::generate()` for consistent event identity, call `isItemProcessed`/`markItemProcessed`, and return a DataPacket array.
- EventUpsert logic lives in `Steps/Upsert/Events/EventUpsert.php`: it extends the core `UpdateHandler`, searches for existing events by (title, venue, startDate), performs field-by-field change detection, generates Event Details block markup, and syncs venues via `Core\Venue_Taxonomy::find_or_create_venue`.
- Event identity normalization uses `Utilities\EventIdentifierGenerator::generate($title, $startDate, $venue)` across all import handlers for consistent duplicate detection (lowercase, trim, collapse whitespace, remove articles).
- `Schema::generate_event_schema()` combines block attributes, venue taxonomy meta, and engine data—reuse it instead of rebuilding JSON-LD.

## REST + Frontend Behavior
- Public endpoints live in `inc/Api/Routes.php` and `inc/Api/Controllers/` under the unified `datamachine/v1` namespace: `/events/calendar`, `/events/venues/{id}`, `/events/venues/check-duplicate`. Keep new endpoints in this namespace and reuse existing arg sanitizers.
- Calendar frontend (`inc/blocks/calendar/src/frontend.js`) progressively enhances server-rendered markup: debounced search, flatpickr date ranges, taxonomy modal, History API updates, and rehydrates DisplayStyles (CircuitGrid/Carousel). Any new UI must update both the server template + JS refresh path.
- Event Details block view (`inc/blocks/EventDetails/render.php`) is server-rendered; JS enhancements such as Leaflet maps live in `assets/js/venue-map.js` and respect `Settings_Page::get_map_display_type()` (five free tile layers, 📍 emoji marker). Trigger `jQuery(document).trigger('datamachine-events-loaded')` after injecting events so maps re-init.

## WordPress Conventions
- Post type `datamachine_events` and venue taxonomy registration reside in `inc/Core/Event_Post_Type.php` and `inc/Core/Venue_Taxonomy.php`; add meta fields through the taxonomy class so admin CRUD + REST meta stay in sync.
- `_datamachine_event_datetime` meta powers SQL pagination; whenever block attributes change outside Gutenberg, run `datamachine_events_sync_datetime_meta()` or the migration helper in `inc/Core/meta-storage.php`.
- Settings UI (`inc/Admin/Settings_Page.php`) controls archive/search inclusion, display mode, and map tiles. Use `Settings_Page::get_setting()` helpers instead of re-reading `get_option`.
- Taxonomy badge markup is centralized in `Blocks\Calendar\Taxonomy_Badges`; extend styling via filters `datamachine_events_badge_wrapper_classes` and `datamachine_events_badge_classes`, not by editing templates.

## Build & Dev Workflow
- Install dependencies with `composer install`, then run `npm install && npm run build` (or `npm run start`) separately in `inc/blocks/calendar` and `inc/blocks/EventDetails`.
- `build.sh` orchestrates production builds: Composer `--no-dev`, `npm ci --silent` for both blocks, rsync into `dist/datamachine-events`, zip, and restore dev deps. Use it before shipping to ensure `/dist/datamachine-events.zip` is fresh.
- Frontend linting lives in block-level `package.json` scripts (`npm run lint:js`, `npm run lint:css`). Keep tooling consistent between both block packages.

## Practical Tips
- Keep hooks/functions prefixed `datamachine_`/`datamachine_events` to honor the completed prefix migration.
- When touching REST responses, remember the calendar endpoint returns rendered HTML chunks (`html`, `pagination`, `navigation`, `counter`); update both PHP templates and corresponding DOM swap logic.
- Use `assets/js/venue-autocomplete.js` + `venue-selector.js` (enqueued via `admin_enqueue_scripts` in `EventImportFilters.php`) for admin UX; don't reinvent venue lookup widgets.
- If you add new visual modes, drop CSS into `inc/blocks/calendar/style.css` or a `DisplayStyles/*Renderer.js` (integrate with ColorManager for consistent color handling) so the existing renderer import system can lazy-load it.
- Always sanitize AI/tool input through the helpers in `EventUpsert` and core WordPress APIs; Schema + REST rely on sanitized dates/times to keep pagination accurate.
