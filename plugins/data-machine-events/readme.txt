=== Chill Events ===
Contributors: chubes
Tags: events, calendar, ticketmaster, eventbrite, import, venues, api
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A comprehensive WordPress events plugin with Import Modules system, native API integrations, and universal taxonomy support.

== Description ==

**Chill Events** is a revolutionary WordPress events plugin designed as a complete replacement for bloated event plugins like Tribe Events Calendar. Built with modern WordPress standards and featuring the innovative Import Modules system, it provides automated event management with unprecedented flexibility.

= Key Features =

**🚀 Revolutionary Import Modules System**
* Visual admin interface for configuring any data source
* Universal taxonomy mapping - works with ANY existing site structure
* "Set and forget" global scheduling with centralized management
* Child theme safe - custom scrapers preserved during updates

**🎯 Native API Integrations** 
* **Ticketmaster API** - First WordPress plugin with direct Live Nation integration
* **Dice FM API** - Independent venue and event coverage  
* **Eventbrite API** - Community and local event integration
* **Manual Import Tools** - CSV, JSON, direct input support

**⚡ Lightweight & Modern**
* Single custom post type vs. 50+ database tables (Tribe Events)
* Gutenberg-first with full block editor support
* REST API enabled for headless implementations
* Clean, modern codebase following WordPress standards

**🎨 Complete Frontend System**
* Beautiful calendar interface with month/week/day views
* Modern, responsive event displays
* Advanced filtering based on your site's taxonomies
* Event detail pages with schema markup

**🔧 Universal Compatibility**
* Works with any existing taxonomy structure
* No assumptions about venue, location, or artist taxonomies
* Auto-creates taxonomy terms as needed
* Flexible field mapping per import module

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

= Why Choose Chill Events? =

**vs. Tribe Events Calendar:**
✅ Lightweight architecture vs. bloated codebase
✅ Modern WordPress standards vs. legacy approach  
✅ Visual configuration vs. complex settings
✅ Native API integrations vs. manual entry
✅ Universal taxonomy support vs. rigid structure

**vs. Other Event Plugins:**
✅ First WordPress plugin with Ticketmaster API
✅ Import Modules system - configure any data source visually
✅ Child theme safe custom development
✅ Global scheduling with reliable execution
✅ Complete migration tools from other plugins

= Import Modules Workflow =

1. **Click "Create New Module"** on Import Modules dashboard
2. **Select Data Source** (Ticketmaster API, custom scraper, etc.)
3. **Configure Settings** (location, filters, limits)
4. **Map Taxonomies** (venue → venue_taxonomy, artist → artist_taxonomy)
5. **Save & Activate** - module runs on global schedule automatically

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

1. Upload the plugin files to `/wp-content/plugins/chill-events/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to **Chill Events > Settings** to configure global settings
4. Visit **Chill Events > API Configuration** to add API keys
5. Create your first Import Module from **Chill Events > Import Modules**

== Frequently Asked Questions ==

= Is this a replacement for Tribe Events Calendar? =

Yes! Chill Events is specifically designed as a modern, lightweight replacement for Tribe Events Calendar. It includes migration tools to import your existing events, venues, and settings.

= Do I need coding skills to set up imports? =

No! The Import Modules system provides a visual interface for configuring any data source. Simply select your data source, configure settings, and map to your site's taxonomies - no coding required.

= Will this work with my existing taxonomies? =

Absolutely! Chill Events makes no assumptions about your taxonomy structure. It detects all available taxonomies and allows flexible mapping per import module. You can use existing taxonomies or create new ones automatically.

= What APIs are supported? =

Core integrations include Ticketmaster, Dice FM, and Eventbrite APIs. You can also create custom data sources in your child theme for any venue website or regional API.

= How reliable are the imports? =

Very reliable! The global scheduling system uses a single WP Cron job instead of multiple individual schedules, providing better reliability and performance. Comprehensive logging tracks all import activity.

= Can I customize the design? =

Yes! Chill Events uses dedicated CSS files (no inline styles) and follows WordPress theme standards. All templates can be overridden in your theme, and comprehensive hooks allow customization.

== Screenshots ==

1. **Import Modules Dashboard** - Visual interface for managing all your import sources
2. **Create New Module Modal** - Step-by-step module configuration
3. **Calendar View** - Beautiful month/week/day calendar displays  
4. **Event Cards** - Modern, responsive event grid layout
5. **Settings Page** - Global schedule control and plugin settings
6. **API Configuration** - Secure API key management
7. **Import Logs** - Comprehensive analytics and import history

== Changelog ==

= 1.0.0 =
* Initial release
* Import Modules system with visual configuration
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
Welcome to Chill Events! This is the initial release of our revolutionary Import Modules system. Please review the setup guide after activation. 