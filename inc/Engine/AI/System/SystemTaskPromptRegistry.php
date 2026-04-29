<?php
/**
 * AI system task prompt artifact registry.
 *
 * @package DataMachine\Engine\AI\System
 */

namespace DataMachine\Engine\AI\System;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\Bundle\PromptArtifact;

/**
 * Resolves versioned prompt artifacts for AI-backed system tasks.
 */
final class SystemTaskPromptRegistry {

	/**
	 * Build the stable artifact ID for a system task prompt.
	 */
	public static function artifact_id( string $task_type, string $prompt_key ): string {
		return 'system-task:' . sanitize_key( $task_type ) . ':' . sanitize_key( $prompt_key );
	}

	/**
	 * Build the default artifact for an existing SystemTask prompt definition.
	 *
	 * @param  string $task_type  Task slug.
	 * @param  string $prompt_key Prompt key.
	 * @param  array  $definition Existing getPromptDefinitions() row.
	 * @return PromptArtifact|null
	 */
	public static function artifact_from_definition( string $task_type, string $prompt_key, array $definition ): ?PromptArtifact {
		if ( empty( $definition['default'] ) || ! is_string( $definition['default'] ) ) {
			return null;
		}

		$artifact_id = (string) ( $definition['artifact_id'] ?? self::artifact_id( $task_type, $prompt_key ) );
		$version     = (string) ( $definition['version'] ?? 'builtin' );
		$source_path = (string) ( $definition['source_path'] ?? 'system-tasks/' . sanitize_key( $task_type ) . '/' . sanitize_key( $prompt_key ) . '.md' );
		$metadata    = array(
			'task_type'  => $task_type,
			'prompt_key' => $prompt_key,
			'label'      => (string) ( $definition['label'] ?? '' ),
			'variables'  => $definition['variables'] ?? array(),
		);

		return new PromptArtifact( $artifact_id, PromptArtifact::TYPE_PROMPT, $version, $source_path, $definition['default'], (string) ( $definition['changelog'] ?? '' ), $metadata );
	}

	/**
	 * Resolve an effective task prompt through the artifact seam.
	 *
	 * @param  string              $task_type  Task slug.
	 * @param  string              $prompt_key Prompt key.
	 * @param  PromptArtifact|null $artifact   Default prompt artifact.
	 * @param  string|null         $override   Local override, if any.
	 * @return array{content:string, artifact_id:string, content_hash:string, source:string, version:string, artifact:?PromptArtifact}
	 */
	public static function resolve_effective_prompt( string $task_type, string $prompt_key, ?PromptArtifact $artifact, ?string $override = null ): array {
		$override = null === $override ? null : (string) $override;
		$content  = null !== $override && '' !== $override ? $override : ( $artifact ? $artifact->content() : '' );
		$source   = null !== $override && '' !== $override ? 'override' : 'artifact';

		$resolved = array(
			'content'      => $content,
			'artifact_id'  => $artifact ? $artifact->artifact_id() : self::artifact_id( $task_type, $prompt_key ),
			'content_hash' => hash( 'sha256', $content ),
			'source'       => $source,
			'version'      => $artifact ? $artifact->version() : '',
			'artifact'     => $artifact,
		);

		/**
		 * Filter effective AI system task prompt resolution.
		 *
		 * Consumers can return a different content/source envelope to wire in
		 * site-owned prompt stores without overriding SystemTask classes.
		 *
		 * @param array               $resolved Effective prompt envelope.
		 * @param string              $task_type Task slug.
		 * @param string              $prompt_key Prompt key.
		 * @param PromptArtifact|null $artifact Default prompt artifact.
		 * @param string|null         $override Local override content.
		 */
		$resolved = apply_filters( 'datamachine_system_task_effective_prompt', $resolved, $task_type, $prompt_key, $artifact, $override );

		$final_content = isset( $resolved['content'] ) ? (string) $resolved['content'] : $content;
		$initial_hash  = hash( 'sha256', $content );
		$content_hash  = isset( $resolved['content_hash'] ) ? (string) $resolved['content_hash'] : hash( 'sha256', $final_content );
		if ( $final_content !== $content && $content_hash === $initial_hash ) {
			$content_hash = hash( 'sha256', $final_content );
		}

		return array(
			'content'      => $final_content,
			'artifact_id'  => isset( $resolved['artifact_id'] ) ? (string) $resolved['artifact_id'] : ( $artifact ? $artifact->artifact_id() : self::artifact_id( $task_type, $prompt_key ) ),
			'content_hash' => $content_hash,
			'source'       => isset( $resolved['source'] ) ? (string) $resolved['source'] : $source,
			'version'      => isset( $resolved['version'] ) ? (string) $resolved['version'] : ( $artifact ? $artifact->version() : '' ),
			'artifact'     => $resolved['artifact'] ?? $artifact,
		);
	}
}
