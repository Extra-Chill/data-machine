<?php
/**
 * Agent Admin Page Component Filter Registration
 *
 * @package DataMachine
 * @subpackage Core\Admin\Pages\Agent
 * @since 0.28.0
 */

namespace DataMachine\Core\Admin\Pages\Agent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register all Agent Admin Page component filters
 *
 * @since 0.28.0
 */
function datamachine_register_agent_admin_page_filters() {

	add_filter(
		'datamachine_admin_pages',
		function ( $pages ) {
			$pages['agent'] = array(
				'page_title' => __( 'Agent', 'data-machine' ),
				'menu_title' => __( 'Agent', 'data-machine' ),
				'capability' => 'manage_options',
				'position'   => 20,
				'templates'  => __DIR__ . '/templates/',
				'assets'     => array(
					'css' => array(
						'wp-components'          => array(
							'file'  => null,
							'deps'  => array(),
							'media' => 'all',
						),
						'datamachine-tabs'       => array(
							'file'  => 'inc/Core/Admin/shared/styles/tabs.css',
							'deps'  => array(),
							'media' => 'all',
						),
						'datamachine-agent-page' => array(
							'file'  => 'inc/Core/Admin/Pages/Agent/assets/css/agent-page.css',
							'deps'  => array(),
							'media' => 'all',
						),
					),
					'js'  => array(
						'datamachine-agent-react' => array(
							'file'      => 'inc/Core/Admin/assets/build/agent-react.js',
							'deps'      => array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-dom-ready' ),
							'in_footer' => true,
							'localize'  => array(
								'object' => 'dataMachineAgentConfig',
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

add_action( 'init', __NAMESPACE__ . '\\datamachine_register_agent_admin_page_filters' );
