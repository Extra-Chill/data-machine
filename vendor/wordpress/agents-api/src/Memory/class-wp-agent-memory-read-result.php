<?php
/**
 * Agent Memory Read Result
 *
 * Store-neutral value object returned by WP_Agent_Memory_Store::read().
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Memory_Read_Result {

	/**
	 * @param bool                     $exists                      Whether the file exists in the store.
	 * @param string                   $content                     File content (empty when !exists).
	 * @param string                   $hash                        Content hash (sha1) for compare-and-swap.
	 * @param int                      $bytes                       Content length in bytes.
	 * @param int|null                 $updated_at                  Unix timestamp of last modification, or null if unknown.
	 * @param WP_Agent_Memory_Metadata|null $metadata                    Provenance/trust metadata, or null if unavailable.
	 * @param string[]                 $unsupported_metadata_fields Requested metadata fields the store could not return.
	 */
	public function __construct(
		public readonly bool $exists,
		public readonly string $content,
		public readonly string $hash,
		public readonly int $bytes,
		public readonly ?int $updated_at,
		public readonly ?WP_Agent_Memory_Metadata $metadata = null,
		public readonly array $unsupported_metadata_fields = array(),
	) {}

	/**
	 * Sentinel for "file does not exist."
	 *
	 * @return self
	 */
	public static function not_found(): self {
		return new self( false, '', '', 0, null );
	}
}
