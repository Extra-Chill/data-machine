# CLAUDE.md

Technical guidance for Claude Code when working with the **Data Machine Events** WordPress plugin.

**Version**: 0.1.0

## Development Commands

```bash
# Install dependencies
composer install

# Calendar block (webpack)
cd inc/blocks/calendar && npm install && npm run build
npm run start          # Development watch

# Event Details block (webpack with @wordpress/scripts base)
cd inc/blocks/EventDetails && npm install && npm run build
npm run start          # Development watch
npm run lint:js && npm run lint:css    # Linting
```

## Build Process

- **Production Build:** `./build.sh` creates optimized package in `/dist` directory as `dm-events.zip` with accompanying `build-info.txt` for WordPress deployment
- **VSCode Integration:** `.vscode/tasks.json` provides IDE task management for development workflow
- **Asset Management:** Individual blocks handle their own CSS and JavaScript assets
- **Dynamic Versioning:** Admin assets use `filemtime()` for cache busting
- **Automated Build Pipeline:**
  1. Clean build directory and install production composer dependencies
  2. Build Calendar block (webpack) and Event Details block (webpack with @wordpress/scripts base)
  3. Copy plugin files with rsync (excludes development files)
  4. Create ZIP package with build info
  5. Restore development dependencies for continued work

## Architecture

### Core Principles
- **Block-First:** Event data stored in `Event Details` block attributes (single source of truth)
- **Frontend-Focused:** Event display and presentation (works with Data Machine for imports)
- **Performance Optimized:** Background sync to meta fields for efficient queries
- **PSR-4 Structure:** `DmEvents\` namespace with custom autoloader
- **Data Machine Ready:** Event creation handled by Data Machine plugin pipeline

### Key Components

**Core Classes:**
- `DmEvents\Admin\Status_Detection` - Comprehensive Data Machine integration status monitoring with red/yellow/green indicators
- `DmEvents\Admin\Settings_Page` - Minimal settings interface for controlling event archive behavior, search integration, and display preferences
- `DmEvents\Core\Venue_Taxonomy` - Complete venue taxonomy with 9 meta fields, admin UI, and CRUD operations
- `DmEvents\Core\Event_Post_Type` - Event post type registration with selective admin menu control and taxonomy integration
- `DmEvents\Core\Taxonomy_Badges` - Dynamic taxonomy badge rendering system with automatic color generation and badge display for all non-venue taxonomies (filterable via dm_events_badge_wrapper_classes and dm_events_badge_classes)
- `DmEvents\Core\Breadcrumbs` - Breadcrumb generation with filterable output via dm_events_breadcrumbs filter for theme integration
- `DmEvents\Blocks\Calendar\Template_Loader` - Modular template loading system with variable extraction, output buffering, and template caching for calendar block components
- `DmEvents\Blocks\Calendar\Taxonomy_Helper` - Taxonomy data discovery, hierarchy building, and post count calculations for calendar filtering systems
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsSchema` - Google Event Schema JSON-LD generator for enhanced SEO visibility
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsPublisher` - AI-driven event creation with comprehensive venue handling
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsSettings` - Publisher configuration management
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsFilters` - Publisher filtering system
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsVenue` - Centralized venue taxonomy operations with validation and error handling
- `DmEvents\Steps\EventImport\EventImportStep` - Event import step for Data Machine pipeline with handler discovery
- `DmEvents\Steps\EventImport\EventImportFilters` - Event import step registration with Data Machine
- `DmEvents\Steps\EventImport\Handlers\Ticketmaster\Ticketmaster` - Discovery API integration with single-item processing
- `DmEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterAuth` - API key authentication provider
- `DmEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterSettings` - Handler configuration management
- `DmEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterFilters` - Event filtering system
- `DmEvents\Steps\EventImport\Handlers\DiceFm\DiceFm` - Dice FM event integration with standardized processing
- `DmEvents\Steps\EventImport\Handlers\DiceFm\DiceFmAuth` - Dice FM authentication provider
- `DmEvents\Steps\EventImport\Handlers\DiceFm\DiceFmSettings` - Dice FM handler configuration management
- `DmEvents\Steps\EventImport\Handlers\DiceFm\DiceFmFilters` - Dice FM event filtering system
- `DmEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper` - AI-powered universal web scraper with HTML section processing and tool call handling
- `DmEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraperSettings` - Universal web scraper configuration management
- `DmEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraperFilters` - Universal web scraper filtering system

**Data Flow:** Data Machine Import → Event Details Block → Schema Generation → Calendar Display  
**Schema Flow:** Block Attributes + Venue Taxonomy Meta → DmEventsSchema → JSON-LD Output

### Blocks & Venues

**Blocks:**
- `inc/blocks/calendar/` - Events display with filtering, pagination, modular template system, and DisplayStyles visual enhancement system (webpack build system)
- `inc/blocks/EventDetails/` - Event data storage with InnerBlocks rich content support (webpack with @wordpress/scripts base)
- `inc/blocks/root.css` - Centralized design tokens and CSS custom properties accessible from both CSS and JavaScript

**Template System:**
- **Template_Loader:** Handles loading and rendering of calendar component templates with proper variable passing and clean separation between data processing and HTML presentation
- **Modular Templates:** 7 specialized templates including event-item, date-group, pagination, navigation, no-events, filter-bar, time-gap-separator plus modal subdirectory
- **Modal Templates:** Dedicated modal subdirectory with taxonomy-filter.php for advanced filtering interfaces
- **Variable Extraction:** EXTR_SKIP pattern ensures safe variable passing to template scope without conflicts
- **Output Buffering:** Templates return content as strings for flexible rendering and caching capabilities

**Event Details Block Architecture:**
- **InnerBlocks Integration:** Support for rich content editing within event posts
- **15+ Event Attributes:** Comprehensive data model (startDate, endDate, startTime, endTime, venue, address, price, ticketUrl, performer, performerType, organizer, organizerType, organizerUrl, eventStatus, previousStartDate, priceCurrency, offerAvailability)
- **Display Controls:** showVenue, showPrice, showTicketLink, showPerformer for flexible rendering
- **Block-First Data:** Single source of truth with background sync to meta fields for query efficiency

**Venues:**
- WordPress taxonomy with comprehensive term meta (9 fields: address, city, state, zip, country, phone, website, capacity, coordinates)
- Native WordPress description field used for venue descriptions
- Admin interface via `Venue_Taxonomy` class in `inc/core/class-venue-taxonomy.php` with full CRUD operations
- Dynamic admin form fields for both add and edit operations
- Centralized venue handling via `DmEventsVenue` class for Data Machine integration

## File Structure

**Classes:** PascalCase → `class-kebab-case.php`

**Key Directories:**
- `dm-events.php` - Main plugin file with PSR-4 autoloader
- `inc/admin/class-status-detection.php` - Data Machine integration status monitoring
- `inc/admin/class-settings-page.php` - Event settings interface for archive behavior and display preferences
- `inc/blocks/calendar/` - Events display block with modular template system and DisplayStyles visual enhancement (webpack build system)
  - `class-template-loader.php` - Template loading system with variable extraction and output buffering
  - `class-taxonomy-helper.php` - Taxonomy data processing with hierarchy building and post count calculations
  - `DisplayStyles/` - Visual enhancement components with CircuitGridRenderer.js, CarouselListRenderer.js, and BadgeRenderer.js
  - `templates/` - 7 modular template files for calendar components plus modal subdirectory
- `inc/blocks/EventDetails/` - Event data storage block (webpack with @wordpress/scripts base)
- `inc/blocks/root.css` - Centralized design tokens and CSS custom properties for all blocks
- `inc/core/class-event-post-type.php` - Event post type with selective menu control
- `inc/core/class-venue-taxonomy.php` - Venue taxonomy with 9 meta fields and admin UI
- `inc/core/class-taxonomy-badges.php` - Dynamic taxonomy badge rendering with automatic color generation
- `inc/core/class-breadcrumbs.php` - Breadcrumb generation with filterable output for theme integration
- `inc/steps/EventImport/handlers/` - Import handlers with single-item processing (Ticketmaster, Dice FM, AI-powered web scrapers)
- `inc/steps/publish/handlers/DmEvents/` - AI-driven publishing with Schema generation and venue handling
  - `DmEventsSchema.php` - Google Event structured data generator
  - `DmEventsPublisher.php` - AI-powered event creation
  - `DmEventsVenue.php` - Centralized venue taxonomy operations
- `templates/single-dm_events.php` - Single event template with extensibility hooks
- `assets/css/admin.css` - Admin interface styling
- `assets/js/admin.js` - Admin JavaScript functionality

## WordPress Integration

- **Post Type:** `dm_events`
- **Taxonomy:** Venues with term meta
- **Venue Meta Fields:** 9 comprehensive meta fields via Venue_Taxonomy class
  - `_venue_address`, `_venue_city`, `_venue_state`, `_venue_zip` (location data)
  - `_venue_country`, `_venue_phone`, `_venue_website` (contact information)
  - `_venue_capacity`, `_venue_coordinates` (venue specifications)
  - Plus native WordPress description field for venue details
- **Taxonomy Badge System:** Dynamic rendering of taxonomy badges for all non-venue taxonomies with consistent color classes and data attributes
- **Schema Integration:** Google Event structured data generated from block attributes and venue meta
- **REST API:** Native WordPress REST API via show_in_rest => true
- **Primary Data:** Block attributes (single source of truth), venue taxonomy meta for location data

## Technical Notes

### Data Strategy  
- **Block-First Architecture:** Event Details block attributes serve as single source of truth
- **Schema Generation:** DmEventsSchema combines block data with venue taxonomy meta for comprehensive SEO markup
- **Venue Data Integration:** Venue_Taxonomy provides 9 meta fields with admin UI for complete venue management
- **Smart Parameter Routing:** DmEventsSchema.engine_or_tool() intelligently routes data between system and AI parameters
- **Venue Data Priority:** Venue taxonomy data overrides address attributes during event rendering
- **Single-Item Processing:** Import handlers process one event per job with duplicate prevention
- **Taxonomy Data Processing:** Taxonomy_Helper provides hierarchy building, post count calculations, and structured taxonomy data for calendar filtering systems
- **Template-Driven Display:** Template_Loader enables modular, cacheable template rendering with variable extraction and output buffering

### Security Standards
- Nonce verification on all forms
- Input sanitization with `wp_unslash()`
- Capability checks for admin functions

### Build Systems
- **Calendar Block:** webpack build system with modular template architecture, Template_Loader system, and DisplayStyles visual enhancement (`cd inc/blocks/calendar && npm run build/start`)
- **Event Details Block:** webpack with @wordpress/scripts base (`cd inc/blocks/EventDetails && npm run build/start`)
- **Centralized Design System:** root.css provides unified design tokens accessible from both CSS and JavaScript
- **Visual Enhancement:** DisplayStyles components including CircuitGridRenderer.js, CarouselListRenderer.js, and BadgeRenderer.js for calendar display
- **Template Architecture:** Modular template system with 7 specialized templates plus modal subdirectory for advanced filtering interfaces
- **Template Loading:** Template_Loader class provides get_template(), include_template(), template_exists(), and get_template_path() methods with variable extraction
- **Production Build:** `./build.sh` creates optimized package in `/dist` directory with `dm-events.zip` for WordPress deployment
- **VSCode Integration:** `.vscode/tasks.json` provides IDE task management for development workflow
- **Asset Strategy:** Individual blocks handle their own CSS and JavaScript assets independently
- **Dynamic Versioning:** Admin assets use `filemtime()` for automatic cache invalidation
- **Automated Build Steps:**
  1. Install production composer dependencies (`composer install --no-dev --optimize-autoloader`)
  2. Build Calendar block with webpack (`npm ci && npm run build`)
  3. Build Event Details block with webpack using @wordpress/scripts base (`npm ci && npm run build`)
  4. Copy files with rsync (excludes node_modules, src, development files)
  5. Create `dm-events.zip` file in `/dist` directory
  6. Generate build info and restore development dependencies

### Data Machine Integration
- **Import Handlers:** Ticketmaster Discovery API (API key auth with comprehensive validation), Dice FM, AI-powered UniversalWebScraper in `inc/steps/EventImport/handlers/`
- **AI-Powered Web Scraping:** UniversalWebScraper uses AI to extract structured event data from HTML sections with tool call handling
- **Publishers:** Data Machine Events publisher with AI-driven event creation in `inc/steps/publish/handlers/DmEvents/`
- **Event Creation:** Data Machine processes one event per job via `Event Details` block attributes with AI-generated descriptions
- **Duplicate Prevention:** Flow-scoped processed items tracking prevents importing duplicate events per flow
- **Authentication:** API key authentication for Ticketmaster Discovery API with comprehensive validation and error handling
- **Single-Item Processing:** All handlers process one event per job execution, returning first eligible event immediately
- **Handler Pattern:** Loop through raw data, check `dm_is_item_processed`, apply filters, return first eligible event
- **Processed Items Tracking:** Mark events as processed only after confirming eligibility and before returning
- **API Efficiency:** Handlers process items incrementally across multiple job executions, no wasted API calls

### AI Integration & Schema Generation
- **DmEventsSchema:** Core Schema generator with smart parameter routing (engine vs AI decisions) and comprehensive structured data output
- **DmEventsPublisher:** AI-driven event creation with Event Details block generation and taxonomy handling
- **DmEventsVenue:** Centralized venue operations including term creation, lookup, metadata validation, and assignment workflows
- **UniversalWebScraper:** AI-powered web scraping with HTML section extraction and structured event data processing
- **Visual Enhancement System:** DisplayStyles components with CircuitGridRenderer.js, CarouselListRenderer.js, and BadgeRenderer.js for flexible calendar display
- **Centralized Design System:** root.css provides unified design tokens accessible from both CSS and JavaScript components
- **Schema Architecture:** Combines Event Details block attributes with venue taxonomy meta for complete Google Event schema
- **Smart Parameter Routing:** DmEventsSchema.engine_or_tool() analyzes import data to route parameters between system and AI processing
- **Venue Data Flow:** Import handlers extract venue data, DmEventsPublisher injects via dm_engine_additional_parameters filter
- **Comprehensive Venue Meta:** 9 meta fields populated from import sources with validation and error handling
- **AI Responsibilities:** Event descriptions, performer/organizer inference, web scraping extraction, and taxonomies configured for "ai_decides"
- **Block Content Generation:** Event Details blocks with proper address attribute mapping and display controls
- **SEO Enhancement:** Google Event structured data with location, performer, organizer, offers, and event status data
- **Status Detection:** Comprehensive red/yellow/green monitoring via Status_Detection class for all system components
- **Security & Validation:** WordPress security compliance with comprehensive sanitization and capability checks

### Data Machine Integration Architecture

**Unified Step Execution Pattern:**
All custom steps use Data Machine's unified parameter system via dm_engine_parameters filter:

```php
public function execute(array $parameters): array {
    // Extract from flat parameter structure
    $job_id = $parameters['job_id'];
    $flow_step_id = $parameters['flow_step_id'];
    $data = $parameters['data'] ?? [];
    $flow_step_config = $parameters['flow_step_config'] ?? [];
    
    // Process step logic...
    
    return $data; // Always return data packet for pipeline continuity
}
```

**Handler Implementation Pattern:**
All import handlers follow Data Machine's single-item processing model with flat parameter structure:

```php
public function execute(array $parameters): array {
    // Extract from flat parameter structure (matches PublishStep pattern)
    $job_id = $parameters['job_id'];
    $flow_step_id = $parameters['flow_step_id'];
    $data = $parameters['data'] ?? [];
    $flow_step_config = $parameters['flow_step_config'] ?? [];
    
    // Extract handler configuration
    $handler_config = $flow_step_config['handler']['settings'] ?? [];
    
    // Get API configuration from Data Machine auth system
    $api_config = apply_filters('dm_retrieve_oauth_keys', [], 'ticketmaster_events');
    if (empty($api_config['api_key'])) {
        $this->log_error('Ticketmaster API key not configured');
        return $data; // Return unchanged data packet array
    }
    
    // Build search parameters with validation
    $search_params = $this->build_search_params($handler_config, $api_config['api_key']);
    
    // Fetch events from API with error handling
    $raw_events = $this->fetch_events($search_params);
    if (empty($raw_events)) {
        $this->log_info('No events found from Ticketmaster API');
        return $data; // Return unchanged data packet array
    }
    
    // Process ONE event at a time
    foreach ($raw_events as $raw_event) {
        $standardized_event = $this->map_ticketmaster_event($raw_event);
        
        if (empty($standardized_event['title'])) continue;
        
        $event_identifier = md5($standardized_event['title'] . $standardized_event['startDate'] . $standardized_event['venue']);
        
        // Check if already processed FIRST
        $is_processed = apply_filters('dm_is_item_processed', false, $flow_step_id, 'ticketmaster', $event_identifier);
        if ($is_processed) continue;
        
        // API handles future events filtering via startDateTime parameter
        
        // Mark as processed and return IMMEDIATELY
        if ($flow_step_id && $job_id) {
            do_action('dm_mark_item_processed', $flow_step_id, 'ticketmaster', $event_identifier, $job_id);
        }
        
        // Add to data packet array and return
        array_unshift($data, $event_entry);
        return $data;
    }
    
    // No eligible events found
    return $data; // Return unchanged data packet array
}
```

**Key Principles:**
- **One Event Per Job:** Each execution processes exactly one event
- **Early Exit:** Return immediately on first eligible event 
- **Duplicate Prevention:** Check `dm_is_item_processed` before heavy processing
- **Mark Only Processed:** Only mark events as processed that actually get returned
- **Incremental Processing:** Multiple job executions handle different events over time

### Venue Data Flow Architecture

**Import to Publish Pipeline:**
1. **Import Handlers:** Extract venue data (venueAddress, venueCity, venueState, venueZip, venueCountry, venuePhone, venueWebsite, venueCoordinates)
2. **DmEventsPublisher Filter:** Injects venue data via `dm_engine_additional_parameters` filter for publish steps using create_event handler
3. **Block Content Generation:** Maps venue data to Event Details block address attribute and display controls
4. **Rendering Priority:** Venue taxonomy data overrides address attributes when available

**Venue Data Injection Pattern:**
```php
public function inject_venue_parameters($additional, $data, $flow_step_config, $step_type, $flow_step_id) {
    // Only inject for publish steps using create_event handler
    if ($step_type !== 'publish' || $flow_step_config['handler'] !== 'create_event') {
        return $additional;
    }
    
    // Inject detailed venue data from import handlers
    $venue_fields = ['venueAddress', 'venueCity', 'venueState', 'venueZip', 'venueCountry', 'venuePhone', 'venueWebsite', 'venueCoordinates'];
    foreach ($venue_fields as $field) {
        if (!empty($data[$field])) {
            $additional[$field] = $data[$field];
        }
    }
    
    return $additional;
}
```

### Schema Generation Architecture

**Google Event Schema Generation:**
1. **Block-First Source:** DmEventsSchema generates structured data from Event Details block attributes
2. **Venue Integration:** Combines block data with venue taxonomy meta for complete location information
3. **SEO Enhancement:** Provides comprehensive schema markup for improved search visibility
4. **Standards Compliance:** Follows Google Event schema specifications for optimal search integration

**Schema Generation Pattern:**
```php
// Smart parameter routing for engine vs AI decisions
$routing = DmEventsSchema::engine_or_tool($event_data, $import_data);
// engine: ['startDate' => '2025-09-30', 'venue' => 'Music Hall', 'venueAddress' => '123 Main St']
// tool: ['description', 'performer', 'organizer'] // AI inference parameters

// Generate comprehensive Event schema with venue integration
$venue_data = Venue_Taxonomy::get_venue_data($venue_term_id);
$schema = DmEventsSchema::generate_event_schema($block_attributes, $venue_data, $post_id);

// Output structured data for enhanced search visibility
echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>';

// Venue operations with comprehensive validation
$venue_result = DmEventsVenue::find_or_create_venue($venue_name, $venue_data);
$assignment = DmEventsVenue::assign_venue_to_event($post_id, $venue_name, $venue_data);
```

### Template Architecture

**Single Event Template:**
Single event template (`templates/single-dm_events.php`) provides extensibility via action hooks:

```php
// Template structure with action hooks
get_header();
do_action('dm_events_before_single_event');     // Theme integration point

// Event article with breadcrumbs, badges, content
do_action('dm_events_after_event_article');     // Post-content hook

// Aside section for comments and related content
do_action('dm_events_related_events', $post_id); // Related events display

do_action('dm_events_after_single_event');      // Pre-footer hook
get_footer();
```

**Template Extensibility:**
- **dm_events_before_single_event** - Fires after get_header(), enables theme notices/alerts injection
- **dm_events_after_event_article** - Fires after event content, before comments/aside
- **dm_events_related_events** - Fires in aside section, enables related events by taxonomy
- **dm_events_after_single_event** - Fires before get_footer(), enables footer content injection
- **Standard Comments Support** - Uses WordPress comments_template() with site settings respect

**Calendar Block Template System:**
Calendar block uses a modular template architecture with 7 specialized templates plus modal subdirectory:

```php
// Template_Loader provides clean template rendering
Template_Loader::init(); // Initialize template path
$content = Template_Loader::get_template('event-item', $variables);
Template_Loader::include_template('date-group', $group_data);

// Template structure
templates/
├── single-dm_events.php      # Single event with action hooks
├── event-item.php           # Individual event display
├── date-group.php           # Day-grouped event container
├── pagination.php           # Event pagination controls
├── navigation.php           # Calendar navigation
├── no-events.php           # Empty state display
├── filter-bar.php          # Taxonomy filtering interface
├── time-gap-separator.php   # Time gap visual separator for carousel-list mode
└── modal/
    └── taxonomy-filter.php  # Advanced filter modal
```

**Template Loading Pattern:**
```php
// Safe variable extraction with EXTR_SKIP
public static function get_template($template_name, $variables = []) {
    $template_file = self::$template_path . $template_name . '.php';
    
    if (!file_exists($template_file)) {
        return '<!-- Template not found: ' . esc_html($template_name) . ' -->';
    }
    
    // Extract variables into template scope
    if (!empty($variables)) {
        extract($variables, EXTR_SKIP);
    }
    
    // Capture template output
    ob_start();
    include $template_file;
    return ob_get_clean();
}
```

**Taxonomy Data Processing:**
```php
// Taxonomy_Helper provides structured data for filtering
$taxonomies = Taxonomy_Helper::get_all_taxonomies_with_counts();
$hierarchy = Taxonomy_Helper::get_taxonomy_hierarchy($taxonomy_slug);
$flattened = Taxonomy_Helper::flatten_hierarchy($hierarchy);

// Each taxonomy includes:
// - label, name, hierarchical status
// - terms with event_count, level, children
// - post count calculations for filtering
```

**Badge System Integration:**
```php
// Taxonomy_Badges renders consistent badge HTML
$badges_html = Taxonomy_Badges::render_taxonomy_badges($post_id);
$color_class = Taxonomy_Badges::get_taxonomy_color_class($taxonomy_slug);
$used_taxonomies = Taxonomy_Badges::get_used_taxonomies();

// Badge structure:
// <div class="dm-taxonomy-badges">
//   <span class="dm-taxonomy-badge dm-taxonomy-{slug} dm-term-{term-slug}" 
//         data-taxonomy="{slug}" data-term="{term-slug}">
//     {term-name}
//   </span>
// </div>
```

### Flat Data Machine Parameter System

**Flat Parameter Architecture:**
Data Machine uses a flat parameter system managed by the `dm_engine_parameters` filter:

```php
// All custom steps implement this simplified signature
public function execute(array $parameters): array
```

**Flat Parameter Structure:**
```php
$parameters = [
    'job_id' => 'unique-job-identifier',
    'flow_step_id' => 'flow-step-uuid',
    'flow_step_config' => [], // Step configuration from pipeline builder
    'data' => [], // Cumulative data packet from previous steps
    // Dynamic metadata from dm_engine_additional_parameters filter
    'venue' => 'Music Hall',
    'venueAddress' => '123 Main St',
    'venueCity' => 'Charleston',
    // ... other injected parameters
];
```

**Benefits:**
- **Ultimate Simplicity:** Single flat parameter structure for all steps
- **Consistency:** EventImportStep, FetchStep, PublishStep, UpdateStep all use identical flat structure
- **Direct Access:** No nested parameter extraction required - all parameters available at top level
- **Pipeline Continuity:** Data packet flows unchanged through error conditions
- **Maintainability:** No parameter mapping complexity between steps
- **Future-Proof:** Single point of parameter evolution for entire Data Machine ecosystem