<?php
/**
 * ticketmaster_locations.php
 * 
 * Contains the list of locations to fetch events from Ticketmaster.
 */

/**
 * Retrieve the list of event locations.
 *
 * @return array An array of associative arrays containing location details.
 */
function get_event_locations() {
    return [
        [
            'name'      => 'Charleston',
            'latitude'  => 32.7765,
            'longitude' => -79.9311,
            'slug'      => 'charleston',
            'term_id'   => 2693, // Known term ID for Charleston
        ],
        [
            'name'      => 'Austin',
            'latitude'  => 30.2672,
            'longitude' => -97.7431,
            'slug'      => 'austin',
            'term_id'   => 2691, // Replace with the actual term ID for Austin
        ],
        // Add more locations here as needed.
    ];
}
