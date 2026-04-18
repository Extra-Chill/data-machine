<?php
/**
 * Agent Memory List Entry
 *
 * Immutable value object representing a single file in a layer listing
 * returned by AgentMemoryStoreInterface::list_layer().
 *
 * @package DataMachine\Core\FilesRepository
 * @since   next
 */

namespace DataMachine\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

final class AgentMemoryListEntry {

	/**
	 * @param string   $filename   Filename or relative path within the layer.
	 * @param string   $layer      Layer the file belongs to (shared|agent|user|network).
	 * @param int      $bytes      Content length in bytes.
	 * @param int|null $updated_at Unix timestamp of last modification, or null if unknown.
	 */
	public function __construct(
		public readonly string $filename,
		public readonly string $layer,
		public readonly int $bytes,
		public readonly ?int $updated_at,
	) {}
}
