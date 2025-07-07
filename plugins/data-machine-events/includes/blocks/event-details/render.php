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
$description = $attributes['description'] ?? '';
$show_venue = $attributes['showVenue'] ?? true;
$show_artist = $attributes['showArtist'] ?? true;
$show_price = $attributes['showPrice'] ?? true;
$show_ticket_link = $attributes['showTicketLink'] ?? true;
$layout = $attributes['layout'] ?? 'compact';

// Get post meta as fallback
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
$block_classes = array('chill-event-details', 'layout-' . $layout);
if (!empty($attributes['align'])) {
    $block_classes[] = 'align' . $attributes['align'];
}
$block_class = implode(' ', $block_classes);
?>

<div class="<?php echo esc_attr($block_class); ?>">
    <?php if ($layout === 'detailed'): ?>
        <!-- Detailed Layout -->
        <div class="event-details-detailed">
            <?php if ($start_datetime || $end_datetime): ?>
                <div class="event-datetime">
                    <h3><?php _e('Date & Time', 'chill-events'); ?></h3>
                    <div class="datetime-info">
                        <?php if ($start_datetime): ?>
                            <div class="start-datetime">
                                <strong><?php _e('Start:', 'chill-events'); ?></strong>
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($start_datetime))); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($end_datetime): ?>
                            <div class="end-datetime">
                                <strong><?php _e('End:', 'chill-events'); ?></strong>
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($end_datetime))); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($show_venue && ($venue || $address)): ?>
                <div class="event-location">
                    <h3><?php _e('Location', 'chill-events'); ?></h3>
                    <?php if ($venue): ?>
                        <div class="venue-name"><?php echo esc_html($venue); ?></div>
                    <?php endif; ?>
                    <?php if ($address): ?>
                        <div class="venue-address"><?php echo esc_html($address); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($show_artist && $artist): ?>
                <div class="event-artist">
                    <h3><?php _e('Artist/Performer', 'chill-events'); ?></h3>
                    <div class="artist-name"><?php echo esc_html($artist); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($show_price && $price): ?>
                <div class="event-price">
                    <h3><?php _e('Price', 'chill-events'); ?></h3>
                    <div class="price-amount"><?php echo esc_html($price); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($show_ticket_link && $ticket_url): ?>
                <div class="event-tickets">
                    <a href="<?php echo esc_url($ticket_url); ?>" class="ticket-link" target="_blank" rel="noopener">
                        <?php _e('Get Tickets', 'chill-events'); ?>
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($description): ?>
                <div class="event-description">
                    <h3><?php _e('Event Description', 'chill-events'); ?></h3>
                    <div class="description-content"><?php echo wp_kses_post($description); ?></div>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($layout === 'minimal'): ?>
        <!-- Minimal Layout -->
        <div class="event-details-minimal">
            <?php if ($start_datetime): ?>
                <div class="event-date">
                    <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($start_datetime))); ?>
                </div>
            <?php endif; ?>
            <?php if ($venue): ?>
                <div class="event-venue"><?php echo esc_html($venue); ?></div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- Compact Layout (Default) -->
        <div class="event-details-compact">
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
    <?php endif; ?>
</div> 