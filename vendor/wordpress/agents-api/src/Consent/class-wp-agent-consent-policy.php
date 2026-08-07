<?php
/**
 * Agent consent policy interface.
 *
 * @package AgentsAPI
 */

use AgentsAPI\AI\Consent\WP_Agent_Consent_Decision;

defined( 'ABSPATH' ) || exit;

/**
 * Generic consent policy contract for agent memory, transcripts, and escalation.
 */
interface WP_Agent_Consent_Policy {

	/**
	 * Whether consolidated agent memory may be stored.
	 *
	 * @param array<mixed> $context JSON-friendly request, principal, adapter, and UX context.
	 * @return WP_Agent_Consent_Decision
	 */
	public function can_store_memory( array $context = array() ): WP_Agent_Consent_Decision;

	/**
	 * Whether existing agent memory may be used for a run.
	 *
	 * @param array<mixed> $context JSON-friendly request, principal, adapter, and UX context.
	 * @return WP_Agent_Consent_Decision
	 */
	public function can_use_memory( array $context = array() ): WP_Agent_Consent_Decision;

	/**
	 * Whether a raw conversation transcript may be stored.
	 *
	 * @param array<mixed> $context JSON-friendly request, principal, adapter, and UX context.
	 * @return WP_Agent_Consent_Decision
	 */
	public function can_store_transcript( array $context = array() ): WP_Agent_Consent_Decision;

	/**
	 * Whether a raw conversation transcript may be shared outside its owning context.
	 *
	 * @param array<mixed> $context JSON-friendly request, principal, adapter, and UX context.
	 * @return WP_Agent_Consent_Decision
	 */
	public function can_share_transcript( array $context = array() ): WP_Agent_Consent_Decision;

	/**
	 * Whether a run or transcript may be escalated to a human/support adapter.
	 *
	 * @param array<mixed> $context JSON-friendly request, principal, adapter, and UX context.
	 * @return WP_Agent_Consent_Decision
	 */
	public function can_escalate_to_human( array $context = array() ): WP_Agent_Consent_Decision;
}
