<?php
/**
 * Logs Admin Page Component Filter Registration
 *
 * "Plugins Within Plugins" Architecture Implementation
 *
 * This file serves as the Logs Admin Page's "main plugin file" - the complete
 * interface contract with the engine, demonstrating complete self-containment
 * and zero bootstrap dependencies.
 *
 * @package DataMachine
 * @subpackage Core\Admin\Pages\Logs
 * @since 0.1.0
 */

namespace DataMachine\Core\Admin\Pages\Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register all Logs Admin Page component filters
 *
 * Complete self-registration pattern following "plugins within plugins" architecture.
 * Engine discovers Logs Admin Page capabilities purely through filter-based discovery.
 *
 * @since 0.1.0
 */
function datamachine_register_logs_admin_page_filters() {

	// Pure discovery mode - matches actual system usage
	add_filter(
		'datamachine_admin_pages',
		function ( $pages ) {
			$pages['logs'] = array(
				'page_title' => __( 'Logs', 'data-machine' ),
				'menu_title' => __( 'Logs', 'data-machine' ),
				'capability' => 'manage_options',
				'position'   => 30,
				'templates'  => __DIR__ . '/templates/',
				'assets'     => array(
					'css' => array(
						'wp-components'         => array(
							'file'  => null, // Use WordPress core version
							'deps'  => array(),
							'media' => 'all',
						),
						'datamachine-tabs'      => array(
							'file'  => 'inc/Core/Admin/shared/styles/tabs.css',
							'deps'  => array(),
							'media' => 'all',
						),
						'datamachine-logs-page' => array(
							'file'  => 'inc/Core/Admin/Pages/Logs/assets/css/logs-page.css',
							'deps'  => array(),
							'media' => 'all',
						),
					),
					'js'  => array(
						'datamachine-logs-react' => array(
							'file'      => 'inc/Core/Admin/assets/build/logs-react.js',
							'deps'      => array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-dom-ready' ),
							'in_footer' => true,
							'localize'  => array(
								'object' => 'dataMachineLogsConfig',
								'data'   => array(
									'restNamespace' => 'datamachine/v1',
									'restNonce'     => wp_create_nonce( 'wp_rest' ),
								),
							),
						),
					),
				),
			);
			return $pages;
		}
	);
}

// Auto-register when file loads - achieving complete self-containment
add_action( 'init', __NAMESPACE__ . '\\datamachine_register_logs_admin_page_filters' );
