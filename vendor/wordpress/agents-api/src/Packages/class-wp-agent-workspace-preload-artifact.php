<?php
/**
 * Generic workspace preload package artifact contract.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Workspace_Preload_Artifact' ) ) {
	/**
	 * Normalizes and validates portable workspace preload artifacts.
	 */
	final class WP_Agent_Workspace_Preload_Artifact {

		public const TYPE   = 'agent-runtime/workspace-preload';
		public const SCHEMA = 'agent-runtime/workspace-preload/v1';

		/**
		 * Registers the generic workspace preload artifact type.
		 *
		 * @return void
		 */
		public static function register(): void {
			wp_register_agent_package_artifact_type(
				self::TYPE,
				array(
					'label'             => 'Workspace preload',
					'description'       => 'Portable repository declarations for runtimes that can pre-materialize coding workspaces.',
					'validate_callback' => array( self::class, 'validate' ),
					'import_callback'   => array( self::class, 'import' ),
					'meta'              => array(
						'schema'       => self::SCHEMA,
						'materializer' => 'runtime',
					),
				)
			);
		}

		/**
		 * Validates a workspace preload artifact payload.
		 *
		 * @param WP_Agent_Package_Artifact $artifact Artifact declaration.
		 * @param array<string,mixed>       $context  Consumer context.
		 * @return array<string,mixed>|WP_Error Normalized contract or validation error.
		 */
		public static function validate( WP_Agent_Package_Artifact $artifact, array $context = array() ) {
			return self::normalized_from_context( $artifact, $context );
		}

		/**
		 * Imports a workspace preload artifact as a runtime materialization contract.
		 *
		 * Core does not clone, mount, or persist workspaces. Runtime adopters consume
		 * the returned contract and decide how to materialize repositories safely.
		 *
		 * @param WP_Agent_Package_Artifact $artifact Artifact declaration.
		 * @param array<string,mixed>       $context  Consumer context.
		 * @return array<string,mixed>|WP_Error Normalized import contract or validation error.
		 */
		public static function import( WP_Agent_Package_Artifact $artifact, array $context = array() ) {
			$contract = self::normalized_from_context( $artifact, $context );
			if ( is_wp_error( $contract ) ) {
				return $contract;
			}

			return array(
				'status'   => 'materialization-contract',
				'artifact' => $contract,
			);
		}

		/**
		 * Normalizes raw payload into the stable workspace preload contract.
		 *
		 * @param array<string,mixed> $payload Raw payload.
		 * @return array<string,mixed>|WP_Error Normalized payload or validation error.
		 */
		public static function normalize_payload( array $payload ) {
			$repositories = $payload['repositories'] ?? null;
			if ( ! is_array( $repositories ) ) {
				return new WP_Error( 'wp_agent_workspace_preload_repositories_invalid', 'Workspace preload payload requires a repositories array.' );
			}

			$normalized = array();
			foreach ( $repositories as $index => $repository ) {
				if ( ! is_array( $repository ) ) {
					return new WP_Error( 'wp_agent_workspace_preload_repository_invalid', 'Workspace preload repository entries must be objects.', array( 'index' => $index ) );
				}

				$name = self::string_value( $repository['name'] ?? '' );
				$url  = self::string_value( $repository['url'] ?? '' );
				$ref  = self::string_value( $repository['ref'] ?? '' );

				if ( '' === $name || sanitize_title( $name ) !== $name ) {
					return new WP_Error( 'wp_agent_workspace_preload_repository_name_invalid', 'Workspace preload repository name must be a non-empty slug.', array( 'index' => $index ) );
				}

				if ( '' === $url || ! self::is_supported_repository_url( $url ) ) {
					return new WP_Error( 'wp_agent_workspace_preload_repository_url_invalid', 'Workspace preload repository url must be an HTTPS or SSH Git URL.', array( 'index' => $index ) );
				}

				$entry = array(
					'name' => $name,
					'url'  => $url,
				);

				if ( '' !== $ref ) {
					$entry['ref'] = $ref;
				}

				$normalized[] = $entry;
			}

			if ( array() === $normalized ) {
				return new WP_Error( 'wp_agent_workspace_preload_repositories_empty', 'Workspace preload payload requires at least one repository.' );
			}

			$result = array(
				'schema'       => self::SCHEMA,
				'repositories' => $normalized,
			);

			if ( isset( $payload['meta'] ) && is_array( $payload['meta'] ) ) {
				$result['meta'] = self::string_keyed_array( $payload['meta'] );
			}

			return $result;
		}

		/**
		 * Extracts and normalizes payload from an artifact callback context.
		 *
		 * @param WP_Agent_Package_Artifact $artifact Artifact declaration.
		 * @param array<string,mixed>       $context  Consumer context.
		 * @return array<string,mixed>|WP_Error Normalized contract or validation error.
		 */
		private static function normalized_from_context( WP_Agent_Package_Artifact $artifact, array $context ) {
			$target  = is_array( $context['target'] ?? null ) ? self::string_keyed_array( $context['target'] ) : array();
			$payload = is_array( $context['payload'] ?? null ) ? self::string_keyed_array( $context['payload'] ) : ( is_array( $target['payload'] ?? null ) ? self::string_keyed_array( $target['payload'] ) : array() );
			if ( array() === $payload ) {
				return new WP_Error( 'wp_agent_workspace_preload_payload_missing', 'Workspace preload artifact callbacks require a payload array.', array( 'artifact' => $artifact->get_slug() ) );
			}

			$normalized = self::normalize_payload( $payload );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			return array(
				'type'    => self::TYPE,
				'slug'    => $artifact->get_slug(),
				'source'  => $artifact->get_source(),
				'payload' => $normalized,
			);
		}

		private static function is_supported_repository_url( string $url ): bool {
			return str_starts_with( $url, 'https://' ) || preg_match( '/^git@[A-Za-z0-9._-]+:[A-Za-z0-9._\/-]+\.git$/', $url );
		}

		private static function string_value( mixed $value ): string {
			if ( is_scalar( $value ) || $value instanceof Stringable ) {
				return trim( (string) $value );
			}

			return '';
		}

		/**
		 * @param array<mixed> $values Raw values.
		 * @return array<string,mixed>
		 */
		private static function string_keyed_array( array $values ): array {
			$prepared = array();
			foreach ( $values as $key => $value ) {
				if ( is_string( $key ) ) {
					$prepared[ $key ] = $value;
				}
			}

			return $prepared;
		}

		private function __construct() {}
	}
}
