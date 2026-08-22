<?php
/**
 * WP_Agent_Package_Update_Planner service.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Update_Planner' ) ) {
	/**
	 * Compares installed, current, and target package artifact state.
	 */
	final class WP_Agent_Package_Update_Planner {

		/**
		 * Plans an artifact update without mutating storage.
		 *
		 * Artifact arrays accept: artifact_type, artifact_id, source, payload, hash.
		 * Installed arrays may also use artifact_id/source_path for downstream adapters.
		 *
		 * @param array<int,array<string,mixed>|WP_Agent_Package_Installed_Artifact> $installed_artifacts Install-time artifact records.
		 * @param array<int,array<string,mixed>>                                     $current_artifacts Current local artifacts.
		 * @param array<int,array<string,mixed>|WP_Agent_Package_Artifact>           $target_artifacts Target package artifacts.
		 * @param array<string,mixed>                                                $meta Optional plan metadata.
		 * @return WP_Agent_Package_Update_Plan
		 */
		public static function plan( array $installed_artifacts, array $current_artifacts, array $target_artifacts, array $meta = array() ): WP_Agent_Package_Update_Plan {
			$installed = self::index_installed_artifacts( $installed_artifacts );
			$current   = self::index_artifacts( $current_artifacts );
			$target    = self::index_artifacts( $target_artifacts );
			/** @var array<string,array<int,array<string,mixed>>> $buckets */
			$buckets = array(
				'auto_apply'     => array(),
				'needs_approval' => array(),
				'warnings'       => array(),
				'no_op'          => array(),
			);

			foreach ( $target as $key => $target_artifact ) {
				$current_artifact   = $current[ $key ] ?? null;
				$installed_artifact = $installed[ $key ] ?? null;
				$current_hash       = self::artifact_hash( $current_artifact );
				$target_hash        = self::artifact_hash( $target_artifact ) ?? '';
				$installed_hash     = self::optional_string_value( $installed_artifact['installed_hash'] ?? null );

				if ( null !== $current_hash && hash_equals( $target_hash, $current_hash ) ) {
					$buckets['no_op'][] = self::entry( $key, $target_artifact, $installed_hash, $current_hash, $target_hash, 'already_matches_target', $current_artifact );
					continue;
				}

				if ( null === $current_hash && null !== $installed_hash ) {
					$buckets['warnings'][] = self::entry( $key, $target_artifact, $installed_hash, null, $target_hash, 'missing_local_artifact', $current_artifact );
					continue;
				}

				if ( null === $installed_hash ) {
					$bucket               = null === $current_hash ? 'auto_apply' : 'needs_approval';
					$reason               = null === $current_hash ? 'new_artifact' : 'untracked_local_artifact';
					$buckets[ $bucket ][] = self::entry( $key, $target_artifact, null, $current_hash, $target_hash, $reason, $current_artifact );
					continue;
				}

				if ( null !== $current_hash && hash_equals( $installed_hash, $current_hash ) ) {
					$buckets['auto_apply'][] = self::entry( $key, $target_artifact, $installed_hash, $current_hash, $target_hash, 'local_unchanged_from_installed', $current_artifact );
					continue;
				}

				$buckets['needs_approval'][] = self::entry( $key, $target_artifact, $installed_hash, $current_hash, $target_hash, 'local_modified', $current_artifact );
			}

			foreach ( $installed as $key => $installed_artifact ) {
				if ( isset( $target[ $key ] ) ) {
					continue;
				}

				$buckets['warnings'][] = array(
					'artifact_key'  => $key,
					'artifact_type' => self::string_value( $installed_artifact['artifact_type'] ),
					'artifact_id'   => self::string_value( $installed_artifact['artifact_id'] ),
					'source'        => self::string_value( $installed_artifact['source'] ?? '' ),
					'reason'        => 'orphaned_installed_artifact',
					'summary'       => sprintf( '%s %s is not present in the target package.', self::string_value( $installed_artifact['artifact_type'] ), self::string_value( $installed_artifact['artifact_id'] ) ),
				);
			}

			return new WP_Agent_Package_Update_Plan( $buckets, $meta );
		}

		/**
		 * @param array<int,array<string,mixed>|WP_Agent_Package_Installed_Artifact> $artifacts Installed artifacts.
		 * @return array<string,array<string,mixed>>
		 */
		private static function index_installed_artifacts( array $artifacts ): array {
			$indexed = array();
			foreach ( $artifacts as $artifact ) {
				$row             = $artifact instanceof WP_Agent_Package_Installed_Artifact ? $artifact->to_array() : $artifact;
				$row             = self::normalize_artifact_row( $row );
				$key             = self::artifact_key( self::string_value( $row['artifact_type'] ), self::string_value( $row['artifact_id'] ) );
				$indexed[ $key ] = $row;
			}

			ksort( $indexed, SORT_STRING );
			return $indexed;
		}

		/**
		 * @param array<int,array<string,mixed>|WP_Agent_Package_Artifact> $artifacts Artifacts.
		 * @return array<string,array<string,mixed>>
		 */
		private static function index_artifacts( array $artifacts ): array {
			$indexed = array();
			foreach ( $artifacts as $artifact ) {
				$row             = $artifact instanceof WP_Agent_Package_Artifact ? self::row_from_package_artifact( $artifact ) : $artifact;
				$row             = self::normalize_artifact_row( $row );
				$key             = self::artifact_key( self::string_value( $row['artifact_type'] ), self::string_value( $row['artifact_id'] ) );
				$indexed[ $key ] = $row;
			}

			ksort( $indexed, SORT_STRING );
			return $indexed;
		}

		/** @return array<string,mixed> */
		private static function row_from_package_artifact( WP_Agent_Package_Artifact $artifact ): array {
			return array(
				'artifact_type' => $artifact->get_type(),
				'artifact_id'   => $artifact->get_slug(),
				'source'        => $artifact->get_source(),
				'hash'          => '' !== $artifact->get_checksum() ? $artifact->get_checksum() : null,
			);
		}

		/**
		 * @param array<string,mixed> $row
		 * @return array<string,mixed>
		 */
		private static function normalize_artifact_row( array $row ): array {
			return array_merge(
				$row,
				array(
					'artifact_type' => WP_Agent_Package_Artifact::prepare_type( $row['artifact_type'] ?? '' ),
					'artifact_id'   => self::normalize_artifact_id( $row['artifact_id'] ?? ( $row['artifact_slug'] ?? '' ) ),
					'source'        => self::string_value( $row['source'] ?? ( $row['source_path'] ?? '' ) ),
				)
			);
		}

		/**
		 * @param array<string,mixed>      $target
		 * @param array<string,mixed>|null $current
		 * @return array<string,mixed>
		 */
		private static function entry( string $key, array $target, ?string $installed_hash, ?string $current_hash, string $target_hash, string $reason, ?array $current ): array {
			return array(
				'artifact_key'   => $key,
				'artifact_type'  => self::string_value( $target['artifact_type'] ),
				'artifact_id'    => self::string_value( $target['artifact_id'] ),
				'source'         => self::string_value( $target['source'] ?? '' ),
				'reason'         => $reason,
				'installed_hash' => $installed_hash,
				'current_hash'   => $current_hash,
				'target_hash'    => $target_hash,
				'summary'        => sprintf( '%s %s: %s', self::string_value( $target['artifact_type'] ), self::string_value( $target['artifact_id'] ), str_replace( '_', ' ', $reason ) ),
				'diff'           => array(
					'before' => self::redact( $current['payload'] ?? null ),
					'after'  => self::redact( $target['payload'] ?? null ),
				),
			);
		}

		private static function artifact_key( string $type, string $slug ): string {
			return $type . ':' . $slug;
		}

		private static function normalize_artifact_id( mixed $artifact_id ): string {
			$artifact_id = trim( str_replace( '\\', '/', self::string_value( $artifact_id ) ) );
			if ( '' === $artifact_id || str_starts_with( $artifact_id, '/' ) || str_contains( $artifact_id, '..' ) ) {
				throw new InvalidArgumentException( 'Agent package artifact rows require a package-local artifact_id.' );
			}

			return $artifact_id;
		}

		/** @param array<string,mixed>|null $artifact */
		private static function artifact_hash( ?array $artifact ): ?string {
			if ( null === $artifact ) {
				return null;
			}

			$hash = self::optional_string_value( $artifact['hash'] ?? null );
			if ( null !== $hash ) {
				return $hash;
			}

			if ( ! array_key_exists( 'payload', $artifact ) || null === $artifact['payload'] ) {
				return null;
			}

			return WP_Agent_Package_Artifact_Hasher::hash( $artifact['payload'] );
		}

		private static function redact( mixed $value ): mixed {
			if ( ! is_array( $value ) ) {
				return $value;
			}

			$redacted = array();
			foreach ( $value as $key => $child ) {
				$key_string = (string) $key;
				if ( preg_match( '/(secret|token|password|api[_-]?key|authorization|credential)/i', $key_string ) ) {
					$redacted[ $key ] = '[redacted]';
					continue;
				}
				$redacted[ $key ] = self::redact( $child );
			}

			return $redacted;
		}

		private static function optional_string_value( mixed $value ): ?string {
			$value = self::string_value( $value );
			return '' === trim( $value ) ? null : $value;
		}

		private static function string_value( mixed $value ): string {
			if ( null === $value ) {
				return '';
			}

			if ( is_scalar( $value ) || $value instanceof Stringable ) {
				return (string) $value;
			}

			return '';
		}

		/**
		 * Prevents construction.
		 */
		private function __construct() {}
	}
}
