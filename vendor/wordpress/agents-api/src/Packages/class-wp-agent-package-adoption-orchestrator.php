<?php
/**
 * WP_Agent_Package_Adoption_Orchestrator service.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Adoption_Orchestrator' ) ) {
	/**
	 * Coordinates storage-neutral package adoption primitives.
	 */
	final class WP_Agent_Package_Adoption_Orchestrator {

		private WP_Agent_Package_Artifact_State_Store $state_store;

		public function __construct( WP_Agent_Package_Artifact_State_Store $state_store ) {
			$this->state_store = $state_store;
		}

		/**
		 * Builds an update plan without applying changes.
		 *
		 * @param WP_Agent_Package   $package Package definition.
		 * @param array<string,mixed> $context Consumer context.
		 * @return WP_Agent_Package_Update_Plan
		 */
		public function plan( WP_Agent_Package $package, array $context = array() ): WP_Agent_Package_Update_Plan {
			return WP_Agent_Package_Update_Planner::plan(
				$this->state_store->get_installed_artifacts( $package, $context ),
				$this->state_store->get_current_artifacts( $package, $context ),
				$this->state_store->get_target_artifacts( $package, $context ),
				array(
					'package_slug'    => $package->get_slug(),
					'package_version' => $package->get_version(),
				) + $context
			);
		}

		/**
		 * Applies safe or approved package artifacts.
		 *
		 * @param WP_Agent_Package_Adoption_Request $request Adoption request.
		 * @return WP_Agent_Package_Adoption_Result
		 */
		public function adopt( WP_Agent_Package_Adoption_Request $request ): WP_Agent_Package_Adoption_Result {
			$package = $request->get_package();
			$context = $request->get_context();
			$target  = $this->state_store->get_target_artifacts( $package, $context );
			$plan    = WP_Agent_Package_Update_Planner::plan(
				$this->state_store->get_installed_artifacts( $package, $context ),
				$this->state_store->get_current_artifacts( $package, $context ),
				$target,
				array(
					'package_slug'    => $package->get_slug(),
					'package_version' => $package->get_version(),
					'operation'       => $request->get_operation(),
				) + $context
			);

			if ( $request->is_dry_run() || 'dry-run' === $request->get_operation() ) {
				return new WP_Agent_Package_Adoption_Result( 'planned', $package->get_agent()->get_slug(), array(), $plan );
			}

			$approved_keys = array_fill_keys( $request->get_approved_artifact_keys(), true );
			$target_index  = self::index_artifacts( $target );
			/** @var array<int,array<string,mixed>> $applied */
			$applied = array();
			/** @var array<int,array<string,mixed>> $skipped */
			$skipped = array();
			/** @var array<int,array<string,mixed>> $failed */
			$failed    = array();
			$snapshots = array();

			foreach ( $plan->get_buckets() as $bucket => $entries ) {
				foreach ( $entries as $entry ) {
					$artifact_key = self::string_value( $entry['artifact_key'] ?? '' );
					$can_apply    = ( 'auto_apply' === $bucket && $request->allows_auto_apply() ) || isset( $approved_keys[ $artifact_key ] );

					if ( ! $can_apply ) {
						$skipped[] = self::entry_with_status( $entry, 'skipped', 'not_approved' );
						continue;
					}

					$target_row = $target_index[ $artifact_key ] ?? null;
					if ( null === $target_row ) {
						$failed[] = self::entry_with_status( $entry, 'failed', 'target_missing' );
						continue;
					}

					$artifact = self::artifact_from_entry( $package, $entry, $target_row );
					$result   = WP_Agent_Package_Artifact_Callbacks::import(
						$artifact,
						array(
							'package' => $package,
							'request' => $request,
							'entry'   => $entry,
							'target'  => $target_row,
						) + $context
					);

					if ( false === $result ) {
						$failed[] = self::entry_with_status( $entry, 'failed', 'callback_failed' );
						continue;
					}

					$applied[]   = self::entry_with_status( $entry, 'applied', 'applied' );
					$snapshots[] = self::snapshot_from_target( $package, $target_row, $context );
				}
			}

			$recorded = array();
			if ( $snapshots ) {
				$recorded = $this->state_store->record_installed_artifacts( $package, $snapshots, $context ) ? $snapshots : array();
			}

			return new WP_Agent_Package_Adoption_Result(
				self::result_status( $applied, $skipped, $failed ),
				$package->get_agent()->get_slug(),
				array(),
				$plan,
				$applied,
				$skipped,
				$failed,
				$recorded
			);
		}

		/**
		 * @param array<int,array<string,mixed>> $artifacts
		 * @return array<string,array<string,mixed>>
		 */
		private static function index_artifacts( array $artifacts ): array {
			$indexed = array();
			foreach ( $artifacts as $artifact ) {
				$type = WP_Agent_Package_Artifact::prepare_type( $artifact['artifact_type'] ?? ( $artifact['type'] ?? '' ) );
				$id   = self::artifact_id( $artifact );
				$indexed[ self::artifact_key( $type, $id ) ] = array_merge(
					$artifact,
					array(
						'artifact_type' => $type,
						'artifact_id'   => $id,
					)
				);
			}

			return $indexed;
		}

		/**
		 * @param array<string,mixed> $entry
		 * @param array<string,mixed> $target
		 */
		private static function artifact_from_entry( WP_Agent_Package $package, array $entry, array $target ): WP_Agent_Package_Artifact {
			$type = self::string_value( $entry['artifact_type'] ?? $target['artifact_type'] );
			$id   = self::string_value( $entry['artifact_id'] ?? $target['artifact_id'] );
			foreach ( $package->get_artifacts() as $artifact ) {
				if ( $artifact->get_type() === $type && $artifact->get_slug() === sanitize_title( $id ) ) {
					return $artifact;
				}
			}

			return new WP_Agent_Package_Artifact(
				array(
					'type'   => $type,
					'slug'   => $id,
					'source' => self::string_value( $target['source'] ?? $entry['source'] ?? '' ),
				)
			);
		}

		/**
		 * @param array<string,mixed> $target
		 * @param array<string,mixed> $context
		 */
		private static function snapshot_from_target( WP_Agent_Package $package, array $target, array $context ): WP_Agent_Package_Installed_Artifact {
			$hash      = self::string_value( $target['hash'] ?? WP_Agent_Package_Artifact_Hasher::hash( $target['payload'] ?? null ) );
			$timestamp = self::string_value( $context['timestamp'] ?? gmdate( 'c' ) );

			return new WP_Agent_Package_Installed_Artifact(
				array(
					'package_slug'      => $package->get_slug(),
					'package_version'   => $package->get_version(),
					'artifact_type'     => self::string_value( $target['artifact_type'] ),
					'artifact_id'       => self::artifact_id( $target ),
					'source'            => self::string_value( $target['source'] ?? '' ),
					'installed_hash'    => $hash,
					'current_hash'      => $hash,
					'installed_payload' => $target['payload'] ?? null,
					'installed_at'      => $timestamp,
					'updated_at'        => $timestamp,
				)
			);
		}

		/** @param array<string,mixed> $artifact */
		private static function artifact_id( array $artifact ): string {
			return trim( str_replace( '\\', '/', self::string_value( $artifact['artifact_id'] ?? ( $artifact['slug'] ?? '' ) ) ) );
		}

		private static function artifact_key( string $type, string $id ): string {
			return $type . ':' . $id;
		}

		/**
		 * @param array<string,mixed> $entry
		 * @return array<string,mixed>
		 */
		private static function entry_with_status( array $entry, string $status, string $reason ): array {
			return array_merge(
				$entry,
				array(
					'apply_status' => $status,
					'apply_reason' => $reason,
				)
			);
		}

		/**
		 * @param array<int,array<string,mixed>> $applied
		 * @param array<int,array<string,mixed>> $skipped
		 * @param array<int,array<string,mixed>> $failed
		 */
		private static function result_status( array $applied, array $skipped, array $failed ): string {
			if ( $failed ) {
				return $applied ? 'partial' : 'failed';
			}

			if ( $applied && $skipped ) {
				return 'partial';
			}

			return $applied ? 'applied' : 'skipped';
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
	}
}
