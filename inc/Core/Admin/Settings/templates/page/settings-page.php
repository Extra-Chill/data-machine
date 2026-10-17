<?php
/**
 * Settings Page Template - React Mount Point
 *
 * Minimal template that provides the React app mounting point.
 * The React app handles all tab navigation and content rendering.
 *
 * @package DataMachine\Core\Admin\Settings\Templates
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<div class="wrap datamachine-settings-page">
	<h1><?php echo esc_html( $data['page_config']['page_title'] ?? __( 'Data Machine Settings', 'data-machine' ) ); ?></h1>
	<div id="datamachine-settings-root">
		<p class="description">Loading settings...</p>
	</div>
</div>
