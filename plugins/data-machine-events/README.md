# Data Machine Events

Frontend-focused WordPress events plugin with **block-first architecture**. Features AI-driven event creation via Data Machine integration, Event Details blocks with InnerBlocks for rich content editing, Calendar blocks for display, and comprehensive venue taxonomy management.

**Version**: 0.4.10

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

**Template Architecture Migration (v0.3.0):**
- ✅ **Complete:** Removed single event template - events now use theme's `single.php`
- ✅ **Block-first approach:** Event Details block provides all data rendering
- ✅ **Theme flexibility:** Full control over event presentation layout
- ✅ **Simplified architecture:** 162 lines removed, cleaner separation of concerns

**Circuit Grid Removal (v0.4.0):**
- ✅ **Complete:** Circuit Grid display mode removed - Carousel List is now the single display mode
- ✅ **Codebase Simplified:** Removed ~1,300 lines of SVG border rendering, shape calculation, and badge positioning code
- ✅ **Single Display Path:** One proven display mode eliminates branching logic and maintenance burden
- ✅ **Performance Optimized:** Carousel List CSS-only implementation provides modern horizontal scroll UX

## Features

### Events
- **Block-First Architecture:** Event data managed via `Event Details` block with InnerBlocks support (single source of truth)
- **Rich Content Editing:** InnerBlocks integration allows rich content within events
- **Comprehensive Data Model:** 15+ event attributes including performer, organizer, pricing, and event status
- **Calendar Display:** Gutenberg block with modular template system, taxonomy filtering, pagination, and search capabilities
- **Advanced Filtering:** Date context filtering, taxonomy dependencies, and localStorage state persistence
- **Theme Integration:** Events use theme's `single.php` template with Event Details block providing all data rendering
- **Display Controls:** Flexible rendering with showVenue, showPrice, showTicketLink options
- **Performance Optimized:** Background sync to meta fields for efficient database queries
- **Data Machine Integration:** Automated AI-driven event imports with 9 import handlers including ICS Calendar support

### Venues
- **Rich Taxonomy:** 9 comprehensive meta fields (address, city, state, zip, country, phone, website, capacity, coordinates)
- **Admin Interface:** Dynamic form fields for comprehensive venue management with full CRUD operations
- **Auto-Population:** AI-driven venue creation with complete metadata from import sources
- **SEO Ready:** Archive pages and structured data

### Usage
1. **Plugin Settings:** Events → Settings → Configure archive behavior, search integration, and display preferences
2. **Admin Navigation:** Events menu in WordPress admin bar for quick access to event management
3. **Automated Import:** Configure Data Machine plugin for Ticketmaster Discovery API, Dice FM, Google Calendar, SpotHopper, Eventbrite, WordPress Events API, Event Flyer, or universal web scraper imports
4. **AI-Driven Publishing:** Data Machine AI creates events with descriptions, comprehensive venue creation, and taxonomy assignments
5. **Manual Events:** Add Event post → Insert "Event Details" block → Fill event data
6. **Display Events:** Add "Data Machine Events Calendar" block to any page/post
7. **Manage Venues:** Events → Venues → Add comprehensive venue details with 9 meta fields (auto-populated via AI imports)

## Project Structure

```
datamachine-events/
├── datamachine-events.php   # Main plugin file with PSR-4 autoloader
├── inc/
│   ├── Admin/               # Admin interface classes
│   │   ├── Admin_Bar.php                       # Events navigation menu in admin bar
│   │   ├── Settings_Page.php                   # Event settings interface
│   │   └── Status_Detection.php                # Legacy status detection stub
│   ├── Api/                 # REST API controllers and routes
│   │   ├── Routes.php       # API route registration
│   │   └── Controllers/     # Calendar, Venues, Events controllers
│   ├── Blocks/
│   │   ├── Calendar/        # Calendar block (webpack) with modular template system
│   │   │   ├── src/
│   │   │   │   ├── modules/              # ES modules for frontend
│   │   │   │   │   ├── api-client.js     # REST API communication
│   │   │   │   │   ├── carousel.js       # Carousel overflow, dots, chevrons
│   │   │   │   │   ├── date-picker.js    # Flatpickr integration
│   │   │   │   │   ├── filter-modal.js   # Taxonomy filter modal
│   │   │   │   │   ├── navigation.js     # Past/upcoming navigation
│   │   │   │   │   └── state.js          # URL state management
│   │   │   │   ├── flatpickr-theme.css   # Date picker theming
│   │   │   │   └── frontend.js           # Module orchestration
│   │   │   ├── Taxonomy_Badges.php       # Dynamic badge rendering
│   │   │   ├── Taxonomy_Helper.php       # Taxonomy data processing
│   │   │   ├── Template_Loader.php       # Template loading system
│   │   │   ├── Pagination.php            # Calendar pagination logic
│   │   │   └── templates/   # 7 specialized templates plus modal subdirectory
│   │   ├── EventDetails/    # Event details block (webpack with @wordpress/scripts base)
│   │   └── root.css         # Centralized design tokens and CSS custom properties
│   ├── Core/                # Core plugin classes
│   │   ├── Event_Post_Type.php                 # Event post type with menu control
│   │   ├── Venue_Taxonomy.php                  # Venue taxonomy with 9 meta fields
│   │   ├── VenueService.php                    # Centralized venue operations
│   │   └── meta-storage.php                    # Event metadata sync and management
│   ├── steps/               # Data Machine integration
│   │   ├── EventImport/     # Import handlers with single-item processing
│   │   │   ├── Handlers/    # Import handlers (8 total)
│   │   │   │   ├── Ticketmaster/               # Ticketmaster Discovery API
│   │   │   │   ├── DiceFm/                     # Dice FM integration
│   │   │   │   ├── GoogleCalendar/             # Google Calendar integration
│   │   │   │   │   ├── GoogleCalendarUtils.php # Calendar ID/URL utilities
│   │   │   │   │   └── GoogleCalendarAuth.php  # Authentication handling
│   │   │   │   ├── SpotHopper/                 # SpotHopper venue events
│   │   │   │   ├── WebScraper/                 # AI-powered web scraping
│   │   │   │   ├── WordPressEventsAPI/         # External WordPress events (auto-format detection)
│   │   │   │   ├── EventFlyer/                 # AI vision extraction from flyer images
│   │   │   │   └── Eventbrite/                 # Eventbrite JSON-LD parsing
│   │   │   ├── EventImportStep.php             # Pipeline step with handler discovery
│   │   │   └── EventImportHandler.php          # Abstract base for import handlers
│   │   └── Upsert/Events/   # EventUpsert handler for create/update operations
│   │       ├── EventUpsert.php
│   │       ├── EventUpsertFilters.php
│   │       ├── EventUpsertSettings.php  # Configuration management
│   │       ├── Schema.php
│   │       └── Venue.php
│   └── Utilities/           # Shared utilities
│       └── EventIdentifierGenerator.php  # Event identifier normalization
├── templates/
│   └── admin/
│       └── settings-page.php # Admin settings template
├── assets/
│   ├── css/                 # Admin styling (admin.css)
│   │   ├── admin.css
│   │   ├── venue-autocomplete.css
│   │   └── venue-map.css
│   └── js/                  # Admin JavaScript
│       ├── venue-autocomplete.js
│       ├── venue-map.js
│       └── venue-selector.js
└── composer.json            # PHP dependencies
```

## Development

**Requirements:** WordPress 6.0+, PHP 8.0+, Composer, Node.js 16+ (for block development)

**WordPress Version:** Tested up to 6.8

**Data Machine Requirement:** Data Machine v0.2.7+ required for WordPressPublishHelper compatibility and EngineData architecture changes

**Setup:**
```bash
composer install
# Build blocks
cd inc/Blocks/Calendar && npm install && npm run build
cd ../EventDetails && npm install && npm run build
```

**Production Build:**
```bash
# Run automated build script to create optimized WordPress plugin package
./build.sh
# Creates: /dist/datamachine-events.zip with build info and production assets
```
Upload to `/wp-content/plugins/datamachine-events/` and activate.

**Block Development:**
```bash
# Calendar (webpack)
cd inc/Blocks/Calendar
npm run start    # Development watch

# Event Details (webpack with @wordpress/scripts base)
cd inc/Blocks/EventDetails
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
use DataMachineEvents\Core\EventSchemaProvider;

// Schema generates comprehensive structured data from block attributes
$schema = EventSchemaProvider::generateSchemaOrg($event_data, $venue_data, $post_id);
echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>';

// Combines block data with venue taxonomy meta for complete SEO markup
// Includes performer, organizer, location, offers, and event status data
```

## AI Integration

**AI-Driven Event Creation Pipeline:**
1. **Import Handlers:** Extract event data from 9 sources (Ticketmaster, Dice FM, Google Calendar, ICS Calendar, SpotHopper, Eventbrite, WordPress Events API, Event Flyer, Universal Web Scraper) using single-item processing
2. **Event Identifier Normalization:** EventIdentifierGenerator creates consistent identifiers from (title, startDate, venue) for duplicate detection
3. **Engine Data Persistence:** Event import handlers store venue/location/contact fields in engine data for downstream access
4. **AI Web Scraping:** UniversalWebScraper uses AI to extract event data from HTML sections with automated processing
5. **AI Vision Extraction:** EventFlyer uses vision AI to extract event details from promotional flyer/poster images with "fill OR AI extracts" field pattern
6. **Eventbrite Integration:** Schema.org JSON-LD parsing from public organizer pages (no API key required)
7. **WordPress Integration:** WordPressEventsAPI imports events from external WordPress sites with auto-format detection (Tribe Events v1, Tribe WP REST, generic WordPress)
8. **Schema Management:** EventSchemaProvider centralizes field definitions and Schema.org JSON-LD generation
9. **Venue Parameter Handling:** VenueParameterProvider manages dynamic venue parameter generation for AI tools
10. **Intelligent Event Upsert:** EventUpsert handler searches for existing events by identity, performs field-by-field change detection
11. **Venue Data Processing:** Venue_Taxonomy handles find-or-create operations with metadata validation
12. **AI Content Generation:** AI generates event descriptions while preserving structured venue data
13. **Block Creation:** EventUpsert creates/updates Event Details blocks with InnerBlocks support and proper attribute mapping
14. **Venue Management:** Venue handles term creation, lookup, metadata validation, and event assignment
15. **Schema Generation:** Schema creates Google Event structured data combining block attributes with venue taxonomy meta
16. **Template Rendering:** Template_Loader system provides modular, cacheable template rendering with variable extraction
17. **Taxonomy Display:** Taxonomy_Badges generates dynamic badge HTML for all non-venue taxonomies with consistent styling
18. **Visual Enhancement:** Carousel List display with CSS-only horizontal scrolling and day-grouped events

## Calendar Filtering Architecture

The calendar block uses a progressive enhancement pattern with REST API filtering for optimal performance and accessibility.

**Progressive Enhancement:**
- **Without JavaScript:** Server-side rendering with URL parameters (SEO-friendly, accessible, works for all users)
- **With JavaScript:** REST API provides seamless filtering without page reload (enhanced user experience)
- **URL-Based Filtering:** All filter states preserved in URL for sharing, bookmarking, and back/forward navigation
- **History API:** Browser back/forward buttons work correctly with filtered states

**REST API Endpoint:**
```bash
GET /wp-json/datamachine/v1/events/calendar

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
- **Intelligent Event Upsert:** EventUpsert handler with identity-based search, field-by-field change detection, prevents unnecessary updates
- **Event Identifier Normalization:** EventIdentifierGenerator ensures consistent event identity across all import handlers
- **REST API Controllers:** Modular controller architecture with unified namespace for Calendar, Venues, and Events endpoints

- **AI-Powered Web Scraping:** UniversalWebScraper uses AI to extract structured event data from any HTML page
- **Modular Template Architecture:** Template_Loader provides 7 specialized templates plus modal with variable extraction and output buffering
- **Dynamic Taxonomy Badges:** Taxonomy_Badges system with automatic color generation and HTML structure for all non-venue taxonomies  
- **Taxonomy Data Processing:** Taxonomy_Helper with hierarchy building, post count calculations, and structured data for filtering
- **Visual Enhancement System:** Carousel List display with CSS-only horizontal scrolling and time-gap separators
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
// EventIdentifierGenerator for consistent event identity
use DataMachineEvents\Utilities\EventIdentifierGenerator;

$event_identifier = EventIdentifierGenerator::generate($title, $startDate, $venue);
// Normalization: lowercase, trim, collapse whitespace, remove articles

// EventUpsert handler - intelligent create-or-update
public function executeUpdate(array $parameters, array $handler_config): array {
    // Search for existing event by (title, venue, startDate)
    $existing_post_id = $this->findExistingEvent($title, $venue, $startDate);

    if ($existing_post_id) {
        // Field-by-field change detection
        if (!$this->hasDataChanged($existing_data, $parameters)) {
            return ['action' => 'no_change', 'post_id' => $existing_post_id];
        }
        // Update only changed fields
        $this->updateEventPost($existing_post_id, $parameters);
        return ['action' => 'updated', 'post_id' => $existing_post_id];
    }

    // Create new event
    $post_id = $this->createEventPost($parameters);
    return ['action' => 'created', 'post_id' => $post_id];
}

// Single-item processing with EventIdentifierGenerator
foreach ($raw_events as $raw_event) {
    $standardized_event = $this->map_ticketmaster_event($raw_event);
    $event_identifier = EventIdentifierGenerator::generate(
        $standardized_event['title'],
        $standardized_event['startDate'],
        $standardized_event['venue']
    );
    $is_processed = apply_filters('datamachine_is_item_processed', false, $flow_step_id, 'ticketmaster', $event_identifier);
    if ($is_processed) continue;

    // Mark as processed and return IMMEDIATELY
    do_action('datamachine_mark_item_processed', $flow_step_id, 'ticketmaster', $event_identifier, $job_id);
    array_unshift($data, $event_entry);
    return $data;
}
```

**Theme Integration:**
Events use theme's `single.php` template. Event Details block provides all data rendering including structured data, venue maps, and action buttons. Themes can override event presentation using standard WordPress template hierarchy.

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

// Taxonomy_Badges dynamic badge generation (moved to Core namespace)
$badges_html = DataMachineEvents\Core\Taxonomy_Badges::render_taxonomy_badges($post_id);
$color_class = DataMachineEvents\Core\Taxonomy_Badges::get_taxonomy_color_class('event_category');

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