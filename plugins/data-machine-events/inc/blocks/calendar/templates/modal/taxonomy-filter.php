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

<div class="datamachine-taxonomy-filter-content" data-filters-endpoint="<?php echo esc_url( rest_url( 'datamachine/v1/events/filters' ) ); ?>">
	<div class="datamachine-filter-loading">
		<span class="datamachine-filter-spinner"></span>
		<span><?php esc_html_e( 'Loading filters...', 'datamachine-events' ); ?></span>
	</div>
	<div class="datamachine-filter-taxonomies"></div>
</div>