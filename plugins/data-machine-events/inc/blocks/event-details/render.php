<?php
/**
 * Event Details Block Render Template
 *
 * @package ChillEvents
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

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
    $start_date = get_post_meta($post_id, '_chill_event_start_date', true);
}
if (empty($end_date)) {
    $end_date = get_post_meta($post_id, '_chill_event_end_date', true);
}
if (empty($artist)) {
    $artist = get_post_meta($post_id, '_chill_event_artist_name', true);
}
if (empty($price)) {
    $price = get_post_meta($post_id, '_chill_event_price', true);
}
if (empty($ticket_url)) {
    $ticket_url = get_post_meta($post_id, '_chill_event_ticket_url', true);
}

// Get venue from taxonomy if not set
if (empty($venue)) {
    $venue_terms = get_the_terms($post_id, 'venue');
    if ($venue_terms && !is_wp_error($venue_terms)) {
        $venue = $venue_terms[0]->name;
    }
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
$block_classes = array('chill-event-details');
if (!empty($attributes['align'])) {
    $block_classes[] = 'align' . $attributes['align'];
}
$block_class = implode(' ', $block_classes);
?>

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
                <?php _e('Get Tickets', 'chill-events'); ?>
            </a>
        </div>
    <?php endif; ?>

</div> 