<?php
/**
 * Directive Renderer
 *
 * Converts validated directive outputs into provider-agnostic system messages.
 *
 * @package DataMachine\Engine\AI\Directives
 */

namespace DataMachine\Engine\AI\Directives;

use AgentsAPI\AI\AgentMessageEnvelope;

defined( 'ABSPATH' ) || exit;

class DirectiveRenderer {

	public static function renderMessages( array $validated_outputs ): array {
		$messages = array();

		foreach ( $validated_outputs as $output ) {
			$type = $output['type'] ?? '';

			if ( 'system_text' === $type ) {
				$messages[] = AgentMessageEnvelope::text( 'system', $output['content'] );
				continue;
			}

			if ( 'system_json' === $type ) {
				$label = $output['label'];
				$data  = $output['data'];

				$messages[] = AgentMessageEnvelope::text( 'system', $label . ":\n\n" . wp_json_encode( $data, JSON_PRETTY_PRINT ) );
				continue;
			}

			if ( 'system_file' === $type ) {
				$messages[] = AgentMessageEnvelope::text(
					'system',
					array(
						array(
							'type'      => 'file',
							'file_path' => $output['file_path'],
							'mime_type' => $output['mime_type'],
						),
					)
				);
				continue;
			}
		}

		return $messages;
	}
}
