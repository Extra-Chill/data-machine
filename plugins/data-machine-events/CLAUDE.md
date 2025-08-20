# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

**Install Dependencies:**
```bash
composer install
```

**Block Development:**
```bash
# Calendar block (uses webpack)
cd inc/blocks/calendar
npm install
npm run build          # Production build
npm run start          # Development with watch

# Event Details block (uses @wordpress/scripts)
cd inc/blocks/event-details  
npm install
npm run build          # Production build
npm run start          # Development with watch
npm run lint:js        # JavaScript linting
npm run lint:css       # CSS linting
```

**Testing:** No specific test framework configured - manual testing required

## Core Architecture

### Plugin Focus
- **Frontend-Focused:** Event display, presentation, and user experience
- **Block-First Architecture:** Event data stored in `Event Details` Gutenberg block attributes
- **Schema & SEO:** Structured data output for search engines
- **Venue Management:** Rich venue taxonomy with custom term meta
- **Data Machine Ready:** Event creation handled by Data Machine plugin

### Plugin Structure
- **PSR-4 Autoloading:** Classes in `ChillEvents\` namespace with custom autoloader in main plugin file
- **Filter-Based Dependencies:** WordPress filters handle component initialization and dependency injection
- **Venue Taxonomy System:** Rich venue management with address, phone, website, capacity
- **REST API Support:** Custom fields exposed for headless implementations

### Key Components

**Main Plugin File:** `chill-events.php`
- Singleton pattern with `Chill_Events::get_instance()`
- Custom PSR-4 autoloader with specific class-to-file mappings
- Initializes core components, admin interface, and frontend blocks

**Core Classes:**
- `ChillEvents\Core` - Post types, taxonomies, and WordPress integration
- `ChillEvents\Admin` - Admin interface for display settings
- `ChillEvents\Events\Event_Data_Manager` - Syncs block data to meta fields for performance
- `ChillEvents\Events\Venues\Venue_Term_Meta` - Venue taxonomy admin interface

**Data Flow:**
1. **Creation:** Event data created via Data Machine pipeline → Event Details block attributes
2. **Storage:** Event data stored in `Event Details` block attributes (single source of truth)
3. **Performance Sync:** `Event_Data_Manager` background process syncs start date to `_chill_event_start_date_utc` meta for calendar queries
4. **Display:** Gutenberg blocks render events using block attributes as primary data source

### Gutenberg Blocks
- **Calendar Block:** `inc/blocks/calendar/` - Main events display with filtering (uses webpack build)
- **Event Details Block:** `inc/blocks/event-details/` - Single source of truth for event data (uses @wordpress/scripts)
- **Block Attributes:** Rich schema with validation for event properties (dates, venues, pricing, etc.)
- **Build Output:** Each block builds to its own `build/` directory

### Venue Management
- **Venue Taxonomy:** WordPress taxonomy with rich term meta (address, phone, website, capacity)
- **Venue Term Meta:** `ChillEvents\Events\Venues\Venue_Term_Meta` class handles admin interface

## File Naming Conventions

**Classes:** PascalCase → `class-kebab-case.php`
- `Event_Data_Manager` → `class-event-data-manager.php`
- `Venue_Term_Meta` → `class-venue-term-meta.php`

**Directories:**
- `inc/admin/` - Admin interface for display settings
- `inc/blocks/` - Gutenberg block definitions with separate build systems
- `inc/core/` - Core plugin functionality and WordPress integration
- `inc/events/` - Event data management and sync
- `inc/events/venues/` - Venue taxonomy and term meta management
- `inc/utils/` - Utility classes and helper functions

## WordPress Integration

**Post Types:** `chill_events` - main event post type
**Taxonomies:** Venue taxonomy with rich term meta fields
**Meta Fields:** 
- `_chill_event_start_date_utc` - Performance field synced from block data for calendar queries
- Block attributes are primary data source

**REST API:** Custom fields exposed for headless implementations
**Admin Interface:** Display settings for Event Details blocks

## Development Notes

### Core Principles
- **Block Data Priority:** Always use block attributes as primary data source, meta fields are for performance only
- **Frontend Focus:** Plugin handles display and presentation, not event creation
- **Data Machine Integration:** Event creation handled by Data Machine pipeline
- **Single Responsibility:** Each class handles one specific domain (display, sync, admin, venues)

### Performance Considerations
- **Background Sync:** `Event_Data_Manager` runs via WP Cron to sync block data to meta fields
- **Query Optimization:** `_chill_event_start_date_utc` meta field enables efficient calendar date queries
- **Asset Loading:** Block assets loaded conditionally based on block presence

### Security & Data Integrity
- **Nonce Verification:** All admin forms use WordPress nonces
- **Data Sanitization:** Input sanitization with `wp_unslash()` and WordPress sanitization functions
- **Capability Checks:** Admin functions restricted to appropriate user capabilities

### Build Process
- **Calendar Block:** Uses webpack build system - run `npm run build` after JS/CSS changes
- **Event Details Block:** Uses @wordpress/scripts - run `npm run build` after changes
- **Development:** Use `npm run start` for development with file watching

### Integration with Data Machine
- **Event Creation:** Events are created using Data Machine input handlers (Ticketmaster, Dice FM, Eventbrite, iCal)
- **Data Flow:** Data Machine → Event Details block attributes → Event_Data_Manager sync → Calendar display
- **Separation of Concerns:** Chill Events handles presentation, Data Machine handles ingestion