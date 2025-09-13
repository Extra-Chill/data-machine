<?php
/**
 * Event Details Block Server-Side Render Template
 *
 * Displays event information with venue integration and structured data.
 *
 * @var array $attributes Block attributes
 * @var string $content InnerBlocks content
 * @var WP_Block $block Block instance
 */

if (!defined('ABSPATH')) {
    exit;
}

use DmEvents\Core\Venue_Taxonomy;
use DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsSchema;

$start_date = $attributes['startDate'] ?? '';
$end_date = $attributes['endDate'] ?? '';
$start_time = $attributes['startTime'] ?? '';
$end_time = $attributes['endTime'] ?? '';
$venue = $attributes['venue'] ?? '';
$address = $attributes['address'] ?? '';
$price = $attributes['price'] ?? '';
$ticket_url = $attributes['ticketUrl'] ?? '';
$show_venue = $attributes['showVenue'] ?? true;
$show_price = $attributes['showPrice'] ?? true;
$show_ticket_link = $attributes['showTicketLink'] ?? true;

$post_id = get_the_ID();

$venue_data = null;
$venue_terms = get_the_terms($post_id, 'venue');
if ($venue_terms && !is_wp_error($venue_terms)) {
    $venue_term = $venue_terms[0];
    $venue_data = Venue_Taxonomy::get_venue_data($venue_term->term_id);
    $venue = $venue_data['name'];
    $address = Venue_Taxonomy::get_formatted_address($venue_term->term_id);
}

$start_datetime = '';
$end_datetime = '';
if ($start_date) {
    $start_datetime = $start_time ? $start_date . ' ' . $start_time : $start_date;
}
if ($end_date) {
    $end_datetime = $end_time ? $end_date . ' ' . $end_time : $end_date;
}

$block_classes = array('dm-event-details');
if (!empty($attributes['align'])) {
    $block_classes[] = 'align' . $attributes['align'];
}
$block_class = implode(' ', $block_classes);


$event_schema = null;
if (!empty($start_date)) {
    $engine_parameters = [];
    
    $event_schema = DmEventsSchema::generate_event_schema($attributes, $venue_data, $post_id, $engine_parameters);
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
                    <?php if ($venue_data && !empty($venue_data['phone'])): ?>
                        <br><small><?php printf(__('Phone: %s', 'dm-events'), esc_html($venue_data['phone'])); ?></small>
                    <?php endif; ?>
                    <?php if ($venue_data && !empty($venue_data['website'])): ?>
                        <br><small><a href="<?php echo esc_url($venue_data['website']); ?>" target="_blank" rel="noopener"><?php _e('Venue Website', 'dm-events'); ?></a></small>
                    <?php endif; ?>
                </span>
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