# CLAUDE.md

Technical guidance for Claude Code when working with this WordPress events plugin.

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

## Architecture

### Core Principles
- **Block-First:** Event data stored in `Event Details` block attributes (single source of truth)
- **Frontend-Focused:** Event display and presentation (works with Data Machine for imports)
- **Performance Optimized:** Background sync to meta fields for efficient queries
- **PSR-4 Structure:** `ChillEvents\` namespace with custom autoloader
- **Data Machine Ready:** Event creation handled by Data Machine plugin pipeline

### Key Components

**Core Classes:**
- `ChillEvents\Admin` - Display settings interface
- `ChillEvents\Events\Event_Data_Manager` - Syncs block data to meta fields
- `ChillEvents\Events\Venues\Venue_Term_Meta` - Venue taxonomy admin
- `ChillEvents\Events\Event_Duplicate_Checker` - Prevents duplicate events

**Data Flow:** Data Machine → Event Details Block → Background Sync → Calendar Display

### Blocks & Venues

**Blocks:**
- `inc/blocks/calendar/` - Events display with filtering (webpack)
- `inc/blocks/event-details/` - Event data storage (@wordpress/scripts)

**Venues:**
- WordPress taxonomy with term meta (address, phone, website, capacity)
- Admin interface via `Venue_Term_Meta` class

## File Structure

**Classes:** PascalCase → `class-kebab-case.php`

**Key Directories:**
- `inc/admin/` - Display settings interface
- `inc/blocks/` - Gutenberg blocks (separate build systems)
- `inc/events/` - Event data management and duplicate checking
- `inc/events/venues/` - Venue taxonomy with term meta
- `inc/steps/` - Data Machine integration (import handlers and publishers)

## WordPress Integration

- **Post Type:** `chill_events`
- **Taxonomy:** Venues with term meta
- **Meta Field:** `_chill_event_start_date_utc` (synced from blocks for performance)
- **REST API:** Event metadata exposed
- **Primary Data:** Block attributes (meta fields for queries only)

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
- **Event Details:** @wordpress/scripts (`npm run build/start`)
- **No Frontend JS:** Individual blocks handle their own JavaScript

### Data Machine Integration
- **Import Handlers:** Ticketmaster, Dice FM, Web Scrapers in `inc/steps/event-import/handlers/`
- **Publishers:** Chill Events publisher in `inc/steps/publish/handlers/chill-events/`
- **Event Creation:** Data Machine creates events via `Event Details` block attributes
- **Duplicate Prevention:** `Event_Duplicate_Checker` prevents importing existing events