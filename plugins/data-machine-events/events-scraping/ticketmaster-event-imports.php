<?php
/**
 * ticketmaster-event-imports.php
 * 
 * Handles fetching events from Ticketmaster and posting them to The Events Calendar.
 * Ensures venue data is correctly formatted as an array and location taxonomy is assigned.
 */


/**
 * Fetch Ticketmaster events for a given location.
 *
 * @param array $location An associative array containing 'name', 'latitude', 'longitude', 'slug', 'term_id'.
 * @return WP_REST_Response|WP_Error
 */
function fetch_ticketmaster_events($location) {
    $api_key = get_ticketmaster_api_key();
    if (empty($api_key)) {
        error_log("Ticketmaster API key is not defined.");
        return new WP_Error('no_api_key', 'Ticketmaster API key is not defined.', ['status' => 500]);
    }

    $classificationName = 'Music';
    $startDateTime = gmdate('Y-m-d\TH:i:s\Z'); // Use GMT time as per Ticketmaster API requirements.

    $events = [];
    $processedEventIds = []; // Track IDs of processed events to handle duplicates.
    $page = 0;
    $size = 20;
    $totalPages = 1;

    while ($page < $totalPages) {
        $url = "https://app.ticketmaster.com/discovery/v2/events.json?apikey={$api_key}&classificationName={$classificationName}&startDateTime={$startDateTime}&size={$size}&page={$page}&geoPoint={$location['latitude']},{$location['longitude']}&radius=50&unit=miles&includeVenues=true";

        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            error_log("Failed to fetch events from Ticketmaster for location '{$location['name']}': " . $response->get_error_message());
            return new WP_Error('fetch_failed', 'Failed to fetch events from Ticketmaster', ['status' => 500]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['_embedded']['events'])) {
            error_log("Invalid JSON format from Ticketmaster API for location '{$location['name']}'.");
            return new WP_Error('invalid_json', 'Invalid JSON format from Ticketmaster API', ['status' => 500]);
        }

        foreach ($data['_embedded']['events'] as $event) {
            if (empty($event['dates']['start']['dateTime']) || $event['dates']['start']['dateTime'] === 'TBA' || in_array($event['id'], $processedEventIds)) {
                continue;
            }

            $processedEventIds[] = $event['id']; // Mark this event ID as processed.

            $dateTimeZoneNewYork = new DateTimeZone('America/New_York');
            $startTimeUTC = $event['dates']['start']['dateTime'];
            $startTime = new DateTime($startTimeUTC ? $startTimeUTC : 'now', new DateTimeZone('UTC'));
            $startTime->setTimezone($dateTimeZoneNewYork);

            $endTime = clone $startTime;
            $endTime->modify('+3 hours'); // Adjust duration as needed.

            $venue = $event['_embedded']['venues'][0] ?? [];
            $priceRange = $event['priceRanges'][0] ?? [];
            $event_url = isset($event['url']) ? esc_url_raw(urldecode($event['url'])) : '';

            // Ensure all necessary venue fields are present
            $venue_data = [
                'venue'    => $venue['name'] ?? 'N/A',
                'address'  => isset($venue['address']['line1']) ? $venue['address']['line1'] : 'N/A',
                'city'     => isset($venue['city']['name']) ? $venue['city']['name'] : 'N/A',
                'state'    => isset($venue['state']['name']) ? $venue['state']['name'] : 'N/A',
                'country'  => isset($venue['country']['name']) ? $venue['country']['name'] : 'N/A',
                'zip'      => isset($venue['postalCode']) ? $venue['postalCode'] : 'N/A',
                'website'  => isset($venue['url']) ? $venue['url'] : '',
                'phone'    => isset($venue['boxOfficeInfo']['phoneNumberDetail']) ? $venue['boxOfficeInfo']['phoneNumberDetail'] : '',
            ];

            $events[] = [
                'id'             => $event['id'],
                'title'          => $event['name'],
                'start_date'     => $startTime->format('Y-m-d H:i:s'),
                'end_date'       => $endTime->format('Y-m-d H:i:s'),
                'url'            => $event_url,  // Assign the processed URL here
                'venue'          => $venue_data, // Now an array with required keys
                'price_range'    => [
                    'min'      => $priceRange['min'] ?? 'N/A', // Only assign if 'min' is available
                    'max'      => $priceRange['max'] ?? 'N/A', // Only assign if 'max' is available
                    'currency' => $priceRange['currency'] ?? 'N/A', // Only assign if 'currency' is available
                ],
                'ticket_link'    => $event_url,
                'location_slug'  => $location['slug'], // Add location slug for taxonomy assignment.
                'location_term_id' => $location['term_id'], // Add location term ID
            ];
        }

        $totalPages = isset($data['page']['totalPages']) ? intval($data['page']['totalPages']) : 1;
        $page++;
    }

    return new WP_REST_Response($events, 200);
}


/**
 * Post Ticketmaster events to The Events Calendar.
 *
 * @param int    $maxEvents      Maximum number of events to post per location.
 * @param string $source         The source of the import (e.g., 'cron ticketmaster', 'manual ticketmaster').
 * @param string|null $location_slug Specific location slug to import (optional).
 * @return array|WP_Error
 */
function post_ticketmaster_events_to_calendar($maxEvents = 10, $source = 'ticketmaster', $location_slug = null) {
    $locations = get_event_locations();

    // Filter locations if a specific location slug is provided
    if ($location_slug) {
        $locations = array_filter($locations, function($location) use ($location_slug) {
            return $location['slug'] === $location_slug;
        });
    }

    if (empty($locations)) {
        error_log("No event locations defined.");
        return new WP_Error('no_locations', 'No event locations defined.', ['status' => 500]);
    }

    $postedEventIds = get_option('posted_ticketmaster_event_ids', []); // Retrieve posted event IDs from WP options.
    $api_endpoint  = get_site_url() . '/wp-json/tribe/events/v1/events';
    $posted_events = [];
    $total_posted  = 0;

    foreach ($locations as $location) {
        $fetched_events = fetch_ticketmaster_events($location);
        if (is_wp_error($fetched_events)) {
            error_log("Error fetching events for location '{$location['name']}': " . $fetched_events->get_error_message());
            continue;
        }

        $events_data = $fetched_events->get_data();
        if (empty($events_data)) {
            error_log("No events found for location '{$location['name']}'.");
            continue;
        }

        foreach ($events_data as $event) {
            if ($total_posted >= $maxEvents || in_array($event['id'], $postedEventIds)) {
                continue; // Skip this event if it's already been posted or maxEvents reached.
            }

            // Directly use the known term ID for 'charleston' (2693)
            $location_term_id = $event['location_term_id'];
            if (empty($location_term_id)) {
                error_log("Location term ID is missing for event '{$event['title']}'.");
                continue;
            }

            $eventData = [
                'title'      => $event['title'],
                'start_date' => $event['start_date'],
                'end_date'   => $event['end_date'],
                'venue'      => $event['venue'],
                'website'    => $event['url'], // Use the original event URL here
            ];
            
            // Log the exact URL being sent
            error_log('Event URL being sent: ' . $eventData['website']);
            

            // Optional: Include 'description' if available
            if (isset($event['description'])) {
                $eventData['description'] = sanitize_textarea_field($event['description']);
            }

            // Debugging: Log the event data being sent
            error_log('Posting Event Data: ' . print_r($eventData, true));

            $response = wp_remote_post($api_endpoint, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode(get_events_calendar_auth()), // Securely retrieve auth from wp-config.php
                    'Content-Type'  => 'application/json',
                ],
                'body'        => wp_json_encode($eventData),
                'method'      => 'POST',
                'data_format' => 'body',
            ]);

            if (is_wp_error($response)) {
                error_log("Failed to post event '{$event['title']}': " . $response->get_error_message());
                $posted_events[] = [
                    'error' => $response->get_error_message(),
                    'title' => $event['title'],
                ];
                continue;
            }

            $responseBody = wp_remote_retrieve_body($response);
            $responseCode = wp_remote_retrieve_response_code($response);

            if ($responseCode >= 200 && $responseCode < 300) {
                // Parse the response to get the created event's ID
                $responseData = json_decode($responseBody, true);
                if (isset($responseData['id'])) {
                    $created_event_id = intval($responseData['id']);
                
                    update_post_meta($created_event_id, '_EventURL', $event['url']);

                    // Assign taxonomy as before
                    $assign_taxonomy = wp_set_object_terms($created_event_id, [$location_term_id], 'location', false);
                    if (is_wp_error($assign_taxonomy)) {
                        error_log("Failed to assign location taxonomy to event ID {$created_event_id}: " . $assign_taxonomy->get_error_message());
                    } else {
                        error_log("Successfully assigned location taxonomy to event ID {$created_event_id}.");
                    }
                
                    // Existing code to track posted events
                    $postedEventIds[] = $event['id'];
                    update_option('posted_ticketmaster_event_ids', $postedEventIds);
                
                    $posted_events[] = [
                        'title'      => $event['title'],
                        'venue'      => isset($event['venue']['venue']) ? $event['venue']['venue'] : 'N/A',
                        'start_date' => $event['start_date'],
                    ];
                    $total_posted++;
                }
                
            }
        }
    }

    return $posted_events;
}


add_action('rest_api_init', function () {
    register_rest_route('ticketmaster/v1', '/events', [
        'methods'             => 'GET',
        'callback'            => 'fetch_ticketmaster_events_handler',
        'permission_callback' => '__return_true', // This line allows public access.
    ]);
});

/**
 * REST API handler to fetch events for a specific location.
 * Accepts a 'location_slug' parameter to specify the location.
 *
 * Example API call: /wp-json/ticketmaster/v1/events?location_slug=charleston
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function fetch_ticketmaster_events_handler(WP_REST_Request $request) {
    $location_slug = sanitize_text_field($request->get_param('location_slug'));

    if (empty($location_slug)) {
        error_log("No location specified in the API request.");
        return new WP_Error('no_location', 'No location specified.', ['status' => 400]);
    }

    $locations = get_event_locations();
    $location = null;
    foreach ($locations as $loc) {
        if ($loc['slug'] === $location_slug) {
            $location = $loc;
            break;
        }
    }

    if (!$location) {
        error_log("Invalid location specified: '{$location_slug}'.");
        return new WP_Error('invalid_location', 'Invalid location specified.', ['status' => 400]);
    }

    $events = fetch_ticketmaster_events($location);
    return $events;
}


/**
 * Fetch details for a specific Ticketmaster event by its ID.
 *
 * @param string $event_id The ID of the event to fetch.
 * @return WP_REST_Response|WP_Error
 */
function fetch_ticketmaster_event_by_id($event_id) {
    $api_key = get_ticketmaster_api_key();
    if (empty($api_key)) {
        error_log("Ticketmaster API key is not defined.");
        return new WP_Error('no_api_key', 'Ticketmaster API key is not defined.', ['status' => 500]);
    }

    $url = "https://app.ticketmaster.com/discovery/v2/events/{$event_id}.json?apikey={$api_key}";
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        error_log("Failed to fetch event from Ticketmaster for event ID '{$event_id}': " . $response->get_error_message());
        return new WP_Error('fetch_failed', 'Failed to fetch event from Ticketmaster', ['status' => 500]);
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['_embedded']['events'][0])) {
        error_log("Invalid JSON format from Ticketmaster API for event ID '{$event_id}'.");
        return new WP_Error('invalid_json', 'Invalid JSON format from Ticketmaster API', ['status' => 500]);
    }

    $event = $data['_embedded']['events'][0];
    return new WP_REST_Response($event, 200);
}



/**
 * One-time function to update Ticketmaster event URLs for existing events.
 */
function update_ticketmaster_event_urls() {
    // Get the posted event IDs from an option or any other source
    $postedEventIds = get_option('posted_ticketmaster_event_ids', []);
    
    if (empty($postedEventIds)) {
        error_log("No Ticketmaster event IDs found to update.");
        return;
    }

    // Iterate through each event ID and update the URL
    foreach ($postedEventIds as $event_id) {
        // Fetch the event data from Ticketmaster by ID
        $event_data = fetch_ticketmaster_event_by_id($event_id);

        if (is_wp_error($event_data)) {
            error_log("Error fetching updated data for event ID {$event_id}: " . $event_data->get_error_message());
            continue;
        }

        $event = $event_data->get_data();
        if (empty($event)) {
            error_log("No updated data found for event ID {$event_id}.");
            continue;
        }

        // Update the URL for the specific event
        $event_url = $event['url'] ?? '';
        if (!empty($event_url)) {
            update_post_meta($event_id, '_EventURL', $event_url);
            error_log("Updated URL for event ID {$event_id} to: " . $event_url);
        }
    }

    error_log("Completed updating Ticketmaster event URLs.");
}



// Add custom admin action to trigger the update function
add_action('admin_init', 'run_update_ticketmaster_event_urls_once');

function run_update_ticketmaster_event_urls_once() {
    if (isset($_GET['run_ticketmaster_update']) && current_user_can('manage_options')) {
        update_ticketmaster_event_urls();
        error_log("Ticketmaster event URL update function has been triggered manually.");
        // Optional: Redirect to remove the query parameter after execution
        wp_safe_redirect(remove_query_arg('run_ticketmaster_update'));
        exit;
    }
}
