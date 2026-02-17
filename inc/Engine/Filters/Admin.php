<?php
/**
 * Admin filter registration and discovery hub.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Register admin interface filters for Data Machine.
 *
 * Registers filters for admin page discovery, menu management, and UI components.
 *
 * @since 0.1.0
 */
function datamachine_register_admin_filters() {

	// One-time cleanup of deprecated options
	if ( get_option( 'datamachine_page_hook_suffixes' ) ) {
		delete_option( 'datamachine_page_hook_suffixes' );
		delete_option( 'datamachine_page_configs' );
	}

	// Template rendering with dynamic discovery
	add_filter(
		'datamachine_render_template',
		function ( $content, $template_name, $data = array() ) {
			// Template discovery and rendering
			// Dynamic discovery of all registered admin pages and their template directories
			$all_pages = apply_filters( 'datamachine_admin_pages', array() );

			foreach ( $all_pages as $slug => $page_config ) {
				if ( ! empty( $page_config['templates'] ) ) {
					$template_path = $page_config['templates'] . $template_name . '.php';
					if ( file_exists( $template_path ) ) {
						ob_start();
						include $template_path;
						return ob_get_clean();
					}
				}
			}

			return '';
		},
		10,
		3
	);

	// WordPress-native admin hook registration
	add_action( 'admin_menu', 'datamachine_register_admin_menu' );
	add_action( 'admin_enqueue_scripts', 'datamachine_enqueue_admin_assets' );
}



/**
 * Register admin menu and pages for Data Machine.
 *
 * Creates WordPress admin menu structure and registers pages based on enabled settings.
 * Handles Engine Mode restrictions and page ordering.
 *
 * @since 0.1.0
 */
function datamachine_register_admin_menu() {
	// Get all registered admin pages
	$registered_pages = apply_filters( 'datamachine_admin_pages', array() );

	// Only create Data Machine menu if pages are available and not in Engine Mode
	if ( empty( $registered_pages ) ) {
		return;
	}

	// Sort pages by position, then alphabetically by menu_title
	uasort(
		$registered_pages,
		function ( $a, $b ) {
			$pos_a = $a['position'] ?? 50; // Default position 50
			$pos_b = $b['position'] ?? 50;

			if ( $pos_a === $pos_b ) {
				// Same position - sort alphabetically by menu_title
				$title_a = $a['menu_title'] ?? $a['page_title'] ?? '';
				$title_b = $b['menu_title'] ?? $b['page_title'] ?? '';
				return strcasecmp( $title_a, $title_b );
			}

			return $pos_a <=> $pos_b;
		}
	);

	// Use first sorted page as top-level menu
	$first_page = reset( $registered_pages );
	$first_slug = key( $registered_pages );

	add_menu_page(
		'Data Machine',
		'Data Machine',
		$first_page['capability'] ?? 'manage_options',
		'datamachine-' . $first_slug,
		'', // No callback - main menu is just container
		'dashicons-database-view',
		30
	);

	// Add first page as submenu with its proper title
	add_submenu_page(
		'datamachine-' . $first_slug,
		$first_page['page_title'] ?? $first_page['menu_title'] ?? ucfirst( $first_slug ),
		$first_page['menu_title'] ?? ucfirst( $first_slug ),
		$first_page['capability'] ?? 'manage_options',
		'datamachine-' . $first_slug,
		function () use ( $first_page, $first_slug ) {
			datamachine_render_admin_page_content( $first_page, $first_slug );
		}
	);

	// Add remaining pages as submenus (already sorted)
	$remaining_pages = array_slice( $registered_pages, 1, null, true );
	foreach ( $remaining_pages as $slug => $page_config ) {
		add_submenu_page(
			'datamachine-' . $first_slug,
			$page_config['page_title'] ?? $page_config['menu_title'] ?? ucfirst( $slug ),
			$page_config['menu_title'] ?? ucfirst( $slug ),
			$page_config['capability'] ?? 'manage_options',
			'datamachine-' . $slug,
			function () use ( $page_config, $slug ) {
				datamachine_render_admin_page_content( $page_config, $slug );
			}
		);
	}
}

/**
 * Get enabled admin pages based on settings.
 */
function datamachine_get_enabled_admin_pages() {
	$settings = \DataMachine\Core\PluginSettings::all();

	$all_pages = apply_filters( 'datamachine_admin_pages', array() );

	if ( empty( $settings['enabled_pages'] ) ) {
		return $all_pages;
	}

	$enabled_keys = array_keys( array_filter( $settings['enabled_pages'] ) );
	return array_intersect_key( $all_pages, array_flip( $enabled_keys ) );
}

/**
 * Get enabled general AI tools (non-handler-specific).
 */
function datamachine_get_enabled_global_tools() {
	$settings  = \DataMachine\Core\PluginSettings::all();
	$all_tools = apply_filters( 'chubes_ai_tools', array() );

	$global_tools = array_filter(
		$all_tools,
		function ( $tool_config ) {
			return ! isset( $tool_config['handler'] );
		}
	);

	if ( empty( $settings['disabled_tools'] ) ) {
		return $global_tools;
	}

	return array_diff_key( $global_tools, array_filter( $settings['disabled_tools'] ) );
}

/**
 * Render admin page content using unified configuration.
 *
 * @param array  $page_config Page configuration
 * @param string $page_slug Page slug
 */
function datamachine_render_admin_page_content( $page_config, $page_slug ) {
	// Direct template rendering using standardized template name pattern
	$content = apply_filters(
		'datamachine_render_template',
		'',
		"page/{$page_slug}-page",
		array(
			'page_slug'   => $page_slug,
			'page_config' => $page_config,
		)
	);

	if ( ! empty( $content ) ) {
		echo wp_kses( $content, datamachine_allowed_html() );
	} else {
		// Default empty state
		echo '<div class="wrap"><h1>' . esc_html( $page_config['page_title'] ?? ucfirst( $page_slug ) ) . '</h1>';
		echo '<p>' . esc_html( 'Page content not configured.' ) . '</p></div>';
	}
}

/**
 * Get allowed HTML for admin template content
 *
 * Returns comprehensive allowed HTML array for WordPress admin templates
 * including form elements and data attributes needed for admin interfaces.
 *
 * @return array Allowed HTML tags and attributes
 */
function datamachine_allowed_html(): array {
	// Start with WordPress post allowed HTML as base
	$allowed_html = wp_kses_allowed_html( 'post' );

	// Add essential admin form elements
	$allowed_html['form'] = array(
		'action'     => array(),
		'method'     => array(),
		'class'      => array(),
		'id'         => array(),
		'enctype'    => array(),
		'novalidate' => array(),
	);

	$allowed_html['input'] = array(
		'type'        => array(),
		'name'        => array(),
		'value'       => array(),
		'class'       => array(),
		'id'          => array(),
		'checked'     => array(),
		'disabled'    => array(),
		'readonly'    => array(),
		'placeholder' => array(),
		'required'    => array(),
		'min'         => array(),
		'max'         => array(),
		'step'        => array(),
		'data-*'      => true,
	);

	$allowed_html['select'] = array(
		'name'     => array(),
		'class'    => array(),
		'id'       => array(),
		'multiple' => array(),
		'required' => array(),
		'disabled' => array(),
		'data-*'   => true,
	);

	$allowed_html['option'] = array(
		'value'    => array(),
		'selected' => array(),
		'disabled' => array(),
	);

	$allowed_html['textarea'] = array(
		'name'        => array(),
		'class'       => array(),
		'id'          => array(),
		'rows'        => array(),
		'cols'        => array(),
		'placeholder' => array(),
		'required'    => array(),
		'readonly'    => array(),
		'disabled'    => array(),
		'data-*'      => true,
	);

	$allowed_html['button'] = array(
		'type'     => array(),
		'class'    => array(),
		'id'       => array(),
		'disabled' => array(),
		'name'     => array(),
		'value'    => array(),
		'data-*'   => true,
	);

	$allowed_html['label'] = array(
		'for'   => array(),
		'class' => array(),
		'id'    => array(),
	);

	// Add fieldset and legend for form grouping
	$allowed_html['fieldset'] = array(
		'class'    => array(),
		'id'       => array(),
		'disabled' => array(),
	);

	$allowed_html['legend'] = array(
		'class' => array(),
		'id'    => array(),
	);

	return $allowed_html;
}

function datamachine_enqueue_admin_assets( $hook_suffix ) {
	// Extract page slug from hook suffix
	// Pattern: datamachine-{slug} (handles both toplevel and submenu)
	if ( ! preg_match( '/datamachine-([a-z0-9_-]+)$/', $hook_suffix, $matches ) ) {
		return; // Not a Data Machine page
	}

	$page_slug = $matches[1];

	// Get fresh page configs (filter applied fresh = nonces created fresh)
	$registered_pages = apply_filters( 'datamachine_admin_pages', array() );

	if ( ! isset( $registered_pages[ $page_slug ] ) ) {
		return; // Page not registered
	}

	$page_config = $registered_pages[ $page_slug ];
	$page_assets = $page_config['assets'] ?? array();

	if ( ! empty( $page_assets['css'] ) || ! empty( $page_assets['js'] ) ) {
		datamachine_enqueue_page_assets( $page_assets, $page_slug );
	}
}

function datamachine_enqueue_page_assets( $assets, $page_slug ) {
	$plugin_base_path = DATAMACHINE_PATH;
	$plugin_base_url  = DATAMACHINE_URL;
	$version          = DATAMACHINE_VERSION;

	// Enqueue CSS files
	if ( ! empty( $assets['css'] ) ) {
		foreach ( $assets['css'] as $handle => $css_config ) {
			// WordPress core styles: null/empty file = just enqueue existing WP registration
			if ( empty( $css_config['file'] ) ) {
				wp_enqueue_style( $handle );
				continue;
			}

			$css_path    = $plugin_base_path . $css_config['file'];
			$css_url     = $plugin_base_url . $css_config['file'];
			$css_version = file_exists( $css_path ) ? filemtime( $css_path ) : $version;

			wp_enqueue_style(
				$handle,
				$css_url,
				$css_config['deps'] ?? array(),
				$css_version,
				$css_config['media'] ?? 'all'
			);
		}
	}

	// Enqueue JS files
	if ( ! empty( $assets['js'] ) ) {
		foreach ( $assets['js'] as $handle => $js_config ) {
			$js_path    = $plugin_base_path . $js_config['file'];
			$js_url     = $plugin_base_url . $js_config['file'];
			$js_version = file_exists( $js_path ) ? filemtime( $js_path ) : $version;

			wp_enqueue_script(
				$handle,
				$js_url,
				$js_config['deps'] ?? array(),
				$js_version,
				$js_config['in_footer'] ?? true
			);

			// Add localization if provided
			if ( ! empty( $js_config['localize'] ) ) {
				wp_add_inline_script(
					$handle,
					'window.' . $js_config['localize']['object'] . ' = ' . wp_json_encode( $js_config['localize']['data'] ) . ';',
					'before'
				);
			}
		}
	}
}

/**
 * Global system prompt injection moved to modular directive system
 *
 * All AI directive handling is now centralized in modular directive classes:
 * GlobalSystemPromptDirective, PipelineSystemPromptDirective, ToolDefinitionsDirective,
 * and SiteContextDirective.
 */
