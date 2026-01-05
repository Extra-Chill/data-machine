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
$formatted_time_display = $display_vars['formatted_time_display'] ?? '';
$venue_name = $display_vars['venue_name'] ?? '';
$performer_name = $display_vars['performer_name'] ?? '';
$price = $display_vars['price'] ?? '';
$ticket_url = $display_vars['ticket_url'] ?? '';
$iso_start_date = $display_vars['iso_start_date'] ?? '';

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

    <div class="datamachine-event-link">

        <?php echo \DataMachineEvents\Blocks\Calendar\Taxonomy_Badges::render_taxonomy_badges($event_post->ID); ?>

        <h4 class="datamachine-event-title">
            <a href="<?php echo esc_url(get_the_permalink()); ?>">
                <?php the_title(); ?>
            </a>
        </h4>

        <div class="datamachine-event-meta">
            <?php if (!empty($formatted_time_display)) : ?>
                <div class="datamachine-event-time">
                    <span class="dashicons dashicons-clock"></span>
                    <?php echo esc_html($formatted_time_display); ?>
                </div>
            <?php endif; ?>

            <?php if ($show_performer && !empty($performer_name)) : ?>
                <div class="datamachine-event-performer">
                    <span class="dashicons dashicons-admin-users"></span>
                    <?php echo esc_html($performer_name); ?>
                </div>
            <?php endif; ?>

            <a href="<?php echo esc_url(get_the_permalink()); ?>" 
               class="<?php echo esc_attr(implode(' ', apply_filters('datamachine_events_more_info_button_classes', ['datamachine-more-info-button']))); ?>">
                <?php esc_html_e('More Info', 'datamachine-events'); ?>
            </a>
        </div>

    </div>
</div>
