<?php
/**
 * Cross-Site Handoff Directive.
 *
 * Chat sessions are network-wide: a user's conversation follows them across
 * every subsite. When a session is resumed on a different site than the one it
 * was last active on, the stored transcript can reference a site the user is no
 * longer on. This directive injects a lightweight system note for that single
 * turn so the agent reconciles the move instead of getting whiplash — e.g.
 * "This conversation was last active on <host A>; you are now on <host B>."
 *
 * The note is driven entirely by the request-local `cross_site_handoff` payload
 * (previous host vs. current host), which the chat orchestrator resolves at
 * runtime. Hosts are derived from live request state, never hardcoded — this
 * directive stays generic and carries no platform or vendor names.
 *
 * Priority 36 = right after ClientContextDirective (35), so the "where you are
 * now" context and the "you just moved" note read together.
 *
 * @package DataMachine\Engine\AI\Directives
 */

namespace DataMachine\Engine\AI\Directives;

defined( 'ABSPATH' ) || exit;

class CrossSiteHandoffDirective {

	/**
	 * Produce a handoff note when the current host differs from the last-seen host.
	 *
	 * @param string      $provider_name AI provider identifier.
	 * @param array       $tools         Available tools.
	 * @param string|null $step_id       Pipeline step ID (null in chat).
	 * @param array       $payload       Request payload including cross_site_handoff.
	 * @return array Directive outputs (system_text entries).
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$handoff = $payload['cross_site_handoff'] ?? array();

		if ( ! is_array( $handoff ) ) {
			return array();
		}

		$previous_host = self::normalize_host( $handoff['previous_host'] ?? '' );
		$current_host  = self::normalize_host( $handoff['current_host'] ?? '' );

		// Nothing to reconcile unless we know both hosts and they actually differ.
		if ( '' === $previous_host || '' === $current_host || $previous_host === $current_host ) {
			return array();
		}

		$note = sprintf(
			'This conversation was last active on %1$s; the user is now on %2$s. '
				. 'Treat %2$s as the site they are currently on: resolve page, repo, and capability context from the current request, not from where the transcript started. '
				. 'If earlier turns reference %1$s, reconcile that the user has since moved sites rather than assuming they are still there.',
			$previous_host,
			$current_host
		);

		$content = "# Cross-Site Handoff\n\n" . $note;

		$outputs = array(
			array(
				'type'    => 'system_text',
				'content' => $content,
			),
		);

		return function_exists( 'apply_filters' )
			? apply_filters( 'datamachine_cross_site_handoff_directive_outputs', $outputs, $handoff, $payload, $provider_name, $tools, $step_id )
			: $outputs;
	}

	/**
	 * Normalize a host value to a bare, comparable hostname.
	 *
	 * Accepts a full URL or a raw host and reduces it to the host component,
	 * lowercased and trimmed, so comparisons are stable regardless of scheme
	 * or trailing path.
	 *
	 * @param mixed $value Host or URL value.
	 * @return string Normalized host, or '' when not resolvable.
	 */
	private static function normalize_host( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$value = trim( $value );

		if ( false !== strpos( $value, '://' ) ) {
			$parsed = wp_parse_url( $value, PHP_URL_HOST );
			if ( is_string( $parsed ) && '' !== $parsed ) {
				$value = $parsed;
			}
		}

		return strtolower( $value );
	}
}

// Self-register in the directive system.
// Priority 36 = immediately after ClientContextDirective (35) so the live
// "current context" and the "you moved sites" note render together.
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$modes = array( 'chat' );
		if ( function_exists( 'apply_filters' ) ) {
			$modes = apply_filters( 'datamachine_cross_site_handoff_directive_modes', $modes );
		}

		$directives[] = array(
			'class'    => CrossSiteHandoffDirective::class,
			'priority' => 36,
			'modes'    => is_array( $modes ) ? $modes : array( 'chat' ),
		);
		return $directives;
	}
);
