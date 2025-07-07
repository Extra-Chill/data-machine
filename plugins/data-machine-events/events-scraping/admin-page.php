<?php
/**
 * event scraper admin page (admin-page.php)
 */

/**
 * Register a custom admin menu page.
 */
add_action('admin_menu', 'register_custom_menu_page');
function register_custom_menu_page() {
    add_menu_page(
        'Post Events',              // Page title
        'Post Events',              // Menu title
        'manage_options',           // Capability
        'post-events',              // Menu slug
        'post_events_admin_page',   // Function to display the page
        'dashicons-calendar-alt',   // Icon
        6                           // Position
    );
}

/**
 * Display the admin page content.
 */
function post_events_admin_page() {
    echo '<div class="wrap"><h1>Post Events to Calendar</h1>';

    // Handle form submissions securely.
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        if ($_GET['action'] === 'post_scraped_events') {
            $maxEvents = isset($_GET['max_events']) ? intval($_GET['max_events']) : 5;
            // Post scraped events
            $results = post_aggregated_events_to_calendar($maxEvents, 'manual scraping');
            echo get_posting_results($results);
        } elseif ($_GET['action'] === 'post_tm_events') {
            $maxEvents = isset($_GET['max_tm_events']) ? intval($_GET['max_tm_events']) : 5;
            $location_slug = isset($_GET['location_slug']) ? sanitize_text_field($_GET['location_slug']) : null;
            // Post Ticketmaster events for the specified location or all locations
            $results = post_ticketmaster_events_to_calendar($maxEvents, 'manual ticketmaster', $location_slug);
            echo get_posting_results($results);
        } elseif ($_GET['action'] === 'post_dice_events') {
            // New condition to handle DICE.FM events
            $maxEvents = isset($_GET['max_dice_events']) ? intval($_GET['max_dice_events']) : 5;
            // Call our DICE.FM posting function (defined in dice-fm-event-imports.php)
            $results = post_dice_fm_events_to_calendar($maxEvents);
            echo get_posting_results($results);
        }
    }

    // Form for scraped events
    echo '<form method="get" style="margin-bottom: 20px;">';
    echo '<input type="hidden" name="page" value="post-events" />';
    echo '<label for="max_events">Maximum number of events to post (Scraped Events): </label>';
    echo '<input type="number" id="max_events" name="max_events" min="1" style="width: 80px;" value="5">';
    echo '<input type="hidden" name="action" value="post_scraped_events" />';
    echo '<input type="submit" value="Post Scraped Events" class="button button-primary" />';
    echo '</form>';

    // Form for Ticketmaster events
    echo '<form method="get" style="margin-bottom: 20px;">';
    echo '<input type="hidden" name="page" value="post-events" />';
    echo '<label for="max_tm_events">Maximum number of events to post (Ticketmaster Events): </label>';
    echo '<input type="number" id="max_tm_events" name="max_tm_events" min="1" style="width: 80px;" value="5">';
    // Location dropdown
    echo '<label for="location_slug">Location: </label>';
    echo '<select id="location_slug" name="location_slug">';
    echo '<option value="">All Locations</option>';
    $locations = get_event_locations();
    foreach ($locations as $location) {
        echo '<option value="' . esc_attr($location['slug']) . '">' . esc_html($location['name']) . '</option>';
    }
    echo '</select>';
    echo '<input type="hidden" name="action" value="post_tm_events" />';
    echo '<input type="submit" value="Post Ticketmaster Events" class="button button-primary" />';
    echo '</form>';

    // **New Form for DICE.FM events**
    echo '<form method="get" style="margin-bottom: 20px;">';
    echo '<input type="hidden" name="page" value="post-events" />';
    echo '<label for="max_dice_events">Maximum number of events to post (DICE.FM Events): </label>';
    echo '<input type="number" id="max_dice_events" name="max_dice_events" min="1" style="width: 80px;" value="5">';
    echo '<input type="hidden" name="action" value="post_dice_events" />';
    echo '<input type="submit" value="Post DICE.FM Events" class="button button-primary" />';
    echo '</form>';

    echo '<p>Enter the maximum number of events you wish to post and click the appropriate button.</p>';

    // Display the last 5 import logs
    echo '<h2>Last 5 Imports</h2>';
    $logs = get_option('event_import_logs', []);
    if (!empty($logs)) {
        echo '<table class="widefat fixed" cellspacing="0">';
        echo '<thead><tr><th>Timestamp</th><th>Source</th><th>Number of Events</th></tr></thead>';
        echo '<tbody>';
        foreach (array_reverse($logs) as $log) {
            $timestamp  = isset($log['timestamp']) ? esc_html($log['timestamp']) : 'N/A';
            $source     = isset($log['source']) ? esc_html(ucfirst($log['source'])) : 'N/A';
            $num_events = isset($log['num_events']) ? esc_html($log['num_events']) : 'N/A';
            echo '<tr>';
            echo '<td>' . $timestamp . '</td>';
            echo '<td>' . $source . '</td>';
            echo '<td>' . $num_events . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
    } else {
        echo '<p>No import logs available.</p>';
    }    

    echo '</div>';
}

/**
 * Format and return posting results for display.
 *
 * @param mixed $results The results from the posting function.
 * @return string HTML content.
 */
function get_posting_results($results) {
    // If $results is a WP_REST_Response, get its data.
    if ( $results instanceof WP_REST_Response ) {
        $results = $results->get_data();
    }
    
    if (is_wp_error($results)) {
        return '<p style="color:red;">Error: ' . esc_html($results->get_error_message()) . '</p>';
    } elseif (empty($results)) {
        return '<p>No new, non-duplicate events found.</p>';
    } else {
        $output = '<div>';
        foreach ($results as $result) {
            if (isset($result['error'])) {
                $eventName        = isset($result['title']) ? $result['title'] : 'Unknown Event';
                $errorDescription = isset($result['error']) ? $result['error'] : 'No error message provided';
                $output           .= '<p style="color:red;">Error posting event: <strong>' . esc_html($eventName) . '</strong> - ' . esc_html($errorDescription) . '</p>';
            } else {
                $title     = isset($result['title']) ? $result['title'] : 'N/A';
                $venueName = 'N/A';
                if (isset($result['venue'])) {
                    if (is_array($result['venue']) && isset($result['venue']['venue'])) {
                        $venueName = $result['venue']['venue'];
                    } elseif (is_string($result['venue'])) {
                        $venueName = $result['venue'];
                    }
                }
                $startDate = isset($result['start_date']) ? $result['start_date'] : 'N/A';
                $output .= '<p style="color:green;">Event posted successfully:</p>';
                $output .= '<ul>';
                $output .= '<li><strong>Title:</strong> ' . esc_html($title) . '</li>';
                $output .= '<li><strong>Venue:</strong> ' . esc_html($venueName) . '</li>';
                $output .= '<li><strong>Date:</strong> ' . esc_html($startDate) . '</li>';
                $output .= '</ul>';
            }
        }
        $output .= '</div>';
        return $output;
    }
}
