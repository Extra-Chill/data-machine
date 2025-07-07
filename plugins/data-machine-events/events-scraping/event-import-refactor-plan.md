# Event Import System Refactoring Plan

**Date:** May 2, 2025

## Objective

Refactor the existing event import system to improve reliability, maintainability, scalability, and error reporting. This includes addressing code duplication, enhancing error handling and logging, migrating to a robust background processing mechanism, and establishing a foundation for future development, potentially including migration away from The Events Calendar plugin.

## Current State Analysis

The current event import system relies on a daily WP-Cron event (`import_daily_events_hook`) that triggers the `extra_chill_automated_event_import` function. This function sequentially calls separate functions to import events from:

*   Local venue scrapers (aggregated via `post_aggregated_events_to_calendar`)
*   Ticketmaster (`post_ticketmaster_events_to_calendar`)
*   DICE.FM (`post_dice_fm_events_to_calendar`)

Events are posted to The Events Calendar via its REST API.

**Identified Issues:**

*   **Code Duplication:** Significant repetition in event posting logic and hardcoded venue/location data.
*   **Fragile Local Scraping:** Vulnerability to website structure changes, potential date/time parsing inaccuracies, and hardcoded venue details.
*   **Limited Import Volume:** Low `$maxEvents` limits (defaulting to 5 per source) severely restrict the number of events imported daily.
*   **Basic Error Handling & Logging:** Reliance on `error_log` and limited detail in the `event_import_logs` option makes debugging and monitoring difficult. Failures are not clearly reported.
*   **Unreliable Background Processing:** WP-Cron's dependency on site traffic and risk of overlapping tasks make it unsuitable for critical scheduled imports.
*   **Configuration Management:** API keys and authorization are stored in `wp-config.php`, and other settings are hardcoded, lacking a centralized management interface.
*   **Duplicate Checking:** Relies on exact title and start date matching, which may not catch all duplicates.

## Proposed Architecture

The refactored system will adopt a more modular and robust architecture:

1.  **Configuration Management:** Utilize the WordPress Settings API to create an admin page for managing API keys, import limits, and other settings.
2.  **Standardized Event Data Packet:** Define a consistent PHP array structure (`Extrachill_Event_Data`) to represent event data from any source.
3.  **Fetching/Scraping Modules:** Dedicated classes or files for each event source (e.g., `Extrachill_Scraper_RoyalAmerican`, `Extrachill_Importer_Ticketmaster`, `Extrachill_Importer_DiceFM`). Each module will be responsible for fetching/scraping events and returning an array of `Extrachill_Event_Data` objects/arrays.
4.  **Dynamic Location/Venue Management:** Implement custom post types or taxonomies for "Locations" and "Venues" to store and manage related data dynamically.
5.  **Central Event Posting Service:** A single class or function (`Extrachill_Event_Poster`) responsible for taking an `Extrachill_Event_Data` object/array and posting it to The Events Calendar API (or a future custom system API). This service will handle API authorization, data formatting for the target API, and basic response checking.
6.  **Detailed Import Logging:** A custom database table (`extrachill_import_logs`) to store detailed logs for each import run and each event processed (source, timestamp, status - added, skipped, failed, reason, error message).
7.  **Admin Log Viewer:** An admin page to display the detailed import logs from the `extrachill_import_logs` table.
8.  **Action Scheduler Integration:** Use Action Scheduler to schedule and manage the main import task.
9.  **Import Orchestrator:** A main function (scheduled by Action Scheduler) that retrieves active locations/venues, iterates through enabled fetching/scraping modules, collects standardized event data, passes data to the `Extrachill_Event_Poster`, and records detailed logs using the new logging system.

## Detailed Refactoring Steps

1.  **Set up Action Scheduler:**
    *   Ensure Action Scheduler is installed and active (it's included with WooCommerce and Tribe Events, but can also be installed as a standalone plugin).
    *   Replace the WP-Cron schedule in `event-import-cron.php` with scheduling an Action Scheduler action (e.g., `ActionScheduler::schedule_action( strtotime('tomorrow midnight'), 'extrachill_daily_event_import' );`).
    *   Create a new function hooked to `extrachill_daily_event_import` that will serve as the Import Orchestrator.
2.  **Implement Detailed Logging:**
    *   Create a new file (e.g., `import-detailed-logger.php`) with functions to create the `extrachill_import_logs` database table on plugin/theme activation and functions to insert log entries (`log_import_event_detail($import_run_id, $event_data, $status, $message = '')`).
    *   Modify the Import Orchestrator and the Central Event Posting Service to use this new detailed logging system.
3.  **Create Admin Log Viewer:**
    *   Create a new file (e.g., `admin-import-logs.php`) to add a new admin menu page under "Events" (or a suitable parent) to display the contents of the `extrachill_import_logs` table. Include filtering and pagination.
4.  **Define Standardized Event Data Packet:**
    *   Create a file (e.g., `class-extrachill-event-data.php`) defining a class or a clear structure for the `Extrachill_Event_Data`. Include properties for all necessary event details (title, dates, URL, description, venue details, location term ID, source identifier, original source ID).
5.  **Implement Dynamic Location/Venue Management:**
    *   Create files (e.g., `cpt-location.php`, `cpt-venue.php`) to register custom post types for Locations and Venues. Include meta boxes for relevant data (coordinates, term ID for Locations; address, website, phone for Venues).
    *   Create helper functions to retrieve Location and Venue data from these custom post types.
6.  **Refactor Fetching/Scraping Modules:**
    *   Modify existing local scraper functions (in `extrachill-custom/events-scraping/charleston-sc/`) to retrieve venue details dynamically from the new Venue CPT.
    *   Update all fetching/scraping functions (`fetch_ticketmaster_events`, `fetch_dice_fm_events`, local scrapers) to return arrays of the standardized `Extrachill_Event_Data`.
    *   Modify `aggregate_venue_events` to work with the standardized data and dynamic venue/location retrieval.
7.  **Create Central Event Posting Service:**
    *   Create a file (e.g., `class-extrachill-event-poster.php`) with a class containing a method `post_event(Extrachill_Event_Data $event_data)`. This method will handle the API call to The Events Calendar, including authorization and basic response handling. It should return the ID of the created event on success or a `WP_Error` on failure.
8.  **Update Import Orchestrator:**
    *   Modify the Import Orchestrator function (scheduled by Action Scheduler) to:
        *   Retrieve active Locations from the new CPT.
        *   Iterate through enabled fetching/scraping modules.
        *   Call each module's fetching function to get standardized event data.
        *   Iterate through the collected events.
        *   Use the `event_already_exists` function (or an improved version) to check for duplicates.
        *   If not a duplicate, call the `Extrachill_Event_Poster::post_event()` method.
        *   Log the outcome of each event (added, skipped, failed) using the detailed logging system, including any error messages from the poster service.
        *   Update the `posted_ticketmaster_event_ids` option for Ticketmaster events.
9.  **Implement Configuration Admin Page:**
    *   Create a file (e.g., `admin-import-settings.php`) to register a settings page using the WordPress Settings API. Include fields for API keys, default import limits, and options to enable/disable specific import sources.
    *   Update the fetching/scraping modules and the Import Orchestrator to retrieve settings from the saved options instead of hardcoded values or `wp-config.php` constants (except for sensitive keys like API tokens, which should remain in `wp-config.php` but be retrieved via helper functions).
10. **Refine Duplicate Checking:**
    *   Review and potentially enhance the `event_already_exists` function to use more robust methods, possibly incorporating external event IDs where available.

## Benefits of Refactoring

*   **Reduced Code Duplication:** Centralized posting logic and dynamic data retrieval eliminate repetitive code.
*   **Improved Maintainability:** Changes to posting logic or venue/location data only need to be made in one place.
*   **Enhanced Reliability:** Action Scheduler ensures tasks run reliably in the background.
*   **Better Error Reporting:** Detailed logs provide clear visibility into import outcomes and failures, simplifying debugging.
*   **Increased Scalability:** The system can handle a larger volume of events by adjusting limits and leveraging background processing.
*   **Easier Extension:** Adding new event sources becomes simpler by creating a new fetching module that returns standardized data.
*   **Foundation for Migration:** The standardized event data packet and decoupled posting service create a clear abstraction layer, making it significantly easier to switch the posting target from The Events Calendar API to a custom event system in the future. This refactoring is a crucial step towards potentially migrating away from Tribe Events Calendar.

## Completed Items

*   [ ] Set up Action Scheduler
*   [ ] Implement Detailed Logging
*   [ ] Create Admin Log Viewer
*   [ ] Define Standardized Event Data Packet
*   [ ] Implement Dynamic Location/Venue Management
*   [ ] Refactor Fetching/Scraping Modules
*   [ ] Create Central Event Posting Service
*   [ ] Update Import Orchestrator
*   [ ] Implement Configuration Admin Page
*   [ ] Refine Duplicate Checking