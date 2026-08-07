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

		private ?WP_Agent_Package_Update_Plan $plan;

		/** @var array<int,array<string,mixed>> */
		private array $applied_artifacts;

		/** @var array<int,array<string,mixed>> */
		private array $skipped_artifacts;

		/** @var array<int,array<string,mixed>> */
		private array $failed_artifacts;

		/** @var array<int,WP_Agent_Package_Installed_Artifact> */
		private array $recorded_artifacts;

		/** @var array<string,mixed> */
		private array $meta;

		/**
		 * Constructor.
		 *
		 * @param string                                     $status Result status.
		 * @param string                                     $agent_slug Adopted agent slug.
		 * @param array<int,string>                          $messages Result messages.
		 * @param WP_Agent_Package_Update_Plan|null          $plan Optional package plan.
		 * @param array<int,array<string,mixed>>             $applied_artifacts Applied entries.
		 * @param array<int,array<string,mixed>>             $skipped_artifacts Skipped entries.
		 * @param array<int,array<string,mixed>>             $failed_artifacts Failed entries.
		 * @param array<int,WP_Agent_Package_Installed_Artifact> $recorded_artifacts Recorded snapshots.
		 * @param array<string,mixed>                        $meta Result metadata.
		 */
		public function __construct( string $status, string $agent_slug, array $messages = array(), ?WP_Agent_Package_Update_Plan $plan = null, array $applied_artifacts = array(), array $skipped_artifacts = array(), array $failed_artifacts = array(), array $recorded_artifacts = array(), array $meta = array() ) {
			$this->status             = $this->prepare_status( $status );
			$this->agent_slug         = sanitize_title( $agent_slug );
			$this->messages           = $this->prepare_messages( $messages );
			$this->plan               = $plan;
			$this->applied_artifacts  = $applied_artifacts;
			$this->skipped_artifacts  = $skipped_artifacts;
			$this->failed_artifacts   = $failed_artifacts;
			$this->recorded_artifacts = $this->prepare_recorded_artifacts( $recorded_artifacts );
			$this->meta               = $meta;

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

		public function get_plan(): ?WP_Agent_Package_Update_Plan {
			return $this->plan;
		}

		/** @return array<int,array<string,mixed>> */
		public function get_applied_artifacts(): array {
			return $this->applied_artifacts;
		}

		/** @return array<int,array<string,mixed>> */
		public function get_skipped_artifacts(): array {
			return $this->skipped_artifacts;
		}

		/** @return array<int,array<string,mixed>> */
		public function get_failed_artifacts(): array {
			return $this->failed_artifacts;
		}

		/** @return array<int,WP_Agent_Package_Installed_Artifact> */
		public function get_recorded_artifacts(): array {
			return $this->recorded_artifacts;
		}

		/** @return array<string,mixed> */
		public function get_meta(): array {
			return $this->meta;
		}

		/**
		 * Exports the value object.
		 *
		 * @return array<string, mixed>
		 */
		public function to_array(): array {
			$data = array(
				'status'     => $this->status,
				'agent_slug' => $this->agent_slug,
				'messages'   => $this->messages,
				'applied'    => $this->applied_artifacts,
				'skipped'    => $this->skipped_artifacts,
				'failed'     => $this->failed_artifacts,
				'recorded'   => array_map(
					static function ( WP_Agent_Package_Installed_Artifact $artifact ): array {
						return $artifact->to_array();
					},
					$this->recorded_artifacts
				),
				'meta'       => $this->meta,
			);

			if ( null !== $this->plan ) {
				$data['plan'] = $this->plan->to_array();
			}

			return $data;
		}

		public function to_run_result_envelope(): \AgentsAPI\AI\WP_Agent_Run_Result_Envelope {
			return \AgentsAPI\AI\WP_Agent_Run_Result_Envelope::from_array(
				array(
					'run_id'        => $this->agent_slug,
					'status'        => $this->canonical_status(),
					'outputs'       => array(
						'agent_slug' => $this->agent_slug,
						'messages'   => $this->messages,
						'applied'    => $this->applied_artifacts,
						'skipped'    => $this->skipped_artifacts,
						'failed'     => $this->failed_artifacts,
					),
					'artifact_refs' => $this->artifact_refs(),
					'provenance'    => array( 'package_adoption_status' => $this->status ),
					'metadata'      => $this->meta,
				)
			);
		}

		public function to_canonical_envelope(): \AgentsAPI\AI\WP_Agent_Run_Result_Envelope {
			return $this->to_run_result_envelope();
		}

		/**
		 * Validates status.
		 *
		 * @param string $status Raw status.
		 * @return string
		 */
		private function prepare_status( string $status ): string {
			$status  = sanitize_title( $status );
			$allowed = array( 'adopted', 'updated', 'skipped', 'failed', 'planned', 'applied', 'partial', 'needs-approval' );
			if ( ! in_array( $status, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Agent package adoption result status is invalid.' );
			}

			return $status;
		}

		private function canonical_status(): string {
			if ( 'failed' === $this->status ) {
				return \AgentsAPI\AI\WP_Agent_Run_Result_Envelope::STATUS_FAILED;
			}
			if ( in_array( $this->status, array( 'skipped', 'needs-approval' ), true ) ) {
				return 'needs-approval' === $this->status ? \AgentsAPI\AI\WP_Agent_Run_Result_Envelope::STATUS_APPROVAL_REQUIRED : \AgentsAPI\AI\WP_Agent_Run_Result_Envelope::STATUS_SKIPPED;
			}

			return \AgentsAPI\AI\WP_Agent_Run_Result_Envelope::STATUS_SUCCEEDED;
		}

		/** @return array<int,array<string,mixed>> */
		private function artifact_refs(): array {
			$refs = array();
			foreach ( $this->recorded_artifacts as $artifact ) {
				$refs[] = $artifact->to_array();
			}

			return \AgentsAPI\AI\WP_Agent_Run_Result_Envelope::normalize_refs( $refs );
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

		/**
		 * @param array<int,mixed> $artifacts Raw artifacts.
		 * @return array<int,WP_Agent_Package_Installed_Artifact>
		 */
		private function prepare_recorded_artifacts( array $artifacts ): array {
			$prepared = array();
			foreach ( $artifacts as $artifact ) {
				if ( $artifact instanceof WP_Agent_Package_Installed_Artifact ) {
					$prepared[] = $artifact;
				}
			}

			return $prepared;
		}
	}
}
