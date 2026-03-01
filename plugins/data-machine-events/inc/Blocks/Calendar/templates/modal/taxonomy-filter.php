<?php
/**
 * Taxonomy Filter Modal Content Template
 *
 * Empty container for JS-populated taxonomy filter interface.
 * Filter data fetched via REST API /events/filters endpoint.
 *
 * @package DataMachineEvents\Blocks\Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="data-machine-taxonomy-filter-content" data-filters-endpoint="<?php echo esc_url( rest_url( 'datamachine/v1/events/filters' ) ); ?>">
	<div class="data-machine-filter-loading">
		<span class="data-machine-filter-spinner"></span>
		<span><?php esc_html_e( 'Loading filters...', 'data-machine-events' ); ?></span>
	</div>
	<div class="data-machine-filter-taxonomies"></div>
</div>