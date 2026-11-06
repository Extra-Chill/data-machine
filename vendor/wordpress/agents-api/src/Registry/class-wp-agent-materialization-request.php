<?php
/**
 * WP_Agent_Materialization_Request value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Materialization_Request' ) ) {
	/**
	 * Describes a storage-neutral installed-agent materialization request.
	 */
	final class WP_Agent_Materialization_Request {

		private WP_Agent $agent;
		private string $operation;
		private ?int $owner_user_id;
		private string $instance_key;
		/** @var array<string,mixed> */
		private array $config;
		/** @var array<string,mixed> */
		private array $context;
		private ?WP_Agent_Package $package;
		private ?WP_Agent_Package_Adoption_Result $package_result;

		/**
		 * Constructor.
		 *
		 * @param WP_Agent            $agent Agent definition to materialize.
		 * @param array<string,mixed> $args  Request args.
		 */
		public function __construct( WP_Agent $agent, array $args = array() ) {
			$this->agent         = $agent;
			$this->operation     = self::prepare_operation( $args['operation'] ?? 'install' );
			$this->owner_user_id = array_key_exists( 'owner_user_id', $args ) && null !== $args['owner_user_id'] ? max( 0, self::int_value( $args['owner_user_id'] ) ) : null;
			$this->instance_key  = self::prepare_instance_key( $args['instance_key'] ?? 'default' );
			$this->config        = self::prepare_config( $agent, $args['config'] ?? array(), (bool) ( $args['adopt_default_config'] ?? true ) );
			$this->context       = self::string_keyed_array( $args['context'] ?? array() );
			$package             = $args['package'] ?? null;
			$package_result      = $args['package_result'] ?? null;

			if ( null !== $package && ! $package instanceof WP_Agent_Package ) {
				throw new InvalidArgumentException( 'Materialization package must be a WP_Agent_Package.' );
			}

			if ( null !== $package_result && ! $package_result instanceof WP_Agent_Package_Adoption_Result ) {
				throw new InvalidArgumentException( 'Materialization package_result must be a WP_Agent_Package_Adoption_Result.' );
			}

			$this->package        = $package;
			$this->package_result = $package_result;
		}

		public function get_agent(): WP_Agent {
			return $this->agent;
		}

		public function get_operation(): string {
			return $this->operation;
		}

		public function get_owner_user_id(): ?int {
			return $this->owner_user_id;
		}

		public function get_instance_key(): string {
			return $this->instance_key;
		}

		/** @return array<string,mixed> */
		public function get_config(): array {
			return $this->config;
		}

		/** @return array<string,mixed> */
		public function get_context(): array {
			return $this->context;
		}

		public function get_package(): ?WP_Agent_Package {
			return $this->package;
		}

		public function get_package_result(): ?WP_Agent_Package_Adoption_Result {
			return $this->package_result;
		}

		/** @return array<string,mixed> */
		public function to_array(): array {
			return array(
				'agent'          => $this->agent->to_array(),
				'operation'      => $this->operation,
				'owner_user_id'  => $this->owner_user_id,
				'instance_key'   => $this->instance_key,
				'config'         => $this->config,
				'context'        => $this->context,
				'package'        => null === $this->package ? null : $this->package->to_array(),
				'package_result' => null === $this->package_result ? null : $this->package_result->to_array(),
			);
		}

		private static function prepare_operation( mixed $operation ): string {
			$operation = sanitize_title( self::string_value( $operation ) );
			$allowed   = array( 'install', 'upgrade', 'reconcile', 'project', 'uninstall', 'dry-run' );
			if ( ! in_array( $operation, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Agent materialization operation must be install, upgrade, reconcile, project, uninstall, or dry-run.' );
			}

			return $operation;
		}

		private static function prepare_instance_key( mixed $value ): string {
			$value = trim( strtolower( str_replace( '\\', '/', self::string_value( $value ) ) ) );
			$value = preg_replace( '#\s*/\s*#', '/', $value );
			$value = is_string( $value ) ? $value : '';
			$value = preg_replace( '#/+#', '/', $value );
			return ! is_string( $value ) || '' === $value ? 'default' : $value;
		}

		/**
		 * @param mixed $config Raw config.
		 * @return array<string,mixed>
		 */
		private static function prepare_config( WP_Agent $agent, mixed $config, bool $adopt_default_config ): array {
			if ( ! is_array( $config ) ) {
				throw new InvalidArgumentException( 'Agent materialization config must be an array.' );
			}

			$config = self::string_keyed_array( $config );
			return $adopt_default_config ? self::string_keyed_array( array_replace_recursive( $agent->get_default_config(), $config ) ) : $config;
		}

		private static function string_value( mixed $value ): string {
			return is_scalar( $value ) ? (string) $value : '';
		}

		private static function int_value( mixed $value ): int {
			return is_numeric( $value ) ? (int) $value : 0;
		}

		/** @return array<string,mixed> */
		private static function string_keyed_array( mixed $values ): array {
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
