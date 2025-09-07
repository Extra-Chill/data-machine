# CLAUDE.md

Technical guidance for Claude Code when working with the **Data Machine Events** WordPress plugin.

## Development Commands

```bash
# Install dependencies
composer install

# Calendar block (webpack)
cd inc/blocks/calendar && npm install && npm run build
npm run start          # Development watch

# Event Details block (@wordpress/scripts)
cd inc/blocks/event-details && npm install && npm run build
npm run start          # Development watch
npm run lint:js && npm run lint:css    # Linting
```

## Build Process

- **Production Build:** `./build.sh` creates optimized package in `/dist` directory with versioned .zip file for WordPress deployment
- **VSCode Integration:** `.vscode/tasks.json` provides IDE task management for development workflow
- **Asset Management:** Frontend CSS located at `assets/css/dm-events-frontend.css` (individual blocks handle own JS)
- **Dynamic Versioning:** Admin assets use `filemtime()` for cache busting
- **Build Steps:** Install dependencies, build blocks (Calendar + Event Details), copy files, create ZIP, restore dev dependencies

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
- `DmEvents\Core\Venue_Taxonomy` - Complete venue taxonomy with 9 meta fields, admin UI, and CRUD operations
- `DmEvents\Core\Event_Post_Type` - Event post type registration with selective admin menu control and taxonomy integration
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
- `DmEvents\Steps\EventImport\Handlers\WebScraper\WebScraper` - Web scraper event extraction with validation
- `DmEvents\Steps\EventImport\Handlers\WebScraper\WebScraperSettings` - Web scraper configuration management
- `DmEvents\Steps\EventImport\Handlers\WebScraper\WebScraperFilters` - Web scraper filtering system

**Data Flow:** Data Machine Import → Event Details Block → Schema Generation → Calendar Display  
**Schema Flow:** Block Attributes + Venue Taxonomy Meta → DmEventsSchema → JSON-LD Output

### Blocks & Venues

**Blocks:**
- `inc/blocks/calendar/` - Events display with filtering (webpack)
- `inc/blocks/event-details/` - Event data storage (@wordpress/scripts)

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
- `inc/blocks/calendar/` - Events display block (webpack build system)
- `inc/blocks/event-details/` - Event data storage block (@wordpress/scripts build system)
- `inc/core/class-event-post-type.php` - Event post type with selective menu control
- `inc/core/class-venue-taxonomy.php` - Venue taxonomy with 9 meta fields and admin UI
- `inc/steps/event-import/handlers/` - Import handlers with single-item processing (Ticketmaster, Dice FM, web scrapers)
- `inc/steps/publish/handlers/dm-events/` - AI-driven publishing with Schema generation and venue handling
  - `DmEventsSchema.php` - Google Event structured data generator
  - `DmEventsPublisher.php` - AI-powered event creation
  - `DmEventsVenue.php` - Centralized venue taxonomy operations
- `assets/css/dm-events-frontend.css` - Frontend styling (blocks handle own JavaScript)
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

### Security Standards
- Nonce verification on all forms
- Input sanitization with `wp_unslash()`
- Capability checks for admin functions

### Build Systems
- **Calendar:** webpack (`npm run build/start`)
- **Event Details:** @wordpress/scripts (`npm run build/start`)
- **Production Build:** `./build.sh` creates optimized package in `/dist` directory with versioned .zip file
- **VSCode Tasks:** `.vscode/tasks.json` provides IDE task management for development workflow
- **Asset Strategy:** Individual blocks handle own JavaScript, shared frontend CSS in `assets/css/dm-events-frontend.css`
- **Dynamic Versioning:** Admin assets use `filemtime()` for automatic cache invalidation
- **Build Steps:** Install dependencies, build blocks (Calendar + Event Details), copy files, create ZIP, restore dev dependencies

### Data Machine Integration
- **Import Handlers:** Ticketmaster Discovery API (API key auth with comprehensive validation), Dice FM, Web Scrapers in `inc/steps/event-import/handlers/`
- **Publishers:** Data Machine Events publisher with AI-driven event creation in `inc/steps/publish/handlers/dm-events/`
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
- **Schema Architecture:** Combines Event Details block attributes with venue taxonomy meta for complete Google Event schema
- **Smart Parameter Routing:** DmEventsSchema.engine_or_tool() analyzes import data to route parameters between system and AI processing
- **Venue Data Flow:** Import handlers extract venue data, DmEventsPublisher injects via dm_engine_additional_parameters filter
- **Comprehensive Venue Meta:** 9 meta fields populated from import sources with validation and error handling
- **AI Responsibilities:** Event descriptions, performer/organizer inference, and taxonomies configured for "ai_decides"
- **Block Content Generation:** Event Details blocks with proper address attribute mapping and display controls
- **SEO Enhancement:** Google Event structured data with location, performer, organizer, offers, and event status data
- **Status Detection:** Comprehensive red/yellow/green monitoring via Status_Detection class for all system components
- **Security & Validation:** WordPress security compliance with comprehensive sanitization and capability checks

### Data Machine Integration Architecture

**Unified Step Execution Pattern:**
All custom steps use Data Machine's unified parameter system via dm_engine_parameters filter:

```php
public function execute(array $parameters): array {
    // Extract from unified parameter structure
    $job_id = $parameters['execution']['job_id'];
    $flow_step_id = $parameters['execution']['flow_step_id'];
    $data = $parameters['data'] ?? [];
    $flow_step_config = $parameters['config']['flow_step'] ?? [];
    
    // Access dynamic metadata from engine filter
    $metadata = $parameters['metadata'] ?? [];
    
    // Process step logic...
    
    return $data; // Always return data packet for pipeline continuity
}
```

**Handler Implementation Pattern:**
All import handlers follow Data Machine's single-item processing model with comprehensive validation and error handling:

```php
public function get_fetch_data(int $pipeline_id, array $handler_config, ?string $job_id = null): array {
    $flow_step_id = $handler_config['flow_step_id'] ?? null;
    
    // Get API configuration from Data Machine auth system
    $api_config = apply_filters('dm_retrieve_oauth_keys', [], 'ticketmaster_events');
    if (empty($api_config['api_key'])) {
        $this->log_error('Ticketmaster API key not configured');
        return ['processed_items' => []];
    }
    
    // Build search parameters with validation
    $search_params = $this->build_search_params($handler_config, $api_config['api_key']);
    
    // Fetch events from API with error handling
    $raw_events = $this->fetch_events($search_params);
    if (empty($raw_events)) {
        $this->log_info('No events found from Ticketmaster API');
        return ['processed_items' => []];
    }
    
    // Process ONE event at a time
    foreach ($raw_events as $raw_event) {
        $standardized_event = $this->standardize_event($raw_event);
        
        if (empty($standardized_event['title'])) continue;
        
        $event_identifier = md5($standardized_event['title'] . $standardized_event['startDate'] . $standardized_event['venue']);
        
        // Check if already processed FIRST
        $is_processed = apply_filters('dm_is_item_processed', false, $flow_step_id, 'source_type', $event_identifier);
        if ($is_processed) continue;
        
        // Apply individual event filters (e.g., future events only)
        if (!$this->is_future_event($standardized_event)) {
            $this->log_debug('Skipping past event', [
                'title' => $standardized_event['title'],
                'date' => $standardized_event['startDate']
            ]);
            continue;
        }
        
        // Mark as processed and return IMMEDIATELY
        if ($flow_step_id && $job_id) {
            do_action('dm_mark_item_processed', $flow_step_id, 'source_type', $event_identifier, $job_id);
        }
        
        return ['processed_items' => [['data' => $standardized_event, 'metadata' => [...]]]];
    }
    
    // No eligible events found
    return ['processed_items' => []];
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

### Unified Data Machine Parameter System

**Single Parameter Architecture:**
Data Machine uses a unified parameter system managed by the `dm_engine_parameters` filter:

```php
// All custom steps implement this simplified signature
public function execute(array $parameters): array
```

**Unified Parameter Structure:**
```php
$parameters = [
    'execution' => [
        'job_id' => 'unique-job-identifier',
        'flow_step_id' => 'flow-step-uuid'
    ],
    'config' => [
        'flow_step' => [] // Step configuration from pipeline builder
    ],
    'data' => [], // Cumulative data packet from previous steps
    'metadata' => [
        // Dynamic metadata from dm_engine_additional_parameters filter
        'venue' => 'Music Hall',
        'venueAddress' => '123 Main St',
        'venueCity' => 'Charleston',
        // ... other injected venue data
    ]
];
```

**Benefits:**
- **Ultimate Simplicity:** Single parameter structure for all steps
- **Consistency:** EventImportStep, FetchStep, PublishStep, UpdateStep all identical
- **Centralized Management:** Engine completely manages parameter assembly via filters
- **Pipeline Continuity:** Data packet flows unchanged through error conditions
- **Maintainability:** No parameter mapping complexity between steps
- **Future-Proof:** Single point of parameter evolution for entire Data Machine ecosystem