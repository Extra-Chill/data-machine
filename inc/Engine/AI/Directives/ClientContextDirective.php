<?php
/**
 * Client Context Directive.
 *
 * Renders client-side context data as a system message so the AI agent
 * knows what the user is currently doing in the application. Context is
 * provided by the frontend via the `client_context` parameter on the
 * chat REST endpoint.
 *
 * Examples of client context:
 *   - { "tab": "compose", "post_id": 123, "post_title": "My Draft" }
 *   - { "screen": "socials", "platform": "instagram" }
 *   - { "page": "forum", "topic_id": 42 }
 *
 * The directive formats the context as a readable system message and
 * injects it after the core memory files (priority 35).
 *
 * @package DataMachine\Engine\AI\Directives
 */

namespace DataMachine\Engine\AI\Directives;

class ClientContextDirective {

	/**
	 * Produce directive outputs from client context.
	 *
	 * @param string      $provider_name AI provider identifier.
	 * @param array       $tools         Available tools.
	 * @param string|null $step_id       Pipeline step ID (null in chat).
	 * @param array       $payload       Request payload including client_context.
	 * @return array Directive outputs (system_text entries).
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$client_context = $payload['client_context'] ?? array();

		if ( empty( $client_context ) || ! is_array( $client_context ) ) {
			return array();
		}

		$lines = array();
		foreach ( $client_context as $key => $value ) {
			$label = str_replace( '_', ' ', $key );
			if ( is_array( $value ) || is_object( $value ) ) {
				$lines[] = sprintf( '- %s: %s', $label, wp_json_encode( $value ) );
			} else {
				$lines[] = sprintf( '- %s: %s', $label, (string) $value );
			}
		}

		if ( empty( $lines ) ) {
			return array();
		}

		$content = "# Current Client Context\n\n"
			. "The user is interacting with you from a frontend interface. "
			. "Here is their current context:\n\n"
			. implode( "\n", $lines );

		return array(
			array(
				'type'    => 'system_text',
				'content' => $content,
			),
		);
	}
}

// Self-register in the directive system.
// Priority 35 = after core memory files (20-30), before pipeline directives (45).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => ClientContextDirective::class,
			'priority' => 35,
			'contexts' => array( 'all' ),
		);
		return $directives;
	}
);
