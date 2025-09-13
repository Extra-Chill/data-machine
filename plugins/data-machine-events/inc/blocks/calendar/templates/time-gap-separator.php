<?php
/**
 * Time Gap Separator Template
 *
 * Displays a visual separator indicating a gap in time between event dates.
 * Only used in carousel-list display mode.
 *
 * @var int $gap_days Number of days in the gap
 */

if (!defined('ABSPATH')) {
    exit;
}

$gap_text = '';
if ($gap_days == 2) {
    $gap_text = __('1 day later', 'dm-events');
} else {
    $gap_text = sprintf(__('%d days later', 'dm-events'), $gap_days - 1);
}
?>

<div class="dm-time-gap-separator">
    <div class="dm-gap-line"></div>
    <div class="dm-gap-text">
        <span class="dm-gap-indicator">• • •</span>
        <span class="dm-gap-label"><?php echo esc_html($gap_text); ?></span>
    </div>
    <div class="dm-gap-line"></div>
</div>