<?php
/**
 * Conservative default agent consent policy.
 *
 * @package AgentsAPI
 */

use AgentsAPI\AI\Consent\WP_Agent_Consent_Decision;
use AgentsAPI\AI\Consent\WP_Agent_Consent_Operation;

defined( 'ABSPATH' ) || exit;

/**
 * Default policy: deny unless an interactive caller supplies explicit consent.
 */
class WP_Agent_Default_Consent_Policy implements WP_Agent_Consent_Policy {

	/**
	 * @inheritDoc
	 */
	public function can_store_memory( array $context = array() ): WP_Agent_Consent_Decision {
		return $this->decide( WP_Agent_Consent_Operation::STORE_MEMORY, $context );
	}

	/**
	 * @inheritDoc
	 */
	public function can_use_memory( array $context = array() ): WP_Agent_Consent_Decision {
		return $this->decide( WP_Agent_Consent_Operation::USE_MEMORY, $context );
	}

	/**
	 * @inheritDoc
	 */
	public function can_store_transcript( array $context = array() ): WP_Agent_Consent_Decision {
		return $this->decide( WP_Agent_Consent_Operation::STORE_TRANSCRIPT, $context );
	}

	/**
	 * @inheritDoc
	 */
	public function can_share_transcript( array $context = array() ): WP_Agent_Consent_Decision {
		return $this->decide( WP_Agent_Consent_Operation::SHARE_TRANSCRIPT, $context );
	}

	/**
	 * @inheritDoc
	 */
	public function can_escalate_to_human( array $context = array() ): WP_Agent_Consent_Decision {
		return $this->decide( WP_Agent_Consent_Operation::ESCALATE_TO_HUMAN, $context );
	}

	/**
	 * Make a conservative explicit-consent decision for one operation.
	 *
	 * @param string $operation Consent operation value.
	 * @param array<mixed>  $context   JSON-friendly policy context.
	 * @return WP_Agent_Consent_Decision
	 */
	private function decide( string $operation, array $context ): WP_Agent_Consent_Decision {
		$audit_metadata = $this->audit_metadata( $operation, $context );

		if ( ! $this->is_interactive( $context ) ) {
			return WP_Agent_Consent_Decision::denied( $operation, 'non_interactive_default_denied', $audit_metadata );
		}

		if ( true === $this->explicit_consent( $operation, $context ) ) {
			return WP_Agent_Consent_Decision::allowed( $operation, 'explicit_consent', $audit_metadata );
		}

		return WP_Agent_Consent_Decision::denied( $operation, 'explicit_consent_missing', $audit_metadata );
	}

	/**
	 * Whether the policy context represents an interactive user flow.
	 *
	 * @param array<mixed> $context JSON-friendly policy context.
	 * @return bool
	 */
	private function is_interactive( array $context ): bool {
		if ( true === ( $context['interactive'] ?? null ) ) {
			return true;
		}

		$mode = $this->first_scalar_string( $context, array( 'mode', 'context', 'request_kind', 'request_context' ) );

		return in_array( strtolower( $mode ), array( 'chat', 'interactive', 'rest' ), true );
	}

	/**
	 * Resolve explicit consent for an operation.
	 *
	 * @param string $operation Consent operation value.
	 * @param array<mixed>  $context   JSON-friendly policy context.
	 * @return bool|null
	 */
	private function explicit_consent( string $operation, array $context ): ?bool {
		if ( array_key_exists( $operation, $context ) && is_bool( $context[ $operation ] ) ) {
			return $context[ $operation ];
		}

		$consent = $context['consent'] ?? array();
		if ( is_array( $consent ) && array_key_exists( $operation, $consent ) && is_bool( $consent[ $operation ] ) ) {
			return $consent[ $operation ];
		}

		return null;
	}

	/**
	 * Build audit metadata common to all decisions.
	 *
	 * @param string $operation Consent operation value.
	 * @param array<mixed>  $context   JSON-friendly policy context.
	 * @return array<mixed>
	 */
	private function audit_metadata( string $operation, array $context ): array {
		return array(
			'policy'      => 'default',
			'operation'   => $operation,
			'interactive' => $this->is_interactive( $context ),
			'mode'        => $this->first_scalar_string( $context, array( 'mode', 'context', 'request_kind', 'request_context' ) ),
			'agent_id'    => $this->scalar_string( $context['agent_id'] ?? null ),
			'user_id'     => $this->scalar_int( $context['user_id'] ?? null ),
		);
	}

	/**
	 * @param array<mixed> $context Context values.
	 * @param string[]     $keys    Candidate keys in priority order.
	 * @return string
	 */
	private function first_scalar_string( array $context, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $context ) ) {
				$value = $this->scalar_string( $context[ $key ] );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function scalar_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private function scalar_int( $value ): int {
		return is_scalar( $value ) ? (int) $value : 0;
	}
}
