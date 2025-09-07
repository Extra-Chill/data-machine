<?php
/**
 * Event Details Block Server-Side Render Template
 *
 * Block-first architecture render that displays event information with venue taxonomy
 * integration and structured data schema generation.
 *
 * Available context:
 * @var array $attributes Block attributes containing event data
 * @var string $content InnerBlocks content for event description
 * @var WP_Block $block Block instance object
 *
 * @package DmEvents\Blocks\EventDetails
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

use DmEvents\Core\Venue_Taxonomy;
use DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsSchema;

// Extract block attributes with defaults
$start_date = $attributes['startDate'] ?? '';
$end_date = $attributes['endDate'] ?? '';
$start_time = $attributes['startTime'] ?? '';
$end_time = $attributes['endTime'] ?? '';
$venue = $attributes['venue'] ?? '';
$address = $attributes['address'] ?? '';
$price = $attributes['price'] ?? '';
$ticket_url = $attributes['ticketUrl'] ?? '';
// InnerBlocks content passed as $content
$show_venue = $attributes['showVenue'] ?? true;
$show_price = $attributes['showPrice'] ?? true;
$show_ticket_link = $attributes['showTicketLink'] ?? true;

// Block-first architecture: Block attributes are single source of truth
$post_id = get_the_ID();

// Get venue data from taxonomy term meta
$venue_data = null;
$venue_terms = get_the_terms($post_id, 'venue');
if ($venue_terms && !is_wp_error($venue_terms)) {
    $venue_term = $venue_terms[0];
    $venue_data = Venue_Taxonomy::get_venue_data($venue_term->term_id);
    $venue = $venue_data['name']; // Use venue name from term
    $address = Venue_Taxonomy::get_formatted_address($venue_term->term_id); // Use formatted address
}

// Format date and time values
$start_datetime = '';
$end_datetime = '';
if ($start_date) {
    $start_datetime = $start_time ? $start_date . ' ' . $start_time : $start_date;
}
if ($end_date) {
    $end_datetime = $end_time ? $end_date . ' ' . $end_time : $end_date;
}

// Generate CSS classes
$block_classes = array('dm-event-details');
if (!empty($attributes['align'])) {
    $block_classes[] = 'align' . $attributes['align'];
}
$block_class = implode(' ', $block_classes);


// Generate structured data schema for SEO
$event_schema = null;
if (!empty($start_date)) {
    // Engine parameters for enhanced schema generation
    $engine_parameters = [];
    
    // Block rendering doesn't have direct engine parameter access
    // Venue taxonomy data contains imported information
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