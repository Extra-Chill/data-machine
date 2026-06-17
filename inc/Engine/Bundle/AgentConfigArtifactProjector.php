<?php
/**
 * Agent config artifact projection policies.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Projects agent_config into bundle-owned artifact payloads.
 */
final class AgentConfigArtifactProjector {

	/**
	 * Return bundle artifact ownership policies keyed by dot path.
	 *
	 * Supported policy fields:
	 * - tracking: include|exclude
	 * - merge: three_way|preserve_local
	 * - reason: decision reason for merge output
	 * - redactor: callable applied to tracked values before hashing/comparison
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function policies(): array {
		$policies = array(
			'datamachine_bundle'           => array(
				'tracking' => 'exclude',
				'merge'    => 'preserve_local',
				'reason'   => 'preserve_runtime_bundle_metadata',
			),
			'intelligence.context_servers' => array(
				'tracking' => 'exclude',
				'merge'    => 'preserve_local',
				'reason'   => 'preserve_plugin_owned_agent_config',
			),
			'intelligence.auth_refs'       => array(
				'tracking' => 'exclude',
				'merge'    => 'preserve_local',
				'reason'   => 'preserve_plugin_owned_agent_config',
			),
			'allowed_redirect_uris'        => array(
				'merge'  => 'preserve_local',
				'reason' => 'preserve_runtime_agent_config',
			),
			'model'                        => array(
				'merge'  => 'preserve_local',
				'reason' => 'preserve_runtime_agent_config',
			),
			'provider'                     => array(
				'merge'  => 'preserve_local',
				'reason' => 'preserve_runtime_agent_config',
			),
		);

		/**
		 * Filter agent_config artifact projection ownership policies.
		 *
		 * Plugins can mark their own agent_config namespaces as runtime-local,
		 * excluded from core bundle drift checks, or redacted before comparison.
		 *
		 * @param array<string,array<string,mixed>> $policies Policy map keyed by dot path.
		 */
		$policies = self::apply_filter( 'datamachine_agent_config_artifact_projection_policies', $policies );

		return self::normalize_policies( is_array( $policies ) ? $policies : array() );
	}

	/**
	 * Return the bundle-owned config payload tracked by lifecycle planning.
	 *
	 * @param array<string,mixed> $config Agent config.
	 * @return array<string,mixed>
	 */
	public static function tracked_payload( array $config ): array {
		foreach ( self::policies() as $path => $policy ) {
			if ( 'exclude' === ( $policy['tracking'] ?? '' ) ) {
				self::unset_path( $config, $path );
				continue;
			}

			if ( is_callable( $policy['redactor'] ?? null ) && self::path_exists( $config, $path ) ) {
				$redactor = $policy['redactor'];
				self::set_path( $config, $path, $redactor( self::get_path( $config, $path ), $path ) );
			}
		}

		self::sort_recursive( $config );
		return $config;
	}

	/**
	 * Return a preserve-local merge policy for a config path, if registered.
	 *
	 * @param string $path Dot path.
	 * @return array<string,mixed>|null
	 */
	public static function preserve_local_policy_for_path( string $path ): ?array {
		foreach ( self::policies() as $prefix => $policy ) {
			if ( 'preserve_local' !== ( $policy['merge'] ?? '' ) ) {
				continue;
			}
			if ( $path === $prefix || str_starts_with( $path, $prefix . '.' ) ) {
				return $policy;
			}
		}

		return null;
	}

	/**
	 * Restore preserve-local config paths before writing a projected payload live.
	 *
	 * @param array<string,mixed> $payload        Projected bundle payload.
	 * @param array<string,mixed> $current_config Current live agent config.
	 * @return array<string,mixed>
	 */
	public static function preserve_local_paths( array $payload, array $current_config ): array {
		foreach ( self::policies() as $path => $policy ) {
			if ( 'preserve_local' !== ( $policy['merge'] ?? '' ) || ! self::path_exists( $current_config, $path ) ) {
				continue;
			}
			self::set_path( $payload, $path, self::get_path( $current_config, $path ) );
		}

		return $payload;
	}

	/** @param array<string,array<string,mixed>> $policies */
	private static function normalize_policies( array $policies ): array {
		$normalized = array();
		foreach ( $policies as $path => $policy ) {
			$path = self::normalize_path( (string) $path );
			if ( '' === $path || ! is_array( $policy ) ) {
				continue;
			}

			$tracking = (string) ( $policy['tracking'] ?? 'include' );
			$merge    = (string) ( $policy['merge'] ?? 'three_way' );
			$entry    = array(
				'tracking' => in_array( $tracking, array( 'include', 'exclude' ), true ) ? $tracking : 'include',
				'merge'    => in_array( $merge, array( 'three_way', 'preserve_local' ), true ) ? $merge : 'three_way',
				'reason'   => self::sanitize_key( (string) ( $policy['reason'] ?? 'agent_config_projection_policy' ) ),
			);
			if ( is_callable( $policy['redactor'] ?? null ) ) {
				$entry['redactor'] = $policy['redactor'];
			}

			$normalized[ $path ] = $entry;
		}

		uksort(
			$normalized,
			static function ( string $a, string $b ): int {
				$depth_compare = substr_count( $b, '.' ) <=> substr_count( $a, '.' );
				return 0 !== $depth_compare ? $depth_compare : strcmp( $a, $b );
			}
		);
		return $normalized;
	}

	private static function normalize_path( string $path ): string {
		$path = trim( str_replace( '/', '.', $path ), '.' );
		$path = preg_replace( '/[^A-Za-z0-9_\.-]+/', '', $path );
		return trim( is_string( $path ) ? $path : '', '.' );
	}

	private static function unset_path( array &$config, string $path ): void {
		self::unset_path_parts( $config, explode( '.', $path ) );
	}

	/** @param array<int,string> $parts */
	private static function unset_path_parts( array &$node, array $parts ): bool {
		$part = array_shift( $parts );
		if ( null === $part || ! array_key_exists( $part, $node ) ) {
			return empty( $node );
		}

		if ( empty( $parts ) ) {
			unset( $node[ $part ] );
			return empty( $node );
		}

		if ( is_array( $node[ $part ] ) && self::unset_path_parts( $node[ $part ], $parts ) ) {
			unset( $node[ $part ] );
		}

		return empty( $node );
	}

	private static function path_exists( array $config, string $path ): bool {
		$node = $config;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return false;
			}
			$node = $node[ $part ];
		}
		return true;
	}

	private static function get_path( array $config, string $path ): mixed {
		$node = $config;
		foreach ( explode( '.', $path ) as $part ) {
			$node = is_array( $node ) && array_key_exists( $part, $node ) ? $node[ $part ] : null;
		}
		return $node;
	}

	private static function set_path( array &$config, string $path, mixed $value ): void {
		$parts = explode( '.', $path );
		$last  = array_pop( $parts );
		$node  = &$config;
		foreach ( $parts as $part ) {
			if ( ! isset( $node[ $part ] ) || ! is_array( $node[ $part ] ) ) {
				$node[ $part ] = array();
			}
			$node = &$node[ $part ];
		}
		if ( null !== $last ) {
			$node[ $last ] = $value;
		}
	}

	private static function sort_recursive( array &$value ): void {
		foreach ( $value as &$child ) {
			if ( is_array( $child ) ) {
				self::sort_recursive( $child );
			}
		}
		ksort( $value, SORT_STRING );
	}

	private static function apply_filter( string $hook, mixed $value ): mixed {
		if ( ! \function_exists( 'apply_filters' ) ) {
			return $value;
		}

		return \apply_filters( $hook, $value );
	}

	private static function sanitize_key( string $key ): string {
		if ( \function_exists( 'sanitize_key' ) ) {
			return \sanitize_key( $key );
		}

		$sanitized = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key );
		return strtolower( is_string( $sanitized ) ? $sanitized : '' );
	}
}
