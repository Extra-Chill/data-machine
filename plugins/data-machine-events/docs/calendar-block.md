# Calendar Block

The Calendar block renders a Carousel List of events with progressive enhancement powered by REST routes and modular templates. It pairs server-rendered HTML with scoped JavaScript so filtering, pagination, and navigation stay fast and accessible.

## Carousel List Display

- **Day grouping**: Events are grouped by date using `date-group.php` so each brochure of upcoming or past days remains obvious.
- **Time gap separators**: `time-gap-separator.php` inserts visual dividers when there are large gaps between event start times.
- **Horizontal scroll**: CSS delivers native touch/trackpad scrolling with chevrons, dots, and active state indicators handled by the carousel module.
- **Compact cards**: `event-item.php` renders each event summary with taxonomy badges, time, and CTA buttons linked to more details.

## Server Templates & Helpers

- `event-item.php`, `date-group.php`, `navigation.php`, `pagination.php`, `results-counter.php`, `no-events.php`, `filter-bar.php`, `time-gap-separator.php`, and `modal/taxonomy-filter.php` live under `inc/Blocks/Calendar/templates` and are orchestrated by `inc/Core/Template_Loader`.
- `inc/Core/Taxonomy_Helper` builds hierarchical term data and counts for each template, while `Taxonomy_Badges` renders badge markup that respects `data_machine_events_badge_wrapper_classes`, `data_machine_events_badge_classes`, and `data_machine_events_more_info_button_classes` filters.
- The filter modal uses taxonomy helpers to surface dynamic dependencies, counts, and active state indicators before handing control to the filter modal module.

## REST API Support

- `GET /wp-json/datamachine/v1/events/calendar`: Calendar controller returns `html`, `pagination`, `navigation`, `counter`, and `success` fragments. It accepts `event_search`, `date_start`, `date_end`, `tax_filter` (object), `archive_taxonomy`, `archive_term_id`, `paged`, and `past`.
- `GET /wp-json/datamachine/v1/events/filters`: Filters controller lists taxonomy terms with counts, dependency hints, and hierarchy metadata; accepts `active`, `context`, `date_start`, `date_end`, and `past` so the modal shows accurate controls that respect the current date logic.
- Progressive enhancement: server-rendered HTML works without JavaScript; when scripts run they fetch these routes for instant filtering while preserving their shareable URL state.

## JavaScript Modules

- `inc/Blocks/Calendar/src/frontend.js` bootstraps each `.data-machine-events-calendar`, wiring the following modules:
  - `modules/api-client.js` handles REST requests and swaps fragments.
  - `modules/carousel.js` detects overflow, updates dots, and powers chevrons (with click-and-hold support).
  - `modules/date-picker.js` integrates Flatpickr for date range filters.
  - `modules/filter-modal.js` keeps the taxonomy modal accessible and debounced when filters change.
  - `modules/filter-state.js` centralizes filter state management across URL params, localStorage, and DOM with regex support for both indexed (`tax_filter[taxonomy][0]`) and non-indexed (`tax_filter[taxonomy][]`) array syntax.
  - `modules/navigation.js` powers past/upcoming toggles, calendar navigation, and pagination link handling.
  - `modules/state.js` serializes query parameters, manages history state, and debounces search input.

## Filter Modal & Taxonomy Helpers

The modal is populated via `Taxonomy_Helper`, which computes term hierarchies, counts, and `data_machine_events_excluded_taxonomies` filters, ensuring the modal only shows the taxonomies the block exposes. When filters change, the modal calls the API client to refresh fragments without reloading the page.

## Pagination

- Day-based pagination lives in `inc/Blocks/Calendar/Pagination.php` and produces five full days per page.
- Filters `data_machine_events_pagination_wrapper_classes` and `data_machine_events_pagination_args` allow theme or plugin code to modify wrapper classes or pass additional `paginate_links` arguments.
- Pagination fragments come from the REST calendar response so the JavaScript can replace controls while keeping server-rendered markup for non-JS contexts.

## Progressive Enhancement

Calendar rendering works without any JavaScript; templates are server-rendered during the initial request, making the block SEO-friendly and accessible. JavaScript hooks into the REST API for smoother filtering, search debouncing (500ms), history support, and full control over pagination without forcing reloads.
