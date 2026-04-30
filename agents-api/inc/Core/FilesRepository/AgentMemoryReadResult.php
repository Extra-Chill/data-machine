<?php
/**
 * Agent Memory Read Result
 *
 * Store-neutral value object returned by AgentMemoryStoreInterface::read().
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

final class AgentMemoryReadResult {

	/**
	 * @param bool        $exists     Whether the file exists in the store.
	 * @param string      $content    File content (empty when !exists).
	 * @param string      $hash       Content hash (sha1) for compare-and-swap. Empty when !exists.
	 * @param int         $bytes      Content length in bytes.
	 * @param int|null    $updated_at Unix timestamp of last modification, or null if unknown.
	 */
	public function __construct(
		public readonly bool $exists,
		public readonly string $content,
		public readonly string $hash,
		public readonly int $bytes,
		public readonly ?int $updated_at,
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
