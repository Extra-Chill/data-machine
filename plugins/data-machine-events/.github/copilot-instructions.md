# Data Machine Events – Copilot Guide

## System Map
- `data-machine-events.php` wires the PSR-4 autoloader (`DataMachineEvents\` namespace) and boots admin, blocks, API, and step registrations; never `require` class files manually.
- Event data flows Event Details block → `_datamachine_event_datetime` meta sync (`inc/Core/meta-storage.php`) → Calendar templates (`inc/Blocks/Calendar/templates`) → theme `single.php`; keep block attributes the single source of truth.
- Root tokens live in `inc/Blocks/root.css` and power both CSS + JS sizing/color logic; adjust variables there before touching individual block styles.

## Data Machine Integration
- Pipelines discover handlers via filters; import handlers extend `Steps/EventImport/EventImportHandler.php` and call `HandlerRegistrationTrait::registerHandler()` inside constructors.
- Each import handler (Ticketmaster, Dice, Eventbrite, WebScraper, etc.) must single-item process, generate an identifier via `Utilities/EventIdentifierGenerator::generate()`, check `datamachine_is_item_processed`, then `datamachine_mark_item_processed` before returning the payload.
- Event upserts live in `Steps/Upsert/Events/EventUpsert.php` (extends `UpdateHandler`); it searches by title+venue+startDate, runs field-level diffing, syncs venues through `Core/Venue_Taxonomy::find_or_create_venue`, and rebuilds Event Details block markup.
- `Core/EventSchemaProvider.php` centralizes Schema.org JSON-LD + AI tool parameter definitions; never hand-roll schema.
- When exposing tooling, register via `inc/Steps/Upsert/Events/EventUpsertFilters.php` or the handler constructor so Data Machine discovery picks it up.

## REST & Blocks
- All routes register through `inc/Api/Routes.php` under `datamachine/v1`; reuse existing controller patterns in `inc/Api/Controllers/Calendar|Events|Venues` for sanitizers, capability checks, and response shapes.
- Calendar endpoint returns rendered fragments (`html`, `pagination`, `navigation`, `counter`); any template tweak in `inc/Blocks/Calendar/templates/` must be mirrored in the JS replacement path.
- Frontend JS lives in `inc/Blocks/Calendar/src/` with modules for API access, carousel, date picker, filter modal, navigation, and state; `frontend.js` simply orchestrates imports—add new behavior as a module, then wire it in.
- Event Details block is server-rendered (`inc/Blocks/EventDetails/render.php`) and enhanced by admin scripts in `assets/js/venue-map.js`, `venue-autocomplete.js`, and `venue-selector.js`; triggering `jQuery(document).trigger('data-machine-events-loaded')` lets maps and selectors re-init after AJAX inserts.

## WordPress Domain Rules
- Post type + taxonomy live in `inc/Core/Event_Post_Type.php` and `inc/Core/Venue_Taxonomy.php`; add venue meta via that class so REST + admin stay aligned.
- `_datamachine_event_datetime` meta drives SQL pagination; when editing events outside Gutenberg, call `data_machine_events_sync_datetime_meta()` from `Core/meta-storage.php` to keep queries accurate.
- Badges and taxonomy markup are centralized in `inc/Blocks/Calendar/Taxonomy_Badges.php`; extend via `data_machine_events_badge_wrapper_classes` / `data_machine_events_badge_classes` filters instead of editing templates.
- Settings UI lives in `inc/Admin/Settings_Page.php`; always read settings via the provided getters (map display type, archive behavior, etc.).

## Build & Dev Workflow
- Install PHP deps with `composer install`; each block (`inc/Blocks/Calendar`, `inc/Blocks/EventDetails`) has its own `npm install`, `npm run build`, and `npm run start` scripts (Calendar uses webpack, EventDetails uses `@wordpress/scripts`).
- Run `npm run lint:js` / `npm run lint:css` inside each block package; there is no monorepo-level lint aggregation.
- `build.sh` performs the release flow: composer install --no-dev, `npm ci --silent` for both blocks, rsync to `dist/data-machine-events`, zip, then restore dev deps—use it before handing off artifacts.
- Blocks rely on transpiled assets committed under `build/`; when tweaking JS/CSS, regenerate bundles before pushing.

## Practical Guardrails
- Adhere to `datamachine_`/`data_machine_events` prefixes for hooks, functions, and options—legacy aliases are gone.
- Whenever you touch REST, propagate data contract changes to both PHP controllers and `inc/Blocks/Calendar/src/modules/api-client.js`.
- Use WordPress HTTP + sanitization helpers (`wp_unslash`, `sanitize_text_field`, `rest_sanitize_boolean`) in controllers and handlers; AI payloads often contain user-provided strings.
- Venue UI already ships autocomplete/map components—reuse `assets/js/venue-*` modules instead of introducing new admin widgets.
- Keep CSS display modes centralized in `inc/Blocks/Calendar/style.css`; Carousel List is the only supported mode, so new layouts should extend that pattern rather than add parallel systems.
