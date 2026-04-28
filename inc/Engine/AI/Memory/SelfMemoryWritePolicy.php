<?php
/**
 * Policy gate for self-scoped operational memory writes.
 *
 * @package DataMachine\Engine\AI\Memory
 */

namespace DataMachine\Engine\AI\Memory;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\AgentMemory;

defined( 'ABSPATH' ) || exit;

/**
 * Constrains agents to writing their own operational memory sections.
 */
final class SelfMemoryWritePolicy {

	private const DEFAULT_ALLOWED_SECTION_TYPES = array(
		'operating_note',
		'source_quirk',
		'run_lesson',
		'task_note',
	);

	private const DURABLE_FACT_TYPES = array(
		'fact',
		'domain_fact',
		'wiki_fact',
		'knowledge',
	);

	public static function execute( array $input ): array {
		$current_agent_id = PermissionHelper::get_acting_agent_id();
		if ( null === $current_agent_id ) {
			return self::deny( 'Self-memory writes require an active agent context.', 'missing_agent_context' );
		}

		$target_agent_id = absint( $input['agent_id'] ?? $current_agent_id );
		if ( $target_agent_id !== $current_agent_id && ! self::delegated_cross_agent_write_allowed( $current_agent_id, $target_agent_id, $input ) ) {
			return self::deny( 'Self-memory writes cannot target another agent without explicit delegation.', 'cross_agent_denied' );
		}

		$section_type = sanitize_key( (string) ( $input['section_type'] ?? 'operating_note' ) );
		if ( in_array( $section_type, self::DURABLE_FACT_TYPES, true ) ) {
			return self::deny( 'Durable domain facts belong in wiki/graph tools, not operational memory.', 'durable_fact_denied' );
		}

		$allowed = apply_filters( 'datamachine_self_memory_allowed_section_types', self::DEFAULT_ALLOWED_SECTION_TYPES, $current_agent_id, $input );
		$allowed = is_array( $allowed ) ? array_map( 'sanitize_key', $allowed ) : self::DEFAULT_ALLOWED_SECTION_TYPES;
		if ( ! in_array( $section_type, $allowed, true ) ) {
			return self::deny( sprintf( 'Section type "%s" is not allowed for self-memory writes.', $section_type ), 'section_type_denied' );
		}

		$file    = (string) ( $input['file'] ?? 'MEMORY.md' );
		$section = trim( (string) ( $input['section'] ?? '' ) );
		$content = (string) ( $input['content'] ?? '' );
		$mode    = 'set' === ( $input['mode'] ?? 'append' ) ? 'set' : 'append';

		if ( '' === $section || '' === trim( $content ) ) {
			return self::deny( 'section and content are required.', 'missing_required_input' );
		}

		$artifact = self::memory_section_artifact( $target_agent_id, $file, $section, $section_type, $input );
		if ( $artifact && $artifact->is_bundle_owned() ) {
			return MemorySectionPendingAction::stage( self::stage_args( $input, $target_agent_id, $file, $section, $section_type, $content, $mode, 'bundle_upgrade' ) );
		}

		$sensitive_types = apply_filters( 'datamachine_self_memory_sensitive_section_types', array(), $current_agent_id, $input );
		$sensitive_types = is_array( $sensitive_types ) ? array_map( 'sanitize_key', $sensitive_types ) : array();
		if ( ! empty( $input['requires_approval'] ) || in_array( $section_type, $sensitive_types, true ) ) {
			return MemorySectionPendingAction::stage( self::stage_args( $input, $target_agent_id, $file, $section, $section_type, $content, $mode, 'runtime_agent' ) );
		}

		$memory = new AgentMemory( PermissionHelper::acting_user_id(), $target_agent_id, $file );
		$result = 'set' === $mode ? $memory->set_section( $section, $content ) : $memory->append_to_section( $section, $content );

		return array_merge(
			$result,
			array(
				'agent_id'     => $target_agent_id,
				'file'         => $file,
				'section'      => $section,
				'section_type' => $section_type,
				'owner'        => MemorySectionArtifact::OWNER_RUNTIME,
			)
		);
	}

	private static function memory_section_artifact( int $agent_id, string $file, string $section, string $section_type, array $input ): ?MemorySectionArtifact {
		$artifact = apply_filters(
			'datamachine_memory_section_artifact',
			null,
			array(
				'agent_id'     => $agent_id,
				'file'         => $file,
				'section'      => $section,
				'section_type' => $section_type,
				'input'        => $input,
			)
		);

		if ( $artifact instanceof MemorySectionArtifact ) {
			return $artifact;
		}

		return is_array( $artifact ) ? MemorySectionArtifact::from_array( $artifact ) : null;
	}

	private static function delegated_cross_agent_write_allowed( int $current_agent_id, int $target_agent_id, array $input ): bool {
		return true === apply_filters( 'datamachine_self_memory_allow_cross_agent_write', false, $current_agent_id, $target_agent_id, $input );
	}

	private static function stage_args( array $input, int $agent_id, string $file, string $section, string $section_type, string $content, string $mode, string $source ): array {
		return array(
			'agent_id'     => $agent_id,
			'user_id'      => PermissionHelper::acting_user_id(),
			'file'         => $file,
			'section'      => $section,
			'section_type' => $section_type,
			'content'      => $content,
			'mode'         => $mode,
			'reason'       => (string) ( $input['reason'] ?? '' ),
			'source'       => $source,
		);
	}

	private static function deny( string $message, string $code ): array {
		return array(
			'success'    => false,
			'error'      => $message,
			'error_code' => $code,
		);
	}
}
