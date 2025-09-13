<?php
/**
 * No Events Template
 *
 * Renders the empty state message when no events are found.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="dm-events-no-events">
    <p><?php _e('No events found.', 'dm-events'); ?></p>
</div>