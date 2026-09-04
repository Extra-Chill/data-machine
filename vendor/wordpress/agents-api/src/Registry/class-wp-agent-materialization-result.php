<?php
/**
 * WP_Agent_Materialization_Result value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Materialization_Result' ) ) {
	/**
	 * Reports storage-neutral installed-agent materialization outcomes.
	 */
	final class WP_Agent_Materialization_Result {

		private string $status;
		private ?WP_Agent_Installed_Agent $installed_agent;
		private ?WP_Agent $projected_agent;
		/** @var array<int,string> */
		private array $messages;
		/** @var array<string,mixed> */
		private array $meta;

		/**
		 * Constructor.
		 *
		 * @param string                        $status          Result status.
		 * @param WP_Agent_Installed_Agent|null $installed_agent Installed state.
		 * @param WP_Agent|null                 $projected_agent  Projected request-local definition.
		 * @param array<int,string>             $messages         Messages.
		 * @param array<string,mixed>           $meta             Metadata.
		 */
		public function __construct( string $status, ?WP_Agent_Installed_Agent $installed_agent = null, ?WP_Agent $projected_agent = null, array $messages = array(), array $meta = array() ) {
			$this->status          = self::prepare_status( $status );
			$this->installed_agent = $installed_agent;
			$this->projected_agent = $projected_agent;
			$this->messages        = self::prepare_messages( $messages );
			$this->meta            = self::string_keyed_array( $meta );
		}

		public function get_status(): string {
			return $this->status;
		}

		public function get_installed_agent(): ?WP_Agent_Installed_Agent {
			return $this->installed_agent;
		}

		public function get_projected_agent(): ?WP_Agent {
			return $this->projected_agent;
		}

		/** @return array<int,string> */
		public function get_messages(): array {
			return $this->messages;
		}

		/** @return array<string,mixed> */
		public function get_meta(): array {
			return $this->meta;
		}

		/** @return array<string,mixed> */
		public function to_array(): array {
			return array(
				'status'          => $this->status,
				'installed_agent' => null === $this->installed_agent ? null : $this->installed_agent->to_array(),
				'projected_agent' => null === $this->projected_agent ? null : $this->projected_agent->to_array(),
				'messages'        => $this->messages,
				'meta'            => $this->meta,
			);
		}

		private static function prepare_status( string $status ): string {
			$status  = sanitize_title( $status );
			$allowed = array( 'installed', 'updated', 'projected', 'skipped', 'failed', 'planned', 'removed' );
			if ( ! in_array( $status, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Agent materialization result status is invalid.' );
			}

			return $status;
		}

		/**
		 * @param array<int,string> $messages
		 * @return array<int,string>
		 */
		private static function prepare_messages( array $messages ): array {
			$prepared = array();
			foreach ( $messages as $message ) {
				$message = trim( $message );
				if ( '' !== $message ) {
					$prepared[] = $message;
				}
			}

			return $prepared;
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
	}
}
