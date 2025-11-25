<?php
/**
 * Event Item Template
 *
 * Renders individual event item with all event details and metadata.
 *
 * @var WP_Post $event_post Event post object
 * @var array $event_data Event Details block attributes
 * @var array $display_vars Processed display variables
 */

if (!defined('ABSPATH')) {
    exit;
}
$formatted_start_time = $display_vars['formatted_start_time'] ?? '';
$venue_name = $display_vars['venue_name'] ?? '';
$performer_name = $display_vars['performer_name'] ?? '';
$price = $display_vars['price'] ?? '';
$ticket_url = $display_vars['ticket_url'] ?? '';
$iso_start_date = $display_vars['iso_start_date'] ?? '';

$show_venue = $display_vars['show_venue'] ?? true;
$show_performer = $display_vars['show_performer'] ?? true;
$show_price = $display_vars['show_price'] ?? true;
$show_ticket_link = $display_vars['show_ticket_link'] ?? true;
?>

<div class="datamachine-event-item"
     data-title="<?php echo esc_attr(get_the_title()); ?>"
     data-venue="<?php echo esc_attr($venue_name); ?>"
     data-performer="<?php echo esc_attr($performer_name); ?>"
     data-date="<?php echo esc_attr($iso_start_date); ?>"
     data-ticket-url="<?php echo esc_url($ticket_url); ?>"
     data-has-tickets="<?php echo ($show_ticket_link && !empty($ticket_url)) ? 'true' : 'false'; ?>">

    <a href="<?php echo esc_url(get_the_permalink()); ?>"
       class="datamachine-event-link"
       aria-label="<?php echo esc_attr(sprintf(__('View event: %s', 'datamachine-events'), get_the_title())); ?>">

        <?php echo \DataMachineEvents\Blocks\Calendar\Taxonomy_Badges::render_taxonomy_badges($event_post->ID); ?>

        <h4 class="datamachine-event-title">
            <?php the_title(); ?>
        </h4>

        <div class="datamachine-event-meta">
            <?php if (!empty($formatted_start_time)) : ?>
                <div class="datamachine-event-time">
                    <span class="dashicons dashicons-clock"></span>
                    <?php echo esc_html($formatted_start_time); ?>
                </div>
            <?php endif; ?>

            

            <?php if ($show_performer && !empty($performer_name)) : ?>
                <div class="datamachine-event-performer">
                    <span class="dashicons dashicons-admin-users"></span>
                    <?php echo esc_html($performer_name); ?>
                </div>
            <?php endif; ?>
        </div>

    </a>
</div>