=== Data Machine Events ===
Contributors: chubes
Tags: events, calendar, ticketmaster, eventbrite, import, venues, api
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 0.8.42
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Frontend-focused WordPress events plugin with block-first architecture. Integrates with Data Machine plugin for automated event imports and AI-driven event publishing.

== Description ==

**Data Machine Events** is a modern WordPress events plugin built with a **block-first architecture**. It provides elegant event display and management through Gutenberg blocks while integrating seamlessly with the Data Machine plugin for automated event imports and AI-driven content creation.

= Key Features =

**🏗️ Block-First Architecture**
* **Single Source of Truth:** All event data (date, time, venue, price, etc.) is managed directly within the `Event Details` block in the Gutenberg editor.
* **No More Meta Boxes:** This modern approach eliminates the need for cumbersome custom fields and meta boxes, creating an intuitive editing experience.
* **Optimized for Performance:** Key data, like the event start time, is automatically synced to a meta field in the background, ensuring fast and efficient calendar queries.

**🚀 Data Machine Integration**
* Automated event imports from Ticketmaster, Dice FM, and web scrapers
* AI-driven event creation with smart descriptions and taxonomy assignments
* Seamless venue management with automatic term creation
* Background processing for optimal performance

**🎯 API Integrations (via Data Machine)** 
* **Ticketmaster API** - Live Nation integration with API key authentication
* **Dice FM API** - Independent venue and event coverage
* **Web Scrapers** - Custom scrapers for specific venues
* **Flexible Data Sources** - Extensible handler system for additional sources

**⚡ Lightweight & Modern**
* **Gutenberg-First:** All event data is managed via blocks, not custom meta boxes.
* Single custom post type vs. 50+ database tables (Tribe Events)
* REST API enabled for API-first implementations
* Clean, modern codebase following WordPress standards

**🎨 Complete Frontend System**
* **Gutenberg-Powered:** The entire frontend, including the calendar and event details, is rendered using native Gutenberg blocks.
* Beautiful calendar interface with list and grid views.
* Modern, responsive event displays.
* Advanced filtering and real-time search.
* Event detail pages with schema markup.

**🔧 WordPress Native Integration**
* Single `datamachine_events` post type with venue taxonomy
* Comprehensive venue meta fields (10 fields: address, phone, website, capacity, coordinates, etc.)
* REST API enabled for API-first implementations
* Block-first approach with background meta field sync for performance
* Legacy status detection monitors removed (class retained as compatibility stub)
* PSR-4 autoloading with clean class organization

= Real-World Examples =

**Charleston Music Venue:**
* Ticketmaster module imports 50+ events daily from 50-mile radius
* Royal American scraper pulls venue-specific shows
* LoFi Brewing Eventbrite module imports brewery events
* All mapped to existing venue, location, and artist taxonomies

**Tourism Board:**
* Multiple API modules covering different event types
* Regional venue scrapers for local coverage  
* Universal taxonomy mapping for consistent categorization
* Automated daily imports with comprehensive logging

= Why Choose Data Machine Events? =

**vs. Tribe Events Calendar:**
* ✅ Lightweight architecture vs. bloated codebase
* ✅ Modern WordPress standards vs. legacy approach  
* ✅ Visual configuration vs. complex settings
* ✅ Native API integrations vs. manual entry
* ✅ Universal taxonomy support vs. rigid structure

**vs. Other Event Plugins:**
* ✅ Block-first architecture (modern WordPress standards)
* ✅ Data Machine integration for automated imports and AI publishing
* ✅ AI-driven content creation and taxonomy management
* ✅ Performance-optimized with background meta sync
* ✅ Lightweight single post type approach
* ✅ Legacy status detection removed; plugin now relies on Data Machine core health checks
* ✅ PSR-4 autoloading with clean class organization

= Data Machine Events Workflow =

1. **Install Data Machine Plugin** - Required for automated imports
2. **Configure Import Handlers** - Set up Ticketmaster API, Dice FM, or web scrapers
3. **Set Up AI Publisher** - Configure DM Events publisher for event creation
4. **Add Calendar Block** - Display events on any page or post
5. **Manage Events** - Edit via Event Details blocks or add manual events

= Developer Features =

* **PSR-4 Autoloading** for clean class organization
* **Abstract Data Source** base class for easy extension
* **Child Theme Integration** for site-specific customizations
* **WP-CLI Commands** for scaffolding new data sources
* **Comprehensive Hooks** for customization
* **Example Scrapers** included for learning

= Professional Support =

Developed by **Chris Huber** (chubes.net) - WordPress expert specializing in custom solutions for venues, tourism boards, and event organizations.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/dm-events/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to **Data Machine Events > Settings** to configure global settings
4. Visit **Data Machine Events > API Configuration** to add API keys
5. Set up Data Machine plugin for automated imports (optional)

== Frequently Asked Questions ==

= Is this a replacement for Tribe Events Calendar? =

Yes! Data Machine Events is specifically designed as a modern, lightweight replacement for Tribe Events Calendar. It includes migration tools to import your existing events, venues, and settings.

= Do I need coding skills to set up imports? =

No! The Data Machine plugin provides a visual interface for configuring any data source. Simply select your data source, configure settings, and map to your site's taxonomies - no coding required.

= Will this work with my existing taxonomies? =

Absolutely! Data Machine Events makes no assumptions about your taxonomy structure. It detects all available taxonomies and allows flexible mapping per import module. You can use existing taxonomies or create new ones automatically.

= What APIs are supported? =

Core integrations include Ticketmaster, Dice FM, and Eventbrite APIs. You can also create custom data sources in your child theme for any venue website or regional API.

= How reliable are the imports? =

Very reliable! The global scheduling system uses a single WP Cron job instead of multiple individual schedules, providing better reliability and performance. Comprehensive logging tracks all import activity.

= Can I customize the design? =

Yes! Data Machine Events uses dedicated CSS files (no inline styles) and follows WordPress theme standards. All templates can be overridden in your theme, and comprehensive hooks allow customization.

== Screenshots ==

1. **Data Machine Dashboard** - Visual interface for managing all your import sources
2. **Create New Module Modal** - Step-by-step module configuration
3. **Calendar View** - Beautiful month/week/day calendar displays  
4. **Event Cards** - Modern, responsive event grid layout
5. **Settings Page** - Global schedule control and plugin settings
6. **API Configuration** - Secure API key management
7. **Import Logs** - Comprehensive analytics and import history

== Changelog ==

= 0.8.31 =
* Added time range display support to Calendar block and event items
* Enhanced Event Details block with dynamic "Event Link" CTA for free/TBD events
* Integrated venueTimezone into EventEngineData for end-to-end timezone propagation
* Added description support to UpdateVenue AI tool
* Automated timezone derivation on coordinate updates in Venue_Taxonomy
* Removed legacy Timezone Backfill UI and REST endpoints
* Explicitly clear stale coordinates on address changes to trigger re-geocoding
* Added comprehensive query parameter sanitization to Calendar navigation

= 0.8.30 =
* Added Get Venue Events AI tool for event retrieval and term investigation
* Enhanced UpdateVenue AI tool with empty string checks to prevent data clearing

= 0.8.29 =
* Implemented automatic sorting by event count in Venue Health Check AI tool
* Prioritized venues with active events for data quality fixes

= 0.8.28 =
* Added automatic coordinate clearing on address changes for venues
* Improved address change detection across all components (city, state, zip, etc.)

= 0.8.27 =
* Introduced BaseExtractor for standardized Web Scraper Extractors
* Standardized date/time parsing and HTML cleaning across 18+ handlers
* Added WP-CLI `scrape-test` command for validation
* Added address autocomplete and geocoding integration to venue admin UI

= 0.8.22 =
* Enhanced UniversalWebScraper with improved XPath selectors for Brown Bear and Drupal event listings
* Optimized PageVenueExtractor with robust Squarespace map block data parsing
* Improved address detection with stricter state and ZIP code validation
* Standardized UniversalWebScraper fetch configuration to use browser mode for improved compatibility
* Enhanced CLI output with full raw HTML display in scraper test command

= 0.8.21 =
* Enhanced PageVenueExtractor with multi-layered JSON-LD structured data support
* Improved address extraction with footer and organization schema parsing
* Added page-level venue data merging to RhpEventsExtractor for better event fidelity
* Refined PageVenueExtractor priority order: JSON-LD > Title > Footer/Body
* Optimized UniversalWebScraper with consolidated extraction methods

= 0.8.20 =
* Added WordPress API Discovery to UniversalWebScraper for automatic platform identification
* Added direct structured data support for .ics and WordPress API URLs
* Standardized scraper User-Agent for better transparency
* Optimized UniversalWebScraper HTTP configuration

= 0.8.19 =
* Enhanced SquarespaceExtractor with JSON API support and HTML list view fallback parsing
* Improved PageVenueExtractor with specific Squarespace site title extraction and robust address detection
* Added support for announcement bar and footer section address extraction in Squarespace sites
* Refined venue name extraction from page titles with expanded filter keywords and separator support
* Added documentation for Universal Web Scraper test commands

= 0.8.17 =
* Enhanced GoDaddyExtractor with Rest API detection to prevent incorrect platform identification
* Improved JSON validation in GoDaddyExtractor to handle malformed data gracefully

= 0.8.16 =
* Added GeoNames integration for automatic venue timezone detection based on coordinates
* Introduced Venue Timezone backfill system with batch processing via REST API
* Enhanced Universal Web Scraper with WordPressExtractor, GoDaddyExtractor, and BandzoogleExtractor
* Integrated WP-CLI commands for testing Universal Web Scraper directly from command line
* Improved FirebaseExtractor with script sniffing for database URL discovery
* Enhanced JsonLdExtractor with HTML entity decoding and venue address support
* Refined EventUpsert with better venue/promoter configuration logic
* Standardized admin setting for geonames_username in plugin settings

= 0.8.15 =
* Refactored Prekindle handler into a specialized extractor within Universal Web Scraper
* Added PrekindleExtractor with support for hybrid API/HTML data extraction
* Removed legacy standalone Prekindle handler and settings classes
* Updated Universal Web Scraper documentation to reflect Prekindle integration

= 0.8.14 = 
* Introduced centralized timezone handling via DateTimeParser and venueTimezone metadata 
* Updated Calendar block to respect venue-specific timezones for date grouping and display 
* Added SquarespaceExtractor for Universal Web Scraper to parse Squarespace platform events 
* Added SpotHopperExtractor to Universal Web Scraper and converted SpotHopper into an extractor 
* Standardized UTC and local datetime parsing across all event import handlers 
* Added venueTimezone to VenueParameterProvider and Venue_Taxonomy 

= 0.8.13 =
* Unified taxonomy filter parameter sanitization with recursive structure preservation
* Improved structured data quality by extracting plain text for Schema.org descriptions
* Enhanced filter state management with support for both indexed and non-indexed array syntax
* Refactored EventImportStep for standardized self-registration via StepTypeRegistrationTrait
* Updated REST API terminology from 'headless' to 'API-first'

= 0.8.11 =
* Refactored EventImportStep to use StepTypeRegistrationTrait for standardized step registration
* Updated plugin bootstrap to instantiate EventImportStep during Data Machine integration
* Removed legacy manual step registration filters from EventImportFilters.php

= 1.0.0 =
* Initial release
* Data Machine integration with visual configuration
* Native Ticketmaster, Dice FM, and Eventbrite API integrations
* Universal taxonomy support and mapping
* Global scheduling with centralized execution
* Complete admin interface with modal workflows
* Modern calendar frontend with filtering
* Child theme data source support
* Comprehensive logging and analytics
* Migration tools from other event plugins
* Full Gutenberg and REST API support

== Upgrade Notice ==

= 1.0.0 =
Welcome to Data Machine Events! This is the initial release with Data Machine plugin integration. Please review the setup guide after activation. 