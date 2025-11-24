# Changelog

All notable changes to Data Machine Events will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.2] - 2025-11-23

### Fixed
- **Circuit Grid Borders**: Fixed missing borders on mobile devices
  - Re-enabled SVG border overlay for mobile viewports
  - Implemented dynamic padding calculation to prevent border crossing on smaller screens
  - Ensures borders maintain safe distance (min 2px) regardless of grid gap size
  - Preserves exact desktop rendering while adapting to tablet and mobile constraints

## [0.2.1] - 2025-11-23

### Added
- **Mobile Optimization**: Enhanced Circuit Grid layout for mobile and tablet devices
  - Added tablet breakpoint (769px-1199px) for 2-3 events per row
  - Optimized mobile view (≤768px) for single column, full-width cards
  - Added responsive CSS variables for grid cell width and gap
- **Tablet Support**: Dedicated CSS variables and grid logic for tablet viewports

### Changed
- **Circuit Grid Renderer**: Intelligent viewport detection for layout adjustments
  - Simplified border rendering on mobile for better performance
  - Dynamic cell width calculation based on device type
- **Event Engine Data**: Improved venue context persistence
  - Uses `datamachine_merge_engine_data` for reliable data merging
  - Flattened venue metadata structure for better compatibility
- **Authentication Handlers**: Refactored Dice.fm and Ticketmaster to use `BaseAuthProvider`
  - Consistent authentication pattern across all handlers
  - Improved error handling for missing credentials
- **Event Upsert**: Enhanced engine context resolution and image processing
  - Better handling of featured images with engine data fallback
  - Improved venue taxonomy assignment logic

### Fixed
- **Timezone Handling**: Fixed timezone issues in Calendar block rendering
  - Explicitly uses `wp_timezone()` in DateTime constructors to ensure correct date calculations
- **CSS Variables**: Removed duplicated day color variables (centralized in root.css)

## [0.2.0] - 2025-11-22

### Added
- **EventUpsert Handler**: New intelligent create-or-update handler replacing Publisher
  - Searches for existing events by (title, venue, startDate) via WordPress queries
  - Updates existing events when data changes
  - Skips updates when data unchanged (preserves modified dates, reduces DB writes)
  - Returns action status: `created`, `updated`, or `no_change`
- **EventIdentifierGenerator Utility**: Shared normalization for consistent event identifiers
  - Normalizes text (lowercase, trim, collapse whitespace, remove articles)
  - Handles variations: "The Blue Note" vs "Blue Note" → same identifier
  - Used by all import handlers (Ticketmaster, DiceFm, GoogleCalendar, WebScraper)
- **Change Detection**: Field-by-field comparison prevents unnecessary updates
- **Two-Layer Architecture**: Clean separation between HTML processing (ProcessedItems) and event identity (EventUpsert)
- **REST API Refactoring**: Modular controller-based architecture with unified namespace
  - New `inc/Api/Routes.php` for centralized route registration (108 lines)
  - New `inc/Api/Controllers/Calendar.php` for calendar endpoint logic (364 lines)
  - New `inc/Api/Controllers/Venues.php` for venue operations (118 lines)
  - New `inc/Api/Controllers/Events.php` for event operations (11 lines)
  - Replaced monolithic 574-line `inc/core/rest-api.php` with modular system
- **ColorManager System**: Centralized color CSS custom properties helper
  - `getFillVar()` method returns CSS var references for RGBA fills
  - `getStrokeVar()` method returns CSS var references for solid strokes
  - `getComputedVar()` method resolves CSS custom property values
  - `applyToElement()` method applies fill/stroke to elements with var references
  - Integrates with CircuitGrid BadgeRenderer for consistent color management
- **Google Calendar Utilities**: New `GoogleCalendarUtils` class for calendar ID/URL handling
  - `is_calendar_url_like()` detects calendar URLs
  - `generate_ics_url_from_calendar_id()` generates public ICS URLs
  - `resolve_calendar_url()` resolves config calendar URLs from ID or URL
  - Supports both `calendar_id` and `calendar_url` configuration options
  - Auto-conversion between calendar URLs and IDs
- **EventUpsertFilters**: Handler registration and AI tool configuration system (249 lines)
- **Multi-Calendar Support**: Instance-specific IDs prevent conflicts when multiple calendar blocks exist on same page
  - Unique instance ID generation for each calendar block
  - Instance-specific selectors for filter system, search, date pickers, and modals
- **Design Token Expansion**: Added RGB and RGBA variables for all day colors in `inc/blocks/root.css`
  - RGB variables for each day (e.g., `--datamachine-day-sunday-rgb: 255, 107, 107`)
  - RGBA variables with 0.15 opacity for fills (e.g., `--datamachine-day-sunday-rgba`)
  - Supports ColorManager's var reference system

### Changed
- **Import Handlers**: All handlers now use EventIdentifierGenerator for consistent normalization
  - Ticketmaster, DiceFm, and GoogleCalendar updated
  - UniversalWebScraper continues using HTML hash (ProcessedItems handles HTML tracking)
- **Event Processing Flow**: AI tool changed from `create_event` to `upsert_event`
- **Version Bump**: Updated from 0.1.1 to 0.2.0 (minor version bump for new feature)
- **CircuitGrid BadgeRenderer**: Multi-group support with date-based aggregation (92 lines changed)
  - `detectDayGroups()` now aggregates badges by `data-date` attribute instead of day name
  - Prevents duplicate badges when multiple groups share same date
  - Deduplicates events and sorts chronologically
  - Uses ColorManager for badge styling with CSS var references
  - Changed from `dayName-index` keys to stable `dateKey` for badge tracking
- **Calendar Frontend JavaScript**: Enhanced with multiple improvements (216 lines changed)
  - Double-initialization prevention with `dmFiltersInitialized` flag
  - Unique element IDs using instance-specific selectors
  - Improved date range picker with server-injected initial dates
  - Enhanced modal accessibility (ARIA attributes, dialog role)
  - Fixed modal class names (`datamachine-modal-active` instead of `visible`)
  - Better keyboard navigation and focus management
- **Filter Bar Template**: Instance-specific IDs and enhanced UI (24 lines changed)
  - Instance-specific IDs for search, date range, and modal
  - Preserves search query value on render
  - Adds filter count badge to filter button
  - ARIA attributes for accessibility
  - Active filter state indicators
- **Calendar Render**: Improved architecture and sanitization (97 lines changed)
  - Added unique instance ID generation for multi-calendar support
  - Improved tax_filter sanitization (proper array handling with absint)
  - Moved results counter, pagination, and navigation outside grid container
  - Added filter count display and taxonomies_data to filter-bar template
  - CSS dependency chain improvements (root.css as dependency)
- **Google Calendar Handler**: Updated to support both calendar_id and calendar_url
  - Uses GoogleCalendarUtils for calendar URL resolution (25 lines changed)
  - Updated authentication for calendar ID/URL dual support (39 lines changed)
  - Added calendar_id field to settings UI (67 lines changed)
- **Design Tokens**: Updated Friday color from `#ff9ff3` to `#d63384` for better visual consistency
- **No Events Template**: Added "Show events from Today" reset button for better UX (5 lines added)
- **Main Plugin File**: Updated architecture and component loading (47 lines changed)
  - Changed from `load_publish_handlers()` to `load_upsert_handlers()`
  - Removed Publisher instantiation, added EventUpsert instantiation
  - Updated REST API loading to use modular Routes.php
  - Moved Settings_Page instantiation into admin init for proper hook timing
- **CircuitGrid CSS**: Updates to support ColorManager var references (14 lines changed)
- **CarouselList CSS**: Updates to support ColorManager var references (14 lines changed)
- **Calendar Block Styles**: Filter button active state styles and filter count badge positioning (20 lines changed)
- **Taxonomy Filter Modal**: Compatibility updates for new filter system (2 lines changed)

### Removed
- **Publisher Handler**: Completely removed in favor of EventUpsert
  - `inc/Steps/Publish/Events/Publisher.php` deleted (530 lines)
  - `inc/Steps/Publish/Events/Filters.php` deleted (419 lines)
  - `load_publish_handlers()` method removed from main plugin file
- **Monolithic REST API**: Deleted `inc/core/rest-api.php` (574 lines) in favor of modular controller architecture

### Fixed
- **Duplicate Events**: HTML changes no longer create duplicate posts
- **Event Updates**: Source event changes now update existing posts instead of creating duplicates
- **Fluid Calendar**: Events stay current with automatic updates from source data
- **Multi-Calendar Badge Conflicts**: Fixed badge rendering issues when multiple calendar blocks share same date
- **Filter State Persistence**: Search and date filters now preserve values across page refreshes
- **Modal Accessibility**: Added proper ARIA attributes, dialog role, and keyboard navigation
- **Double Initialization**: Prevented filter system from initializing multiple times on same calendar instance

## [0.1.1] - 2025-11-20

### Added
- **CHANGELOG.md**: Introduced changelog documentation for version tracking
- **Major OOP Refactoring**: Complete alignment with Data Machine core's new architecture patterns
- **New Base Classes**: PublishHandler, FetchHandler, Step, and EventImportHandler for standardized operations
- **WordPressSharedTrait Integration**: Shared WordPress utilities across all handlers
- **TaxonomyHandler Integration**: Centralized taxonomy management with custom venue handler support
- **Handler Discovery System**: Registry-based handler loading with automatic instantiation and execution
- **Dual Architecture Support**: Backward compatibility with legacy handlers while supporting new FetchHandler pattern
- **Version bump**: Updated from 0.1.0 to 0.1.1 to mark major architectural improvements

## [0.1.0] - 2025-11-XX

### Added
- **Initial Release**: Complete events management plugin with block-first architecture
- **Event Details Block**: Rich event data storage with 15+ attributes (dates, times, venue, pricing, performers, organizers)
- **Calendar Block**: Advanced event display with multiple templates and filtering
- **Venue Taxonomy**: Complete venue management with 9 meta fields and admin interface
- **AI Integration**: Data Machine pipeline support for automated event imports
- **REST API Endpoints**: Full REST API implementation under `datamachine/v1/events/*`
- **Import Handlers**: Ticketmaster, Dice FM, Google Calendar, and Universal Web Scraper integrations
- **Schema.org Support**: Google Event JSON-LD structured data generation
- **Map Integration**: Leaflet.js venue mapping with 5 free tile layer options
- **Admin Interface**: Comprehensive settings page with display preferences
- **Template System**: Modular calendar display with 7 specialized templates
- **Performance Optimization**: Background sync to meta fields for efficient queries