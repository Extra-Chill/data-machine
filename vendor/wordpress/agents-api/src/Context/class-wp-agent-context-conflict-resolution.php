<?php
/**
 * Context Conflict Resolution
 *
 * Describes the selected item and rejected alternatives for one conflict key.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\AI\Context;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Context_Conflict_Resolution {

	/**
	 * @param string                 $conflict_key Conflict key being resolved.
	 * @param WP_Agent_Context_Item   $winner       Selected context item.
	 * @param WP_Agent_Context_Item[] $rejected     Rejected context items.
	 * @param string                 $strategy     Resolution strategy identifier.
	 * @param string                 $reason       Human-readable reason.
	 */
	public function __construct(
		public readonly string $conflict_key,
		public readonly WP_Agent_Context_Item $winner,
		public readonly array $rejected,
		public readonly string $strategy,
		public readonly string $reason,
	) {}

	/**
	 * Export as a JSON-friendly array.
	 *
	 * @return array<mixed>
	 */
	public function to_array(): array {
		return array(
			'conflict_key' => $this->conflict_key,
			'winner'       => $this->winner->to_array(),
			'rejected'     => array_map(
				static fn ( WP_Agent_Context_Item $item ): array => $item->to_array(),
				$this->rejected
			),
			'strategy'     => $this->strategy,
			'reason'       => $this->reason,
		);
	}
}
