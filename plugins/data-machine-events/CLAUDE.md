# CLAUDE.md

Technical guidance for Claude Code when working with the **Data Machine Events** WordPress plugin.

**Version**: 0.3.2

## Migration Status

**REST API**: ✅ Complete - All AJAX eliminated (~950 lines removed), endpoints under `datamachine/v1/events/*`

**Prefix Migration**: ✅ Complete - Fully migrated to `datamachine_events` post type and `datamachine_` prefixes

## OOP Architecture (v0.2.0)

**Major Refactoring**: Complete alignment with Data Machine core's OOP patterns featuring intelligent event upsert operations.

### Base Classes
- **`UpdateHandler`** - Base class for event upsert operations (used by `EventUpsert.php`)
- **`FetchHandler`** - Base class for all data fetching operations (used by `EventImportHandler`)
- **`Step`** - Base class for all pipeline steps (used by `EventImportStep`)
- **`EventImportHandler`** - Abstract base class for all import handlers (extends `FetchHandler`)

### Shared Utilities
- **`TaxonomyHandler`** - Centralized taxonomy management with custom venue handler integration
- **`WordPressPublishHelper`** - Image attachment and publishing utilities (v0.2.7+)

### Handler Discovery System
- **Registry-based Loading**: Handlers registered via `datamachine_handlers` filter
- **Automatic Instantiation**: Framework handles handler creation and execution
- **Dual Architecture Support**: Supports both new FetchHandler-based and legacy execute-based handlers

### Venue Taxonomy Integration
- **Custom TaxonomyHandler**: Specialized venue taxonomy processing
- **Centralized Operations**: Venue creation, lookup, and assignment through unified interface

## Development Commands

```bash
composer install                                 # PHP dependencies

# Calendar block (webpack)
cd inc/Blocks/Calendar && npm install && npm run build
npm run start                                    # Development watch

# Event Details block (webpack with @wordpress/scripts base)
cd inc/Blocks/EventDetails && npm install && npm run build
npm run start                                    # Development watch
npm run lint:js && npm run lint:css             # Linting

./build.sh                                       # Production build to /dist/datamachine-events.zip
```

## Architecture

### Core Principles
- **Block-First**: Event Details block attributes are single source of truth
- **Frontend-Focused**: Display and presentation (Data Machine handles imports)
- **Performance**: Background sync to meta fields for efficient queries
- **PSR-4 Structure**: `DataMachineEvents\` namespace with custom autoloader
- **REST API**: Progressive enhancement - works with/without JavaScript

### Key Components

**Base Classes**:
- `DataMachine\Core\Steps\Update\Handlers\UpdateHandler` - Base for event upsert operations
- `DataMachine\Core\Steps\Fetch\Handlers\FetchHandler` - Base for all data fetching operations
- `DataMachine\Core\Steps\Step` - Base for all pipeline steps
- `Steps\EventImport\EventImportHandler` - Abstract base for all import handlers

**Core Classes**:
- `Admin\Settings_Page` - Event archive behavior, search integration, display preferences, map display type (5 free tile layer options)
- `Core\Event_Post_Type` - Post type registration with selective admin menu control
- `Core\Venue_Taxonomy` - Venue taxonomy with 9 meta fields, admin UI, CRUD operations
- `Core\VenueService` - Venue operations and data management service
- `Core\meta-storage` - Event metadata synchronization and management
- `Blocks\Calendar\Template_Loader` - Modular template system with 7 specialized templates
- `Blocks\Calendar\Taxonomy_Helper` - Taxonomy data processing for filtering systems
- `Core\Taxonomy_Badges` - Dynamic badge rendering with automatic color generation (moved to Core as of v0.3.2)
- `Blocks\Calendar\DisplayStyles\ColorManager` - Centralized color CSS custom properties helper
- `Blocks\Calendar\DisplayStyles\CircuitGrid\BadgeRenderer` - Day badge positioning with ColorManager integration
- `Steps\Upsert\Events\Schema` - Google Event JSON-LD generator
- `Steps\Upsert\Events\Venue` - Centralized venue taxonomy operations
- `Steps\Upsert\Events\EventUpsert` - Intelligent create-or-update handler (extends UpdateHandler, requires Data Machine v0.2.7+)
- `Steps\Upsert\Events\EventUpsertFilters` - EventUpsert handler registration with Data Machine
- `Steps\EventImport\EventImportStep` - Event import step for Data Machine pipeline with handler discovery (extends Step)
- `Steps\EventImport\EventImportHandler` - Abstract base class for import handlers (extends FetchHandler)
- `Steps\EventImport\Handlers\Ticketmaster\Ticketmaster` - Discovery API integration
- `Steps\EventImport\Handlers\DiceFm\DiceFm` - Dice FM event integration
- `Steps\EventImport\Handlers\GoogleCalendar\GoogleCalendar` - Google Calendar integration
- `Steps\EventImport\Handlers\WebScraper\UniversalWebScraper` - AI-powered web scraping
- `Utilities\EventIdentifierGenerator` - Shared event identifier normalization utility
- `Api\Controllers\Calendar` - Calendar REST endpoint controller
- `Api\Controllers\Venues` - Venues REST endpoint controller
- `Api\Controllers\Events` - Events REST endpoint controller

**Shared Utilities** (from Data Machine core):
- `DataMachine\Core\WordPress\TaxonomyHandler` - Centralized taxonomy management
- `DataMachine\Core\WordPress\WordPressPublishHelper` - Image attachment and publishing utilities

**Data Flow**: Data Machine Import → Event Details Block → Schema Generation → Calendar Display

**Schema Flow**: Block Attributes + Venue Taxonomy Meta → Schema → JSON-LD Output

### Blocks & Venues

**Event Details Block**:
- **InnerBlocks Integration**: Rich content editing within event posts
- **15+ Event Attributes**: startDate, endDate, startTime, endTime, venue, address, price, ticketUrl, performer, performerType, organizer, organizerType, organizerUrl, eventStatus, previousStartDate, priceCurrency, offerAvailability
- **Display Controls**: showVenue, showPrice, showTicketLink, showPerformer
- **Hooks**:
  - `apply_filters('datamachine_events_ticket_button_classes', $classes)`
  - `do_action('datamachine_events_action_buttons', $post_id, $ticket_url)`

**Calendar Block**:
- Webpack build system with modular templates (event-item, date-group, pagination, navigation, no-events, filter-bar, time-gap-separator, modal/taxonomy-filter)
- DisplayStyles visual enhancement:
  - `ColorManager.js` - Centralized CSS custom properties helper for fill/stroke var references
  - `CircuitGrid/BadgeRenderer.js` - Day badge positioning with multi-group support and ColorManager integration
  - `CarouselListRenderer.js` - Carousel list display mode
- Template_Loader provides get_template(), include_template(), template_exists(), get_template_path()
- Taxonomy_Helper provides structured data with hierarchy building and post count calculations

**Venues**:
- WordPress taxonomy with 9 meta fields: address, city, state, zip, country, phone, website, capacity, coordinates
- Native WordPress description field for venue descriptions
- Admin interface via Venue_Taxonomy class with full CRUD operations
- Centralized venue handling via Venue class

**Map Display Types** (5 free Leaflet.js tile layers, no API keys):
- OpenStreetMap Standard (default), CartoDB Positron, CartoDB Voyager, CartoDB Dark Matter, Humanitarian OpenStreetMap
- Configurable via Settings → Map Display Type
- Static getter: `Settings_Page::get_map_display_type()`
- Custom 📍 emoji marker for visual consistency

## File Structure

```
datamachine-events/
├── datamachine-events.php                      # Main plugin file with PSR-4 autoloader
├── inc/
│   ├── Admin/                                  # Admin interface classes
│   │   └── class-settings-page.php            # Event settings interface
│   ├── Api/                                    # REST API controllers and routes
│   │   ├── Routes.php                          # API route registration
│   │   └── Controllers/                        # Calendar, Venues, Events controllers
│   ├── Blocks/
│   │   ├── Calendar/                           # Events display (webpack)
│   │   │   ├── Template_Loader.php             # Template loading system
│   │   │   ├── Taxonomy_Helper.php             # Taxonomy data processing
│   │   │   ├── DisplayStyles/                  # Visual enhancement components
│   │   │   │   ├── ColorManager.js             # Centralized color helper
│   │   │   │   └── CircuitGrid/                # CircuitGrid display mode
│   │   │   │       └── BadgeRenderer.js        # Day badge positioning
│   │   │   └── templates/                      # 7 modular templates + modal subdirectory
│   │   ├── EventDetails/                       # Event data storage (webpack + @wordpress/scripts)
│   │   └── root.css                            # Centralized design tokens
│   ├── Core/
│   │   ├── class-event-post-type.php           # Post type registration
│   │   ├── class-venue-taxonomy.php            # Venue taxonomy + 9 meta fields
│   │   ├── class-taxonomy-badges.php           # Dynamic badge rendering
│   │   └── meta-storage.php                    # Event metadata sync
│   ├── steps/
│   │   ├── EventImport/handlers/               # Import handlers (Ticketmaster, DiceFm, WebScraper)
│   │   └── Upsert/Events/                      # EventUpsert handler for create/update operations
│   └── Utilities/                              # Shared utilities
│       └── EventIdentifierGenerator.php        # Event identifier normalization
└── assets/                                     # CSS and JavaScript
```

## WordPress Integration

- **Post Type**: `datamachine_events`
- **Taxonomy**: Venues with 9 meta fields + native description field
- **REST API**: Native WordPress REST + custom unified namespace endpoints (`/wp-json/datamachine/v1/events/*`)
- **Primary Data**: Block attributes (single source of truth), venue taxonomy meta for location
- **Schema Integration**: Google Event structured data from block + venue meta

## REST API Architecture

**Endpoints**:
- `GET /datamachine/v1/events/calendar` - Public calendar filtering (progressive enhancement)
- `GET /datamachine/v1/events/venues/{id}` - Admin venue operations
- `GET /datamachine/v1/events/venues/check-duplicate` - Duplicate venue checking

**Query Parameters**:
- `event_search` - Search by title, venue, taxonomy terms
- `date_start`, `date_end` - Date range filtering (YYYY-MM-DD)
- `tax_filter[taxonomy][]` - Taxonomy term IDs
- `paged` - Page number
- `past` - Show past events when "1"

**Server-Side Processing**:
- WP_Query with meta_query for efficient date filtering
- Taxonomy filtering with tax_query (AND logic)
- SQL-based pagination (~10 events per page vs 500+ in memory)
- Separate count queries for past/future navigation

**Progressive Enhancement**:
- Server-side rendering works without JavaScript (SEO-friendly)
- JavaScript enabled: REST API calls for seamless filtering
- History API updates URL for shareable filter states
- 500ms debounced search input
- Loading states and error handling

**Code Removed** (~950 lines):
- `/inc/blocks/calendar/ajax-handler.php` deleted
- `/inc/blocks/calendar/src/FilterManager.js` deleted (431 lines)
- Client-side filtering functions removed (~400 lines)
- Venue AJAX handlers removed (~120 lines)

## Data Machine Integration

### Handler Discovery System
- **Registry-based Loading**: Handlers registered via `datamachine_handlers` filter
- **Automatic Instantiation**: Framework handles handler creation and execution
- **Dual Architecture Support**: Supports both FetchHandler-based and legacy execute-based handlers

### Import Handlers
- **Ticketmaster**: Discovery API with API key authentication, uses EventIdentifierGenerator for consistent event identity
- **Dice FM**: Event integration with EventIdentifierGenerator normalization
- **UniversalWebScraper**: AI-powered HTML section extraction with HTML hash tracking (ProcessedItems)

**Handler Pattern**: Single-item processing - return first eligible event immediately
```php
foreach ($raw_events as $raw_event) {
    $event_identifier = md5($title . $startDate . $venue);

    // Check processed FIRST
    if (apply_filters('datamachine_is_item_processed', false, $flow_step_id, 'handler', $event_identifier)) {
        continue;
    }

    // Mark as processed and return immediately
    do_action('datamachine_mark_item_processed', $flow_step_id, 'handler', $event_identifier, $job_id);
    array_unshift($data, $event_entry);
    return $data;
}
```

### EventUpsert Pattern
```php
public function executeUpdate(array $parameters, array $handler_config): array {
    // Intelligent create-or-update based on event identity
    $existing_post_id = $this->findExistingEvent($title, $venue, $startDate);

    if ($existing_post_id) {
        // Check if data changed
        if ($this->hasDataChanged($existing_data, $parameters)) {
            $this->updateEventPost($existing_post_id, $parameters, ...);
            return ['action' => 'updated', 'post_id' => $existing_post_id];
        }
        return ['action' => 'no_change', 'post_id' => $existing_post_id];
    }

    // Create new event
    $post_id = $this->createEventPost($parameters, ...);
    return ['action' => 'created', 'post_id' => $post_id];
}
```

### Schema Generation
```php
// Smart parameter routing for engine vs AI decisions
$routing = Schema::engine_or_tool($event_data, $import_data);
// engine: ['startDate', 'venue', 'venueAddress'] - system parameters
// tool: ['description', 'performer', 'organizer'] - AI inference parameters

$schema = Schema::generate_event_schema($block_attributes, $venue_data, $post_id);
```

### Event Identifier Normalization
All import handlers use EventIdentifierGenerator for consistent event identity:
```php
use DataMachineEvents\Utilities\EventIdentifierGenerator;

// Generate normalized identifier
$event_identifier = EventIdentifierGenerator::generate($title, $startDate, $venue);

// Handles variations like "The Blue Note" vs "Blue Note"
// Normalization: lowercase, trim, collapse whitespace, remove articles
```

### Unified Step Execution
All steps extend base Step class and use Data Machine's flat parameter structure:
```php
class EventImportStep extends Step {
    protected function executeStep(): array {
        // Handler discovery and execution logic
        $handler = $this->getHandlerFromRegistry();
        if ($handler instanceof FetchHandler) {
            return $handler->get_fetch_data($pipeline_id, $config, $job_id);
        }
        // Legacy handler support
        return $handler->execute($legacy_payload);
    }
}
```

### EventUpsert Architecture
EventUpsert extends UpdateHandler with WordPressSharedTrait:
```php
class EventUpsert extends UpdateHandler {
    use WordPressSharedTrait;

    protected function executeUpdate(array $parameters, array $handler_config): array {
        // Search for existing event by (title, venue, startDate)
        $existing_post_id = $this->findExistingEvent($title, $venue, $startDate);

        // Field-by-field change detection prevents unnecessary updates
        if ($existing_post_id && !$this->hasDataChanged($existing_data, $parameters)) {
            return ['action' => 'no_change'];
        }

        // Intelligent venue handling with find-or-create
        $venue_result = Venue_Taxonomy::find_or_create_venue($venue_name, $venue_data);
        TaxonomyHandler::addCustomHandler('venue', [$this, 'assignVenueTaxonomy']);

        return ['success' => true, 'action' => 'created|updated|no_change'];
    }
}
```

### REST API Controllers Architecture
Modular controller-based REST API with unified namespace:
```php
// inc/Api/Routes.php - Route registration
register_rest_route('datamachine/v1', '/events/calendar', [
    'methods' => 'GET',
    'callback' => [new Calendar(), 'calendar'],
    'permission_callback' => '__return_true'
]);

// inc/Api/Controllers/Calendar.php - Calendar endpoint logic
class Calendar {
    public function calendar(WP_REST_Request $request) {
        // SQL-based filtering, pagination, template rendering
        return rest_ensure_response(['success' => true, 'html' => $events_html]);
    }
}
```

## Template Architecture

### Single Event Template
Extensibility via action hooks in `templates/single-datamachine_events.php`:
- `datamachine_events_before_single_event` - After get_header()
- `datamachine_events_after_event_article` - After event content
- `datamachine_events_related_events` - In aside section
- `datamachine_events_after_single_event` - Before get_footer()

### Calendar Block Templates
7 specialized templates + modal subdirectory:
- `event-item.php` - Individual event display
- `date-group.php` - Day-grouped container
- `pagination.php` - Event pagination
- `navigation.php` - Calendar navigation
- `no-events.php` - Empty state
- `filter-bar.php` - Filtering interface
- `time-gap-separator.php` - Time gap separator for carousel-list mode
- `modal/taxonomy-filter.php` - Advanced filter modal

**Template Loading**:
```php
$content = Template_Loader::get_template('event-item', $variables);
Template_Loader::include_template('date-group', $group_data);
```

## Build Process

`./build.sh` creates optimized package in `/dist` directory:
1. Install production composer dependencies (`--no-dev --optimize-autoloader`)
2. Build Calendar block (webpack)
3. Build Event Details block (webpack with @wordpress/scripts)
4. Copy files with rsync (excludes development files)
5. Create `datamachine-events.zip` file
6. Generate build info and restore development dependencies

## Security Standards

- Nonce verification on all forms
- Input sanitization with `wp_unslash()` before `sanitize_text_field()`
- Capability checks for admin functions
- WordPress application password or cookie authentication for REST API

## Key Development Principles

- **Block-First**: Event Details block attributes as single source of truth
- **Performance**: SQL queries filter at database level before sending to browser
- **Progressive Enhancement**: Works with/without JavaScript
- **Modular Templates**: Clean separation between data processing and HTML presentation
- **REST API Aligned**: 100% compliance with Data Machine ecosystem strategy
- **Zero AJAX**: Complete REST API migration with ~950 lines removed

---

**Version**: 0.3.2
**For ecosystem architecture, see root CLAUDE.md file**
