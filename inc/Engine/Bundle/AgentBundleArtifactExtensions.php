<?php
/**
 * Extension hooks for plugin-owned agent bundle artifacts.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes generic artifact envelopes exchanged with owning plugins.
 */
final class AgentBundleArtifactExtensions {

	/**
	 * Collect plugin-provided target artifacts for export.
	 *
	 * @param array<string,mixed> $agent Agent database row.
	 * @param array<string,mixed> $context Export context.
	 * @return array<int,array<string,mixed>>
	 */
	public static function export_artifacts( array $agent, array $context = array() ): array {
		/**
		 * Let plugins contribute target artifacts to an agent bundle export.
		 *
		 * @param array<int,array<string,mixed>> $artifacts Artifact envelopes.
		 * @param array<string,mixed>            $agent Agent database row.
		 * @param array<string,mixed>            $context Export context.
		 */
		$artifacts = self::apply_filter( 'datamachine_agent_bundle_export_artifacts', array(), $agent, $context );

		return self::normalize_artifacts( is_array( $artifacts ) ? $artifacts : array() );
	}

	/**
	 * Collect plugin-reported current artifact state for drift planning.
	 *
	 * @param array<string,mixed> $agent Agent database row.
	 * @param array<int,array<string,mixed>> $installed Installed artifact rows.
	 * @param array<string,mixed> $context Planning context.
	 * @return array<int,array<string,mixed>>
	 */
	public static function current_artifacts( array $agent, array $installed = array(), array $context = array() ): array {
		/**
		 * Let plugins report local state for plugin-owned bundle artifacts.
		 *
		 * @param array<int,array<string,mixed>> $artifacts Artifact envelopes.
		 * @param array<string,mixed>            $agent Agent database row.
		 * @param array<string,mixed>            $context Planning context.
		 */
		$artifacts = self::apply_filter(
			'datamachine_agent_bundle_current_artifacts',
			array(),
			$agent,
			array_merge( $context, array( 'installed_artifacts' => $installed ) )
		);

		return self::normalize_artifacts( is_array( $artifacts ) ? $artifacts : array() );
	}

	/**
	 * Route an artifact apply operation to the owning plugin.
	 *
	 * @param array<string,mixed> $artifact Target artifact envelope.
	 * @param array<string,mixed> $agent Agent database row.
	 * @param array<string,mixed> $context Apply context.
	 * @return mixed|null
	 */
	public static function apply_artifact( array $artifact, array $agent = array(), array $context = array() ): mixed {
		/**
		 * Apply one plugin-owned bundle artifact.
		 *
		 * Return null to decline handling the artifact.
		 *
		 * @param mixed               $result Null until a plugin applies the artifact.
		 * @param array<string,mixed> $artifact Target artifact envelope.
		 * @param array<string,mixed> $agent Agent database row.
		 * @param array<string,mixed> $context Apply context.
		 */
		return self::apply_filter( 'datamachine_agent_bundle_apply_artifact', null, $artifact, $agent, $context );
	}

	/**
	 * Normalize artifact envelopes and reject unknown plugin artifact types.
	 *
	 * @param array<int,array<string,mixed>> $artifacts Raw artifact envelopes.
	 * @return array<int,array<string,mixed>>
	 */
	public static function normalize_artifacts( array $artifacts ): array {
		$allowed    = BundleSchema::artifact_types();
		$normalized = array();

		foreach ( $artifacts as $artifact ) {
			$type        = self::sanitize_key( (string) ( $artifact['artifact_type'] ?? '' ) );
			$id          = trim( (string) ( $artifact['artifact_id'] ?? '' ) );
			$source_path = self::source_path( (string) ( $artifact['source_path'] ?? '' ), $type, $id );
			if ( '' === $type || '' === $id || ! in_array( $type, $allowed, true ) ) {
				continue;
			}

			$normalized[] = array(
				'artifact_type' => $type,
				'artifact_id'   => $id,
				'source_path'   => $source_path,
				'payload'       => $artifact['payload'] ?? null,
			);
		}

		usort(
			$normalized,
			static function ( array $a, array $b ): int {
				$type_compare = strcmp( (string) $a['artifact_type'], (string) $b['artifact_type'] );
				if ( 0 !== $type_compare ) {
					return $type_compare;
				}

				return strcmp( (string) $a['artifact_id'], (string) $b['artifact_id'] );
			}
		);

		return $normalized;
	}

	private static function source_path( string $source_path, string $type, string $id ): string {
		$source_path = str_replace( '\\', '/', trim( $source_path ) );
		$source_path = ltrim( $source_path, '/' );
		if ( '' === $source_path ) {
			$source_path = self::default_source_path( $type, $id );
		}

		if ( str_contains( $source_path, '..' ) || ! str_starts_with( $source_path, BundleSchema::EXTENSIONS_DIR . '/' ) || ! str_ends_with( $source_path, '.json' ) ) {
			$source_path = self::default_source_path( $type, $id );
		}

		return $source_path;
	}

	private static function default_source_path( string $type, string $id ): string {
		return BundleSchema::EXTENSIONS_DIR . '/' . $type . '/' . self::sanitize_path_id( $id ) . '.json';
	}

	private static function sanitize_key( string $key ): string {
		if ( \function_exists( 'sanitize_key' ) ) {
			return \sanitize_key( $key );
		}

		$sanitized = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key );
		return strtolower( is_string( $sanitized ) ? $sanitized : '' );
	}

	private static function sanitize_path_id( string $id ): string {
		if ( \function_exists( 'sanitize_title' ) ) {
			return \sanitize_title( $id );
		}

		$sanitized = preg_replace( '/[^a-zA-Z0-9]+/', '-', $id );
		return trim( strtolower( is_string( $sanitized ) ? $sanitized : '' ), '-' );
	}

	private static function apply_filter( string $hook, mixed $value, mixed ...$args ): mixed {
		if ( ! \function_exists( 'apply_filters' ) ) {
			return $value;
		}

		return call_user_func_array( 'apply_filters', array_merge( array( $hook, $value ), $args ) );
	}
}
