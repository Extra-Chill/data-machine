<?php
/**
 * WP_Agent_Package_Adoption_Request value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Adoption_Request' ) ) {
	/**
	 * Normalized request for package adoption orchestration.
	 */
	final class WP_Agent_Package_Adoption_Request {

		private WP_Agent_Package $package;
		private string $operation;
		private bool $dry_run;
		private bool $auto_apply;
		/** @var array<int,string> */
		private array $approved_artifact_keys;
		/** @var array<string,mixed> */
		private array $context;

		/**
		 * Constructor.
		 *
		 * @param WP_Agent_Package   $package Package definition.
		 * @param array<string,mixed> $args Request arguments.
		 */
		public function __construct( WP_Agent_Package $package, array $args = array() ) {
			$this->package                = $package;
			$this->operation              = self::prepare_operation( $args['operation'] ?? 'upgrade' );
			$this->dry_run                = (bool) ( $args['dry_run'] ?? false );
			$this->auto_apply             = (bool) ( $args['auto_apply'] ?? true );
			$this->approved_artifact_keys = self::prepare_string_list( $args['approved_artifact_keys'] ?? array() );
			$this->context                = self::prepare_string_keyed_array( $args['context'] ?? array() );
		}

		public function get_package(): WP_Agent_Package {
			return $this->package;
		}

		public function get_operation(): string {
			return $this->operation;
		}

		public function is_dry_run(): bool {
			return $this->dry_run;
		}

		public function allows_auto_apply(): bool {
			return $this->auto_apply;
		}

		/** @return array<int,string> */
		public function get_approved_artifact_keys(): array {
			return $this->approved_artifact_keys;
		}

		/** @return array<string,mixed> */
		public function get_context(): array {
			return $this->context;
		}

		/** @return array<string,mixed> */
		public function to_array(): array {
			return array(
				'package'                => $this->package->to_array(),
				'operation'              => $this->operation,
				'dry_run'                => $this->dry_run,
				'auto_apply'             => $this->auto_apply,
				'approved_artifact_keys' => $this->approved_artifact_keys,
				'context'                => $this->context,
			);
		}

		private static function prepare_operation( mixed $operation ): string {
			$operation = is_scalar( $operation ) ? sanitize_title( (string) $operation ) : '';
			$allowed   = array( 'install', 'upgrade', 'reconcile', 'uninstall', 'dry-run' );
			if ( ! in_array( $operation, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Package adoption operation must be install, upgrade, reconcile, uninstall, or dry-run.' );
			}

			return $operation;
		}

		/**
		 * @param mixed $values Raw values.
		 * @return array<int,string>
		 */
		private static function prepare_string_list( $values ): array {
			$prepared = array();
			$values   = is_array( $values ) ? $values : array( $values );
			foreach ( $values as $value ) {
				$value = is_scalar( $value ) ? trim( (string) $value ) : '';
				if ( '' !== $value ) {
					$prepared[] = $value;
				}
			}

			return array_values( array_unique( $prepared ) );
		}

		/**
		 * @param mixed $values Raw values.
		 * @return array<string,mixed>
		 */
		private static function prepare_string_keyed_array( mixed $values ): array {
			if ( ! is_array( $values ) ) {
				return array();
			}

			$prepared = array();
			foreach ( $values as $key => $value ) {
				if ( is_string( $key ) ) {
					$prepared[ $key ] = $value;
				}
			}

			return $prepared;
		}
	}
}
