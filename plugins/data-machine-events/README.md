# Data Machine Events - WordPress Events Plugin

Frontend-focused WordPress events plugin with **block-first architecture**. Integrates with Data Machine plugin for automated event imports while providing elegant event display and management through Gutenberg blocks.

## Features

### Events
- **Block-First Architecture:** Event data managed via `Event Details` block (single source of truth)
- **Calendar Display:** Gutenberg block with filtering and search
- **Performance Optimized:** Background sync to meta fields for efficient queries
- **Data Machine Ready:** Works with Data Machine plugin for automated imports

### Venues
- **Rich Taxonomy:** 9 comprehensive meta fields (address, city, state, zip, country, phone, website, capacity, coordinates)
- **Admin Interface:** Dynamic form fields for comprehensive venue management with full CRUD operations
- **Auto-Population:** AI-driven venue creation with complete metadata from import sources
- **SEO Ready:** Archive pages and structured data

### Development
- **PSR-4 Autoloading:** `DmEvents\` namespace
- **Separate Build Systems:** Calendar (webpack), Event Details (@wordpress/scripts)
- **REST API Support:** Event metadata exposed
- **Schema Generation:** Google Event structured data for SEO enhancement
- **WordPress Standards:** Native hooks and security practices

### Architecture
**Data Flow:** Data Machine → Event Details Block → Background Sync → Calendar Display

**Core Classes:**
- `DmEvents\Admin\Status_Detection` - Data Machine integration status monitoring
- `DmEvents\Core\Venue_Taxonomy` - Complete venue taxonomy with 9 meta fields and admin interface
- `DmEvents\Core\Event_Post_Type` - Event post type registration with selective menu control
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsSchema` - Google Event Schema JSON-LD generator for SEO enhancement
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsPublisher` - AI-driven event creation with comprehensive venue handling
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsSettings` - Publisher configuration management
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsVenue` - Centralized venue taxonomy operations with validation
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsFilters` - Publisher filtering system
- `DmEvents\Steps\EventImport\EventImportStep` - Event import step for Data Machine pipeline with handler discovery
- `DmEvents\Steps\EventImport\EventImportFilters` - Event import step registration with Data Machine
- `DmEvents\Steps\EventImport\Handlers\Ticketmaster\Ticketmaster` - Discovery API integration with comprehensive error handling
- `DmEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterAuth` - API key authentication provider
- `DmEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterSettings` - Handler configuration management
- `DmEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterFilters` - Event filtering system
- `DmEvents\Steps\EventImport\Handlers\DiceFm\DiceFm` - Dice FM event integration with standardized processing
- `DmEvents\Steps\EventImport\Handlers\DiceFm\DiceFmAuth` - Dice FM authentication provider
- `DmEvents\Steps\EventImport\Handlers\DiceFm\DiceFmSettings` - Dice FM handler configuration management
- `DmEvents\Steps\EventImport\Handlers\DiceFm\DiceFmFilters` - Dice FM event filtering system
- `DmEvents\Steps\EventImport\Handlers\WebScraper\WebScraper` - Web scraper event extraction with validation
- `DmEvents\Steps\EventImport\Handlers\WebScraper\WebScraperSettings` - Web scraper configuration management
- `DmEvents\Steps\EventImport\Handlers\WebScraper\WebScraperFilters` - Web scraper filtering system

## Quick Start

### Installation
```bash
# Clone repository
git clone https://github.com/chubes4/dm-events.git
cd dm-events
composer install
```
Upload to `/wp-content/plugins/dm-events/` and activate.

### Usage
1. **Automated Import:** Configure Data Machine plugin for Ticketmaster Discovery API (API key), Dice FM, or web scraper imports
2. **AI-Driven Publishing:** Data Machine AI creates events with descriptions, comprehensive venue creation, and taxonomy assignments
3. **Manual Events:** Add Event post → Insert "Event Details" block → Fill event data  
4. **Display Events:** Add "Data Machine Events Calendar" block to any page/post
5. **Manage Venues:** Events → Venues → Add comprehensive venue details with 9 meta fields (auto-populated via AI imports)

## Project Structure

```
dm-events/
├── dm-events.php            # Main plugin file with PSR-4 autoloader
├── inc/
│   ├── admin/               # Status detection system
│   │   └── class-status-detection.php
│   ├── blocks/
│   │   ├── calendar/        # Calendar block (webpack)
│   │   └── event-details/   # Event details block (@wordpress/scripts)
│   ├── core/                # Core plugin classes
│   │   ├── class-event-post-type.php    # Event post type with menu control
│   │   └── class-venue-taxonomy.php     # Venue taxonomy with 9 meta fields
│   └── steps/               # Data Machine integration
│       ├── event-import/    # Import handlers with single-item processing
│       │   └── handlers/    # Ticketmaster, Dice FM, web scrapers
│       └── publish/         # AI-driven publishing with Schema generation
│           └── handlers/dm-events/  # DmEventsPublisher, Schema, Venue handling
├── assets/
│   ├── css/                 # Frontend styling (dm-events-frontend.css)
│   └── js/                  # Admin JavaScript
└── composer.json            # PHP dependencies
```

## Development

**Requirements:** WordPress 6.0+, PHP 8.0+, Composer, Node.js (for blocks)

**WordPress Version:** Tested up to 6.4

**Setup:**
```bash
composer install
# Build blocks
cd inc/blocks/calendar && npm install && npm run build
cd ../event-details && npm install && npm run build
```

**Production Build:**
```bash
# Run build script to create optimized package with versioned .zip file
./build.sh
```

**Block Development:**
```bash
# Calendar (webpack)
cd inc/blocks/calendar
npm run start    # Development watch

# Event Details (@wordpress/scripts)  
cd inc/blocks/event-details
npm run start  # Development watch
npm run lint:js && npm run lint:css
```

### Code Examples

**Block Attributes (Primary Data):**
```json
{
  "startDate": "2025-09-30",
  "startTime": "19:00", 
  "venue": "The Charleston Music Hall",
  "artist": "Mary Chapin Carpenter"
}
```

**Google Event Schema Generation:**
```php
// DmEventsSchema generates comprehensive structured data from block attributes
$schema = DmEventsSchema::generate_event_schema($block_attributes, $venue_data, $post_id);
echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>';

// Combines block data with venue taxonomy meta for complete SEO markup
// Includes performer, organizer, location, offers, and event status data
```

## AI Integration

**Enhanced Event Creation Flow:**
1. Data Machine import handlers extract venue data using single-item processing pattern
2. DmEventsPublisher injects venue data via dm_engine_additional_parameters filter
3. AI generates event descriptions and handles taxonomies configured for "ai_decides"
4. Publisher creates Event Details blocks with proper address attribute mapping
5. DmEventsSchema generates Google Event structured data for SEO enhancement
6. DmEventsVenue handles centralized venue creation with comprehensive meta validation

**Enhanced Publisher Features:**
- **Google Event Schema:** DmEventsSchema generates comprehensive structured data from block attributes and venue taxonomy meta for enhanced SEO visibility
- **Centralized Venue Handling:** DmEventsVenue class provides venue term creation, lookup, metadata validation, and assignment operations
- **Smart Parameter Routing:** DmEventsSchema.engine_or_tool() intelligently routes data between system parameters and AI inference
- **Comprehensive Venue Meta:** 9 venue meta fields (address, city, state, zip, country, phone, website, capacity, coordinates) plus native WordPress description
- **AI-Enhanced Descriptions:** AI generates engaging event descriptions while import handlers provide structured venue data
- **Block Content Generation:** Event Details blocks with proper address mapping and display controls (showVenue, showArtist, showPrice, showTicketLink)
- **Single-Item Processing:** Import handlers process one event per job execution with duplicate prevention via dm_is_item_processed
- **Venue Data Priority:** Venue taxonomy data overrides address attributes during event rendering
- **Status Detection System:** Status_Detection class provides comprehensive red/yellow/green monitoring for Data Machine integration
- **Security & Validation:** WordPress security compliance with comprehensive input sanitization and capability checks
- **Error Handling:** Detailed logging and validation throughout the entire import and publishing pipeline

## Technical Details

**Block Registration:**
```php
// Calendar block with comprehensive filtering and pagination
register_block_type($path, array(
    'render_callback' => array($this, 'render_calendar_block'),
    'attributes' => array(
        'defaultView' => array('type' => 'string', 'default' => 'list'),
        'eventsToShow' => array('type' => 'number', 'default' => 10),
        'showPastEvents' => array('type' => 'boolean', 'default' => false),
        'showFilters' => array('type' => 'boolean', 'default' => true),
        'enablePagination' => array('type' => 'boolean', 'default' => true)
    )
));
```

**Event Creation & Schema Generation:**
```php
// Centralized venue operations with comprehensive validation
$venue_result = DmEventsVenue::find_or_create_venue($venue_name, $venue_data);
$assignment = DmEventsVenue::assign_venue_to_event($post_id, $venue_name, $venue_data);

// Smart parameter routing for engine vs AI decisions
$routing = DmEventsSchema::engine_or_tool($event_data, $import_data);

// Generate Google Event Schema with venue taxonomy integration
$venue_data = Venue_Taxonomy::get_venue_data($venue_result['term_id']);
$schema = DmEventsSchema::generate_event_schema($block_attributes, $venue_data, $post_id);

// Single-item processing pattern for import handlers
if ($flow_step_id && $job_id) {
    do_action('dm_mark_item_processed', $flow_step_id, 'source_type', $event_identifier, $job_id);
}
return ['processed_items' => [['data' => $standardized_event, 'metadata' => [...]]]];
```

**Post Type Registration & Taxonomy Integration:**
```php
// Event post type with selective admin menu control
Event_Post_Type::register();

// Venue taxonomy with comprehensive meta fields and admin UI
Venue_Taxonomy::register();

// All public taxonomies automatically registered for dm_events
register_taxonomy_for_object_type($taxonomy_slug, 'dm_events');

// Venue data retrieval with complete meta integration
$venue_data = Venue_Taxonomy::get_venue_data($term_id);
$formatted_address = Venue_Taxonomy::get_formatted_address($term_id);
```

## Contributing

1. Fork the repository
2. Create feature branch (`git checkout -b feature/name`)
3. Commit changes (`git commit -m 'Add feature'`)
4. Push to branch (`git push origin feature/name`) 
5. Open Pull Request

## License

GPL v2 or later

## Support

- GitHub Issues
- Contact: [chubes.net](https://chubes.net) 