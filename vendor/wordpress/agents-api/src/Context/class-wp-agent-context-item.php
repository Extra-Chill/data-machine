<?php
/**
 * Retrieved Context Item
 *
 * Store-neutral value object for one retrieved context item.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\AI\Context;

use AgentsAPI\AI\WP_Agent_Citation_Metadata;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Context_Item {

	/**
	 * @param string      $content        Retrieved context content.
	 * @param array<mixed>       $scope          Product-defined scope metadata.
	 * @param string      $authority_tier Generic authority tier.
	 * @param array<mixed>       $provenance     Source/provenance metadata.
	 * @param string      $conflict_kind  Conflict behavior vocabulary.
	 * @param string|null $conflict_key   Shared key for mutually conflicting items.
	 * @param array<mixed>       $metadata       Additional JSON-friendly metadata.
	 */
	public function __construct(
		public readonly string $content,
		public readonly array $scope,
		public readonly string $authority_tier,
		public readonly array $provenance,
		public readonly string $conflict_kind = WP_Agent_Context_Conflict_Kind::PREFERENCE,
		public readonly ?string $conflict_key = null,
		public readonly array $metadata = array(),
	) {
		WP_Agent_Context_Authority_Tier::normalize( $this->authority_tier );
		WP_Agent_Context_Conflict_Kind::normalize( $this->conflict_kind );
	}

	/**
	 * Export as a JSON-friendly array.
	 *
	 * @return array<mixed>
	 */
	public function to_array(): array {
		return array(
			'content'        => $this->content,
			'scope'          => $this->scope,
			'authority_tier' => WP_Agent_Context_Authority_Tier::normalize( $this->authority_tier ),
			'provenance'     => $this->provenance,
			'conflict_kind'  => WP_Agent_Context_Conflict_Kind::normalize( $this->conflict_kind ),
			'conflict_key'   => $this->conflict_key,
			'metadata'       => WP_Agent_Citation_Metadata::normalize_metadata( $this->metadata ),
		);
	}
}
