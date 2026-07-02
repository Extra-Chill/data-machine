<?php
/**
 * Agent Memory Query
 *
 * Store-neutral retrieval filters and ranking hints for memory listings.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Memory_Query {

	/**
	 * @param string[]   $source_types    Allowed source types.
	 * @param float|null $min_confidence  Minimum accepted confidence.
	 * @param string[]   $authority_tiers Allowed authority tiers.
	 * @param string[]   $validators      Allowed validator identifiers.
	 * @param string[]   $metadata_fields Metadata fields requested on results.
	 * @param string|null $order_by        Ranking field, such as confidence, authority_tier, or updated_at.
	 * @param string      $order           asc or desc.
	 */
	public function __construct(
		public readonly array $source_types = array(),
		public readonly ?float $min_confidence = null,
		public readonly array $authority_tiers = array(),
		public readonly array $validators = array(),
		public readonly array $metadata_fields = WP_Agent_Memory_Metadata::FIELDS,
		public readonly ?string $order_by = null,
		public readonly string $order = 'desc',
	) {}

	/**
	 * Metadata fields a store must filter on to satisfy this query.
	 *
	 * @return string[]
	 */
	public function filter_fields(): array {
		$fields = array();
		if ( array() !== $this->source_types ) {
			$fields[] = 'source_type';
		}
		if ( null !== $this->min_confidence ) {
			$fields[] = 'confidence';
		}
		if ( array() !== $this->authority_tiers ) {
			$fields[] = 'authority_tier';
		}
		if ( array() !== $this->validators ) {
			$fields[] = 'validator';
		}

		return $fields;
	}
}
