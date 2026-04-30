<?php
/**
 * Agent Memory Write Result
 *
 * Store-neutral value object returned by AgentMemoryStoreInterface::write()
 * and ::delete().
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

final class AgentMemoryWriteResult {

	/**
	 * @param bool        $success Whether the operation succeeded.
	 * @param string      $hash    Hash (sha1) of the post-write content. Empty on failure or on delete.
	 * @param int         $bytes   Post-write content length in bytes. Zero on failure or on delete.
	 * @param string|null $error   Machine-readable error code on failure ('conflict', 'capability',
	 *                             'io', 'not_found', etc.) or null on success.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $hash,
		public readonly int $bytes,
		public readonly ?string $error,
	) {}

	public static function ok( string $hash, int $bytes ): self {
		return new self( true, $hash, $bytes, null );
	}

	public static function failure( string $error ): self {
		return new self( false, '', 0, $error );
	}
}
