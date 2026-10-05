<?php
/**
 * WP_Agent_Token_Store contract.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WP_Agent_Token_Store' ) ) {
	/**
	 * Store contract for hashed agent bearer tokens.
	 */
	interface WP_Agent_Token_Store {

		/**
		 * Create a token metadata record for a pre-hashed token.
		 */
		public function create_token( WP_Agent_Token $token ): WP_Agent_Token;

		/**
		 * Resolve a token by hash. Stores must not require or return raw tokens.
		 */
		public function resolve_token_hash( string $token_hash ): ?WP_Agent_Token;

		/**
		 * Update a token's last-used timestamp.
		 */
		public function touch_token( int $token_id, ?string $used_at = null ): void;

		/**
		 * Revoke a token owned by an agent.
		 */
		public function revoke_token( int $token_id, string $agent_id ): bool;

		/**
		 * Revoke all tokens for an agent.
		 */
		public function revoke_all_tokens_for_agent( string $agent_id ): int;

		/**
		 * Fetch token metadata by ID.
		 */
		public function get_token( int $token_id ): ?WP_Agent_Token;

		/**
		 * List token metadata for an agent.
		 *
		 * @return WP_Agent_Token[]
		 */
		public function list_tokens( string $agent_id ): array;
	}
}
