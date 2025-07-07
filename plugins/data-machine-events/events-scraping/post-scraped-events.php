<?php

// post-scraped-events.php

/**
 * Posts aggregated scraper events to The Events Calendar.
 *
 * @param int $maxEvents Maximum number of events to post.
 * @return array|WP_Error Array of posted events or WP_Error on failure.
 */
function post_aggregated_events_to_calendar($maxEvents = 10) {
    // Permission check removed - not suitable for cron execution

    // Aggregate events from all venues across all cities
    $events = aggregate_venue_events();

    // Verify that events were successfully aggregated
    if (!is_array($events) || empty($events)) {
        return new WP_Error('no_events', 'No events found to post.', array('status' => 404));
    }

    // Filter events to include only future events
    $events = filter_future_events($events);

    // Define the REST API endpoint for The Events Calendar
    $api_endpoint = get_site_url() . '/wp-json/tribe/events/v1/events';
    $results = [];
    $addedCount = 0; // Counter for successfully added events

    foreach ($events as $event) {
        // Stop processing if the maximum number of events has been reached
        if ($addedCount >= $maxEvents) {
            break;
        }

        // Check if the event already exists in The Events Calendar
        if (event_already_exists($event['title'], $event['start_date'])) {
            continue; // Skip to the next event without incrementing the count
        }

        /**
         * Prepare the event data for posting.
         * Ensure that the 'venue' object maintains its existing structure.
         */
        $eventData = [
            'title'      => sanitize_text_field($event['title']),
            'start_date' => sanitize_text_field($event['start_date']),
            'end_date'   => sanitize_text_field($event['end_date']),
            'url'        => isset($event['url']) ? esc_url_raw($event['url']) : '',
            'venue'      => [
                'venue'    => sanitize_text_field($event['venue']['name']), // Preserve existing key ('name')
                'address'  => sanitize_text_field($event['venue']['address']),
                'city'     => sanitize_text_field($event['venue']['city']),
                'country'  => isset($event['venue']['country']) ? sanitize_text_field($event['venue']['country']) : '',
                'state'    => sanitize_text_field($event['venue']['state']),
                'zip'      => sanitize_text_field($event['venue']['zip']),
                'website'  => isset($event['venue']['website']) ? esc_url_raw($event['venue']['website']) : '',
                'phone'    => isset($event['venue']['phone']) ? sanitize_text_field($event['venue']['phone']) : '',
            ],
        ];

        // Include the event description if available
        if (isset($event['description'])) {
            $eventData['description'] = sanitize_textarea_field($event['description']);
        }

        /**
         * Retrieve authorization credentials from wp-config.php.
         * Ensure that EVENTS_CALENDAR_AUTH is defined in wp-config.php.
         */
        if (!defined('EVENTS_CALENDAR_AUTH')) {
            return new WP_Error('auth_missing', 'Authorization credentials not defined.', array('status' => 500));
        }

        $authorization = 'Basic ' . base64_encode(EVENTS_CALENDAR_AUTH);

        /**
         * Post the event to The Events Calendar via REST API.
         */
        $response = wp_remote_post($api_endpoint, [
            'headers' => [
                'Authorization' => $authorization,
                'Content-Type'  => 'application/json',
            ],
            'body'        => wp_json_encode($eventData),
            'method'      => 'POST',
            'data_format' => 'body',
        ]);

        // Handle the response
        if (is_wp_error($response)) {
            // Optionally, handle the error as needed (e.g., notify admin)
            continue; // Skip to the next event without incrementing the count
        }

        // Retrieve the response body and status code
        $responseBody = wp_remote_retrieve_body($response);
        $responseCode = wp_remote_retrieve_response_code($response);

        // Check if the event was successfully created
        if ($responseCode >= 200 && $responseCode < 300) {
            // Decode the response to get the created event's ID
            $responseData = json_decode($responseBody, true);
            if (isset($responseData['id'])) {
                $created_event_id = intval($responseData['id']);

                /**
                 * Assign the 'location' taxonomy to the created event.
                 * Use the 'location_term_id' assigned during aggregation.
                 */
                wp_set_object_terms($created_event_id, [$event['location_term_id']], 'location', false);

                // Increment the count of successfully added events
                $addedCount++;

                // Add the successful post to the results
                $results[] = [
                    'title'      => $event['title'],
                    'venue'      => $event['venue']['name'],
                    'start_date' => $event['start_date'],
                ];
            }
        }
    }

    // Log the import event using import-logger.php
    log_import_event('scraper', $addedCount);

    // Return the results
    return $results;
}
