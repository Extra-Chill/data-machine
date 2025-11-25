# Changelog

All notable changes to Data Machine Events will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.3] - 2025-11-25

### Changed
- **Taxonomy Exclusion Filter Enhancement** - Added `$context` parameter to `datamachine_events_excluded_taxonomies` filter
  - Filter now passes context identifier: `'badge'` for taxonomy badges, `'modal'` for filter modal
  - Callbacks can exclude taxonomies from specific contexts or all contexts (no context check = exclude from all)
  - Removed hardcoded venue exclusion from `Taxonomy_Helper::get_all_taxonomies_with_counts()`
  - Centralized taxonomy visibility control through single filter for both badges and modal
- **Event Card Link Structure** - Refactored event item template for better accessibility and UX
  - Changed from full-card link wrapper to individual links for title and "More Info" button
  - Added `datamachine_events_more_info_button_classes` filter for button customization
  - Taxonomy badges now render as clickable links to term archive pages
- **EventUpsertSettings Cleanup** - Simplified user options retrieval using `WordPressSettingsHandler::get_user_options()`

## [0.3.2] - 2025-11-24

### Fixed
- **Compatibility with Data Machine v0.2.7** - Migrated to WordPressPublishHelper for image operations
  - Updated EventUpsert handler to use `WordPressPublishHelper::attachImageToPost()` instead of deprecated `EngineData::attachImageToPost()`
  - Refactored image processing to use static helper methods following platform-agnostic architecture

### Requirements
- **Requires Data Machine v0.2.7+** - Due to EngineData API changes and WordPressPublishHelper introduction

## [0.3.1] - 2025-11-23

### Fixed
- **Compatibility with Data Machine v0.2.7** - Removed dependency on WordPressSharedTrait (breaking change in core)
  - Migrated EventUpsert handler to direct EngineData instantiation pattern
  - Migrated to WordPressSettingsResolver for WordPress settings (post status, post author)
  - Added private helper methods for EngineData context resolution
  - Direct TaxonomyHandler usage for taxonomy processing

### Changed
- **EventUpsert Handler Architecture** - Refactored to use direct EngineData pattern for single source of truth data access
- **Code Deduplication** - Eliminated duplicated WordPress settings logic by using centralized WordPressSettingsResolver

### Requirements
- **Requires Data Machine v0.2.7+** - Due to WordPressSharedTrait removal and WordPressSettingsResolver introduction

## [0.3.0] - 2024-11-23

### BREAKING CHANGES

**Single Event Template Removed**
- Event posts now use theme's `single.php` template instead of custom plugin template
- Themes have full control over event presentation layout
- Event Details block remains self-contained and provides all event data rendering

**Removed Action Hooks** (previously in `templates/single-datamachine_events.php`):
- `datamachine_events_before_single_event` - Use theme's `single.php` or template parts
- `datamachine_events_after_single_event` - Use theme's `single.php` or template parts
- `datamachine_events_related_events` - Implement in theme's `single.php` for `datamachine_events` post type
- `datamachine_events_after_event_article` - Use theme's `single.php` or template parts

**Removed Filter Hook**:
- `datamachine_events_breadcrumbs` - Use theme's breadcrumb system (Yoast, Rank Math, custom theme breadcrumbs)

**Taxonomy_Badges Namespace Change**:
- Moved from `DataMachineEvents\Core\Taxonomy_Badges` to `DataMachineEvents\Blocks\Calendar\Taxonomy_Badges`
- Plugins/themes using `class_exists('DataMachineEvents\Core\Taxonomy_Badges')` must update to new namespace
- All filter hooks preserved and unchanged (`datamachine_events_badge_wrapper_classes`, `datamachine_events_badge_classes`, `datamachine_events_excluded_taxonomies`)

### Changed
- **Taxonomy_Badges**: Moved from `inc/Core/` to `inc/Blocks/Calendar/` for self-contained block architecture
  - Namespace changed from `DataMachineEvents\Core` to `DataMachineEvents\Blocks\Calendar`
  - All extensibility filter hooks preserved for theme/plugin compatibility
  - Used exclusively by Calendar block event-item template
  - Location: `inc/Blocks/Calendar/Taxonomy_Badges.php`

### Removed
- **Single Post Template**: Deleted `templates/single-datamachine_events.php` (93 lines)
  - Events now use theme's default `single.php` template
  - Allows themes full control over event presentation
  - Aligns with WordPress block-first architecture (blocks provide data, themes provide presentation)
  - Event Details block handles all event data rendering with structured data, venue maps, and action buttons
- **Breadcrumbs Class**: Deleted `inc/Core/Breadcrumbs.php` (69 lines)
  - Only used in removed single post template
  - Themes should use their own breadcrumb systems
  - Reduces plugin maintenance burden and complexity
- **Frontend Template Override**: Removed `template_include` filter and `load_event_templates()` method
  - Simplifies plugin by eliminating theme override logic
  - Removed `init_frontend()` method

### Architectural Benefits
- **Simplified Plugin**: 162 lines removed, cleaner separation of concerns
- **Self-Contained Calendar Block**: All taxonomy badge logic lives with the block that uses it
- **Theme Flexibility**: Full control over single event post layout and presentation
- **Extensibility**: Themes/plugins can build custom features on solid foundation
- **KISS Principle**: Each component has singular purpose with clear boundaries

### Added
- **EventUpsertSettings**: New dedicated settings class for Event Upsert handler
  - Includes comprehensive venue handling explanation in handler configuration UI
  - Informational field shows users which venue metadata fields are automatically populated (name, address, city, state, zip, country, phone, website, coordinates, capacity)
  - Makes automatic venue metadata population from import handlers (Ticketmaster, Dice FM, Web Scraper, Google Calendar) transparent to users

### Changed
- **Directory Structure**: Completed Publisher → Upsert migration
  - Moved `Schema.php` from `inc/Steps/Publish/Events/` to `inc/Steps/Upsert/Events/`
  - Moved `Venue.php` from `inc/Steps/Publish/Events/` to `inc/Steps/Upsert/Events/`
  - Updated all namespaces from `DataMachineEvents\Steps\Publish\Events` to `DataMachineEvents\Steps\Upsert\Events`
  - Updated imports in `EventUpsert.php` and `EventDetails/render.php` to use new Upsert namespace
- **EventUpsertFilters**: Now uses `EventUpsertSettings::class` instead of deprecated Publisher Settings

### Removed
- **Deprecated Publisher Directory**: Deleted entire `inc/Steps/Publish/` directory
  - Publisher handler was replaced by EventUpsert in v0.2.0 but directory structure lingered
  - All functionality properly migrated to Upsert directory

## [0.2.4] - 2025-11-23

### Fixed
- **Mobile Layout**: Reverted mobile padding changes to restore correct vertical spacing
  - Restored side padding (1rem) on mobile grid container
  - Restored max-width (400px) and auto margins on event cards
  - Maintained unified absolute badge positioning from 0.2.3

## [0.2.3] - 2025-11-23

### Fixed
- **Circuit Grid Badges**: Unified badge rendering logic for all viewports
  - Removed complex gap detection system; badges now sit on top of borders
  - Fixed mobile badge positioning to align with border (matching desktop behavior)
  - Removed extraneous side padding on mobile for better screen utilization
  - Simplified border path generation logic for better performance

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