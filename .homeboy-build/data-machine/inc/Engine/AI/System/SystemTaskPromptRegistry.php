<?php
/**
 * AI system task prompt artifact registry.
 *
 * @package DataMachine\Engine\AI\System
 */

namespace DataMachine\Engine\AI\System;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\Bundle\PromptArtifact;
use DataMachine\Engine\Bundle\BundleSchema;
use DataMachine\Engine\Tasks\TaskRegistry;
use DataMachine\Engine\AI\System\Tasks\SystemTask;

/**
 * Resolves versioned prompt artifacts for AI-backed system tasks.
 */
final class SystemTaskPromptRegistry {

	/**
	 * Option key for bundle-installed system task prompt artifacts.
	 */
	private const INSTALLED_ARTIFACTS_OPTION = 'datamachine_system_task_prompt_artifacts';

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
	 * Collect default system task prompt artifacts from registered tasks.
	 *
	 * @return array<string,PromptArtifact> Artifact ID => artifact.
	 */
	public static function default_artifacts(): array {
		$artifacts = array();

		foreach ( TaskRegistry::getHandlers() as $task_type => $handler_class ) {
			if ( ! class_exists( $handler_class ) ) {
				continue;
			}

			$task = new $handler_class();
			if ( ! $task instanceof SystemTask ) {
				continue;
			}

			foreach ( $task->getPromptDefinitions() as $prompt_key => $definition ) {
				if ( ! is_array( $definition ) ) {
					continue;
				}

				$artifact = self::artifact_from_definition( (string) $task_type, (string) $prompt_key, $definition );
				if ( $artifact ) {
					$artifacts[ $artifact->artifact_id() ] = self::installed_artifact_for( $artifact ) ?? $artifact;
				}
			}
		}

		ksort( $artifacts, SORT_STRING );
		return $artifacts;
	}

	/**
	 * Build bundle prompt files for system task prompt artifacts.
	 *
	 * @return array<string,array<string,mixed>> Relative path under prompts/ => artifact payload.
	 */
	public static function bundle_prompt_files(): array {
		$files = array();
		foreach ( self::default_artifacts() as $artifact ) {
			$files[ self::bundle_relative_path( $artifact ) ] = $artifact->to_array();
		}

		ksort( $files, SORT_STRING );
		return $files;
	}

	/**
	 * Report current runtime artifact state for upgrade planning.
	 *
	 * Local overrides deliberately change the reported payload hash so upgrade
	 * planning treats them as locally modified instead of overwriting them.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function current_artifacts(): array {
		$artifacts = array();
		$overrides = SystemTask::getAllPromptOverrides();

		foreach ( self::default_artifacts() as $artifact ) {
			$metadata   = $artifact->version_metadata()['metadata'] ?? array();
			$task_type  = is_array( $metadata ) ? (string) ( $metadata['task_type'] ?? '' ) : '';
			$prompt_key = is_array( $metadata ) ? (string) ( $metadata['prompt_key'] ?? '' ) : '';
			$override   = $overrides[ $task_type ][ $prompt_key ] ?? null;

			$artifacts[] = array(
				'artifact_type' => PromptArtifact::TYPE_PROMPT,
				'artifact_id'   => $artifact->artifact_id(),
				'source_path'   => BundleSchema::PROMPTS_DIR . '/' . self::bundle_relative_path( $artifact ),
				'payload'       => self::payload_with_override( $artifact, is_string( $override ) ? $override : null ),
			);
		}

		return $artifacts;
	}

	/**
	 * Apply a bundle-carried system task prompt artifact without touching overrides.
	 *
	 * @param array<string,mixed> $artifact Artifact envelope.
	 * @return bool True when the artifact was accepted and stored.
	 */
	public static function apply_bundle_artifact( array $artifact ): bool {
		if ( PromptArtifact::TYPE_PROMPT !== (string) ( $artifact['artifact_type'] ?? '' ) ) {
			return false;
		}

		$payload = is_array( $artifact['payload'] ?? null ) ? $artifact['payload'] : array();
		if ( empty( $payload ) ) {
			return false;
		}

		$prompt_artifact = PromptArtifact::from_array( $payload );
		if ( ! str_starts_with( $prompt_artifact->artifact_id(), 'system-task:' ) ) {
			return false;
		}

		$installed                                    = self::installed_artifacts();
		$installed[ $prompt_artifact->artifact_id() ] = $prompt_artifact->to_array();

		return update_option( self::INSTALLED_ARTIFACTS_OPTION, $installed, false );
	}

	/**
	 * Whether applying the target would collide with a local prompt override.
	 */
	public static function has_local_override_for_artifact( array $artifact ): bool {
		$payload = is_array( $artifact['payload'] ?? null ) ? $artifact['payload'] : array();
		if ( empty( $payload ) ) {
			return false;
		}

		$metadata   = is_array( $payload['metadata'] ?? null ) ? $payload['metadata'] : array();
		$task_type  = (string) ( $metadata['task_type'] ?? '' );
		$prompt_key = (string) ( $metadata['prompt_key'] ?? '' );
		if ( '' === $task_type || '' === $prompt_key ) {
			return false;
		}

		$overrides = SystemTask::getAllPromptOverrides();
		return isset( $overrides[ $task_type ][ $prompt_key ] ) && '' !== (string) $overrides[ $task_type ][ $prompt_key ];
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
		$artifact = $artifact ? ( self::installed_artifact_for( $artifact ) ?? $artifact ) : null;
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

	private static function installed_artifact_for( PromptArtifact $default_artifact ): ?PromptArtifact {
		$installed = self::installed_artifacts();
		$payload   = $installed[ $default_artifact->artifact_id() ] ?? null;
		if ( ! is_array( $payload ) ) {
			return null;
		}

		try {
			$artifact = PromptArtifact::from_array( $payload );
		} catch ( \Throwable $e ) {
			return null;
		}

		return $artifact->artifact_id() === $default_artifact->artifact_id() ? $artifact : null;
	}

	/** @return array<string,array<string,mixed>> */
	private static function installed_artifacts(): array {
		$stored = get_option( self::INSTALLED_ARTIFACTS_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	private static function bundle_relative_path( PromptArtifact $artifact ): string {
		$metadata   = $artifact->version_metadata()['metadata'] ?? array();
		$task_type  = is_array( $metadata ) ? sanitize_key( (string) ( $metadata['task_type'] ?? '' ) ) : '';
		$prompt_key = is_array( $metadata ) ? sanitize_key( (string) ( $metadata['prompt_key'] ?? '' ) ) : '';
		if ( '' !== $task_type && '' !== $prompt_key ) {
			return 'system-tasks/' . $task_type . '/' . $prompt_key . '.json';
		}

		return 'system-tasks/' . sanitize_key( str_replace( ':', '-', $artifact->artifact_id() ) ) . '.json';
	}

	/** @return array<string,mixed> */
	private static function payload_with_override( PromptArtifact $artifact, ?string $override ): array {
		$payload = $artifact->to_array();
		if ( null === $override || '' === $override ) {
			return $payload;
		}

		$payload['content']                    = $override;
		$payload['content_hash']               = hash( 'sha256', $override );
		$payload['metadata']                   = is_array( $payload['metadata'] ?? null ) ? $payload['metadata'] : array();
		$payload['metadata']['local_override'] = true;

		return $payload;
	}
}
