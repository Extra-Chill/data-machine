<?php
/**
 * WP_Agent_Package_Adoption_Result value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Package_Adoption_Result' ) ) {
	/**
	 * Describes the outcome of adopting an agent package.
	 */
	final class WP_Agent_Package_Adoption_Result {

		/**
		 * Adoption status.
		 *
		 * @var string
		 */
		private string $status;

		/**
		 * Adopted agent slug.
		 *
		 * @var string
		 */
		private string $agent_slug;

		/**
		 * Result messages.
		 *
		 * @var array<int, string>
		 */
		private array $messages;

		/**
		 * Constructor.
		 *
		 * @param string             $status     Result status.
		 * @param string             $agent_slug Adopted agent slug.
		 * @param array<int, string> $messages   Result messages.
		 */
		public function __construct( string $status, string $agent_slug, array $messages = array() ) {
			$this->status     = $this->prepare_status( $status );
			$this->agent_slug = sanitize_title( $agent_slug );
			$this->messages   = $this->prepare_messages( $messages );

			if ( '' === $this->agent_slug ) {
				throw new InvalidArgumentException( 'Agent package adoption result requires an agent slug.' );
			}
		}

		/**
		 * Retrieves the result status.
		 *
		 * @return string
		 */
		public function get_status(): string {
			return $this->status;
		}

		/**
		 * Retrieves the adopted agent slug.
		 *
		 * @return string
		 */
		public function get_agent_slug(): string {
			return $this->agent_slug;
		}

		/**
		 * Retrieves messages.
		 *
		 * @return array<int, string>
		 */
		public function get_messages(): array {
			return $this->messages;
		}

		/**
		 * Exports the value object.
		 *
		 * @return array<string, mixed>
		 */
		public function to_array(): array {
			return array(
				'status'     => $this->status,
				'agent_slug' => $this->agent_slug,
				'messages'   => $this->messages,
			);
		}

		/**
		 * Validates status.
		 *
		 * @param string $status Raw status.
		 * @return string
		 */
		private function prepare_status( string $status ): string {
			$status  = sanitize_title( $status );
			$allowed = array( 'adopted', 'updated', 'skipped', 'failed' );
			if ( ! in_array( $status, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Agent package adoption result status must be one of adopted, updated, skipped, failed.' );
			}

			return $status;
		}

		/**
		 * Normalizes messages.
		 *
		 * @param array<int, string> $messages Raw messages.
		 * @return array<int, string>
		 */
		private function prepare_messages( array $messages ): array {
			$prepared = array();
			foreach ( $messages as $message ) {
				$message = trim( (string) $message );
				if ( '' !== $message ) {
					$prepared[] = $message;
				}
			}

			return $prepared;
		}
	}
}
