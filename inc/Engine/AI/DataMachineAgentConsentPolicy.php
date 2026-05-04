<?php
/**
 * Data Machine agent consent policy adapter.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\AI\Consent\AgentConsentDecision;
use AgentsAPI\AI\Consent\AgentConsentOperation;

defined( 'ABSPATH' ) || exit;

/**
 * Implements Agents API consent decisions using Data Machine product policy.
 */
class DataMachineAgentConsentPolicy implements \WP_Agent_Consent_Policy_Interface {

	/**
	 * Resolve the active product policy.
	 *
	 * @return \WP_Agent_Consent_Policy_Interface
	 */
	public static function get(): \WP_Agent_Consent_Policy_Interface {
		$policy = apply_filters( 'datamachine_agent_consent_policy', new self() );

		return $policy instanceof \WP_Agent_Consent_Policy_Interface ? $policy : new self();
	}

	/**
	 * @inheritDoc
	 */
	public function can_store_memory( array $context = array() ): AgentConsentDecision {
		if ( true === ( $context['permission_granted'] ?? null ) || true === ( $context[ AgentConsentOperation::STORE_MEMORY ] ?? null ) ) {
			return $this->allowed( AgentConsentOperation::STORE_MEMORY, 'datamachine_memory_permission', $context );
		}

		return $this->denied( AgentConsentOperation::STORE_MEMORY, 'datamachine_memory_permission_missing', $context );
	}

	/**
	 * @inheritDoc
	 */
	public function can_use_memory( array $context = array() ): AgentConsentDecision {
		if ( false === ( $context[ AgentConsentOperation::USE_MEMORY ] ?? null ) ) {
			return $this->denied( AgentConsentOperation::USE_MEMORY, 'datamachine_memory_use_denied', $context );
		}

		return $this->allowed( AgentConsentOperation::USE_MEMORY, 'datamachine_memory_policy', $context );
	}

	/**
	 * @inheritDoc
	 */
	public function can_store_transcript( array $context = array() ): AgentConsentDecision {
		$mode = strtolower( (string) ( $context['mode'] ?? '' ) );

		if ( 'chat' === $mode && true === $this->is_interactive( $context ) ) {
			return $this->allowed( AgentConsentOperation::STORE_TRANSCRIPT, 'datamachine_chat_session', $context );
		}

		if ( true === ( $context['persist_transcript'] ?? null ) || true === ( $context[ AgentConsentOperation::STORE_TRANSCRIPT ] ?? null ) ) {
			return $this->allowed( AgentConsentOperation::STORE_TRANSCRIPT, 'datamachine_transcript_opt_in', $context );
		}

		return $this->denied( AgentConsentOperation::STORE_TRANSCRIPT, 'datamachine_transcript_not_configured', $context );
	}

	/**
	 * @inheritDoc
	 */
	public function can_share_transcript( array $context = array() ): AgentConsentDecision {
		if ( true === ( $context[ AgentConsentOperation::SHARE_TRANSCRIPT ] ?? null ) ) {
			return $this->allowed( AgentConsentOperation::SHARE_TRANSCRIPT, 'explicit_share_transcript_consent', $context );
		}

		return $this->denied( AgentConsentOperation::SHARE_TRANSCRIPT, 'share_transcript_consent_missing', $context );
	}

	/**
	 * @inheritDoc
	 */
	public function can_escalate_to_human( array $context = array() ): AgentConsentDecision {
		if ( true === ( $context[ AgentConsentOperation::ESCALATE_TO_HUMAN ] ?? null ) ) {
			return $this->allowed( AgentConsentOperation::ESCALATE_TO_HUMAN, 'explicit_escalation_consent', $context );
		}

		return $this->denied( AgentConsentOperation::ESCALATE_TO_HUMAN, 'escalation_consent_missing', $context );
	}

	/**
	 * Build an allowed decision.
	 *
	 * @param string $operation Consent operation.
	 * @param string $reason    Reason code.
	 * @param array  $context   Policy context.
	 * @return AgentConsentDecision
	 */
	private function allowed( string $operation, string $reason, array $context ): AgentConsentDecision {
		return AgentConsentDecision::allowed( $operation, $reason, $this->audit_metadata( $operation, $context ) );
	}

	/**
	 * Build a denied decision.
	 *
	 * @param string $operation Consent operation.
	 * @param string $reason    Reason code.
	 * @param array  $context   Policy context.
	 * @return AgentConsentDecision
	 */
	private function denied( string $operation, string $reason, array $context ): AgentConsentDecision {
		return AgentConsentDecision::denied( $operation, $reason, $this->audit_metadata( $operation, $context ) );
	}

	/**
	 * Whether context came from an interactive user flow.
	 *
	 * @param array $context Policy context.
	 * @return bool
	 */
	private function is_interactive( array $context ): bool {
		if ( true === ( $context['interactive'] ?? null ) ) {
			return true;
		}

		$mode = strtolower( (string) ( $context['mode'] ?? '' ) );

		return in_array( $mode, array( 'chat', 'rest', 'interactive' ), true );
	}

	/**
	 * Build audit-safe decision metadata.
	 *
	 * @param string $operation Consent operation.
	 * @param array  $context   Policy context.
	 * @return array
	 */
	private function audit_metadata( string $operation, array $context ): array {
		return array(
			'policy'             => 'datamachine',
			'operation'          => $operation,
			'mode'               => (string) ( $context['mode'] ?? '' ),
			'interactive'        => $this->is_interactive( $context ),
			'agent_id'           => isset( $context['agent_id'] ) ? (int) $context['agent_id'] : 0,
			'user_id'            => isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
			'configured_setting' => (string) ( $context['configured_setting'] ?? '' ),
		);
	}
}
