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
	 * Render a scalar or structured value for directive output.
	 *
	 * @param mixed $value Value to stringify.
	 * @return string
	 */
	private static function render_value( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return (string) wp_json_encode( $value );
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return 'null';
		}

		return (string) $value;
	}

	/**
	 * Build explicit guidance for active editor context.
	 *
	 * @param array $client_context Full client context payload.
	 * @return string
	 */
	private static function build_editor_guidance( array $client_context ): string {
		$active_context = $client_context['active_context'] ?? null;

		if ( ! is_array( $active_context ) ) {
			return '';
		}

		$kind     = $active_context['kind'] ?? '';
		$surface  = $active_context['surface'] ?? '';
		$resource = $active_context['resource'] ?? null;

		if ( 'editor' !== $kind || ! is_array( $resource ) ) {
			return '';
		}

		$post_id    = isset( $resource['id'] ) ? (string) $resource['id'] : '';
		$post_title = isset( $resource['title'] ) ? (string) $resource['title'] : '';
		$post_type  = isset( $resource['postType'] ) ? (string) $resource['postType'] : '';
		$status     = isset( $resource['status'] ) ? (string) $resource['status'] : '';

		$lines   = array();
		$lines[] = '## Current Editor Context';
		$lines[] = '';
		$lines[] = 'Treat this editor context as the authoritative current item the user is working on.';
		$lines[] = 'If the user refers to "this post", "that post", "the post in the editor", or "the current draft", use this context first instead of asking them for a URL or post ID again.';
		$lines[] = 'Only ask for clarification if the editor context is missing, ambiguous, or the user explicitly asks about a different post.';
		$lines[] = '';
		$lines[] = sprintf( '- surface: %s', self::render_value( $surface ) );

		if ( '' !== $post_id ) {
			$lines[] = sprintf( '- post id: %s', $post_id );
		}

		if ( '' !== $post_type ) {
			$lines[] = sprintf( '- post type: %s', $post_type );
		}

		if ( '' !== $status ) {
			$lines[] = sprintf( '- status: %s', $status );
		}

		if ( '' !== $post_title ) {
			$lines[] = sprintf( '- title: %s', $post_title );
		}

		if ( ! empty( $active_context['content'] ) && is_array( $active_context['content'] ) ) {
			$content = $active_context['content'];
			if ( isset( $content['excerpt'] ) && '' !== (string) $content['excerpt'] ) {
				$lines[] = sprintf( '- content excerpt: %s', (string) $content['excerpt'] );
			}
			if ( isset( $content['characterCount'] ) ) {
				$lines[] = sprintf( '- character count: %s', self::render_value( $content['characterCount'] ) );
			}
		}

		$lines[] = '';
		$lines[] = 'Important: frontend editor context can describe the user\'s current in-browser draft state. Prefer it when answering questions about what is currently open in the editor.';

		return implode( "\n", $lines );
	}

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
			$label   = str_replace( '_', ' ', $key );
			$lines[] = sprintf( '- %s: %s', $label, self::render_value( $value ) );
		}

		if ( empty( $lines ) ) {
			return array();
		}

		$content = "# Current Client Context\n\n"
			. 'The user is interacting with you from a frontend interface. '
			. "Here is their current context:\n\n"
			. implode( "\n", $lines );

		$editor_guidance = self::build_editor_guidance( $client_context );
		if ( '' !== $editor_guidance ) {
			$content .= "\n\n" . $editor_guidance;
		}

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
