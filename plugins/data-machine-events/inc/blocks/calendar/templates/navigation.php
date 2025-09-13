<?php
/**
 * Navigation Template
 *
 * Renders Past/Upcoming events navigation buttons.
 *
 * @var bool $show_past Whether currently showing past events
 * @var int $past_events_count Number of past events available
 * @var int $future_events_count Number of future events available
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Show Past Events button when viewing upcoming events and past events exist
if (!$show_past && $past_events_count > 0) : ?>
    <div class="dm-events-past-navigation">
        <a href="<?php echo esc_url(add_query_arg('past', '1', remove_query_arg('paged'))); ?>" class="dm-events-past-btn">
            <?php _e('← Past Events', 'dm-events'); ?>
        </a>
    </div>

<?php 
// Show Upcoming Events button when viewing past events and future events exist
elseif ($show_past && $future_events_count > 0) : ?>
    <div class="dm-events-past-navigation">
        <a href="<?php echo esc_url(remove_query_arg(['past', 'paged'])); ?>" class="dm-events-upcoming-btn">
            <?php _e('Upcoming Events →', 'dm-events'); ?>
        </a>
    </div>

<?php endif; ?>