# Data Machine Events

Frontend-focused WordPress events plugin with **block-first architecture**. Features AI-driven event creation via Data Machine integration, Event Details blocks with InnerBlocks for rich content editing, Calendar blocks for display, and comprehensive venue taxonomy management.

## Features

### Events
- **Block-First Architecture:** Event data managed via `Event Details` block with InnerBlocks support (single source of truth)
- **Rich Content Editing:** InnerBlocks integration allows rich content within events
- **Comprehensive Data Model:** 15+ event attributes including performer, organizer, pricing, and event status
- **Calendar Display:** Gutenberg block with filtering, pagination, and search capabilities  
- **Display Controls:** Flexible rendering with showVenue, showPrice, showTicketLink, showArtist options
- **Performance Optimized:** Background sync to meta fields for efficient database queries
- **Data Machine Integration:** Automated AI-driven event imports with single-item processing

### Venues
- **Rich Taxonomy:** 9 comprehensive meta fields (address, city, state, zip, country, phone, website, capacity, coordinates)
- **Admin Interface:** Dynamic form fields for comprehensive venue management with full CRUD operations
- **Auto-Population:** AI-driven venue creation with complete metadata from import sources
- **SEO Ready:** Archive pages and structured data

### Development
- **PSR-4 Autoloading:** `DmEvents\` namespace with enhanced autoloader for Data Machine handlers
- **Dual Build Systems:** Calendar block (webpack), Event Details block (@wordpress/scripts)
- **Production Build:** Automated `./build.sh` script creates optimized WordPress plugin package
- **REST API Support:** Event metadata exposed via WordPress REST API
- **Schema Generation:** Google Event structured data with smart parameter routing for SEO enhancement
- **WordPress Standards:** Native hooks, security practices, and comprehensive input sanitization

### Architecture
**Data Flow:** Data Machine Import → Event Details Block (InnerBlocks) → Schema Generation → Calendar Display
**Schema Flow:** Block Attributes + Venue Taxonomy Meta → DmEventsSchema → JSON-LD Output

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

**Requirements:** WordPress 6.0+, PHP 8.0+, Composer, Node.js 16+ (for block development)

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
# Run automated build script to create optimized WordPress plugin package
./build.sh
# Creates: /dist/dm-events.zip with versioned build info and production assets
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

**Event Details Block Attributes (Single Source of Truth):**
```json
{
  "startDate": "2025-09-30",
  "startTime": "19:00", 
  "venue": "The Charleston Music Hall",
  "performer": "Mary Chapin Carpenter",
  "performerType": "MusicGroup",
  "price": "45.00",
  "priceCurrency": "USD",
  "ticketUrl": "https://example.com/tickets",
  "showVenue": true,
  "showPrice": true,
  "showTicketLink": true
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

**AI-Driven Event Creation Pipeline:**
1. **Import Handlers:** Extract event data from APIs (Ticketmaster, Dice FM) or web scrapers using single-item processing
2. **Venue Data Injection:** DmEventsPublisher injects venue metadata via dm_engine_additional_parameters filter
3. **AI Content Generation:** AI generates event descriptions while preserving structured venue data
4. **Block Creation:** Publisher creates Event Details blocks with InnerBlocks support and proper attribute mapping
5. **Venue Management:** DmEventsVenue handles term creation, lookup, metadata validation, and event assignment
6. **Schema Generation:** DmEventsSchema creates Google Event structured data combining block attributes with venue taxonomy meta

**Key Integration Features:**
- **Smart Parameter Routing:** DmEventsSchema.engine_or_tool() intelligently routes data between system parameters and AI inference
- **Unified Parameter System:** Data Machine's dm_engine_parameters filter manages single parameter structure across all custom steps
- **InnerBlocks Support:** Event Details blocks with rich content editing capabilities and proper attribute mapping
- **Comprehensive Venue Meta:** 9 venue meta fields plus native WordPress description automatically populated from import sources
- **Single-Item Processing:** Import handlers process one event per job execution with duplicate prevention and incremental processing
- **Status Detection:** Red/yellow/green monitoring via Status_Detection class for all Data Machine integration components
- **Security Compliance:** WordPress security standards with comprehensive input sanitization and capability checks

## Technical Details

**Event Details Block with InnerBlocks:**
```javascript
// Event Details block registration with InnerBlocks support
registerBlockType('dm-events/event-details', {
    edit: function Edit({ attributes, setAttributes }) {
        const { startDate, venue, performer, showVenue, showPrice } = attributes;
        
        return (
            <div {...useBlockProps()}>
                <TextControl 
                    label="Event Date" 
                    value={startDate} 
                    onChange={(value) => setAttributes({ startDate: value })}
                />
                {/* 15+ comprehensive event attributes */}
                <InnerBlocks /> {/* Rich content editing support */}
            </div>
        );
    },
    save: () => <InnerBlocks.Content />
});
```

**Data Machine Integration Pattern:**
```php
// Unified parameter system for all custom steps
public function execute(array $parameters): array {
    $job_id = $parameters['execution']['job_id'];
    $flow_step_id = $parameters['execution']['flow_step_id'];
    $data = $parameters['data'] ?? [];
    $metadata = $parameters['metadata'] ?? []; // Venue data injection
    return $data; // Always return data packet for pipeline continuity
}

// Single-item processing with duplicate prevention
foreach ($raw_events as $raw_event) {
    $event_identifier = md5($standardized_event['title'] . $standardized_event['startDate']);
    $is_processed = apply_filters('dm_is_item_processed', false, $flow_step_id, 'source_type', $event_identifier);
    if ($is_processed) continue;
    
    // Mark as processed and return IMMEDIATELY
    do_action('dm_mark_item_processed', $flow_step_id, 'source_type', $event_identifier, $job_id);
    return ['processed_items' => [['data' => $standardized_event]]];
}
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