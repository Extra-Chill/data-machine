# CLAUDE.md

Technical guidance for Claude Code when working with the **Data Machine Events** WordPress plugin.

**Version**: 0.1.1

## Migration Status

**REST API**: ✅ Complete - All AJAX eliminated (~950 lines removed), endpoints under `datamachine/v1/events/*`

**Prefix Migration**: ✅ Complete - Fully migrated to `datamachine_events` post type and `datamachine_` prefixes

## New OOP Architecture (v0.1.1)

**Major Refactoring**: Complete alignment with Data Machine core's new OOP patterns and base classes.

### Base Classes
- **`PublishHandler`** - Base class for all publishing operations (used by `Publisher.php`)
- **`FetchHandler`** - Base class for all data fetching operations (used by `EventImportHandler`)
- **`Step`** - Base class for all pipeline steps (used by `EventImportStep`)
- **`EventImportHandler`** - Abstract base class for all import handlers (extends `FetchHandler`)

### Shared Utilities
- **`WordPressSharedTrait`** - Provides shared WordPress utilities across all handlers
- **`TaxonomyHandler`** - Centralized taxonomy management with custom venue handler integration

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
- `DataMachine\Core\Steps\Publish\Handlers\PublishHandler` - Base for all publishing operations
- `DataMachine\Core\Steps\Fetch\Handlers\FetchHandler` - Base for all data fetching operations
- `DataMachine\Core\Steps\Step` - Base for all pipeline steps
- `Steps\EventImport\EventImportHandler` - Abstract base for all import handlers

**Core Classes**:
- `Admin\Settings_Page` - Event archive behavior, search integration, display preferences, map display type (5 free tile layer options)
- `Core\Event_Post_Type` - Post type registration with selective admin menu control
- `Core\Venue_Taxonomy` - Venue taxonomy with 9 meta fields, admin UI, CRUD operations
- `Core\Taxonomy_Badges` - Dynamic badge rendering with automatic color generation
- `Core\Breadcrumbs` - Breadcrumb generation (filterable via datamachine_events_breadcrumbs)
- `Blocks\Calendar\Template_Loader` - Modular template system with 7 specialized templates
- `Blocks\Calendar\Taxonomy_Helper` - Taxonomy data processing for filtering systems
- `Steps\Publish\Events\Publisher` - AI-driven event creation (extends PublishHandler)
- `Steps\Publish\Events\Venue` - Centralized venue operations
- `Steps\Publish\Events\Schema` - Google Event JSON-LD generator
- `Steps\EventImport\EventImportStep` - Event import step for Data Machine pipelines (extends Step)
- `Steps\EventImport\EventImportHandler` - Abstract base for import handlers (extends FetchHandler)
- `Steps\EventImport\Handlers\Ticketmaster\Ticketmaster` - Discovery API integration
- `Steps\EventImport\Handlers\DiceFm\DiceFm` - Dice FM event integration
- `Steps\EventImport\Handlers\WebScraper\UniversalWebScraper` - AI-powered web scraping

**Shared Utilities**:
- `DataMachine\Core\WordPress\WordPressSharedTrait` - Shared WordPress utilities
- `DataMachine\Core\WordPress\TaxonomyHandler` - Centralized taxonomy management

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
- DisplayStyles visual enhancement (CircuitGridRenderer, CarouselListRenderer, BadgeRenderer)
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
│   ├── admin/class-settings-page.php          # Event settings interface
│   ├── blocks/
│   │   ├── calendar/                          # Events display (webpack)
│   │   │   ├── Template_Loader.php            # Template loading system
│   │   │   ├── Taxonomy_Helper.php            # Taxonomy data processing
│   │   │   ├── DisplayStyles/                 # Visual enhancement components
│   │   │   └── templates/                     # 7 modular templates + modal/
│   │   ├── EventDetails/                      # Event data storage (webpack + @wordpress/scripts)
│   │   └── root.css                           # Centralized design tokens
│   ├── core/
│   │   ├── class-event-post-type.php          # Post type registration
│   │   ├── class-venue-taxonomy.php           # Venue taxonomy + 9 meta fields
│   │   ├── class-taxonomy-badges.php          # Dynamic badge rendering
│   │   ├── class-breadcrumbs.php              # Breadcrumb generation
│   │   └── rest-api.php                       # REST endpoints
│   └── steps/
│       ├── EventImport/handlers/              # Import handlers (Ticketmaster, DiceFm, WebScraper)
│       └── publish/Events/                    # Publisher + Schema + Venue
├── templates/single-datamachine_events.php    # Single event template
└── assets/                                    # CSS and JavaScript
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
- **Ticketmaster**: Discovery API with API key authentication, comprehensive validation
- **Dice FM**: Event integration with standardized processing
- **UniversalWebScraper**: AI-powered HTML section extraction

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

### Publisher Pattern
```php
public function handle_tool_call(array $parameters, array $tool_def = []): array {
    // AI-driven event creation
    $post_id = $this->create_event_post($title, $content, $block_attributes);

    // Venue handling
    $venue_result = Venue::find_or_create_venue($venue_name, $venue_data);
    Venue::assign_venue_to_event($post_id, $venue_name, $venue_data);

    return ['success' => true, 'data' => ['id' => $post_id, 'url' => $permalink]];
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

### Publisher Architecture
Publisher extends PublishHandler with WordPressSharedTrait:
```php
class Publisher extends PublishHandler {
    use WordPressSharedTrait;

    protected function executePublish(array $parameters, array $handler_config): array {
        // AI-driven event creation with venue handling
        $post_id = $this->create_event_post($parameters);
        $venue_id = Venue::find_or_create_venue($venue_data);
        TaxonomyHandler::addCustomHandler('venue', [$this, 'assignVenueTaxonomy']);

        return ['success' => true, 'data' => ['id' => $post_id]];
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

**Version**: 0.1.1
**For ecosystem architecture, see root CLAUDE.md**
