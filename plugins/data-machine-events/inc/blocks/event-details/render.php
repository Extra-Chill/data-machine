<?php
/**
 * Event Details Block Render Template
 *
 * @package DmEvents
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

use DmEvents\Events\Venues\Venue_Term_Meta;

// Get block attributes
$start_date = $attributes['startDate'] ?? '';
$end_date = $attributes['endDate'] ?? '';
$start_time = $attributes['startTime'] ?? '';
$end_time = $attributes['endTime'] ?? '';
$venue = $attributes['venue'] ?? '';
$address = $attributes['address'] ?? '';
$artist = $attributes['artist'] ?? '';
$price = $attributes['price'] ?? '';
$ticket_url = $attributes['ticketUrl'] ?? '';
// InnerBlocks content is passed as $content parameter
$show_venue = $attributes['showVenue'] ?? true;
$show_artist = $attributes['showArtist'] ?? true;
$show_price = $attributes['showPrice'] ?? true;
$show_ticket_link = $attributes['showTicketLink'] ?? true;

// Block-first architecture: Block attributes are the primary data source
// Only use post meta as fallback for backwards compatibility with existing events
$post_id = get_the_ID();
if (empty($start_date)) {
    $start_date = get_post_meta($post_id, '_dm_event_start_date', true);
}
if (empty($end_date)) {
    $end_date = get_post_meta($post_id, '_dm_event_end_date', true);
}
if (empty($artist)) {
    $artist = get_post_meta($post_id, '_dm_event_artist_name', true);
}
if (empty($price)) {
    $price = get_post_meta($post_id, '_dm_event_price', true);
}
if (empty($ticket_url)) {
    $ticket_url = get_post_meta($post_id, '_dm_event_ticket_url', true);
}

// Get venue data from taxonomy term meta (replaces flat address system)
$venue_data = null;
$venue_terms = get_the_terms($post_id, 'venue');
if ($venue_terms && !is_wp_error($venue_terms)) {
    $venue_term = $venue_terms[0];
    $venue_data = Venue_Term_Meta::get_venue_data($venue_term->term_id);
    $venue = $venue_data['name']; // Use venue name from term
    $address = Venue_Term_Meta::get_formatted_address($venue_term->term_id); // Use structured address
}

// Format dates
$start_datetime = '';
$end_datetime = '';
if ($start_date) {
    $start_datetime = $start_time ? $start_date . ' ' . $start_time : $start_date;
}
if ($end_date) {
    $end_datetime = $end_time ? $end_date . ' ' . $end_time : $end_date;
}

// CSS classes
$block_classes = array('dm-event-details');
if (!empty($attributes['align'])) {
    $block_classes[] = 'align' . $attributes['align'];
}
$block_class = implode(' ', $block_classes);

/**
 * Generate Google Event Schema JSON-LD
 * 
 * Combines system data (venue, dates) with AI-generated schema fields
 * for comprehensive Google Event schema markup.
 */
function ce_generate_event_schema($attributes, $venue_data, $post_id) {
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Event',
        'name' => get_the_title($post_id),
    ];
    
    // Required: startDate with proper timezone formatting
    if (!empty($attributes['startDate'])) {
        $start_time = !empty($attributes['startTime']) ? 'T' . $attributes['startTime'] : '';
        $schema['startDate'] = $attributes['startDate'] . $start_time;
    }
    
    // Recommended: endDate
    if (!empty($attributes['endDate'])) {
        $end_time = !empty($attributes['endTime']) ? 'T' . $attributes['endTime'] : '';
        $schema['endDate'] = $attributes['endDate'] . $end_time;
    }
    
    // Required: location with structured address from venue term meta
    if ($venue_data) {
        $schema['location'] = [
            '@type' => 'Place',
            'name' => $venue_data['name']
        ];
        
        // Add structured address if available
        if (!empty($venue_data['address']) || !empty($venue_data['city'])) {
            $schema['location']['address'] = [
                '@type' => 'PostalAddress'
            ];
            
            if (!empty($venue_data['address'])) {
                $schema['location']['address']['streetAddress'] = $venue_data['address'];
            }
            if (!empty($venue_data['city'])) {
                $schema['location']['address']['addressLocality'] = $venue_data['city'];
            }
            if (!empty($venue_data['state'])) {
                $schema['location']['address']['addressRegion'] = $venue_data['state'];
            }
            if (!empty($venue_data['zip'])) {
                $schema['location']['address']['postalCode'] = $venue_data['zip'];
            }
            if (!empty($venue_data['country'])) {
                $schema['location']['address']['addressCountry'] = $venue_data['country'];
            } else {
                $schema['location']['address']['addressCountry'] = 'US'; // Default
            }
        }
    }
    
    // AI-generated description (always present)
    if (!empty($attributes['description'])) {
        $schema['description'] = $attributes['description'];
    }
    
    // AI-inferred performer details
    if (!empty($attributes['artist'])) {
        $performer_type = $attributes['performerType'] ?? 'PerformingGroup';
        $schema['performer'] = [
            '@type' => $performer_type,
            'name' => $attributes['artist']
        ];
    }
    
    // AI-inferred organizer
    if (!empty($attributes['organizerName'])) {
        $organizer_type = $attributes['organizerType'] ?? 'Organization';
        $schema['organizer'] = [
            '@type' => $organizer_type,
            'name' => $attributes['organizerName']
        ];
    }
    
    // System + AI offers data
    if (!empty($attributes['ticketUrl'])) {
        $schema['offers'] = [
            '@type' => 'Offer',
            'url' => $attributes['ticketUrl'],
            'availability' => 'https://schema.org/' . ($attributes['offerAvailability'] ?? 'InStock')
        ];
        
        if (!empty($attributes['price'])) {
            // Extract numeric price
            $numeric_price = preg_replace('/[^0-9.]/', '', $attributes['price']);
            if ($numeric_price) {
                $schema['offers']['price'] = floatval($numeric_price);
                $schema['offers']['priceCurrency'] = $attributes['priceCurrency'] ?? 'USD';
            }
        }
    }
    
    // AI-inferred event status
    if (!empty($attributes['eventStatus'])) {
        $schema['eventStatus'] = 'https://schema.org/' . $attributes['eventStatus'];
    } else {
        $schema['eventStatus'] = 'https://schema.org/EventScheduled';
    }
    
    return $schema;
}

// Generate schema markup
$event_schema = null;
if ($venue_data && !empty($start_date)) {
    $event_schema = ce_generate_event_schema($attributes, $venue_data, $post_id);
}
?>

<?php if ($event_schema): ?>
    <script type="application/ld+json">
    <?php echo wp_json_encode($event_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); ?>
    </script>
<?php endif; ?>

<div class="<?php echo esc_attr($block_class); ?>">
    <?php if (!empty($content)): ?>
        <div class="event-description">
            <?php echo $content; ?>
        </div>
    <?php endif; ?>
    
    <div class="event-info-grid">
        <?php if ($start_datetime): ?>
            <div class="event-date-time">
                <span class="icon">📅</span>
                <span class="text">
                    <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($start_datetime))); ?>
                    <?php if ($start_time): ?>
                        <br><small><?php echo esc_html(date_i18n(get_option('time_format'), strtotime($start_datetime))); ?></small>
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>

        <?php if ($show_venue && $venue): ?>
            <div class="event-venue">
                <span class="icon">📍</span>
                <span class="text">
                    <?php echo esc_html($venue); ?>
                    <?php if ($address): ?>
                        <br><small><?php echo esc_html($address); ?></small>
                    <?php endif; ?>
                    <?php if ($venue_data && !empty($venue_data['website'])): ?>
                        <br><small><a href="<?php echo esc_url($venue_data['website']); ?>" target="_blank" rel="noopener"><?php _e('Venue Website', 'dm-events'); ?></a></small>
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>

        <?php if ($show_artist && $artist): ?>
            <div class="event-artist">
                <span class="icon">🎤</span>
                <span class="text"><?php echo esc_html($artist); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($show_price && $price): ?>
            <div class="event-price">
                <span class="icon">💰</span>
                <span class="text"><?php echo esc_html($price); ?></span>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($show_ticket_link && $ticket_url): ?>
        <div class="event-tickets">
            <a href="<?php echo esc_url($ticket_url); ?>" class="ticket-button" target="_blank" rel="noopener">
                <?php _e('Get Tickets', 'dm-events'); ?>
            </a>
        </div>
    <?php endif; ?>

</div> 