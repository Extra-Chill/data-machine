<?php

// this code is used to filter events by location in the calendar view

// calendar-filter.php

// Force 'tribe-bar-location' into URLs for all views if set in context.
add_filter('tribe_events_views_v2_view_url', function($url, $canonical, $view) {
    $context_location = $view->get_context()->get('tribe-bar-location', '');

    // Add 'tribe-bar-location' to URL if available in context
    if (!empty($context_location)) {
        $url = add_query_arg('tribe-bar-location', $context_location, $url);
    }

    return $url;
}, 10, 3);

// Ensure 'tribe-bar-location' is included in query args for publicly visible views and AJAX.
add_filter('tribe_events_views_v2_publicly_visible_views_query_args', function($query_args) {
    $location = tribe_context()->get('tribe-bar-location', '');

    // Check for 'tribe-bar-location' in context or URL query and apply
    if (!empty($location)) {
        $query_args['tribe-bar-location'] = $location;
    } elseif (isset($_GET['tribe-bar-location'])) {
        // Fallback to $_GET to capture URL parameter on direct access
        $query_args['tribe-bar-location'] = sanitize_text_field($_GET['tribe-bar-location']);
    }

    return $query_args;
}, 1, 1);

// Modify the event repository args to filter by 'tribe-bar-location' across requests.
add_filter('tribe_events_views_v2_view_repository_args', function($args, $context) {
    $location = $context->get('tribe-bar-location', '');

    // Fallback to $_GET if context lacks 'tribe-bar-location'
    if (empty($location) && isset($_GET['tribe-bar-location'])) {
        $location = sanitize_text_field($_GET['tribe-bar-location']);
    }

    if (!empty($location)) {
        if (!isset($args['tax_query'])) {
            $args['tax_query'] = [];
        }

        // Filter events based on 'location' taxonomy
        $args['tax_query'][] = [
            'taxonomy' => 'location',
            'field'    => 'slug',
            'terms'    => $location,
            'operator' => 'IN',
        ];
    }

    return $args;
}, 10, 2);

// Custom filter to ensure 'tribe-bar-location' is present in every template render.
add_filter('tribe_events_template_var', function($value, $key) {
    if ($key === ['bar', 'tribe-bar-location']) {
        // Check `view_data` for `tribe-bar-location` in initial request
        if (isset($_REQUEST['view_data']['tribe-bar-location'])) {
            return sanitize_text_field($_REQUEST['view_data']['tribe-bar-location']);
        }

        // Check `url` parameter for AJAX view switching (Month view included)
        if (isset($_REQUEST['url'])) {
            $url = $_REQUEST['url'];
            $parsed_url = parse_url($url);
            if (isset($parsed_url['query'])) {
                parse_str($parsed_url['query'], $query_vars);
                if (isset($query_vars['tribe-bar-location'])) {
                    return sanitize_text_field($query_vars['tribe-bar-location']);
                }
            }
        }

        // Check GET parameter directly if the above conditions don’t capture `tribe-bar-location`
        if (isset($_GET['tribe-bar-location'])) {
            return sanitize_text_field($_GET['tribe-bar-location']);
        }
    }
    return $value;
}, 10, 2);


add_filter('tribe_events_views_v2_cache_key', function($cache_key, $view, $context) {
    // Retrieve the 'tribe-bar-location' parameter from the current context
    $location = $context->get('tribe-bar-location', '');

    // If a location is set, append it to the cache key to make it unique per location
    if (!empty($location)) {
        $cache_key .= '_location_' . sanitize_key($location);
    }

    return $cache_key;
}, 10, 3);
