<?php
/**
 * Date Group Template
 *
 * Renders the date group header/badge for grouping events by date.
 *
 * @var DateTime $date_obj Date object for this group
 * @var string $day_of_week Lowercase day name for CSS classes
 * @var string $formatted_date_label Human-readable date display
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>

<?php
/**
 * Date Group Start Template
 *
 * Opens the date group container and renders the date badge.
 * Note: The closing </div> is handled separately in the render.php loop.
 */
?>
<div class="datamachine-date-group datamachine-day-<?php echo esc_attr($day_of_week); ?>" data-date="<?php echo esc_attr($date_obj->format('Y-m-d')); ?>">
    <div class="datamachine-day-badge datamachine-day-badge-<?php echo esc_attr($day_of_week); ?>" 
         data-date-label="<?php echo esc_attr($formatted_date_label); ?>" 
         data-day-name="<?php echo esc_attr($day_of_week); ?>">
        <?php echo esc_html($formatted_date_label); ?>
    </div>