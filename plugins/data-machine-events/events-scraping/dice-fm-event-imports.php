<?php
/**
 * dice-fm-event-imports.php
 *
 * Handles fetching events from DICE.FM and posting them to The Events Calendar.
 * Only events from Austin, TX are imported.
 */

/**
 * Retrieve the DICE.FM API token.
 *
 * @return string
 */
function get_dice_fm_api_token() {
    if ( defined( 'DICE_FM_API_TOKEN' ) ) {
        return DICE_FM_API_TOKEN;
    }
    return '';
}

/**
 * Fetch DICE.FM events for a given location.
 * This version uses the GET-based v2 API endpoint, which is what the widget uses.
 *
 * @param array $location Associative array with location details (from get_event_locations()).
 * @return WP_REST_Response|WP_Error
 */
function fetch_dice_fm_events( $location ) {
    $api_token = get_dice_fm_api_token();
    if ( empty( $api_token ) ) {
        error_log( "DICE.FM API token is not defined." );
        return new WP_Error( 'no_api_token', 'DICE.FM API token is not defined.', array( 'status' => 500 ) );
    }

    // Build the query parameters.
    $params = array(
        'page[size]'       => 100,                   // Adjust as needed.
        'types'            => 'linkout,event',      // Comma-separated event types.
        'filter[cities][]' => $location['name'],    // e.g., "Austin"
    );

    // Build the full endpoint URL with query parameters.
    $endpoint = add_query_arg( $params, 'https://partners-endpoint.dice.fm/api/v2/events' );

    // Prepare headers. We use the x-api-key header for authentication.
    $headers = array(
        'Accept'   => 'application/json',
        'x-api-key'=> $api_token,
    );
    // Optionally, add the partner ID if defined.
    if ( defined( 'DICE_FM_PARTNER_ID' ) ) {
        $headers['X-Partner-Id'] = trim( DICE_FM_PARTNER_ID );
    }

    // Make a GET request.
    $response = wp_remote_get( $endpoint, array(
        'headers' => $headers,
        'timeout' => 30,
    ) );

    if ( is_wp_error( $response ) ) {
        error_log( "Failed to fetch events from DICE.FM: " . $response->get_error_message() );
        return new WP_Error( 'fetch_failed', 'Failed to fetch events from DICE.FM', array( 'status' => 500 ) );
    }

    $responseBody = wp_remote_retrieve_body( $response );
    $data = json_decode( $responseBody, true );
    error_log('DICE API Full Response: ' . print_r($data, true));

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        error_log( "Invalid JSON response from DICE.FM." );
        return new WP_Error( 'invalid_json', 'Invalid JSON response from DICE.FM', array( 'status' => 500 ) );
    }

    // Check for events data under the 'data' key.
    if ( isset( $data['data'] ) && !empty( $data['data'] ) ) {
        $events_list = $data['data'];
    } else {
        error_log( "No events data in DICE.FM response." );
        return new WP_REST_Response( array(), 200 );
    }

    $events = array();

    // Loop through each event returned.
    foreach ( $events_list as $event ) {
        // Only include events whose venue(s) indicate they are in Austin.
        $include_event = false;
        if ( isset( $event['venues'] ) && is_array( $event['venues'] ) ) {
            foreach ( $event['venues'] as $venue ) {
                if ( isset( $venue['city'] ) ) {
                    // If the city is an array, extract the name.
                    if ( is_array( $venue['city'] ) && isset( $venue['city']['name'] ) ) {
                        $city = $venue['city']['name'];
                    } else {
                        $city = $venue['city'];
                    }
                    if ( strpos( strtolower( $city ), 'austin' ) !== false ) {
                        $include_event = true;
                        break;
                    }
                }
            }
        }
        if ( ! $include_event ) {
            continue;
        }

        // Map the start and end dates.
        // Use "date" for the event start and "date_end" for the event end.
        $start_field = isset($event['date']) ? $event['date'] : null;
        $end_field   = isset($event['date_end']) ? $event['date_end'] : null;

        if ( !$start_field || !$end_field ) {
            continue; // Skip event if dates are missing.
        }

        try {
            $start = new DateTime( $start_field );
            $start->setTimezone( new DateTimeZone( 'America/Chicago' ) );
            $start_formatted = $start->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            $start_formatted = $start_field;
        }
        try {
            $end = new DateTime( $end_field );
            $end->setTimezone( new DateTimeZone( 'America/Chicago' ) );
            $end_formatted = $end->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            $end_formatted = $end_field;
        }

        // Build the venue data.
        // For the detailed address information, check the event's "location" object.
        $venue_data = array();
        if ( ! empty( $event['venues'] ) && is_array( $event['venues'] ) ) {
            $venue = $event['venues'][0]; // Use the first venue
            // Determine the address:
            // Prefer the street from the "location" object; if not available, fall back to the top-level "address" string.
            if ( isset($event['location']['street']) && !empty($event['location']['street']) ) {
                $venue_address = $event['location']['street'];
            } elseif ( isset($event['address']) && !empty($event['address']) ) {
                $venue_address = $event['address'];
            } else {
                $venue_address = 'N/A';
            }

            // Determine the city: first try the venue's own "city" field; if not, check the location.
            if ( isset($venue['city']) ) {
                if ( is_array($venue['city']) && isset($venue['city']['name']) ) {
                    $venue_city = $venue['city']['name'];
                } else {
                    $venue_city = $venue['city'];
                }
            } elseif ( isset($event['location']['city']) ) {
                $venue_city = $event['location']['city'];
            } else {
                $venue_city = 'N/A';
            }

            // Similarly for state, zip, and country use the "location" object.
            $venue_state   = isset($event['location']['state']) ? $event['location']['state'] : 'N/A';
            $venue_zip     = isset($event['location']['zip']) ? $event['location']['zip'] : 'N/A';
            $venue_country = isset($event['location']['country']) ? $event['location']['country'] : 'N/A';

            $venue_data = array(
                'venue'   => isset( $venue['name'] ) ? $venue['name'] : 'N/A',
                'address' => $venue_address,
                'city'    => $venue_city,
                'state'   => $venue_state,
                'zip'     => $venue_zip,
                'country' => $venue_country,
                'website' => '', // Not provided by DICE.FM
                'phone'   => '',
            );
        }

        // Build the event object.
        $events[] = array(
            'id'                => $event['id'],
            'title'             => $event['name'],
            'start_date'        => $start_formatted,
            'end_date'          => $end_formatted,
            'description'       => isset( $event['description'] ) ? $event['description'] : '',
            'url'               => isset( $event['url'] ) ? $event['url'] : '',
            'venue'             => $venue_data,
            'location_slug'     => $location['slug'],     // For taxonomy assignment.
            'location_term_id'  => $location['term_id'],
        );
    }

    return new WP_REST_Response( $events, 200 );
}


/**
 * Post DICE.FM events to The Events Calendar.
 * This function retrieves events for Austin from DICE.FM and posts them,
 * skipping duplicates and assigning the proper taxonomy.
 *
 * @param int $maxEvents Maximum number of events to post.
 * @return array|WP_Error Array of posted events or WP_Error on failure.
 */
function post_dice_fm_events_to_calendar( $maxEvents = 10 ) {
    // Retrieve the list of locations and select Austin.
    $locations = get_event_locations();
    $austin_location = null;
    foreach ( $locations as $loc ) {
        if ( $loc['slug'] === 'austin' ) {
            $austin_location = $loc;
            break;
        }
    }
    if ( ! $austin_location ) {
        error_log( "Austin location not defined." );
        return new WP_Error( 'no_location', 'Austin location not defined.', array( 'status' => 500 ) );
    }

    $fetched_events = fetch_dice_fm_events( $austin_location );
    if ( is_wp_error( $fetched_events ) ) {
        error_log( "Error fetching DICE.FM events: " . $fetched_events->get_error_message() );
        return $fetched_events;
    }
    $events_data = $fetched_events->get_data();
    if ( empty( $events_data ) ) {
        error_log( "No DICE.FM events found for Austin." );
        return array();
    }

    $api_endpoint = get_site_url() . '/wp-json/tribe/events/v1/events';
    $posted_events = array();
    $total_posted  = 0;

    foreach ( $events_data as $event ) {
        if ( $total_posted >= $maxEvents ) {
            break;
        }

        // Use the duplicate check.
        if ( event_already_exists( $event['title'], $event['start_date'] ) ) {
            continue;
        }

        // Build the event data.
        // Note that we assign the original event URL to the 'website' key.
        $eventData = array(
            'title'      => sanitize_text_field( $event['title'] ),
            'start_date' => sanitize_text_field( $event['start_date'] ),
            'end_date'   => sanitize_text_field( $event['end_date'] ),
            // You may still keep the 'url' field if needed, but we add a 'website' field below.
            'url'        => isset( $event['url'] ) ? esc_url_raw( $event['url'] ) : '',
            'website'    => isset( $event['url'] ) ? esc_url_raw( $event['url'] ) : '',  // <-- Added this line
            'venue'      => array(
                'venue'   => sanitize_text_field( $event['venue']['venue'] ),
                'address' => sanitize_text_field( $event['venue']['address'] ),
                'city'    => sanitize_text_field( $event['venue']['city'] ),
                'state'   => sanitize_text_field( $event['venue']['state'] ),
                'zip'     => sanitize_text_field( $event['venue']['zip'] ),
                'country' => sanitize_text_field( $event['venue']['country'] ),
                // Optionally, if you ever receive a venue website from Dice, you could use it here.
                'website' => '',
                'phone'   => '',
            ),
        );

        if ( ! empty( $event['description'] ) ) {
            $eventData['description'] = sanitize_textarea_field( $event['description'] );
        }

        // Retrieve authorization credentials from wp-config.php.
        if ( ! defined( 'EVENTS_CALENDAR_AUTH' ) ) {
            return new WP_Error( 'auth_missing', 'Authorization credentials not defined.', array( 'status' => 500 ) );
        }
        $authorization = 'Basic ' . base64_encode( EVENTS_CALENDAR_AUTH );

        $response = wp_remote_post( $api_endpoint, array(
            'headers'     => array(
                'Authorization' => $authorization,
                'Content-Type'  => 'application/json',
            ),
            'body'        => wp_json_encode( $eventData ),
            'method'      => 'POST',
            'data_format' => 'body',
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( "Failed to post DICE.FM event '{$event['title']}': " . $response->get_error_message() );
            continue;
        }

        $responseBody = wp_remote_retrieve_body( $response );
        $responseCode = wp_remote_retrieve_response_code( $response );
        if ( $responseCode >= 200 && $responseCode < 300 ) {
            $responseData = json_decode( $responseBody, true );
            if ( isset( $responseData['id'] ) ) {
                $created_event_id = intval( $responseData['id'] );
                // Assign the location taxonomy.
                wp_set_object_terms( $created_event_id, array( $event['location_term_id'] ), 'location', false );
                $posted_events[] = array(
                    'title'      => $event['title'],
                    'venue'      => $event['venue']['venue'],
                    'start_date' => $event['start_date'],
                );
                $total_posted++;
            }
        }
    }

    // Log the import event (assuming log_import_event() is defined elsewhere).
    // log_import_event( 'dice', $total_posted ); // Removed redundant logging

    return new WP_REST_Response( $posted_events, 200 );
}

