# Data Machine Events

Frontend-focused WordPress events plugin with **block-first architecture**. Features AI-driven event creation via Data Machine integration, Event Details blocks with InnerBlocks for rich content editing, Calendar blocks for display, and comprehensive venue taxonomy management.

## Migration Showcase

**DM Events demonstrates completed migrations serving as reference implementations for the Data Machine Ecosystem:**

**REST API Migration:**
- ✅ **Complete:** ALL AJAX eliminated - calendar filtering + venue operations fully migrated to REST API
- Implementation: Unified `datamachine/v1` namespace with `/events/*` routes
- Endpoints: `/events/calendar` (public), `/events/venues/{id}` (admin), `/events/venues/check-duplicate` (admin)
- Status: **100% REST API - Zero AJAX dependencies**
- Architecture: SQL-based filtering, History API integration, ~950 lines of AJAX code removed

**Prefix Migration:**
- ✅ **Complete:** Extension fully migrated to `datamachine_events` post type and `datamachine_` prefixes
- Status: Production-ready with WordPress repository compliance

## Features

### Events
- **Block-First Architecture:** Event data managed via `Event Details` block with InnerBlocks support (single source of truth)
- **Rich Content Editing:** InnerBlocks integration allows rich content within events
- **Comprehensive Data Model:** 15+ event attributes including performer, organizer, pricing, and event status
- **Calendar Display:** Gutenberg block with modular template system, taxonomy filtering, pagination, and search capabilities
- **Single Event Template:** Extensible template with action hooks for theme integration (datamachine_events_before_single_event, datamachine_events_after_event_article, datamachine_events_related_events, datamachine_events_after_single_event)
- **Display Controls:** Flexible rendering with showVenue, showPrice, showTicketLink options
- **Performance Optimized:** Background sync to meta fields for efficient database queries
- **Data Machine Integration:** Automated AI-driven event imports with single-item processing

### Venues
- **Rich Taxonomy:** 9 comprehensive meta fields (address, city, state, zip, country, phone, website, capacity, coordinates)
- **Admin Interface:** Dynamic form fields for comprehensive venue management with full CRUD operations
- **Auto-Population:** AI-driven venue creation with complete metadata from import sources
- **SEO Ready:** Archive pages and structured data

### Development
- **PSR-4 Autoloading:** `DatamachineEvents\` namespace with enhanced autoloader for Data Machine handlers
- **Dual Build Systems:** Calendar block (webpack), Event Details block (webpack with @wordpress/scripts base)
- **Modular Template Architecture:** 7 specialized templates with Template_Loader system for flexible calendar rendering
- **Dynamic Taxonomy Badges:** Automatic badge generation for all taxonomies with consistent color classes and HTML structure
- **Visual Enhancement System:** DisplayStyles components including CircuitGridRenderer.js, CarouselListRenderer.js, and BadgeRenderer.js for calendar display
- **Centralized Design Tokens:** root.css provides unified CSS custom properties for all blocks and JavaScript
- **Production Build:** Automated `./build.sh` script creates optimized WordPress plugin package in `/dist` directory
- **REST API Support:** Event metadata exposed via WordPress REST API
- **Schema Generation:** Google Event structured data with smart parameter routing for SEO enhancement
- **WordPress Standards:** Native hooks, security practices, and comprehensive input sanitization

### Architecture
**Data Flow:** Data Machine Import → Event Details Block (InnerBlocks) → Schema Generation → Calendar Display
**Schema Flow:** Block Attributes + Venue Taxonomy Meta → DataMachineEventsSchema → JSON-LD Output

**Core Classes:**
- `DataMachineEvents\Admin\Status_Detection` - Legacy stub retained for backwards compatibility (status detection removed)
- `DataMachineEvents\Admin\Settings_Page` - Event settings interface for archive behavior and display preferences
- `DataMachineEvents\Core\Venue_Taxonomy` - Complete venue taxonomy with 9 meta fields and admin interface
- `DataMachineEvents\Core\Event_Post_Type` - Event post type registration with selective menu control
- `DataMachineEvents\Core\Taxonomy_Badges` - Dynamic taxonomy badge rendering system with filterable output (datamachine_events_badge_wrapper_classes, datamachine_events_badge_classes)
- `DataMachineEvents\Core\Breadcrumbs` - Breadcrumb generation with filterable output (datamachine_events_breadcrumbs) for theme integration
- `DataMachineEvents\Blocks\Calendar\Template_Loader` - Modular template loading system with variable extraction, output buffering, and template caching for calendar block components
- `DataMachineEvents\Blocks\Calendar\Taxonomy_Helper` - Taxonomy data discovery, hierarchy building, and post count calculations for calendar filtering systems
- `DataMachineEvents\Steps\Publish\Events\Schema` - Google Event Schema JSON-LD generator for SEO enhancement
- `DataMachineEvents\Steps\Publish\Events\Publisher` - AI-driven event creation with comprehensive venue handling
- `DataMachineEvents\Steps\Publish\Events\Settings` - Publisher configuration management
- `DataMachineEvents\Steps\Publish\Events\Venue` - Centralized venue taxonomy operations with validation
- `DataMachineEvents\Steps\Publish\Events\Filters` - Publisher filtering system
- `DataMachineEvents\Steps\EventImport\EventImportStep` - Event import step for Data Machine pipeline with handler discovery
- `DataMachineEvents\Steps\EventImport\EventImportFilters` - Event import step registration with Data Machine
- `DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\Ticketmaster` - Discovery API integration with comprehensive error handling
- `DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterAuth` - API key authentication provider
- `DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterSettings` - Handler configuration management
- `DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterFilters` - Event filtering system
- `DataMachineEvents\Steps\EventImport\Handlers\DiceFm\DiceFm` - Dice FM event integration with standardized processing
- `DataMachineEvents\Steps\EventImport\Handlers\DiceFm\DiceFmAuth` - Dice FM authentication provider
- `DataMachineEvents\Steps\EventImport\Handlers\DiceFm\DiceFmSettings` - Dice FM handler configuration management
- `DataMachineEvents\Steps\EventImport\Handlers\DiceFm\DiceFmFilters` - Dice FM event filtering system
- `DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper` - AI-powered universal web scraper with HTML section processing
- `DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraperSettings` - Universal web scraper configuration management
- `DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraperFilters` - Universal web scraper filtering system

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
1. **Plugin Settings:** Events → Settings → Configure archive behavior, search integration, and display preferences
2. **Automated Import:** Configure Data Machine plugin for Ticketmaster Discovery API (API key), Dice FM, or universal web scraper imports
3. **AI-Driven Publishing:** Data Machine AI creates events with descriptions, comprehensive venue creation, and taxonomy assignments
4. **Manual Events:** Add Event post → Insert "Event Details" block → Fill event data  
5. **Display Events:** Add "Data Machine Events Calendar" block to any page/post
6. **Manage Venues:** Events → Venues → Add comprehensive venue details with 9 meta fields (auto-populated via AI imports)

## Project Structure

```
dm-events/
├── dm-events.php            # Main plugin file with PSR-4 autoloader
├── inc/
│   ├── admin/               # Admin interface classes (includes legacy status detection stub)
│   │   ├── class-status-detection.php
│   │   └── class-settings-page.php
│   ├── blocks/
│   │   ├── calendar/        # Calendar block (webpack) with modular template system
│   │   │   ├── class-template-loader.php
│   │   │   ├── class-taxonomy-helper.php
│   │   │   └── templates/   # 7 specialized templates plus modal subdirectory
│   │   └── EventDetails/    # Event details block (webpack with @wordpress/scripts base)
│   ├── core/                # Core plugin classes
│   │   ├── class-event-post-type.php    # Event post type with menu control
│   │   ├── class-venue-taxonomy.php     # Venue taxonomy with 9 meta fields
│   │   ├── class-taxonomy-badges.php    # Dynamic taxonomy badge rendering with filters
│   │   └── class-breadcrumbs.php        # Breadcrumb generation with datamachine_events_breadcrumbs filter
│   └── steps/               # Data Machine integration
│       ├── EventImport/     # Import handlers with single-item processing
│       │   └── handlers/    # Ticketmaster, Dice FM, web scrapers
│       └── publish/         # AI-driven publishing with Schema generation
│           └── handlers/Events/  # Publisher, Schema, Venue handling
├── templates/
│   └── single-datamachine_events.php # Single event template with extensibility hooks
├── assets/
│   ├── css/                 # Admin styling (admin.css)
│   └── js/                  # Admin JavaScript
├── inc/blocks/root.css      # Centralized design tokens and CSS custom properties
└── composer.json            # PHP dependencies
```

## Development

**Requirements:** WordPress 6.0+, PHP 8.0+, Composer, Node.js 16+ (for block development)

**WordPress Version:** Tested up to 6.8

**Setup:**
```bash
composer install
# Build blocks
cd inc/blocks/calendar && npm install && npm run build
cd ../EventDetails && npm install && npm run build
```

**Production Build:**
```bash
# Run automated build script to create optimized WordPress plugin package
./build.sh
# Creates: /dist/datamachine-events.zip with build info and production assets
```

**Block Development:**
```bash
# Calendar (webpack)
cd inc/blocks/calendar
npm run start    # Development watch

# Event Details (webpack with @wordpress/scripts base)
cd inc/blocks/EventDetails
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
// Schema generates comprehensive structured data from block attributes
$schema = Schema::generate_event_schema($block_attributes, $venue_data, $post_id);
echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>';

// Combines block data with venue taxonomy meta for complete SEO markup
// Includes performer, organizer, location, offers, and event status data
```

## AI Integration

**AI-Driven Event Creation Pipeline:**
1. **Import Handlers:** Extract event data from APIs (Ticketmaster, Dice FM) or AI-powered universal web scrapers using single-item processing
2. **Engine Data Persistence:** Event import handlers store venue/location/contact fields in engine data so AI and publish steps can access them without adapters
3. **AI Web Scraping:** UniversalWebScraper uses AI to extract event data from HTML sections with automated processing
4. **Venue Data Processing:** Publisher processes venue creation and assignment directly
5. **AI Content Generation:** AI generates event descriptions while preserving structured venue data
6. **Block Creation:** Publisher creates Event Details blocks with InnerBlocks support and proper attribute mapping
7. **Venue Management:** Venue handles term creation, lookup, metadata validation, and event assignment
8. **Schema Generation:** Schema creates Google Event structured data combining block attributes with venue taxonomy meta
9. **Template Rendering:** Template_Loader system provides modular, cacheable template rendering with variable extraction
10. **Taxonomy Display:** Taxonomy_Badges generates dynamic badge HTML for all non-venue taxonomies with consistent styling
11. **Visual Enhancement:** BadgeRenderer.js creates taxonomy badge rendering with CircuitGridRenderer.js and CarouselListRenderer.js for flexible calendar display modes

## Calendar Filtering Architecture

The calendar block uses a progressive enhancement pattern with REST API filtering for optimal performance and accessibility.

**Progressive Enhancement:**
- **Without JavaScript:** Server-side rendering with URL parameters (SEO-friendly, accessible, works for all users)
- **With JavaScript:** REST API provides seamless filtering without page reload (enhanced user experience)
- **URL-Based Filtering:** All filter states preserved in URL for sharing, bookmarking, and back/forward navigation
- **History API:** Browser back/forward buttons work correctly with filtered states

**REST API Endpoint:**
```bash
GET /wp-json/datamachine-events/v1/calendar

# Query Parameters
event_search=keyword          # Search events by title, venue, or taxonomy terms
date_start=2024-01-01        # Start date filter (YYYY-MM-DD)
date_end=2024-12-31          # End date filter (YYYY-MM-DD)
tax_filter[festival][]=1     # Festival taxonomy filter (multiple values supported)
tax_filter[location][]=5     # Location taxonomy filter (multiple values supported)
paged=2                      # Current page number
past=1                       # Show past events ("1" for past, omit for upcoming)
```

**Performance Benefits:**
- **SQL-Based Queries:** Filter at database level before sending to browser
- **Optimized Loading:** Only current page events loaded (~10 events per page vs. all 500+ events)
- **Efficient Meta Queries:** Uses indexed `_datamachine_event_datetime` meta field for fast date filtering
- **Scalable Architecture:** Handles large event datasets (500+ events) without performance degradation

**User Experience Features:**
- **Loading Spinner:** Visual feedback during REST API calls (`.loading` class with CSS animation)
- **Error Messages:** User-friendly error display for failed requests
- **Results Counter:** Shows "Viewing events 1-10 of 45 total" for pagination context
- **Filter Count Badge:** Visual indicator on taxonomy filter button showing active filter count
- **Smooth Transitions:** Loading states prevent jarring content switches

**Key Integration Features:**
- **AI-Powered Web Scraping:** UniversalWebScraper uses AI to extract structured event data from any HTML page
- **Modular Template Architecture:** Template_Loader provides 7 specialized templates with variable extraction and output buffering
- **Dynamic Taxonomy Badges:** Taxonomy_Badges system with automatic color generation and HTML structure for all non-venue taxonomies
- **Taxonomy Data Processing:** Taxonomy_Helper with hierarchy building, post count calculations, and structured data for filtering
- **Visual Enhancement System:** DisplayStyles components with CircuitGridRenderer.js, CarouselListRenderer.js, and BadgeRenderer.js for flexible calendar display
- **Centralized Design System:** root.css provides unified design tokens accessible from both CSS and JavaScript
- **Smart Parameter Routing:** Schema.engine_or_tool() intelligently routes data between system parameters and AI inference
- **Flat Parameter System:** Data Machine's single-level parameter structure across all custom steps for simplified integration
- **InnerBlocks Support:** Event Details blocks with rich content editing capabilities and proper attribute mapping
- **Comprehensive Venue Meta:** 9 venue meta fields plus native WordPress description automatically populated from import sources
- **Single-Item Processing:** Import handlers process one event per job execution with duplicate prevention and incremental processing
- **Status Detection:** Removed; integration health checks will return with future Data Machine APIs
- **Security Compliance:** WordPress security standards with comprehensive input sanitization and capability checks

## Technical Details

**Event Details Block with InnerBlocks:**
```javascript
// Event Details block registration with InnerBlocks support
registerBlockType('datamachine-events/event-details', {
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
// Flat parameter system for all custom steps
public function execute(array $parameters): array {
    $job_id = $parameters['job_id'];
    $flow_step_id = $parameters['flow_step_id'];
    $data = $parameters['data'] ?? [];
    $flow_step_config = $parameters['flow_step_config'] ?? [];
    return $data; // Always return data packet for pipeline continuity
}

// Single-item processing with duplicate prevention
foreach ($raw_events as $raw_event) {
    $standardized_event = $this->map_ticketmaster_event($raw_event);
    $event_identifier = md5($standardized_event['title'] . $standardized_event['startDate']);
    $is_processed = apply_filters('datamachine_is_item_processed', false, $flow_step_id, 'ticketmaster', $event_identifier);
    if ($is_processed) continue;
    
    // Mark as processed and return IMMEDIATELY
    do_action('datamachine_mark_item_processed', $flow_step_id, 'ticketmaster', $event_identifier, $job_id);
    // Add to data packet array and return
    array_unshift($data, $event_entry);
    return $data;
}
```

**Single Event Template Extensibility:**
```php
// Hook into single event template for theme integration
add_action('datamachine_events_before_single_event', function() {
    // Inject notices, alerts, or theme content after get_header()
    do_action('extrachill_before_body_content');
});

add_action('datamachine_events_related_events', function($event_id) {
    // Display related events by festival and venue taxonomies
    if (function_exists('extrachill_display_related_posts')) {
        extrachill_display_related_posts('festival', $event_id);
        extrachill_display_related_posts('venue', $event_id);
    }
}, 10, 1);

add_action('datamachine_events_after_single_event', function() {
    // Inject footer content before get_footer()
    do_action('extrachill_after_body_content');
});

// Override breadcrumbs with theme system
add_filter('datamachine_events_breadcrumbs', function($breadcrumbs, $post_id) {
    if (function_exists('display_breadcrumbs')) {
        ob_start();
        display_breadcrumbs();
        return ob_get_clean();
    }
    return $breadcrumbs;
}, 10, 2);

// Enhance taxonomy badges with theme classes
add_filter('datamachine_events_badge_wrapper_classes', function($classes, $post_id) {
    $classes[] = 'taxonomy-badges';
    return $classes;
}, 10, 2);

add_filter('datamachine_events_badge_classes', function($classes, $taxonomy, $term, $post_id) {
    $classes[] = 'taxonomy-badge';
    if ($taxonomy === 'festival') {
        $classes[] = 'festival-badge';
        $classes[] = 'festival-' . esc_attr($term->slug);
    }
    return $classes;
}, 10, 4);
```

**Calendar Template System & Taxonomy Integration:**
```php
// Template_Loader provides modular template rendering with 7 templates
Template_Loader::init();
$event_item = Template_Loader::get_template('event-item', [
    'event' => $event_data,
    'show_venue' => true,
    'show_price' => true
]);

// Time gap separator for carousel-list display mode
$time_gap = Template_Loader::get_template('time-gap-separator', [
    'gap_days' => $days_between_events
]);

// Taxonomy_Badges dynamic badge generation
$badges_html = Taxonomy_Badges::render_taxonomy_badges($post_id);
$color_class = Taxonomy_Badges::get_taxonomy_color_class('event_category');

// Taxonomy_Helper structured data processing
$taxonomies = Taxonomy_Helper::get_all_taxonomies_with_counts();
$hierarchy = Taxonomy_Helper::get_taxonomy_hierarchy('event_category');

// Event post type with selective admin menu control
Event_Post_Type::register();

// Venue taxonomy with comprehensive meta fields and admin UI
Venue_Taxonomy::register();

// All public taxonomies automatically registered for datamachine_events
register_taxonomy_for_object_type($taxonomy_slug, 'datamachine_events');

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