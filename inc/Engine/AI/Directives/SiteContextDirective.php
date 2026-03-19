<?php
/**
 * Site Context Directive — DEPRECATED.
 *
 * This directive previously injected a JSON blob of WordPress site metadata
 * at priority 80. Site context is now provided entirely by SITE.md via the
 * CoreMemoryFilesDirective at priority 20.
 *
 * This class remains as a no-op for backward compatibility with code that
 * references the class name (e.g., datamachine_site_context_directive filter
 * consumers). It will be removed in a future major version.
 *
 * @package DataMachine\Engine\AI\Directives
 * @since   0.30.0
 * @deprecated 0.48.0 Site context is now provided by SITE.md. This directive is a no-op.
 */

namespace DataMachine\Engine\AI\Directives;

defined( 'ABSPATH' ) || exit;

class SiteContextDirective implements DirectiveInterface {

	/**
	 * Returns empty outputs — site context is now in SITE.md.
	 *
	 * @deprecated 0.48.0
	 *
	 * @param string      $provider_name AI provider name.
	 * @param array       $tools         Available tools.
	 * @param string|null $step_id       Pipeline step ID.
	 * @param array       $payload       Additional payload data.
	 * @return array Always returns empty array.
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		return array();
	}

	/**
	 * Check if site context injection is enabled.
	 *
	 * @deprecated 0.48.0 Use the site_context_enabled setting directly. Controls SITE.md auto-refresh.
	 * @return bool
	 */
	public static function is_site_context_enabled(): bool {
		return \DataMachine\Core\PluginSettings::get( 'site_context_enabled', true );
	}
}

// The directive is no longer registered in the directive system.
// The class exists purely for backward compatibility with code that
// references it by name (e.g., datamachine_site_context_directive filter).
