<?php
/**
 * PendingAction integration for memory section changes.
 *
 * @package DataMachine\Engine\AI\Memory
 */

namespace DataMachine\Engine\AI\Memory;

use DataMachine\Core\FilesRepository\AgentMemory;
use DataMachine\Engine\AI\Actions\PendingActionHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Stages and applies memory section updates through the generic resolver.
 */
final class MemorySectionPendingAction {

	public const KIND = 'memory_section_update';

	public static function register(): void {
		add_filter(
			'datamachine_pending_action_handlers',
			static function ( array $handlers ): array {
				$handlers[ self::KIND ] = array(
					'apply' => array( self::class, 'apply' ),
				);
				return $handlers;
			}
		);
	}

	public static function stage( array $args ): array {
		$agent_id = absint( $args['agent_id'] ?? 0 );
		$user_id  = absint( $args['user_id'] ?? 0 );
		$file     = (string) ( $args['file'] ?? 'MEMORY.md' );
		$section  = (string) ( $args['section'] ?? '' );
		$content  = (string) ( $args['content'] ?? '' );
		$mode     = self::mode( (string) ( $args['mode'] ?? 'append' ) );
		$reason   = (string) ( $args['reason'] ?? '' );

		$memory  = new AgentMemory( $user_id, $agent_id, $file );
		$current = $memory->get_section( $section );
		$current_content = ! empty( $current['success'] ) ? (string) $current['content'] : '';
		$proposed        = 'append' === $mode && '' !== $current_content
			? rtrim( $current_content ) . "\n" . $content
			: $content;

		return PendingActionHelper::stage(
			array(
				'kind'         => self::KIND,
				'summary'      => sprintf( 'Update %s section "%s" for agent %d.', $file, $section, $agent_id ),
				'agent_id'     => $agent_id,
				'user_id'      => $user_id,
				'apply_input'  => array(
					'agent_id'     => $agent_id,
					'user_id'      => $user_id,
					'file'         => $file,
					'section'      => $section,
					'section_type' => (string) ( $args['section_type'] ?? '' ),
					'content'      => $content,
					'mode'         => $mode,
					'reason'       => $reason,
				),
				'preview_data' => array(
					'target_agent'     => $agent_id,
					'file'             => $file,
					'section'          => $section,
					'section_type'     => (string) ( $args['section_type'] ?? '' ),
					'source'           => (string) ( $args['source'] ?? 'runtime_agent' ),
					'reason'           => $reason,
					'current_content'  => self::redact( $current_content ),
					'proposed_content' => self::redact( $proposed ),
					'diff'             => self::redacted_diff( $current_content, $proposed ),
				),
			)
		);
	}

	public static function apply( array $input ): array {
		$memory  = new AgentMemory( absint( $input['user_id'] ?? 0 ), absint( $input['agent_id'] ?? 0 ), (string) ( $input['file'] ?? 'MEMORY.md' ) );
		$section = (string) ( $input['section'] ?? '' );
		$content = (string) ( $input['content'] ?? '' );
		$mode    = self::mode( (string) ( $input['mode'] ?? 'append' ) );

		if ( '' === trim( $section ) ) {
			return array(
				'success' => false,
				'error'   => 'section is required.',
			);
		}

		$result = 'set' === $mode ? $memory->set_section( $section, $content ) : $memory->append_to_section( $section, $content );

		return array(
			'success' => ! empty( $result['success'] ),
			'message' => $result['message'] ?? '',
			'file'    => (string) ( $input['file'] ?? 'MEMORY.md' ),
			'section' => $section,
		);
	}

	private static function mode( string $mode ): string {
		return 'set' === $mode ? 'set' : 'append';
	}

	private static function redacted_diff( string $current, string $proposed ): string {
		$current_lines  = explode( "\n", self::redact( $current ) );
		$proposed_lines = explode( "\n", self::redact( $proposed ) );
		$lines          = array();

		foreach ( $current_lines as $line ) {
			if ( '' !== $line ) {
				$lines[] = '- ' . $line;
			}
		}
		foreach ( $proposed_lines as $line ) {
			if ( '' !== $line ) {
				$lines[] = '+ ' . $line;
			}
		}

		return implode( "\n", array_slice( $lines, 0, 80 ) );
	}

	private static function redact( string $content ): string {
		$redacted = preg_replace( '/\b(api[_-]?key|token|secret|password)\b\s*[:=]\s*\S+/i', '$1: [redacted]', $content );
		$redacted = preg_replace( '/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redacted]', $redacted ?? $content );

		return $redacted ?? $content;
	}
}
