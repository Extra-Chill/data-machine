<?php
/**
 * Agent Memory List Entry
 *
 * Store-neutral value object representing a single file in a layer listing
 * returned by WP_Agent_Memory_Store::list_layer().
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Memory_List_Entry {

	/**
	 * @param string                   $filename                    Filename or relative path within the layer.
	 * @param string                   $layer                       Layer the file belongs to (shared|agent|user|network).
	 * @param int                      $bytes                       Content length in bytes.
	 * @param int|null                 $updated_at                  Unix timestamp of last modification, or null if unknown.
	 * @param WP_Agent_Memory_Metadata|null $metadata                    Provenance/trust metadata, or null if unavailable.
	 * @param string[]                 $unsupported_metadata_fields Requested metadata fields the store could not return.
	 */
	public function __construct(
		public readonly string $filename,
		public readonly string $layer,
		public readonly int $bytes,
		public readonly ?int $updated_at,
		public readonly ?WP_Agent_Memory_Metadata $metadata = null,
		public readonly array $unsupported_metadata_fields = array(),
	) {}
}
