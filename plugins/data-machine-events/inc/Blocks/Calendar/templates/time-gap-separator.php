<?php
/**
 * Time Gap Separator Template
 *
 * Displays a visual separator indicating a gap in time between event dates.
 * Only used in carousel-list display mode.
 *
 * @var int $gap_days Number of days in the gap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$gap_text = '';
if ( 2 == $gap_days ) {
	$gap_text = __( '1 day later', 'data-machine-events' );
} else {
	$gap_text = sprintf( __( '%d days later', 'data-machine-events' ), $gap_days - 1 );
}
?>

<div class="datamachine-time-gap-separator">
	<div class="datamachine-gap-line"></div>
	<div class="datamachine-gap-text">
		<span class="datamachine-gap-indicator">• • •</span>
		<span class="datamachine-gap-label"><?php echo esc_html( $gap_text ); ?></span>
	</div>
	<div class="datamachine-gap-line"></div>
</div>