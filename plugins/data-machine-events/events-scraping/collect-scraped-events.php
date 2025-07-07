<?php

// this code is used to collect scraped events from various venues and prepare them for posting

/**
 * collect-scraped-events.php
 * 
 * Handles fetching events from various venues and preparing them for posting.
 */

add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/all-events/', array(
        'methods'             => 'GET',
        'callback'            => 'get_scraped_events',
        'permission_callback' => '__return_true',  // Ensures the route is publicly accessible
    ));
});

/**
 * REST API handler to fetch or post scraped events.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function get_scraped_events(WP_REST_Request $request) {
    $postEvents = $request->get_param('post');

    if (!is_null($postEvents) && filter_var($postEvents, FILTER_VALIDATE_BOOLEAN)) {
        $postResults = post_aggregated_events_to_calendar();
        return rest_ensure_response($postResults);
    } else {
        $events = aggregate_venue_events();
        return rest_ensure_response($events);
    }
}

/**
 * Retrieve the list of event locations and their associated venues.
 *
 * @return array An associative array mapping cities to their venues and term IDs.
 */
function get_event_location_venues() {
    return [
        'charleston' => [
            'term_id' => 2693, // location term ID for Charleston
            'venues'  => [
                'royal_american'    => 'get_royal_american_events',
                'commodore'         => 'get_commodore_events',
                'burgundy_lounge'   => 'get_burgundy_lounge_events',
               'tin_roof'          => 'get_tin_roof_events',
               'forte_jazz_lounge' => 'get_forte_jazz_lounge_events',
                // Add more venues as needed
            ],
        ],
        'austin' => [
            'term_id' => 2691, // location term ID for Austin
            'venues'  => [
                // Add more venues as needed
            ],
        ],
        // Add more cities and their venues here
    ];
}

/**
 * Aggregate events from all venues across all cities.
 *
 * @return array An aggregated array of all events with location information.
 */
function aggregate_venue_events() {
    $allEvents = [];
    $location_venues = get_event_location_venues();

    foreach ($location_venues as $city_slug => $city_data) {
        $term_id = $city_data['term_id'];
        $venues  = $city_data['venues'];

        foreach ($venues as $venue_slug => $scraper_function) {
            if (function_exists($scraper_function)) {
                $venueEvents = call_user_func($scraper_function);
                
                if (is_string($venueEvents)) { // Check if the result is an error message
                    error_log("Error in {$scraper_function}: {$venueEvents}");
                } elseif (is_array($venueEvents)) {
                    // Assign location information to each event
                    foreach ($venueEvents as &$event) {
                        $event['location_term_id'] = $term_id;
                        $event['location_slug']    = $city_slug;
                    }
                    unset($event); // Break the reference
                    $allEvents = array_merge($allEvents, $venueEvents);
                } else {
                    error_log("Unexpected result type from {$scraper_function}.");
                }
            } else {
                error_log("Scraper function {$scraper_function} does not exist.");
            }
        }
    }

    return $allEvents;
}
