<?php
/**
 * Site Context Directive - Priority 80 (Lowest Priority)
 *
 * Injects WordPress site context information as the final directive in the
 * 5-tier AI directive system. Provides comprehensive site metadata including
 * posts, taxonomies, users, and configuration. Toggleable via settings.
 *
 * Priority Order in Directive System:
 * 1. Priority 10 - Plugin Core Directive
 * 2. Priority 20 - Core Memory Files (SOUL.md, USER.md, MEMORY.md, etc.)
 * 3. Priority 40 - Pipeline Memory Files (per-pipeline selectable)
 * 4. Priority 50 - Pipeline System Prompt
 * 5. Priority 60 - Pipeline Context Files
 * 6. Priority 70 - Tool Definitions and Workflow Context
 * 7. Priority 80 - WordPress Site Context (THIS CLASS)
 */

namespace DataMachine\Engine\AI\Directives;

use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\Directives\DirectiveInterface;

defined( 'ABSPATH' ) || exit;

class SiteContextDirective implements DirectiveInterface {

	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		if ( ! self::is_site_context_enabled() ) {
			return array();
		}

		$context_data = SiteContext::get_context();
		if ( empty( $context_data ) || ! is_array( $context_data ) ) {
			do_action( 'datamachine_log', 'warning', 'Site Context Directive: Empty context generated' );
			return array();
		}

		return array(
			array(
				'type'  => 'system_json',
				'label' => 'WORDPRESS SITE CONTEXT',
				'data'  => $context_data,
			),
		);
	}

	/**
	 * Check if site context injection is enabled in plugin settings.
	 *
	 * @return bool True if enabled, false otherwise
	 */
	public static function is_site_context_enabled(): bool {
		return PluginSettings::get( 'site_context_enabled', true );
	}
}

/**
 * Allow plugins to override the site context directive class.
 * datamachine-multisite uses this to replace single-site context with multisite context.
 *
 * @param string $directive_class The directive class to use for site context
 * @return string The filtered directive class
 */
$datamachine_site_context_directive = apply_filters( 'datamachine_site_context_directive', SiteContextDirective::class );

// Register the filtered directive for global context (applies to all AI agents - allows replacement by multisite plugin)
if ( $datamachine_site_context_directive ) {
	add_filter(
		'datamachine_directives',
		function ( $directives ) use ( $datamachine_site_context_directive ) {
			$directives[] = array(
				'class'       => $datamachine_site_context_directive,
				'priority'    => 80,
				'agent_types' => array( 'all' ),
			);
			return $directives;
		}
	);
}
