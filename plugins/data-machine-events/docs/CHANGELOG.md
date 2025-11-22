# Changelog

All notable changes to Data Machine Events will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

### Changed
- **Import Handlers**: All handlers now use EventIdentifierGenerator for consistent normalization
  - Ticketmaster, DiceFm, and GoogleCalendar updated
  - UniversalWebScraper continues using HTML hash (ProcessedItems handles HTML tracking)
- **Event Processing Flow**: AI tool changed from `create_event` to `upsert_event`
- **Version Bump**: Updated from 0.1.1 to 0.2.0 (minor version bump for new feature)

### Removed
- **Publisher Handler**: Completely removed in favor of EventUpsert
  - `inc/Steps/Publish/Events/Publisher.php` deleted
  - `inc/Steps/Publish/Events/Filters.php` deleted
  - `load_publish_handlers()` method removed from main plugin file

### Fixed
- **Duplicate Events**: HTML changes no longer create duplicate posts
- **Event Updates**: Source event changes now update existing posts instead of creating duplicates
- **Fluid Calendar**: Events stay current with automatic updates from source data

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