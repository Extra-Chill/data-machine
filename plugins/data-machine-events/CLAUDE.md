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
cd inc/blocks/event-details && npm install && wp-scripts build  
wp-scripts start       # Development watch
npm run lint:js && npm run lint:css    # Linting
```

## Build Process

- **Production Build:** Create optimized package in `/dist` directory with versioned .zip file for WordPress deployment
- **VSCode Integration:** Must include `tasks.json` file in `.vscode/` directory for IDE task management
- **Asset Management:** Frontend CSS located at `assets/css/dm-events-frontend.css` (individual blocks handle own JS)
- **Dynamic Versioning:** Admin assets use `filemtime()` for cache busting

## Architecture

### Core Principles
- **Block-First:** Event data stored in `Event Details` block attributes (single source of truth)
- **Frontend-Focused:** Event display and presentation (works with Data Machine for imports)
- **Performance Optimized:** Background sync to meta fields for efficient queries
- **PSR-4 Structure:** `DmEvents\` namespace with custom autoloader
- **Data Machine Ready:** Event creation handled by Data Machine plugin pipeline

### Key Components

**Core Classes:**
- `DmEvents\Admin` - Display settings interface
- `DmEvents\Admin\Status_Detection` - Data Machine status detection system
- `DmEvents\Events\Event_Data_Manager` - Syncs block data to meta fields (block-first architecture)
- `DmEvents\Events\Venues\Venue_Term_Meta` - Venue taxonomy admin with full CRUD operations
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsPublisher` - AI-driven event creation with taxonomy handling
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsSettings` - Publisher configuration management
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsFilters` - Publisher filtering system
- `DmEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterAuth` - API key authentication provider

**Data Flow:** Data Machine → Event Details Block → Background Sync → Calendar Display

### Blocks & Venues

**Blocks:**
- `inc/blocks/calendar/` - Events display with filtering (webpack)
- `inc/blocks/event-details/` - Event data storage (@wordpress/scripts)

**Venues:**
- WordPress taxonomy with comprehensive term meta (10 fields: address, city, state, zip, country, phone, website, capacity, coordinates, description)
- Admin interface via `Venue_Term_Meta` class with full CRUD operations

## File Structure

**Classes:** PascalCase → `class-kebab-case.php`

**Key Directories:**
- `dm-events.php` - Main plugin file with PSR-4 autoloader
- `inc/admin/` - Display settings interface and status detection
- `inc/blocks/calendar/` - Events display block (webpack build system)
- `inc/blocks/event-details/` - Event data storage block (@wordpress/scripts build system)
- `inc/events/` - Event data management
- `inc/events/venues/` - Venue taxonomy with comprehensive term meta
- `inc/steps/event-import/handlers/` - Data Machine import handlers (Ticketmaster, Dice FM, web scrapers)
- `inc/steps/publish/handlers/dm-events/` - Data Machine publisher with AI integration and filtering
- `assets/css/dm-events-frontend.css` - Frontend styling (blocks handle own JavaScript)
- `assets/css/admin.css` - Admin interface styling
- `assets/js/admin.js` - Admin JavaScript functionality

## WordPress Integration

- **Post Type:** `dm_events`
- **Taxonomy:** Venues with term meta
- **Meta Fields:** Performance and REST API meta fields synced from blocks
  - `_dm_event_start_date_utc` (performance queries)
  - `_dm_event_start_date`, `_dm_event_end_date` (REST API)
  - `_dm_event_venue_name`, `_dm_event_artist_name` (REST API)
  - `_dm_event_price`, `_dm_event_ticket_url` (REST API)
- **REST API:** Complete event metadata exposed
- **Primary Data:** Block attributes (meta fields for performance/API only)

## Technical Notes

### Data Strategy
- **Primary Source:** Block attributes (single source of truth)
- **Performance:** Meta fields synced via WP Cron for calendar queries
- **Background Sync:** `Event_Data_Manager` handles meta field updates

### Security Standards
- Nonce verification on all forms
- Input sanitization with `wp_unslash()`
- Capability checks for admin functions

### Build Systems
- **Calendar:** webpack (`npm run build/start`)
- **Event Details:** @wordpress/scripts (`wp-scripts build/start`)
- **Production Build:** Must create optimized package in `/dist` directory with versioned .zip file
- **VSCode Tasks:** Required `tasks.json` file in `.vscode/` for development workflow
- **Asset Strategy:** Individual blocks handle own JavaScript, shared frontend CSS in `assets/css/dm-events-frontend.css`
- **Dynamic Versioning:** Admin assets use `filemtime()` for automatic cache invalidation

### Data Machine Integration
- **Import Handlers:** Ticketmaster (API key auth), Dice FM, Web Scrapers in `inc/steps/event-import/handlers/`
- **Publishers:** Data Machine Events publisher with AI-driven event creation in `inc/steps/publish/handlers/dm-events/`
- **Event Creation:** Data Machine processes one event per job via `Event Details` block attributes with AI-generated descriptions
- **Duplicate Prevention:** Flow-scoped processed items tracking prevents importing duplicate events per flow
- **Authentication:** Simplified API key authentication for Ticketmaster (no OAuth required)
- **Single-Item Processing:** All handlers process one event per job execution, returning first eligible event immediately
- **Handler Pattern:** Loop through raw data, check `dm_is_item_processed`, apply filters, return first eligible event
- **Processed Items Tracking:** Mark events as processed only after confirming eligibility and before returning
- **API Efficiency:** Handlers process items incrementally across multiple job executions, no wasted API calls

### AI Integration & Publishing
- **DmEventsPublisher:** Handles AI tool calls to create WordPress posts with Event Details blocks
- **DmEventsSettings:** Manages publisher configuration and settings
- **DmEventsFilters:** Provides filtering system for publisher operations
- **Taxonomy Management:** AI can create new venue terms and populate term meta automatically
- **Venue Meta Fields:** Address, phone, website, capacity automatically populated from AI parameters (10 fields total)
- **Block Generation:** AI parameters converted to Event Details block attributes in post content
- **Security:** Capability checks, nonce verification, input sanitization for all AI-generated content
- **Settings Integration:** Taxonomy assignments configurable (AI decides vs pre-selected terms)
- **Status Detection:** Integrated status detection system via `Status_Detection` class

### Handler Implementation Pattern

All import handlers follow Data Machine's single-item processing model:

```php
public function get_fetch_data(int $pipeline_id, array $handler_config, ?string $job_id = null): array {
    $flow_step_id = $handler_config['flow_step_id'] ?? null;
    
    // Fetch raw data from source (API, scraper, etc.)
    $raw_events = $this->fetch_source_data($handler_config);
    if (empty($raw_events)) {
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
        
        // Apply source-specific filters
        if (!$this->is_eligible($standardized_event)) continue;
        
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