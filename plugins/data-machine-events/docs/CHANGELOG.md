# Changelog

All notable changes to Data Machine Events will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.8] - 2025-11-29

### Added
- **ICS Calendar Feed Handler** - New dedicated import handler for generic ICS/iCal feeds
  - Supports any ICS feed URL (Tockify, Outlook, Apple Calendar, etc.)
  - Automatic `webcal://` to `https://` protocol conversion
  - Venue name and address override options for consistent venue assignment
  - Include/exclude keyword filtering to filter out non-relevant events
  - Uses existing `johngrogg/ics-parser` library for reliable parsing
  - Single-item processing pattern with EventIdentifierGenerator for deduplication

- **Filters API Controller** - New REST endpoint for dynamic taxonomy filter options
  - `/datamachine/v1/events/filters` endpoint with active filter support
  - Taxonomy dependency handling for hierarchical filtering
  - Context-aware filter generation (modal vs other contexts)
  - Sanitized input handling with proper array validation

- **Geocoding API Controller** - Server-side proxy for OpenStreetMap Nominatim API
  - `/datamachine/v1/events/geocode/search` endpoint for address search
  - CORS-compliant server-side proxy to avoid browser restrictions
  - Proper user agent identification and error handling
  - Admin-only access with capability checks

- **Enhanced Filter Modal** - Dynamic filter loading with REST API integration
  - Real-time filter options loading via Filters API
  - Support for taxonomy dependencies and conditional filtering
  - Improved performance with on-demand filter data
  - Enhanced accessibility and keyboard navigation

- **Taxonomy Dependencies System** - Support for dependent taxonomy relationships
  - Child taxonomies only show terms relevant to parent selections
  - Configurable dependency mappings via `datamachine_events_taxonomy_dependencies` filter
  - Automatic dependency resolution in filter modal and API responses

- **Enhanced Taxonomy Helper** - Improved taxonomy data processing with filter support
  - `get_all_taxonomies_with_counts()` now accepts active filters and dependencies
  - Dependent term filtering for hierarchical taxonomy relationships
  - Better performance with selective term loading
  - Support for filtered taxonomy counts

### Changed
- **Filter Modal Architecture** - Migrated from static HTML to dynamic REST API loading
  - Removed static taxonomy rendering from template
  - Added `loadFilters()` function for dynamic content
  - Enhanced error handling and loading states
  - Improved accessibility with ARIA attributes

- **API Client Integration** - Enhanced calendar API client with filter support
  - Added `fetchFilters()` function for filter data retrieval
  - Improved error handling and response validation
  - Better integration with calendar state management

- **Taxonomy Helper Performance** - Optimized taxonomy queries with selective loading
  - Only load dependent terms when parent filters are active
  - Reduced database queries for large taxonomy sets
  - Better caching and query optimization

- **Google Calendar Handler** - Removed unused configuration options
  - Simplified settings interface for better usability
  - Streamlined handler configuration

## [0.4.7] - 2025-11-29

### Added
- **ICS Calendar Feed Handler** - New dedicated import handler for generic ICS/iCal feeds
  - Supports any ICS feed URL (Tockify, Outlook, Apple Calendar, etc.)
  - Automatic `webcal://` to `https://` protocol conversion
  - Venue name and address override options for consistent venue assignment
  - Include/exclude keyword filtering to filter out non-relevant events
  - Uses existing `johngrogg/ics-parser` library for reliable parsing
  - Single-item processing pattern with EventIdentifierGenerator deduplication

- **Comprehensive Documentation** - Added 4 new detailed documentation files:
  - `event-schema-provider.md` - Complete EventSchemaProvider API reference with examples
  - `geocoding-integration.md` - OpenStreetMap Nominatim geocoding implementation guide
  - `pipeline-components-js.md` - React field components for Data Machine pipeline modals
  - `venue-parameter-provider.md` - Dynamic venue parameter generation documentation

### Changed
- **VenueParameterProvider Enhancement** - Improved engine data integration
  - `getToolParameters()` now accepts engine data to avoid duplicate parameters
  - Added `hasVenueData()` method to check both handler config and engine data
  - Added `filterByEngineData()` to exclude parameters already present in engine data
  - Deprecated `hasStaticVenue()` in favor of more comprehensive `hasVenueData()`

- **EventUpsert Engine Data Precedence** - Engine data now takes priority over AI-provided values
  - Event identity extraction (title, venue, startDate) uses engine data first
  - Venue processing merges engine parameters with AI parameters (engine takes precedence)
  - Improved parameter consistency between engine and AI data sources

- **EventUpsertFilters Dynamic Tool Generation** - Enhanced AI tool registration
  - `registerAITools()` now receives engine data snapshot for dynamic filtering
  - Venue parameters are excluded from AI tool when already present in engine data
  - More efficient AI tool generation with reduced parameter redundancy

- **Admin Screen Detection Fix** - Corrected pipeline admin screen detection
  - Changed from `datamachine` to `datamachine-pipelines` for accurate script loading

## [0.4.6] - 2025-11-29

### Added
- **WordPress Events API Handler** - Import events from external WordPress sites running Tribe Events or similar plugins
- **WordPress Events API Settings** - Configuration for external WordPress API imports with venue override and keyword filtering
- **Enhanced Calendar CSS** - Modern filter bar styling with improved search input and focus states
- **Venue Override Feature** - Consolidate multiple venue stages under one venue name for better map display

### Changed
- **Event Import Handler Base Class** - Integrated VenueParameterProvider for cleaner venue metadata extraction
- **Eventbrite Handler** - Improved JSON-LD extraction from public organizer pages
- **Google Calendar Handler** - Enhanced .ics integration with better event parsing
- **SpotHopper API Integration** - Performance and reliability improvements
- **Universal Web Scraper** - Better Schema.org compliance and fallback handling
- **Event Upsert Architecture** - Refinements for better create/update logic
- **Event Post Type Registration** - Updates for improved admin interface

### Fixed
- Various handler stability improvements and edge case handling
- Calendar styling consistency across different themes

## [0.4.5] - 2025-11-28

### Added
- **EventSchemaProvider** - Centralized event schema provider for AI tools and Schema.org JSON-LD
  - Comprehensive field definitions for all event properties (core, offer, performer, organizer, status, type)
  - Smart parameter routing between engine data and AI tool parameters
  - Schema.org JSON-LD generation with venue integration
  - Single source of truth for event field schemas

- **VenueParameterProvider** - Dynamic venue parameter generation for AI tool definitions
  - Provides venue field parameters when no static venue is configured
  - Maps tool parameters to Venue_Taxonomy meta field keys
  - Extracts venue data from AI tool parameters and event data

- **Pipeline Components JavaScript** - Custom React field components for Data Machine pipeline modals
  - Venue autocomplete with OpenStreetMap Nominatim integration
  - Pipeline hooks for extending core React behavior
  - Native WordPress hooks pattern in JavaScript

- **Geocoding Integration** - Automatic venue coordinate lookup using OpenStreetMap Nominatim
  - Triggers on venue address updates
  - Stores latitude/longitude for map display
  - Uses proper user agent for API requests

### Changed
- **Event Upsert Architecture** - Refactored to use centralized schema and venue providers
  - EventUpsert.php now imports EventSchemaProvider and VenueParameterProvider
  - Cleaner separation between event data and venue data handling
  - Improved parameter routing with engineOrTool() method

- **Event Import Handler** - Simplified venue metadata extraction using VenueParameterProvider
  - Removed hardcoded venue field arrays
  - Uses VenueParameterProvider::extractFromEventData() and stripFromEventData()
  - More maintainable and consistent venue handling across all handlers

- **Event DateTime Storage** - Enhanced end datetime calculation and storage
  - Added EVENT_END_DATETIME_META_KEY constant
  - Calculates end datetime: explicit end date/time, or start + 3 hours default
  - Stores both start and end datetime meta for better event filtering

- **Calendar Query Logic** - Updated to use end datetime for accurate event filtering
  - Past/upcoming queries now use EVENT_END_DATETIME_META_KEY
  - Better handling of multi-day events and event duration
  - More accurate "show past events" and date range filtering

- **Venue Taxonomy** - Added smart geocoding on address updates
  - Automatically looks up coordinates when address fields are populated
  - Only geocodes when address components change
  - Integrates with existing venue creation/update workflow

### Removed
- **Legacy Schema Class** - Deleted Schema.php from Upsert/Events directory
  - Functionality replaced by comprehensive EventSchemaProvider
  - Removed engine_or_tool() method in favor of EventSchemaProvider::engineOrTool()
  - Cleaner architecture with single schema provider

### Technical Improvements
- **Code Deduplication** - Eliminated repeated venue field definitions
- **Better Separation of Concerns** - Schema, venue, and event logic properly separated
- **Enhanced AI Integration** - More sophisticated parameter routing for AI tools
- **Improved Event Filtering** - Better handling of event duration and end times

## [0.4.4] - 2025-11-28

### Added
- **Eventbrite Import Handler** - New handler for importing events from public Eventbrite organizer pages
  - Parses Schema.org JSON-LD structured data from Eventbrite HTML pages
  - Extracts venue metadata, ticket pricing, performer information
  - Supports date range filtering and EventIdentifierGenerator for deduplication
- **Event Flyer Import Handler** - New handler for AI-powered event extraction from promotional images
  - Processes flyer/poster images using vision model capabilities
  - "Fill OR AI extracts" pattern for configurable field handling
  - Integrates with Data Machine Files handler for image storage
- **WordPress Events API Handler** - New handler for importing events from external WordPress sites
  - Auto-detects API format (Tribe Events v1, Tribe WP REST, generic WordPress)
  - Full venue metadata extraction with venue name override support
  - Keyword search and exclude keywords filtering
- **Modal Button Styling** - Added consistent button sizing for taxonomy filter modal actions

### Changed
- **Handler Registration Architecture** - Migrated all import handlers to `HandlerRegistrationTrait` pattern
  - Eliminated 5 separate `*Filters.php` files (~246 lines removed)
  - Handler registration now embedded in handler constructors via `self::registerHandler()`
  - Affected handlers: Ticketmaster, DiceFm, GoogleCalendar, SpotHopper, UniversalWebScraper
- **Handler Loading** - Simplified `load_event_import_handlers()` to instantiate handler classes directly
  - Removed file-based Filters loading and WebScraper scrapers directory loading
  - Clean array-based handler instantiation with `class_exists()` check
- **Filter Modal JavaScript** - Updated close button handling to support multiple close buttons via `querySelectorAll()`
- **Modal Button Classes** - Changed from WordPress admin `button` classes to `datamachine-button` classes for frontend consistency
- **WordPressEventsAPI Settings** - Removed `api_format` select field (auto-detection handles format identification)
  - Simplified settings to: endpoint URL, venue override, pagination, categories, search, and exclude keywords
- **AI Description Prompt** - Simplified EventUpsert AI description generation prompt

### Removed
- **Handler Filter Files** (~246 lines total):
  - `inc/Steps/EventImport/Handlers/Ticketmaster/TicketmasterFilters.php` (50 lines)
  - `inc/Steps/EventImport/Handlers/DiceFm/DiceFmFilters.php` (50 lines)
  - `inc/Steps/EventImport/Handlers/GoogleCalendar/GoogleCalendarFilters.php` (50 lines)
  - `inc/Steps/EventImport/Handlers/SpotHopper/SpotHopperFilters.php` (44 lines)
  - `inc/Steps/EventImport/Handlers/WebScraper/UniversalWebScraperFilters.php` (52 lines)

## [0.4.3] - 2025-11-28

### Added
- **JavaScript Module Architecture** - Refactored 571-line monolithic `frontend.js` into 6 focused ES modules for improved maintainability:
  - `modules/api-client.js` (74 lines) - REST API communication and calendar DOM updates
  - `modules/carousel.js` (129 lines) - Carousel overflow detection, dot indicators, and chevron navigation
  - `modules/date-picker.js` (80 lines) - Flatpickr date range picker integration
  - `modules/filter-modal.js` (111 lines) - Taxonomy filter modal UI and accessibility
  - `modules/navigation.js` (51 lines) - Past/upcoming navigation and pagination link handling
  - `modules/state.js` (80 lines) - URL state management and query parameter building
- **Carousel Navigation UI** - Added dot indicators showing visible events and chevron buttons (`‹`/`›`) for horizontal scrolling
- **Flatpickr Theme CSS** - New `flatpickr-theme.css` (219 lines) with comprehensive design system integration and dark mode support
- **AI Description Formatting** - Enhanced EventUpsertFilters description prompt to request HTML formatting with multiple `<p>` tags, `<strong>` emphasis, and `<ul>/<li>` lists

### Changed
- **CSS Design System Migration** - Replaced ~40 hardcoded hex colors with CSS custom properties throughout:
  - `style.css`: Buttons (`--datamachine-text-accent`), pagination, filter active states
  - `frontend.css` (EventDetails): Text colors, backgrounds, borders, ticket button styles
- **Modal Layout Architecture** - Taxonomy filter modal refactored with flexbox and sticky footer:
  - Modal footer moved from `taxonomy-filter.php` to `filter-bar.php` for proper sticky positioning
  - Body section uses `flex: 1 1 auto` with `overflow-y: auto` for scrollable content while footer stays fixed
- **EventUpsert Block Generation** - `generate_block_content()` now properly wraps InnerBlocks in `<div class="wp-block-datamachine-events-event-details">` and generates separate paragraph blocks for multi-paragraph HTML descriptions via new `generate_description_blocks()` method
- **Compact Layout CSS** - Removed 44 lines of duplicated compact layout styles (now inherits from base `.event-info-grid` styles)
- **Dark Mode CSS** - Reduced EventDetails dark mode overrides from 18 lines to 4 lines (inherits from root.css CSS custom properties)

### Removed
- **Carousel List Separate CSS** - Deleted `DisplayStyles/CarouselList/carousel-list.css` (107 lines) - styles consolidated into main `style.css`
- **Flatpickr Inline Overrides** - Removed 36 lines of flatpickr CSS from `style.css` (moved to dedicated theme file imported by webpack)
- **Monolithic Frontend JavaScript** - Replaced 571-line IIFE with 93-line orchestration file + modular imports

## [0.4.2] - 2025-11-26

### Added
- **Carousel Overflow UI** - Added "More" indicator with gradient fade for overflowing content in Carousel List view.
- **Frontend Logic** - Added `initializeCarouselOverflow()` in `frontend.js` with `ResizeObserver` support to detect and style overflowing date groups.
- **SpotHopper Filtering** - Added keyword search and exclude keyword support to `SpotHopper` import handler.

### Changed
- **Carousel Styling** - Removed scroll snap behavior from `carousel-list.css` for smoother scrolling.
- **SpotHopper Handler** - Implemented `applyKeywordSearch` and `applyExcludeKeywords` in import logic.

## [0.4.1] - 2025-11-26

### Added
- **SpotHopper Event Import Handler**: New handler for venues using SpotHopper platform
  - Public JSON API integration (no authentication required)
  - Configuration: `spot_id` (required), `venue_name_override` (optional for sub-venues like "The Rickhouse")
  - Full venue metadata extraction including address, city, state, zip, coordinates for map display
  - Event image support from SpotHopper CDN
  - Single-item processing with EventIdentifierGenerator for deduplication

## [0.4.0] - 2025-11-26

### BREAKING CHANGES

**Circuit Grid Display Mode Removed**
- Calendar block now uses Carousel List as the only display style
- `calendar_display_type` setting removed from Settings Page
- Sites using Circuit Grid will automatically use Carousel List after update

**Removed Files** (~1,300 lines):
- `inc/Blocks/Calendar/DisplayStyles/CircuitGrid/` (entire directory)
  - `CircuitGridRenderer.js` (890 lines) - SVG border rendering, shape calculations
  - `BadgeRenderer.js` (266 lines) - Badge positioning for circuit grid
  - `circuit-grid.css` (~150 lines) - Circuit grid-specific styling
- `inc/Blocks/Calendar/DisplayStyles/ColorManager.js` (76 lines) - Color helper (only used by circuit grid)

### Removed
- **Circuit Grid Display Mode**: Experimental SVG-based display with dynamic borders
  - Complex shape generation (L-shaped cutouts, split groups, connectors)
  - JavaScript-based badge positioning
  - ResizeObserver-based responsive recalculation
  - Grid position calculations and shape generation algorithms
- **Display Type Setting**: `calendar_display_type` removed from admin settings and Settings_Page defaults
- **ColorManager**: CSS custom property helper (only used by Circuit Grid)
- **Display Renderer System**: `displayRenderers` Map and initialization logic in frontend.js

### Changed
- **Carousel List**: Now the single, default calendar display mode
  - CSS-only implementation (82 lines vs ~1,300 lines for Circuit Grid)
  - Horizontal scroll with native touch/trackpad support
  - No JavaScript required for display rendering
- **Simplified render.php**: Removed display type conditionals and SVG overlay element
- **Simplified frontend.js**: Removed ~100 lines of renderer initialization code

### Architectural Benefits
- **~1,300 lines removed**: Significant reduction in codebase complexity
- **Zero JavaScript display rendering**: Carousel List is pure CSS
- **Single source of truth**: One display path, no branching logic
- **Better maintainability**: Future changes only affect one display mode
- **Modern UX**: Horizontal scroll aligns with contemporary touch interfaces

## [Unreleased]

### Fixed
- **Event Details Block Description** - Fixed InnerBlocks content being stripped when saving
  - Changed `save()` function from returning `null` to returning `<InnerBlocks.Content />` wrapped in block props
  - Description content with formatting (line breaks, headings, lists, etc.) now persists correctly through save/edit cycles
  - Expanded allowed blocks to include: paragraph, heading, image, list, quote, gallery, video, audio, embed, separator, spacer, columns, column, group, freeform, and html blocks

### Added
- **Documentation Alignment** - Comprehensive documentation updates for v0.3.5
  - Added Google Calendar integration documentation with calendar ID/URL utilities
  - Added ColorManager system documentation with CSS custom properties integration
  - Added EventIdentifierGenerator utility documentation with normalization examples
  - Updated file structure documentation to reflect current architecture
  - Fixed outdated class names and file paths throughout documentation
  - Added missing components like results-counter template and CarouselList display mode
  - Updated README.md with jQuery dependency elimination information

## [0.3.5] - 2025-11-25

### Removed
- **jQuery Dependency Elimination** - Completely removed all jQuery and AJAX references from the plugin
  - Deleted `assets/js/admin.js` (no-op jQuery wrapper)
  - Removed jQuery/wp-util script enqueue from `enqueue_admin_assets()`
  - Converted `venue-selector.js` from jQuery IIFE to vanilla JavaScript
  - Removed jQuery conditionals from `venue-map.js` and `venue-autocomplete.js`
- **CarouselListRenderer.js** - Deleted 386-line JavaScript renderer; Carousel List mode is now CSS-only
- **Unused CSS** - Removed ~200 lines of dead carousel card styles, responsive breakpoint variables, and unused grid medium/tablet/mobile variables from `style.css` and `root.css`

### Fixed
- **Calendar Pagination** - Fixed pagination not working on block pages (e.g., `?paged=2` showing page 1 content)
  - Now checks `$_GET['paged']` first for block pages where `get_query_var('paged')` doesn't work
  - Falls back to `get_query_var('paged')` for taxonomy archive pages

### Changed
- **Carousel List CSS** - Simplified from 249 lines to 82 lines with cleaner structure
- **Grid Variables** - Consolidated to single set of grid dimensions (removed tablet/medium/mobile variants)
  - `--datamachine-grid-cell-width: 316px`
  - `--datamachine-grid-cell-height: 222px`
  - `--datamachine-grid-gap: 1.5rem`

## [0.3.4] - 2025-11-25

### Changed
- **Grid Dimensions** - Adjusted grid cell dimensions and gap spacing
  - Cell width: 275px → 316px
  - Cell height: 193px → 222px  
  - Grid gap: 1.5625rem → 1.5rem
- **Block Version Sync** - Updated Calendar block.json version to match plugin version

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