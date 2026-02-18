<?php
/**
 * Settings Admin Page Registration
 *
 * Registers the Settings admin page, its React assets, and template rendering.
 * Settings are managed entirely through the React UI via REST API
 * (SettingsAbilities). No WordPress Settings API (register_setting) is used.
 *
 * @package DataMachine\Core\Admin\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function datamachine_register_settings_admin_page_filters() {
	add_filter(
		'datamachine_admin_pages',
		function ( $pages ) {
			$pages['settings'] = array(
				'page_title' => __( 'Data Machine Settings', 'data-machine' ),
				'menu_title' => __( 'Settings', 'data-machine' ),
				'capability' => 'manage_options',
				'position'   => 100,
				'templates'  => DATAMACHINE_PATH . 'inc/Core/Admin/Settings/templates/',
				'assets'     => array(
					'css' => array(
						'wp-components'             => array(
							'file'  => null,
							'deps'  => array(),
							'media' => 'all',
						),
						'datamachine-tabs'          => array(
							'file'  => 'inc/Core/Admin/shared/styles/tabs.css',
							'deps'  => array(),
							'media' => 'all',
						),
						'datamachine-settings-page' => array(
							'file'  => 'inc/Core/Admin/Settings/assets/css/settings-page.css',
							'deps'  => array(),
							'media' => 'all',
						),
					),
					'js'  => array(
						'datamachine-settings-react' => array(
							'file'      => 'inc/Core/Admin/assets/build/settings-react.js',
							'deps'      => array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ),
							'in_footer' => true,
							'localize'  => array(
								'object' => 'dataMachineSettingsConfig',
								'data'   => array(
									'restNamespace' => 'datamachine/v1',
									'restNonce'     => wp_create_nonce( 'wp_rest' ),
									'adminUrl'      => admin_url(),
								),
							),
						),
					),
				),
			);
			return $pages;
		}
	);

	add_filter(
		'datamachine_render_template',
		function ( $content, $template_name, $data = array() ) {
			$settings_template_path = DATAMACHINE_PATH . 'inc/Core/Admin/Settings/templates/' . $template_name . '.php';
			if ( file_exists( $settings_template_path ) ) {
				ob_start();
				include $settings_template_path;
				return ob_get_clean();
			}
			return $content;
		},
		15,
		3
	);
}

add_action( 'init', 'datamachine_register_settings_admin_page_filters' );
