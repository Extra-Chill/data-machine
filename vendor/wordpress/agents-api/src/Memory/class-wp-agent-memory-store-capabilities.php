<?php
/**
 * Agent Memory Store Capabilities
 *
 * Declares which memory metadata fields a concrete store can persist, return,
 * filter, rank, and validate.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Memory_Store_Capabilities {

	/**
	 * @param string[] $persisted_metadata_fields Metadata fields the store can persist on write.
	 * @param string[] $readable_metadata_fields  Metadata fields the store can return on read/list.
	 * @param string[] $filterable_fields         Metadata fields the store can apply to retrieval filters.
	 * @param string[] $rankable_fields           Metadata fields the store can use for ordering/ranking.
	 * @param string[] $validator_ids             Validator identifiers available through the store.
	 */
	public function __construct(
		public readonly array $persisted_metadata_fields = array(),
		public readonly array $readable_metadata_fields = array(),
		public readonly array $filterable_fields = array(),
		public readonly array $rankable_fields = array(),
		public readonly array $validator_ids = array(),
	) {}

	/**
	 * Store without first-class metadata support.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self();
	}

	/**
	 * Store that supports every standard memory metadata field.
	 *
	 * @param string[] $validator_ids Validator identifiers available through the store.
	 * @return self
	 */
	public static function all( array $validator_ids = array() ): self {
		return new self(
			WP_Agent_Memory_Metadata::FIELDS,
			WP_Agent_Memory_Metadata::FIELDS,
			array( 'source_type', 'workspace', 'confidence', 'validator', 'authority_tier', 'created_at', 'updated_at' ),
			array( 'confidence', 'authority_tier', 'created_at', 'updated_at' ),
			$validator_ids,
		);
	}

	/**
	 * Report unsupported metadata fields for a requested operation.
	 *
	 * @param string[] $requested_fields Requested metadata fields.
	 * @param string   $operation        Operation: persist, read, filter, or rank.
	 * @return string[] Unsupported fields.
	 */
	public function unsupported_metadata_fields( array $requested_fields, string $operation ): array {
		$supported = match ( $operation ) {
			'persist' => $this->persisted_metadata_fields,
			'read'    => $this->readable_metadata_fields,
			'filter'  => $this->filterable_fields,
			'rank'    => $this->rankable_fields,
			default   => array(),
		};

		return array_values( array_diff( array_values( array_unique( $requested_fields ) ), $supported ) );
	}
}
