<?php
/**
 * Agent Memory Validation Result
 *
 * Store-neutral result returned by memory validators.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Memory_Validation_Result {

	/**
	 * @param bool        $valid      Whether the memory still matches the substrate.
	 * @param string      $status     Machine-readable status: valid, stale, unsupported, or error.
	 * @param string|null $message    Human-readable validation detail.
	 * @param float|null  $confidence Updated confidence after validation.
	 */
	public function __construct(
		public readonly bool $valid,
		public readonly string $status,
		public readonly ?string $message = null,
		public readonly ?float $confidence = null,
	) {}

	public static function valid( ?float $confidence = null, ?string $message = null ): self {
		return new self( true, 'valid', $message, $confidence );
	}

	public static function stale( ?string $message = null, ?float $confidence = null ): self {
		return new self( false, 'stale', $message, $confidence );
	}

	public static function unsupported( ?string $message = null ): self {
		return new self( false, 'unsupported', $message, null );
	}

	public static function error( string $message ): self {
		return new self( false, 'error', $message, null );
	}
}
