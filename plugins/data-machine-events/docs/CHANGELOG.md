# Changelog

All notable changes to Data Machine Events will be documented in this file.

## Unreleased

### Added
- Test coverage for EventIdentifierGenerator with title matching scenarios

### Fixed
- Duplicate event detection now uses rightmost delimiter for title extraction, fixing false negatives when titles contain multiple delimiters

## [0.10.1] - 2026-01-21

- Refactor calendar system to Abilities API: consolidate duplicated logic from render.php and REST controller into CalendarAbilities, migrate frontend from AJAX to URL navigation

## [0.10.0] - 2026-01-21

- Add occurrenceDates attribute for recurring events with specific display dates
- Add occurrenceDates support in AI tools (EventSchemaProvider, EventUpsert, EventUpdateAbilities)
- Add occurrenceDates to UpdateEvent CLI command
- Fix calendar pagination to filter occurrence dates to page boundaries

## [0.9.18] - 2026-01-21

- Calendar query improvements and Craftpeak extractor updates

## [0.9.17] - 2026-01-19

### Added
- Squarespace User Items List support in SquarespaceExtractor for sites using custom list blocks instead of native Events collection
- Centralized time parsing methods in BaseExtractor: `parseTimeString()` and `extractTimeFromText()`

### Changed
- Consolidated duplicate time parsing logic from BandzoogleExtractor, CraftpeakExtractor, TimelyExtractor, and SquarespaceExtractor into shared BaseExtractor methods

## [0.9.16] - 2026-01-19

- Fixed: ICS feed timezone parsing - events now display in correct local time instead of being offset by timezone difference
- Added: BatchTimeFixAbilities for batch correction of events with systematic timezone issues

## [0.9.15] - 2026-01-19

### Changed
- Refactor EventHealthCheck and UpdateEvent to Abilities pattern - CLI and chat tools now share business logic via EventHealthAbilities and EventUpdateAbilities

## [0.9.14] - 2026-01-19

### Added
- GetVenueEvents WP-CLI command wrapping EventQueryAbilities
- EventQueryAbilities for venue event queries with filtering

### Fixed
- Code style and escape-i18n fixes across 91 files

## [0.9.13] - 2026-01-19

- Fixed ICS parser dependency missing from production builds

## [0.9.12] - 2026-01-18

### Removed
- Removed deprecated ICS Calendar, Eventbrite, and DoStuff Media API handlers
- Removed migrate-handlers CLI command after completing all handler migrations

## [0.9.11] - 2026-01-18

### Fixed
- Fixed migrate-handlers command to use FlowAbilities API and correct database structure

## [0.9.9] - 2026-01-18

- Added: next_day_cutoff setting for multi-day event detection
- Added: TimezoneAbilities system with find/fix event timezone tools
- Merged: ICS Calendar handler into Universal Web Scraper
- Merged: DoStuff Media API into Universal Web Scraper
- Deprecated: Eventbrite handler (migrates to Universal Web Scraper via JsonLdExtractor)
- Fixed: migrateDoStuffMediaApi method call in MigrateHandlersCommand

## [0.9.8] - 2026-01-17

### Added
- ICS Calendar Migration to Universal Web Scraper
- WP-CLI Migration Tool for Handler Deprecation
- DoStuff Media API Extractor

Created DoStuffExtractor to parse DoStuff Media JSON feeds (Waterloo Records, Do512, etc.). Extracts complete event data including venue coordinates, pricing, artist information, and image URLs. Handles event_groups structure and normalizes all fields to standard event format.

Created MigrateHandlersCommand WP-CLI tool to migrate flows from deprecated handlers to their replacements. Supports ICS Calendar to Universal Web Scraper migration with dry-run mode for safe testing and automatic configuration field mapping.

Created IcsExtractor to parse raw ICS feed content, supporting Tockify, Google Calendar, Apple Calendar, and any standard ICS/iCal feed. Extractor handles timezone detection and venue data extraction from ICS location fields.

### Changed
- Universal Web Scraper Documentation Updates
- Repository Documentation Alignment
- MigrateHandlersCommand Expansion
- MigrateHandlersCommand Eventbrite Support

Expanded migration command to support Eventbrite handler migrations. Added migrateEventbrite() method with 1:1 configuration field mapping from organizer_url to source_url.

Expanded migration command to support DoStuff Media API handler migrations. Added migrateDoStuffMediaApi() method with 1:1 configuration field mapping from feed_url to source_url.

Updated universal-web-scraper-test-command.md with ICS feed support documentation. Added ICS Calendar Feed Support section with examples for Tockify, Google Calendar exports, and migration command usage.

### Deprecated
- ICS Calendar Handler Deprecation
- DoStuff Media API Handler Deprecation
- Eventbrite Handler Deprecation
- Add TimezoneAbilities system with find_broken_timezone_events and fix_event_timezone abilities. EventsHealthCheck now uses abilities as primitives for timezone checking.

Added deprecation notices to Eventbrite handler and settings classes. Existing flows using Eventbrite continue to work but should migrate to Universal Web Scraper using MigrateHandlersCommand. Note: Eventbrite is already handled by JsonLdExtractor in Universal Web Scraper - no new extractor code needed.

Added deprecation notices to DoStuffMediaApi handler and settings classes. Existing flows using DoStuff Media API continue to work but should migrate to Universal Web Scraper using MigrateHandlersCommand.

Added deprecation notices to IcsCalendar handler and settings classes. Existing flows using ICS Calendar continue to work but should migrate to Universal Web Scraper using MigrateHandlersCommand.

## [0.9.7] - 2026-01-16

- Add `next_day_cutoff` setting for multi-day event detection - events ending before this time (default 5:00 AM) on the following day are treated as single-day events.
- Add Calendar Display Settings section to admin settings page.
- Add `datamachine-events settings` WP-CLI command for managing plugin settings.

## [0.9.6] - 2026-01-15

- Add `late_night_time` and `suspicious_end_time` health check categories to `event_health_check` tool.

## [0.9.5] - 2026-01-15

- Calendar groups multi-day events across their full date range.
- Calendar UI adds multi-day labels and continuation styling.
- Event import stores core fields in engine data to prevent AI overrides.
- Venue address formatting reuses preloaded venue data.
- Remove Status_Detection admin file.
- Rename AGENTS.md to CLAUDE.md.

## [0.9.4] - 2026-01-15

- Add published_before/published_after filters to GetVenueEvents tool.
- Improve venue health checks with website detection.

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

## [0.9.3] - 2026-01-08

### Added
- **Venue Health Check Website Detection**: Enhanced `VenueHealthCheck` AI tool to detect data quality issues related to venue websites.
  - Added detection for missing venue websites.
  - Implemented suspicious website detection that flags ticket platform URLs (Eventbrite, Ticketmaster, Dice, etc.) or event-specific paths (`/event/`, `/tickets/`) mistakenly stored in the venue website field.
  - Added sorting for missing and suspicious website categories by event count.
  - Updated tool metadata and response messages to surface these new health indicators to the AI.

## [0.9.2] - 2026-01-08

### Fixed
- **Universal Web Scraper HTML Fallback Logging**: Updated `DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper::extract_raw_html_section()` to log via `ExecutionContext` (and updated the call site), keeping scraper diagnostics consistent during AI HTML fallback extraction.

## [0.9.1] - 2026-01-08

### Fixed
- **Nullable Job Id Handling**: Updated `DataMachineEvents\Steps\EventImport\EventEngineData::storeVenueContext()` to accept a nullable `$job_id` (`?string`) so handler callers can pass null without triggering a PHP type error; value is still cast to int and ignored when invalid.

## [0.9.0] - 2026-01-08

### Changed
- **ExecutionContext Handler Refactor**: Updated Event Import handlers (`DiceFm`, `DoStuffMediaApi`, `Eventbrite`, `EventFlyer`, `IcsCalendar`, `SingleRecurring`, `Ticketmaster`) and the Universal Web Scraper (`UniversalWebScraper`, `StructuredDataProcessor`, `EventSectionFinder`) to use `ExecutionContext` for logging, processed item tracking, flow/pipeline ids, and engine data storage.
- **Engine Data Storage**: Replaced direct `datamachine_merge_engine_data()` calls with `ExecutionContext::storeEngineData()` in the Ticketmaster handler and structured scraper pipeline.
- **WP-CLI Scraper Flow Id**: Updated `Cli\UniversalWebScraperTestCommand` to use `'direct'` flow id when calling `get_fetch_data()`.

## [0.8.42] - 2026-01-07

### Changed
- **WP-CLI Scraper Test Command Simplification**: Updated `Cli\\UniversalWebScraperTestCommand` to run without job creation or upsert options, generating a `cli_test_*` flow step id internally and focusing output on a single packet’s extraction metadata, event payload shape (structured vs `raw_html`), venue/address coverage warnings, and captured `datamachine_log` warnings.

## [0.8.41] - 2026-01-07

### Added
- **Craftpeak/Arryved Extractor**: Added `Steps\\EventImport\\Handlers\\WebScraper\\Extractors\\CraftpeakExtractor` to extract events from Craftpeak/Arryved "Label" theme sites by parsing `/event/{slug}-{YYYY-MM-DD}/` style listings and normalizing title/date/time/image + page-level venue fields.

### Changed
- **Universal Web Scraper Priority**: Updated `UniversalWebScraper` extractor priority to include the Craftpeak/Arryved extractor in the structured extraction pipeline.
- **WP-CLI Scraper Diagnostics**: Enhanced `Cli\\UniversalWebScraperTestCommand` output to report venue/address field coverage (structured vs HTML fallback) and surface warnings when venue metadata is incomplete.

## [0.8.40] - 2026-01-07

### Changed
- **Remove Ticket URL Migration Notice**: Removed `DataMachineEvents\\Admin\\TicketUrlMigration` and its instantiation from `DATAMACHINE_Events::init()` now that the one-time backfill flow is no longer needed.
- **Schema Tool Guidance for Titles**: Updated the AI schema tool parameter description for `Core\\EventSchemaProvider` `title` to emphasize using `original_title` when it is clear, and to rewrite when the scraped title is low quality (ALL CAPS, emojis, dates, formatting).
- **Free Show Price Detection**: Expanded Event Details price CTA logic to treat "no cover" as a non-ticket price alongside "free" and "tbd".
- **Build Output Directory**: Updated `build.sh` to output the packaged plugin into `build/` instead of `dist/`.

## [0.8.39] - 2026-01-07

### Added
- **Ticket URL Indexing Migration**: Added `DataMachineEvents\Admin\TicketUrlMigration`, a one-time admin-triggered migration that backfills `_datamachine_ticket_url` post meta from `datamachine-events/event-details` block `ticketUrl` attributes for existing events.

### Changed
- **Ticket URL Meta Sync**: Event Details block saves now sync a normalized ticket URL into `_datamachine_ticket_url` (query params stripped; trailing slash trimmed) for consistent lookups.
- **Duplicate Detection via Ticket URLs**: `Steps\Upsert\Events\EventUpsert::findExistingEvent()` now checks for existing events by normalized ticket URL + date before falling back to fuzzy/exact title matching.

## [0.8.38] - 2026-01-06

### Changed
- **Music Item Extractor Targeting**: Updated `MusicItemExtractor` to use a strict class token match for `music__item` elements (prevents false positives where `music__item` is a substring of another class).

## [0.8.37] - 2026-01-06

### Added
- **Music Item Extractor**: Added `MusicItemExtractor` to the Universal Web Scraper to extract events from `.music__item` / `.music__artist` HTML patterns.

### Changed
- **Update Event Description Storage**: Updated the `UpdateEvent` AI chat tool to write `description` updates into `core/paragraph` InnerBlocks inside the `datamachine-events/event-details` block (instead of treating description as a block attribute).
- **Event Health Check Description Detection**: Updated the `EventHealthCheck` AI chat tool to derive the event description from the Event Details block InnerBlocks (paragraph content) rather than a non-existent `description` attribute.
- **Venue Address Parsing**: Enhanced `PageVenueExtractor::extractStreetAddress()` to better detect real street addresses (supports `610-A` / `123B` patterns and tighter fallback matching) while reducing false positives.
- **Universal Web Scraper Pipeline**: Added `MusicItemExtractor` to the extraction priority list.

## [0.8.36] - 2026-01-06

### Changed
- **Calendar Pagination Boundaries**: Updated calendar pagination so page boundaries respect both minimum day count and minimum event count.
  - `Calendar_Query::get_unique_event_dates()` now returns `events_per_date` and counts events per `Y-m-d` date while building the unique date list.
  - `Calendar_Query::get_date_boundaries_for_page()` now accepts `events_per_date` and constructs variable-length pages that include at least `DAYS_PER_PAGE` (5) dates and at least `MIN_EVENTS_FOR_PAGINATION` (20) events, without splitting a date across pages.
  - The REST calendar controller (`inc/Api/Controllers/Calendar.php`) and calendar block renderer (`inc/blocks/calendar/render.php`) now pass `events_per_date` into `get_date_boundaries_for_page()`.

## [0.8.35] - 2026-01-06

### Added
- **Skip Tool Item Context**: Added `EventEngineData::storeItemContext()` and invoked it from `EventImportHandler::markItemProcessed()` so Data Machine's `skip_item` tool can mark AI-skipped items as processed and prevent refetching on subsequent runs.

## [0.8.34] - 2026-01-05

### Changed
- **ICS Calendar Sorting**: Updated `IcsCalendar` handler to explicitly sort events chronologically using `sortEventsWithOrder` with `SORT_ASC` to ensure consistent data processing order.
- **WordPress Extractor Refinement**: Enhanced `WordPressExtractor` to more accurately detect Tribe Events content.
  - Implemented stricter regex matching for Tribe-specific container classes and IDs on content elements (div, section, article, main).
  - Added a `SKIP_DOMAINS` blacklist to prevent extraction from domains with non-functional or broken Tribe installations (e.g., `resoundpresents.com`).
  - Refined fallback endpoint construction to only trigger when actual Tribe container elements are present.

## [0.8.33] - 2026-01-05

### Added
- **AI Chat Tools for Events**: Added `EventHealthCheck` and `UpdateEvent` tools to the AI framework.
  - `EventHealthCheck`: Scans events for data quality issues like missing times, suspicious midnight starts, late night start (midnight-4am), suspicious 11:59pm end time, missing venues, or missing descriptions.
  - `UpdateEvent`: Allows AI-driven updates to event block attributes (dates, times, descriptions, ticket URLs) and venue assignments with flexible date parsing.

## [0.8.32] - 2026-01-05

### Added
- **Gigwell Extractor**: New specialized extractor for venues using the Gigwell booking platform (`<gigwell-gigstream>`). Fetches high-fidelity event data including artist details, local timezones, and ticket links via Gigwell's public API.
- **Improved HTML Fallback**: Added XPath support for `article` elements containing `time` tags, improving event detection for sites using Bricks builder and other modern CSS-in-JS frameworks.

### Changed
- **WordPress Extractor Optimization**: Refined `WordPressExtractor` to target Tribe Events specific indicators (`tribe-events`) only, preventing false positives on generic WordPress sites.
- **Universal Web Scraper Expansion**: Reordered extractor priority to include Gigwell and updated documentation to reflect 20 distinct extraction layers.

## [0.8.31] - 2026-01-05

### Added
- **Time Range Display**: Enhanced Calendar block and event items to display full time ranges (e.g., "7:00 PM - 10:00 PM") instead of just start times. Includes smart formatting to omit redundant AM/PM markers when periods match.
- **Dynamic Ticket Button**: Event Details block now automatically changes CTA text from "Get Tickets" to "Event Link" for free or TBD priced events.
- **Venue Timezone Propagation**: Integrated `venueTimezone` into `EventEngineData` to ensure timezone data flows through the Data Machine pipeline to EventUpsert.
- **Venue Description Support**: Added `description` field to `UpdateVenue` AI tool, allowing AI to update venue descriptions via REST API.

### Changed
- **Venue Management Cleanup**: Removed legacy Timezone Backfill UI and REST endpoints. Timezone detection is now fully automated via GeoNames during venue creation/update or provided by import handlers.
- **Improved Venue Logic**: Enhanced `Venue_Taxonomy` to automatically trigger timezone derivation when coordinates are added to an existing venue without them.
- **Sanitization Enhancements**: Added comprehensive query parameter sanitization to Calendar block navigation.

### Fixed
- **Venue Update AI Tool**: Standardized term update logic to use `wp_update_term` correctly for both name and description.
- **Coordinate Stale Data**: Explicitly clearing coordinates when address fields change to ensure re-geocoding uses fresh data.

## [0.8.30] - 2026-01-05

### Added
- **Get Venue Events AI Tool**: New tool to retrieve events for a specific venue, aiding in term investigation and data management.

### Fixed
- **Venue Update AI Tool**: Added check for empty strings in meta field updates to prevent accidental clearing of data when non-null but empty values are passed.

## [0.8.29] - 2026-01-05

### Added
- **Venue Health Check AI Tool**: Implemented automatic sorting of problematic venues by event count descending, prioritizing venues with the most events for data quality fixes.

## [0.8.28] - 2026-01-05

### Added
- **Venue Geocoding Optimization**: Added automatic coordinate clearing when address fields change, ensuring stale coordinates are removed before re-geocoding.

### Fixed
- **Venue Update AI Tool**: Refined meta data update logic to preserve empty strings when intentionally passed, while still filtering null values.
- **Address Change Detection**: Improved venue update logic to explicitly detect changes in any address component (address, city, state, zip, country) before triggering geocoding.

## [0.8.27] - 2026-01-05

### Added
- **BaseExtractor**: New abstract class to standardize Web Scraper Extractors
  - Centralizes date/time parsing, timezone handling, and HTML cleaning
  - Provides consistent sanitization and image resolution helpers
  - Implemented by all specialized extractors (18+ handlers)
- **Universal Web Scraper Test Command**: WP-CLI command to test extraction against a target URL
  - Registers `datamachine-events scrape-test` invokable
  - Supports `--upsert` flag to validate end-to-end venue/event creation
- **Venue Admin Enhancements**:
  - Added address autocomplete to venue term creation/edit screens
  - Integrated geocoding trigger on address updates in admin UI
  - Added event count column to Venue Health Check AI tool

### Changed
- **Calendar Pagination**: Added `MIN_EVENTS_FOR_PAGINATION` (20) threshold
  - Bypasses multi-page navigation when event count is low
  - Simplifies view for users with small event sets
- **Past Events Navigation**: Fixed "Upcoming Events" button URL to correctly remove `past` query parameter
- **Past Events Logic**: Improved date range handling for past events queries (swaps start/end boundaries for correct WP_Query range)
- **Squarespace Extractor**: Enhanced collection discovery and location object parsing
- **Results Counter**: Updated to show relative progress (e.g., "Viewing 1-5 of 120 Events")

## [0.8.26] - 2026-01-04

### Added
- **Elfsight Events Extractor**: New specialized extractor for Universal Web Scraper
  - Detects Elfsight Events Calendar widgets via `elfsight-sapp-{uuid}` class pattern
  - Fetches structured event data from Elfsight API (shy.elfsight.com)
  - Handles JSONP response parsing and widget settings extraction
  - Normalizes events with complete venue, date/time, image, and ticket URL data
  - Supports Shopify domain extraction for widget context
  - Resolves location data from event metadata with timezone handling
  - Extracted 233 lines to `ElfsightEventsExtractor.php`

### Changed
- **UniversalWebScraper**: Added ElfsightEventsExtractor to extraction pipeline at priority 11 (before JSON-LD extractors)
- **Extraction Documentation**: Updated priority list to include Elfsight Events Calendar API support

## [0.8.25] - 2026-01-05

### Added
- **AI Chat Tools**: New venue management tools for Data Machine's AI framework (`inc/Api/Chat/Tools/`):
  - `VenueHealthCheck`: Scans all venues for data quality issues (missing address, coordinates, timezone) and returns detailed counts and lists. Optional `limit` parameter controls results per category (default: 25).
  - `UpdateVenue`: Updates venue name and/or meta fields (address, city, state, zip, country, phone, website, capacity, coordinates, timezone). Accepts venue identifier (term ID, name, or slug) and any combination of fields. Address changes trigger automatic geocoding.
- **Tool Self-Registration**: Both tools use `ToolRegistrationTrait` for automatic registration with the Data Machine AI chat system.

### Changed
- **Plugin Bootstrap**: `load_data_machine_components()` now instantiates Chat tools for AI-driven venue management capabilities.

## [0.8.24] - 2026-01-04

### Added
- **Smart Fallback Mechanism**: Implemented automatic retry with standard headers in `UniversalWebScraper` when browser-mode requests encounter 403 errors or captcha challenges (SiteGround/Cloudflare).
- **Advanced Browser Spoofing**: Updated `HttpClient` with comprehensive browser headers (Sec-Fetch, Accept-Language, etc.) and support for HTTP 202 (Accepted) success codes.
- **Embedded Calendar Enhancements**: Added detection and decoding of Base64-encoded Google Calendar IDs in `EmbeddedCalendarExtractor`.
- **Squarespace Scraper Improvements**: Enhanced `SquarespaceExtractor` to search for events in "Summary Blocks" and hidden template data when main collections are empty.
- **Improved Venue Extraction**: Enhanced `PageVenueExtractor` to handle non-standard US address strings and Squarespace map widgets more effectively.
- **Sahara Lounge Support**: Added `brownbearsw.com` as a reliable source for Sahara Lounge events when the main domain is blocked.

## [0.8.23] - 2026-01-04

### Fixed
- **Google Calendar Detection**: Expanded embedded calendar detection pattern to match `google.com/calendar/embed` without requiring `calendar.` subdomain, improving flexibility for various Google Calendar embed implementations.
- **HTTP Client Configuration**: Added `browser_mode => true` to Google Calendar ICS fetch requests to improve compatibility with sites requiring browser-like behavior.

### Changed
- **EventUpsert Settings**: Excluded `category` and `post_tag` taxonomies from EventUpsert configuration to prevent invalid taxonomy assignments during event import and upsert operations.

## [0.8.22] - 2026-01-04

### Added
- **New Scraper Selectors**: Added XPath patterns for Brown Bear (Sahara Lounge) and Drupal Views/Node event listings in `EventSectionSelectors`.
- **Squarespace Map Block Extraction**: Integrated `extractFromSquarespaceMapBlock` into `PageVenueExtractor` for high-reliability venue data parsing from map widgets.

### Changed
- **UniversalWebScraper Fetch Configuration**: Switched `fetch_html` to use `browser_mode => true` by default to improve compatibility with JavaScript-rendered sites.
- **Improved Address Parsing**: Enhanced `extractCityStateZip` regex to handle comma-separated city/state/zip formats and added stricter US state/zip validation.
- **CLI Command Enhancement**: Updated `UniversalWebScraperTestCommand` to output the full raw HTML content when extraction fails, aiding in scraper debugging.

### Fixed
- **Scraper Title Detection**: Added `EventLink` class pattern to title extraction logic for improved link identification.

## [0.8.21] - 2026-01-04

### Added
- **Enhanced Page Venue Extraction**: Added multi-layered JSON-LD support to `PageVenueExtractor` for high-fidelity venue metadata parsing from site headers and footers.
- **Venue Entity Discovery**: Improved identification of `MusicVenue`, `EntertainmentBusiness`, and `NightClub` schema types in structured data.
- **RhpEvents Enhancement**: Integrated page-level venue data merging into the `RhpEventsExtractor` to fill missing address fields in event listings.

### Changed
- **Extraction Priority**: Refined `PageVenueExtractor` to prioritize JSON-LD structured data over page title parsing and footer scanning.
- **Address Detection**: Improved robust address detection with expanded support for Squarespace announcement bars and site-wide footers.

## [0.8.20] - 2026-01-04

### Added
- **WordPress API Discovery**: Added `attemptWordPressApiDiscovery` to `UniversalWebScraper` to automatically find event API endpoints when the main URL fetch fails.
- **Direct Structured Data Support**: Added direct support for `.ics` and WordPress Tribe Events API URLs in the Universal Web Scraper, bypassing HTML parsing when a direct data feed is provided.

### Changed
- **Scraper User Agent**: Standardized the scraper's User-Agent string to include the Data Machine version and site URL for better transparency.
- **Scraper HTTP Configuration**: Optimized `UniversalWebScraper` HTTP requests to use non-browser mode for faster direct data fetching while maintaining compatibility.

## [0.8.19] - 2026-01-04

### Added
- **Squarespace Extractor Enhancement**: Added JSON API support and HTML list view fallback parsing for better reliability on Squarespace sites.
- **Page Venue Extractor Improvements**: Enhanced with specific Squarespace site title extraction and robust address detection.
- **Universal Web Scraper Documentation**: Added `docs/universal-web-scraper-test-command.md` documenting WP-CLI test commands.

### Changed
- **Venue Detection**: Refined venue name extraction from page titles with expanded filter keywords and better separator support.
- **Address Extraction**: Improved extraction logic to handle Squarespace announcement bars and specific footer sections.

## [0.8.18] - 2026-01-04

### Added
- **Embedded Calendar Extractor**: New extractor for Universal Web Scraper to detect and parse events from embedded calendar widgets (Tockify, etc.).
- **Timely Extractor**: Specialized extractor for Time.ly All-in-One Event Calendar platform.
- **Page Venue Extractor**: Enhanced venue metadata extraction for Universal Web Scraper that parses parent page context for venue details when event listings are sparse.

### Changed
- **Handler Consolidation**: Removed standalone `GoogleCalendar` and `WordPressEventsAPI` handlers. Their logic is now consolidated into the `UniversalWebScraper` via specialized extractors (`EmbeddedCalendarExtractor` and `WordPressExtractor`).
- **Web Scraper Optimization**: Reorganized extractor priority for better detection accuracy and reduced external HTTP requests.
- **CLI Improvements**: Enhanced `UniversalWebScraperTestCommand` with improved venue ZIP code handling and display formatting.

## [0.8.17] - 2026-01-04

### Changed
- **GoDaddy Extractor Enhancement**: Added REST API detection to the `GoDaddyExtractor` in the Universal Web Scraper. This prevents the extractor from incorrectly identifying standard WordPress REST API endpoints (like those from The Events Calendar) as GoDaddy-specific JSON feeds.
- **Improved JSON Validation**: Added stricter array checks for JSON responses in the `GoDaddyExtractor` to handle malformed or unexpected data gracefully.

## [0.8.16] - 2026-01-04

### Added
- **GeoNames Integration**: Added automatic venue timezone detection based on coordinates using the GeoNames API.
- **Timezone Backfill System**: Added a batch-processing REST API and admin UI for updating timezones for existing venues.
- **Scraper Extractors**: Added `WordPressExtractor`, `GoDaddyExtractor`, and `BandzoogleExtractor` to the Universal Web Scraper.
- **WP-CLI Commands**: Added `test-scraper` and `test-scraper-url` commands for debugging web scrapers.
- **Settings**: Added `geonames_username` field to plugin settings for timezone detection.

### Changed
- **Firebase Extractor**: Enhanced script sniffing to automatically detect Firebase database URLs from external scripts.
- **Handler Consolidation**: Removed legacy standalone Bandzoogle, GoDaddy, and SpotHopper handlers in favor of Universal Web Scraper extractors.

### Technical Details
- Added `GeoNamesService` for timezone lookups.
- Added `Venues::batch_update_timezones()` REST endpoint.
- Added `assets/js/settings-backfill.js` for admin timezone processing.

## [0.8.15] - 2026-01-04

### Changed
- **Prekindle Integration**: Refactored the Prekindle handler into a specialized extractor within the `UniversalWebScraper`.
  - Added `PrekindleExtractor` with support for hybrid API/HTML data extraction.
  - Improved data accuracy by scraping `pk-times` HTML blocks for door and show times.
  - Removed legacy standalone `Prekindle` handler and settings classes.
- **Universal Web Scraper**: Integrated `PrekindleExtractor` into the extraction pipeline.
- **Documentation**: Updated `universal-web-scraper-handler.md` with technical details for Prekindle extraction support.

## [0.8.14] - 2026-01-04 

### Added 
- **Timezone Support**: Introduced centralized timezone handling for events and venues 
  - Added `DateTimeParser` utility for robust UTC, local, and ISO 8601 parsing 
  - Added `venueTimezone` metadata to `VenueParameterProvider` and `Venue_Taxonomy` 
  - Updated `Calendar_Query` to respect venue-specific timezones for date grouping and display 
- **Squarespace Extractor**: New extractor for the Universal Web Scraper 
  - Extracts events from `Static.SQUARESPACE_CONTEXT` JavaScript objects 
  - High-fidelity extraction of title, description, images, and ticket URLs 
- **SpotHopper Support**: Converted SpotHopper into a Universal Web Scraper extractor
  - Improved auto-detection of SpotHopper platform and spot_id extraction
  - Fetches structured event data directly from SpotHopper public API

### Changed
- **Handler Consolidation**: Migrated `DiceFm`, `Eventbrite`, `IcsCalendar`, and `Ticketmaster` to standardized FetchHandler patterns.
- **Universal Web Scraper**: Refactored to use structured data extractors instead of monolithic extraction logic. Consolidated legacy standalone `GoogleCalendar` and `WordPressEventsAPI` handlers into `EmbeddedCalendarExtractor` and `WordPressExtractor`.
- **EventImportHandler**: Changed several methods from protected to public (shouldSkipEventTitle, extractVenueMetadata, stripVenueMetadataFromEvent, isPastEvent) for better extensibility.
- **Documentation**: Completely rewrote universal-web-scraper-handler.md to document new extraction architecture

### Technical Details
- **Extraction Priority**: The handler now tries extractors in priority order, using first successful extraction method
- **Engine Data Integration**: Structured data stores venue and event fields in engine data before AI processing
- **Event Filtering**: Structured data processors apply keyword filtering, past event detection, and duplicate prevention uniformly

## [0.7.4] - 2025-12-24

### Fixed
- **Ticketbud OAuth Scope**: Added `scope` parameter with `public` value to authorization URL for proper OAuth 2.0 scope specification

## [0.7.3] - 2025-12-24

### Fixed
- **Ticketbud OAuth Parameter**: Corrected OAuth authorization URL parameter from `redirect_url` to `redirect_uri` to comply with OAuth 2.0 specification

## [0.7.2] - 2025-12-23

### Added
- **Ticketbud Event Import Handler**: New OAuth 2.0 authenticated handler for Ticketbud API
  - Full event data extraction: title, description, dates, times, venue, pricing
  - Venue metadata: name, address, city, state, zip, country, phone, website, coordinates
  - Keyword filtering: include/exclude keywords for selective event imports
  - Past/over event filtering: configurable option to include events marked as over
  - Single-item processing with EventIdentifierGenerator for duplicate prevention
  - OAuth 2.0 authentication with client ID/secret configuration
  - User profile integration: email, name, default subdomain

### Technical Details
- **Ticketbud API Integration**: Fetches from `https://api.ticketbud.com/events.json` with access token
- **Venue Metadata Extraction**: Complete location data mapping from Ticketbud event structure
- **Handler Registration**: Added to `load_event_import_handlers()` array in main plugin file

## [0.7.1] - 2025-12-22

### Added
- **Archive Taxonomy Context Support**: Calendar and filters REST endpoints now accept `archive_taxonomy` and `archive_term_id` parameters for proper filtering on taxonomy archive pages

### Changed
- **Enhanced Date Range Filtering**: Added `user_date_range` flag to detect when users specify custom date ranges, improving query logic for date boundary calculations and calendar rendering
- **API Route Extensions**: Extended calendar and filters endpoints with new parameters for archive-aware taxonomy filtering

### Technical Details
- **Calendar_Query Logic**: Modified date boundary handling to respect user-specified ranges while maintaining default pagination behavior
- **REST Parameter Sanitization**: Added proper sanitization for new archive context parameters in API controllers

## [0.7.0] - 2025-12-21

### Added
- **GoDaddy Calendar Handler**: New JSON-based import handler for GoDaddy Website Builder calendars using `events_url` with configurable venue fields and keyword filtering
- **Global Title Exclusion**: All import handlers now skip events whose title contains `closed` (case-insensitive) for universal event filtering
- **Enhanced Keyword Filtering**: Eventbrite and Google Calendar (via `EmbeddedCalendarExtractor`) handlers now support include/exclude keyword filtering via `search` and `exclude_keywords` settings
- **Handler Title Validation**: Centralized title exclusion logic in `EventImportHandler::shouldSkipEventTitle()` called by all handlers (DiceFm, DoStuffMediaApi, EventFlyer, Eventbrite, Universal Web Scraper, Ticketmaster)
- **EventbriteSettings**: Added `search` and `exclude_keywords` fields for improved event filtering

### Technical Details
- **Handler Base Class**: Added `getGlobalExcludedTitleKeywords()` and `shouldSkipEventTitle()` methods to support centralized title filtering
- **Documentation**: Created `docs/godaddy-calendar-handler.md` with full setup, configuration, and data mapping documentation

## [0.6.5] - 2025-12-20

### Fixed
- **Plugin Dependency Slug**: Corrected `Requires Plugins` header from `datamachine` to `data-machine` for proper WordPress plugin dependency detection

### Added
- **Ticketmaster Price Data Merging**: Price information from Ticketmaster imports now merges into engine data via `datamachine_merge_engine_data()` for improved AI processing context

### Changed
- **EventUpsert Field Support**: Added `price` field to resolved fields array in EventUpsert handler for complete price field handling during event updates
- **Build Dependencies**: Updated @wordpress/scripts and npm package configurations with build warning suppressions for cleaner development experience

## [0.6.4] - 2025-12-19

### Added
- **Event Excerpt Generation**: Automatic excerpt creation from Event Details block content when no manual excerpt is set
- **Archive Filter UX**: Hide taxonomy filter button on archive pages when no additional filter options are available

### Changed
- **Calendar Filter State**: Enhanced filter button visibility logic for archive contexts
- **Event Details Rendering**: Simplified block content output structure
- **Taxonomy Helper**: Added archive taxonomy query override support for accurate filtering
- **Filters API**: Updated to handle archive context for proper term count calculations

## [0.6.3] - 2025-12-17

### Changed
- **Universal Web Scraper Keyword Filtering**: Added include/exclude keyword filtering to improve event selection accuracy
- **Datetime Meta Synchronization**: Post meta now always overrides block attributes for datetime values, ensuring data consistency
- **Time Format Standardization**: Automatic seconds padding for time fields in meta storage
- **Documentation Field References**: Updated handler documentation to reflect 'search' field naming

### Technical Details
- **Calendar Query Hydration**: Modified `hydrate_datetime_fields()` to prioritize meta values over block attributes
- **Web Scraper Processing**: Enhanced to process multiple sections per page with keyword-based filtering
- **Meta Storage**: Improved time format handling with automatic HH:MM:SS conversion

## [0.6.3] - 2025-12-17

### Added
- **SingleRecurring Event Handler**: New handler for creating weekly recurring events like open mics, trivia nights, and regular gatherings
  - Configurable day of week, start/end times, and expiration dates
  - Full venue metadata support with address, city, state, zip, country fields
  - Single-item processing with EventIdentifierGenerator for deduplication
  - Keyword filtering (include/exclude) for selective event imports
  - Automatic next-occurrence calculation based on configured day of week
  - Uses existing venue taxonomy and metadata extraction patterns

## [0.6.2] - 2025-12-17

### Changed
- **Carousel Single Card Mode**: Enhanced carousel indicators to activate viewport dots only when displaying single cards on narrow screens, improving mobile responsiveness
- **Carousel Code Optimization**: Removed duplicate firstEventWidth calculation in chevron navigation logic

## [0.6.0] - 2025-12-16

### Added
- **Archive Context Support**: Calendar block automatically filters to current taxonomy term when viewing venue or promoter archive pages
- **Fuzzy Title Matching**: EventIdentifierGenerator now supports fuzzy title comparison for improved duplicate detection across import sources with varying title formats
- **Enhanced Event Finding**: EventUpsert uses fuzzy title matching when venue and date context are available for better cross-source duplicate detection

### Changed
- **Calendar REST API**: Added `archive_taxonomy` and `archive_term_id` parameters for archive page context
- **Calendar Query Builder**: Enhanced with archive context support and additional taxonomy imports
- **Frontend JavaScript**: Integrated archive context in filter state management system
- **API Client**: Updated to accept and forward archive context to REST endpoints

## [0.5.20] - 2025-12-16

### Changed
- **Import Handler Response Standardization**: Refactored all 12 import handlers to return DataPacket arrays directly instead of using wrapper methods (`emptyResponse()`/`successResponse()`)
- **EventImportStep Compatibility**: Updated to handle both legacy `processed_items` format and new direct DataPacket array format from FetchHandlers
- **Version Synchronization**: Updated composer.json version to match current release

## [0.5.19] - 2025-12-15

### Changed
- **Universal Web Scraper Enhancement**: Added XPath selector for "recspec-events--event" class pattern to improve website compatibility

### Documentation
- **Universal Web Scraper Handler**: Updated documentation to clarify Schema.org JSON-LD and microdata priority over AI processing
- **BandzoogleCalendar Handler**: Added comprehensive documentation for the Bandzoogle calendar import handler
- **Prekindle Handler**: Added complete documentation for the Prekindle widget import handler
- **CLAUDE.md**: Updated to include BandzoogleCalendar and Prekindle handlers
- **README.md**: Updated import pipeline section to reflect all 12 handlers

## [0.5.18] - 2025-12-15

### Added
- **BandzoogleCalendar Import Handler**: New event import handler for Bandzoogle-powered venue calendar pages, supporting forward month pagination, popup HTML extraction, keyword filtering, and venue metadata configuration.

## [0.5.17] - 2025-12-15

### Changed
- **Prekindle Handler Refactoring**: Improved time extraction reliability by switching from URL-based to title-based matching for event times, ensuring more consistent data extraction from Prekindle widget pages.

### Fixed
- **Prekindle Handler**: Added missing `normalizeTitleKey()` method to complete the title-based extraction refactoring.

## [0.5.16] - 2025-12-14

### Changed
- **Prekindle Handler Refactoring**: Updated to use org_id configuration instead of organizer_url for more reliable widget integration, breaking change for existing Prekindle handler configurations

## [0.5.15] - 2025-12-14

### Added
- **Prekindle Handler**: New event import handler for Prekindle organizer pages, combining JSON-LD data with HTML-extracted start times

### Changed
- **Build Process**: Enhanced production build script with colored output, error handling, and build information generation
- **Block Builds**: Updated to use @wordpress/scripts 30.19.0 and removed custom webpack configurations

### Fixed
- **Mobile Pagination**: Improved responsive CSS with 480px breakpoint and better flex wrapping for small screens
- **Web Scraper**: Continued refinements to EventSectionFinder and EventSectionSelectors classes

## [0.5.14] - 2025-12-12

### Changed
- **Universal Web Scraper Refactoring**: Improved code organization by extracting EventSectionFinder and EventSectionSelectors classes for better maintainability and separation of concerns

## [0.5.13] - 2025-12-09

### Changed
- **Carousel Navigation Improvements**: Enhanced mobile dot indicators with viewport-based overflow handling for better navigation with many events
- **CSS Optimization**: Simplified carousel indicators styling by removing class-based collapsed states
- **Documentation**: Renamed AGENTS.md to CLAUDE.md for consistency

## [0.5.12] - 2025-12-09

### Fixed
- **Carousel Indicators CSS**: Added width constraint and flex-start justification for improved collapsed mobile navigation display

## [0.5.11] - 2025-12-XX

### Fixed
- **Carousel Dot Indicators**: Enhanced sliding window functionality for collapsed mobile navigation states

## [0.5.10] - 2025-12-XX

### Fixed
- **Carousel Dot Indicators**: Improved collapsed state handling for better mobile navigation with sliding window functionality
- **Chevron Navigation**: Enhanced positioning and styling for carousel navigation arrows
- **Filter Bar Styling**: Refined CSS for search inputs, date filters, and responsive design consistency

## [0.5.9] - 2025-12-09

### Changed
- **Documentation Updates**: Comprehensive alignment of CLAUDE.md, calendar-block.md, event-details-block.md, ticketmaster-handler.md, and universal-web-scraper-handler.md for improved clarity and current architecture reflection
- **Carousel Enhancements**: Added collapsed dots functionality for mobile navigation with small/medium/active dot states and improved touch interaction
- **Filter Bar Styling**: Modernized search input and filter bar styles with improved focus states, transitions, and responsive design
- **Version Synchronization**: Corrected composer.json version from 0.5.7 to match current version

### Technical Details
- **Mobile UX**: Enhanced carousel dot navigation with MAX_MOBILE_DOTS limit and sliding window for better mobile experience
- **CSS Modernization**: Updated filter bar styles with CSS custom properties and improved accessibility

## [0.5.8] - 2025-12-05

### Fixed
- **Query Parameter Sanitization**: Improved nested array sanitization to properly handle multi-dimensional query parameters
  - New `datamachine_events_sanitize_query_params()` global function for recursive sanitization
  - Updated `Pagination::sanitize_query_params()` to use same recursive logic
  - Preserves array structure while sanitizing all scalar values
  - Fixes taxonomy filter parameters with indexed arrays (e.g., `tax_filter[genre][0]`)

### Changed
- **Event Details Block Description**: Now extracts plain text from block content for Schema.org `description` field
  - Uses `wp_strip_all_tags()` to preserve description data in schema without HTML markup
  - Improves structured data quality for search engines
  - Description flows from InnerBlocks content through to JSON-LD schema

- **Filter State Regex**: Enhanced filter parameter parsing to support both indexed and non-indexed array syntax
  - Regex pattern now matches `tax_filter[taxonomy][0]` and `tax_filter[taxonomy][]` formats
  - Better compatibility with different WordPress filter parameter conventions
  - Fixes filter restoration on pagination with indexed array parameters

- **Universal Web Scraper Table Support**: Improved table-based event pattern detection
  - Moved table XPath selectors to higher priority (before generic list patterns)
  - Added patterns for common table structures: `.calendar`, `.events`, `.schedule` classes
  - Prevents false matches from navigation lists when tables are present
  - Better support for venue websites using tabular event calendars

### Technical Details
- **Pagination Refactoring**: Removed scalar value iteration from `Pagination.php`, using recursive function instead
- **Navigation Template**: Updated to use global `datamachine_events_sanitize_query_params()` function
- **Code Quality**: Minor whitespace cleanup for consistency

### Changed
- **Universal Web Scraper Enhancement**: Added support for table-based event patterns
  - New XPath selectors for HTML table layouts commonly used in venue calendars
  - Added header row detection to skip table headers during event extraction
  - Improves event scraping accuracy for venue websites using tabular event listings

## [0.5.6] - 2025-12-05

### Added
- **Universal Web Scraper Pagination**: Automatic traversal of paginated event listings
  - Follows "next page" links using standard HTML5 `rel="next"`, SEO link elements, and common pagination patterns
  - `MAX_PAGES` constant (20) prevents infinite loops
  - Visited URL tracking prevents re-scraping same pages
  - Supports relative URLs, protocol-relative URLs, and same-domain validation
- **Ticketmaster API Pagination**: Automatic traversal through API result pages
  - `MAX_PAGE` constant (19) respects Ticketmaster API limits
  - Enhanced logging with page number context
  - Fetches next pages when all events on current page are already processed

### Changed
- **UniversalWebScraper Handler**: Refactored `get_fetch_data()` to loop through pages until finding an unprocessed event or reaching max pages
- **Ticketmaster Handler**: Refactored `fetch_events()` to return page metadata; `get_fetch_data()` now uses do-while pagination loop

## [0.5.5] - 2025-12-05

### Changed
- **Filter State Management Refactoring**: Centralized calendar filter state logic into dedicated `FilterStateManager` class
  - New `inc/Blocks/Calendar/src/modules/filter-state.js` module for unified state management
  - Improved separation of concerns between URL handling, localStorage persistence, and UI updates
  - Enhanced filter count badge accuracy and state restoration reliability
- **Frontend JavaScript Architecture**: Refactored calendar modules for better maintainability
  - Updated `frontend.js` to use new FilterStateManager instance pattern
  - Simplified `filter-modal.js` with cleaner state integration
  - Streamlined `state.js` by removing duplicated functionality
- **Documentation Expansion**: Added comprehensive documentation for core components
  - `docs/dynamic-tool-parameters-trait.md` - Dynamic AI tool parameter filtering
  - `docs/event-post-type.md` - Event post type architecture
  - `docs/event-upsert-system.md` - Event upsert handler documentation
  - `docs/meta-storage.md` - Meta field storage system
  - `docs/promoter-taxonomy.md` - Promoter taxonomy implementation
  - `docs/venue-service.md` - Venue service architecture

## [0.5.4] - 2025-12-04

### Changed
- **Filter Modal Enhancement**: Updated filter count logic to count URL parameters instead of checked checkboxes for more accurate badge display
- **Promoter Taxonomy UI**: Added promoter taxonomy to admin menu for better taxonomy management visibility
- **Web Scraper Enhancement**: Added XPath selectors for SeeTickets widget patterns to improve event scraping from additional venue websites

## [0.5.3] - 2025-12-04

### Changed
- **Event Upsert Promoter Handling**: Promoter taxonomy now respects handler configuration, only creating or assigning terms when `taxonomy_promoter_selection` is set to AI or a specific term; existing pipelines default to `skip` so no unintended promoter terms are attached.
- **Handler Documentation**: Streamlined Dice FM, DoStuff Media API, and Ticketmaster handler documentation for improved clarity and conciseness.

## [0.5.2] - 2025-12-04

### Added
- **Schema Meta Fallbacks**: EventSchemaProvider now always injects start/end timestamps using `_datamachine_event_datetime` meta so Google Search Console no longer flags missing `endDate`.
- **Event Upsert Hardening**: `buildEventData()` hydrates start/end dates and times from meta before generating block content, ensuring schema data stays consistent across imports and manual edits.
- **Filters API Controller**: New REST endpoint `/datamachine/v1/events/filters` for dynamic taxonomy filter options with active filter support, date context, and archive context support.

### Changed
- **Event Details Block**: Server-side render always emits Schema.org JSON-LD and relies on EventSchemaProvider's meta fallbacks for complete structured data.
- **Calendar Controller**: Enhanced pagination logic and parameter handling for better performance.
- **Results Counter**: Improved date formatting and event count display for better user experience.
- **Taxonomy Helper**: Enhanced taxonomy processing with filter support and date context filtering for more accurate taxonomy counts.
- **API Client**: Enhanced calendar API client with filter support and improved error handling.
- **Filter Modal**: Updated close button handling to support multiple close buttons and improved accessibility.
- **State Management**: Improved URL state management and query parameter building for better browser history support.
- **Calendar Styles**: Modern filter bar styling with improved search input and focus states.
- **Filter Bar Template**: Instance-specific IDs and enhanced UI for multi-calendar support.
- **Event Upsert Promoter Handling**: Promoter taxonomy now respects handler configuration, only creating or assigning terms when `taxonomy_promoter_selection` is set to AI or a specific term; existing pipelines default to `skip` so no unintended promoter terms are attached.
- **Documentation**: `docs/event-schema-provider.md` and `docs/event-details-block.md` now describe how datetime meta feeds schema fallbacks.

## [0.5.1] - 2025-12-03

### Changed
- **Documentation Updates**: Comprehensive documentation alignment across CLAUDE.md, README.md, and all docs/ files to reflect current architecture
- **Calendar Controller**: Enhanced pagination logic and parameter handling
- **Results Counter**: Improved date formatting and event count display for better user experience

## [0.5.0] - 2025-12-03

### Added
- **Day-Based Pagination**: `Calendar_Query` now exposes a `DAYS_PER_PAGE` constant and returns ordered date boundaries so the calendar API, pagination helpers, and templates always render whole days (5 per page) instead of arbitrary event counts.
- **Promoter Taxonomy & Schema Coverage**: New `Promoter_Taxonomy` registers promoter data, and the Event Details block plus EventSchemaProvider/renderer now surface promoter metadata as Schema.org organizer values.

### Changed
- **Calendar API & Templates**: The calendar controller now builds queries from date boundaries, clamps `paged` to the available pages, and renders pagination/counter output via the new date-aware helpers; documentation (`docs/pagination-system.md`, `docs/calendar-block.md`) was updated to describe the day-based workflow.
- **Event Upsert & Schema Integration**: EventUpsert registers the promoter taxonomy handler, merges organizer fields via engine data, and the schema generator now accepts organizer data before generating JSON-LD to keep event details, taxonomy, and schema outputs aligned.

## [0.4.18] - 2025-12-02

### Added
- **DynamicToolParametersTrait**: Centralized trait for engine-aware AI tool parameter filtering
  - Filters tool parameters at definition time based on engine data presence
  - If a parameter value exists in engine data, it's excluded from tool definition
  - AI only sees parameters it needs to provide, preventing conflicts

### Changed
- **EventSchemaProvider**: Refactored to use `DynamicToolParametersTrait`
  - `getCoreToolParameters()`, `getSchemaToolParameters()`, `getAllToolParameters()` now accept optional `$engine_data` parameter
  - Removed `engineOrTool()` method - filtering now happens at definition time, not execution time
- **VenueParameterProvider**: Refactored to use `DynamicToolParametersTrait`
  - Removed duplicate `filterByEngineData()` implementation
  - Maintains `hasVenueData()` early-exit optimization
- **EventUpsert**: Simplified parameter handling
  - Replaced `engineOrTool()` routing with simple `buildEventData()` merge
  - Engine parameters take precedence, AI fills in remaining fields
- **EventUpsertFilters**: Updated `getDynamicEventTool()` to pass `$engine_data` to all parameter methods

### Technical Details
- **Definition-Time Filtering**: Parameters are filtered when tool definition is built, not during execution
- **Single Responsibility**: Trait handles parameter filtering, providers define their parameters
- **Cleaner Execution**: No routing needed at execution time since AI only provided necessary parameters

## [0.4.17] - 2025-12-01

### Changed
- **HTTP Client Modernization**: Comprehensive refactoring to use DataMachine HttpClient across all import handlers and core components
  - Replaced `wp_remote_get()` with `DataMachine\Core\HttpClient::get()` for consistent HTTP handling
  - Updated error handling patterns from WordPress remote API functions to HttpClient response format
  - Added context parameters for improved logging and debugging capabilities
  - Affected components: Geocoding API, Venue Taxonomy, and all 8 import handlers (DiceFm, DoStuffMediaApi, Eventbrite, GoogleCalendar, SpotHopper, UniversalWebScraper, WordPressEventsAPI, Ticketmaster)
  - Improved code consistency and maintainability across the entire HTTP request layer

## [0.4.16] - 2025-12-01

### Added
- **DoStuffMediaApi Import Handler**: New handler for DoStuff Media JSON feeds with comprehensive venue metadata extraction
  - Supports venues like Waterloo Records using DoStuff Media platform
  - Extracts complete event data including venue coordinates, pricing, and artist information
  - Single-item processing with EventIdentifierGenerator for duplicate prevention
  - Keyword filtering (include/exclude) for selective event imports
- **Address-Based Venue Matching**: Enhanced Venue_Taxonomy with smart address normalization and duplicate prevention
  - Normalizes addresses for consistent matching (street abbreviations, case insensitivity)
  - Finds existing venues by address + city combination before name matching
  - Smart metadata merging when venues are matched by address
- **DiceFm Keyword Filtering**: Include/exclude keyword options for better event selection control
  - Filter events by keywords in title and description
  - Comma-separated keyword lists for flexible filtering

### Enhanced
- **Calendar Filter Reset**: Complete state clearing with localStorage and URL parameter reset
  - Clears localStorage calendar state and URL parameters
  - Resets filter count badge to zero for accurate visual feedback
  - Improved reliability of filter reset functionality
- **Venue Taxonomy**: Smart merging of venue metadata when matching by address
- **Handler Documentation**: Comprehensive docs for DoStuffMediaApi and updated DiceFm/Ticketmaster guides

### Changed
- **DiceFm Handler**: Simplified configuration with hardcoded API parameters for consistency
  - Hardcoded page_size: 100 and types: 'linkout,event' for reliable behavior
  - Removed configurable date_range and page_size parameters
- **Venue Processing**: Address-first matching strategy for improved venue deduplication

## [0.4.15] - 2025-11-30

### Enhanced
- **Calendar Filter Reset**: Complete state clearing when resetting filters
  - Clears localStorage calendar state and URL parameters
  - Resets filter count badge to zero for accurate visual feedback
  - Improved reliability of filter reset functionality

## [0.4.14] - 2025-11-30

### Fixed
- **Filter Modal Memory Leaks**: Added proper cleanup functions to prevent duplicate event listeners and memory leaks during calendar refreshes
- **State Storage Optimization**: Enhanced localStorage filtering to only persist taxonomy filters, preventing storage bloat from other URL parameters

### Changed
- **Dice FM Authentication**: Corrected authentication provider identifier from 'dice_fm_events' to 'dice_fm' for proper API integration
- **Web Scraper Selectors**: Added new XPath selector for better event content detection on various venue websites
- **EventUpsert Settings**: Removed redundant venue information field from handler configuration UI

## [0.4.13] - 2025-11-30

### Changed
- **Carousel Dot Styling** - Refined visual feedback for carousel navigation
  - Reduced dot size from 8px to 7px for cleaner appearance
  - Adjusted active dot scale from 1.2 to 1.08 for subtler interaction feedback
  - Improved visual hierarchy in carousel navigation

- **DiceFm Handler Simplification** - Streamlined configuration and processing
  - Removed configurable date_range, page_size, and event_types parameters
  - Hardcoded API parameters for consistent behavior (page_size: 100, types: 'linkout,event')
  - Eliminated client-side date range filtering to reduce complexity
  - Simplified settings interface and data sanitization

- **Ticketmaster Handler Simplification** - Enhanced reliability with fixed parameters
  - Removed configurable start_date parameter to prevent timezone issues
  - Hardcoded start time to +1 hour from current time for consistent API behavior
  - Simplified API parameter building and request handling

## [0.4.12] - 2025-11-30

### Added
- **Enhanced Interactive Styling** - Day-specific hover colors for event titles and "More Info" buttons
  - Event title links now use day-specific colors on hover matching the day's color scheme
  - "More Info" buttons display day-specific border colors on hover for visual consistency
  - Improved user feedback and interactive element visibility

### Changed
- **Carousel Dot Styling** - Simplified active dot color to use secondary text color
  - Removed complex day-specific carousel dot colors for cleaner visual hierarchy
  - Active dots now use consistent `--datamachine-text-secondary` color
  - Maintains clear visual feedback while reducing CSS complexity

- **Carousel Chevron Styling** - Removed day-specific chevron background colors
  - Simplified chevron styling for better visual consistency
  - Chevrons now use default styling without day-specific background colors
  - Cleaner appearance while maintaining functional navigation

## [0.4.11] - 2025-11-30

### Added
- **Enhanced Day-Specific Styling** - Comprehensive color theming for calendar navigation elements
  - Day-specific hover border colors for all 7 days of week
  - Day-specific carousel dot colors with inactive (rgba) and active (solid) states
  - Day-specific chevron background colors matching day color scheme
  - Improved visual hierarchy and user feedback for interactive elements

### Changed
- **Day Header Positioning** - Adjusted day header positioning from 12px to 14px for better visual alignment
- **Color Consistency** - All interactive calendar elements now use consistent day-specific theming

## [0.4.10] - 2025-11-30

### Added
- **Enhanced Carousel Navigation** - Advanced chevron controls with click and hold functionality
  - Click for single card navigation, hold for continuous scrolling
  - Touch-friendly with proper event handling for mobile devices
  - Smooth scroll behavior with visual feedback (hover/active states)
  - Improved active dot detection using scroll position calculation

- **Day-Specific Border Styling** - Visual date grouping with color-coded borders
  - Each day of week gets unique border color using existing design tokens
  - Sunday through Saturday border colors match day color scheme
  - Enhanced visual separation between different date groups

- **Handler Documentation** - Comprehensive documentation for 4 import handlers
  - `dice-fm-handler.md` - Complete Dice FM API integration guide
  - `ics-calendar-handler.md` - Generic ICS feed handler documentation  
  - `ticketmaster-handler.md` - Ticketmaster Discovery API reference
  - `universal-web-scraper-handler.md` - AI-powered web scraping guide

### Changed
- **Enhanced Carousel Dot Detection** - Improved active dot calculation algorithm
  - Desktop: Uses scroll position to determine visible card range instead of visibility percentage
  - More accurate dot highlighting based on actual scroll progress
  - Better handling of different screen sizes and card widths

- **Improved URL State Management** - Clean URL handling for empty filter states
  - Removes query string when all filters are cleared
  - Cleaner URLs with no trailing `?` characters
  - Better browser history management

- **ICS Calendar Handler Refactoring** - Improved parser integration and data mapping
  - Updated to use object property access instead of array notation for iCal parser
  - Added `filterDaysBefore` configuration to filter old events
  - Removed redundant debug logging for cleaner output
  - Better error handling and data type consistency

- **Taxonomy Helper Date Filtering** - Enhanced date context filtering accuracy
  - Now uses `EVENT_END_DATETIME_META_KEY` to match Calendar_Query behavior
  - More precise past/future event filtering using end datetime
  - Consistent date filtering across taxonomy and calendar queries

- **Flatpickr Theme Enhancement** - Improved weekday styling
  - Changed weekday text color from secondary to primary for better readability
  - More specific CSS selector (`span.flatpickr-weekday`) for targeted styling

- **Documentation Updates** - Comprehensive documentation alignment
  - Updated calendar-block.md with latest carousel navigation features
  - Enhanced event-details-block.md with current attribute documentation
  - Improved rest-api.md with endpoint usage examples
  - Updated venue-management.md with geocoding integration details

### Fixed
- **Carousel Chevron Interactivity** - Fixed chevron buttons being non-interactive
  - Changed from `pointer-events: none` to `pointer-events: auto`
  - Added proper cursor styling and user interaction feedback
  - Implemented hover and active states for better UX

- **ICS Parser Compatibility** - Fixed property access for updated iCal parser library
  - Changed from array access (`$ical_event['SUMMARY']`) to object access (`$ical_event->summary`)
  - Ensures compatibility with latest johngrogg/ics-parser version
  - More robust data extraction with proper null checking

## [0.4.9] - 2025-11-29

### Added
- **Date Context Filtering** - Enhanced Filters API with date parameter support
  - `/datamachine/v1/events/filters` endpoint now accepts `date_start`, `date_end`, and `past` parameters
  - Contextual taxonomy filtering based on selected date ranges
  - Improved filter accuracy with date-aware term counts

- **Local Storage State Persistence** - Calendar filter state is now saved and restored
  - Filter selections persist across page reloads using localStorage
  - Automatic state restoration when returning to clean URLs
  - Enhanced user experience with maintained filter context

- **Address Autocomplete CSS** - Extended venue autocomplete styles
  - Added `.address-autocomplete-container` class support
  - Consistent styling for address field autocomplete functionality

### Changed
- **Enhanced Carousel Dot Detection** - Improved active dot calculation for carousel navigation
  - Better detection of single-card vs multi-card display modes
  - More accurate dot highlighting based on visible event cards
  - Responsive behavior across different screen sizes

- **Date Picker Clear Button** - Added clear functionality to date range picker
  - Clear button appears when dates are selected
  - Improved date picker usability with easy reset option
  - Better visual feedback for date picker state

- **Taxonomy Helper Date Context** - Enhanced taxonomy processing with date filtering
  - `get_all_taxonomies_with_counts()` now accepts optional date context
  - Date-aware taxonomy term counts and filtering
  - More accurate filter options based on selected date ranges

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

- Add published_before/published_after filters to GetVenueEvents tool.
- Improve venue health checks with website detection.

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
