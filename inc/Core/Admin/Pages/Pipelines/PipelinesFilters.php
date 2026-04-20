<?php
/**
 * Pipelines Admin Page Registration
 *
 * Self-contained admin page registration following filter-based discovery architecture.
 * Registers page, assets, and modal integration via datamachine_admin_pages filter.
 *
 * @package DataMachine\Core\Admin\Pages\Pipelines
 * @since 1.0.0
 */

namespace DataMachine\Core\Admin\Pages\Pipelines;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register Pipelines admin page components
 *
 * Self-registration pattern using filter-based discovery.
 * Engine discovers page capabilities through datamachine_admin_pages filter.
 */
function datamachine_register_pipelines_admin_page_filters() {

	// Pure discovery mode - matches actual system usage
	add_filter(
		'datamachine_admin_pages',
		function ( $pages ) {
			$pages['pipelines'] = array(
				'page_title' => __( 'Pipelines', 'data-machine' ),
				'menu_title' => __( 'Pipelines', 'data-machine' ),
				'capability' => 'datamachine_manage_flows',
				'position'   => 10,
				'templates'  => __DIR__ . '/templates/',
				'assets'     => array(
					'css' => array(
						'wp-components'                 => array(
							'file'  => null, // Use WordPress core version
							'deps'  => array(),
							'media' => 'all',
						),
						'datamachine-shared-pagination' => array(
							'file'  => 'inc/Core/Admin/shared/styles/pagination.css',
							'deps'  => array(),
							'media' => 'all',
						),
						'datamachine-pipelines-page'    => array(
							'file'  => 'inc/Core/Admin/Pages/Pipelines/assets/css/pipelines-page.css',
							'deps'  => array( 'datamachine-shared-pagination' ),
							'media' => 'all',
						),
						'datamachine-pipelines-modal'   => array(
							'file'  => 'inc/Core/Admin/Pages/Pipelines/assets/css/pipelines-modal.css',
							'deps'  => array(),
							'media' => 'all',
						),

						'datamachine-chat-sidebar'      => array(
							'file'  => 'inc/Core/Admin/Pages/Pipelines/assets/css/chat-sidebar.css',
							'deps'  => array(),
							'media' => 'all',
						),
					),
					'js'  => array(
						// React bundle (only script needed)
						'datamachine-pipelines-react' => array(
							'file'      => 'inc/Core/Admin/assets/build/pipelines-react.js',
							'deps'      => array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data', 'wp-dom-ready', 'wp-notices', 'wp-hooks' ),
							'in_footer' => true,
							'localize'  => array(
								'object' => 'dataMachineConfig',
								'data'   => array(
									'restNamespace' => 'datamachine/v1',
									'restNonce'     => wp_create_nonce( 'wp_rest' ),
									'maxUploadSize' => wp_max_upload_size(),
									// stepTypes: Loaded via REST API in PipelineContext
									// handlers: Loaded via REST API in PipelineContext
									// aiProviders: Loaded via REST API in ConfigureStepModal
									// aiTools: Loaded via REST API in AIToolsSelector
									// handlerSettings: Lazy-loaded via REST API in HandlerSettingsModal
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

/**
 * Get AI providers formatted for React
 *
 * @return array AI providers with models
 */
function datamachine_get_ai_providers_for_react() {
	try {
		// Use AI HTTP Client library's filters directly
		$library_providers = apply_filters( 'chubes_ai_providers', array() );

		$providers = array();
		foreach ( $library_providers as $key => $provider_info ) {
			// Get models for this provider via filter
			$models = apply_filters( 'chubes_ai_models', $key );

			$providers[ $key ] = array(
				'label'  => $provider_info['name'] ?? ucfirst( $key ),
				'models' => $models,
			);
		}

		return $providers;
	} catch ( \Exception $e ) {
		do_action(
			'datamachine_log',
			'error',
			'Failed to get AI providers for React',
			array(
				'error'     => $e->getMessage(),
				'exception' => $e,
			)
		);
		return array();
	}
}

/**
 * Get AI tools formatted for React.
 *
 * Returns only global (non-handler) tools from the unified `datamachine_tools`
 * registry. Handler tools are excluded because their definitions are shaped
 * by runtime handler config and are only meaningful inside a pipeline
 * execution context.
 *
 * @return array AI tools with configuration status
 */
function datamachine_get_ai_tools_for_react() {
	$tool_manager = new \DataMachine\Engine\AI\Tools\ToolManager();
	$all_tools    = $tool_manager->get_all_tools();

	// Filter to only global tools — exclude both per-handler tools (carry
	// a 'handler' link) and handler tool registry wrappers.
	$global_tools = array_filter(
		$all_tools,
		function ( $tool_def ) {
			if ( ! is_array( $tool_def ) ) {
				return false;
			}
			if ( isset( $tool_def['_handler_callable'] ) ) {
				return false;
			}
			return ! isset( $tool_def['handler'] );
		}
	);

	$tools = array();
	foreach ( $global_tools as $tool_id => $tool_def ) {
		$tools[ $tool_id ] = array(
			'label'       => $tool_def['label'] ?? ucfirst( str_replace( '_', ' ', $tool_id ) ),
			'description' => $tool_def['description'] ?? '',
			'configured'  => apply_filters( 'datamachine_tool_configured', false, $tool_id ),
		);
	}

	return $tools;
}

// Auto-register when file loads - achieving complete self-containment
add_action( 'init', __NAMESPACE__ . '\\datamachine_register_pipelines_admin_page_filters' );
