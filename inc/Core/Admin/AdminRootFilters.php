<?php
/**
 * Admin Root CSS Variables Filter
 *
 * Registers root.css globally across all admin pages for centralized design tokens.
 *
 * @package DataMachine
 */

namespace DataMachine\Core\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Register root CSS variables
 *
 * @return void
 */
function datamachine_register_root_css_filter() {
	add_filter(
		'datamachine_admin_pages',
		function ( $pages ) {
			// Add root.css to EVERY admin page
			foreach ( $pages as $page_slug => &$page_config ) {
				if ( ! isset( $page_config['assets'] ) ) {
					$page_config['assets'] = array();
				}

				if ( ! isset( $page_config['assets']['css'] ) ) {
					$page_config['assets']['css'] = array();
				}

				// Prepend root.css so it loads FIRST
				$page_config['assets']['css'] = array_merge(
					array(
						'datamachine-root' => array(
							'file'  => 'inc/Core/Admin/assets/css/root.css',
							'deps'  => array(),
							'media' => 'all',
						),
					),
					$page_config['assets']['css']
				);
			}

			return $pages;
		},
		15 // Priority 15 to run after page-specific filters (priority 10)
	);
}

datamachine_register_root_css_filter();
