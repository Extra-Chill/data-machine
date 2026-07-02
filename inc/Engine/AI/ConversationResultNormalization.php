<?php
/**
 * Value object holding the output of ConversationResultNormalizer.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable carrier for normalized conversation-result diagnostics.
 *
 * Holds the derived metadata.datamachine payload and the conversation status.
 * The status is exposed alongside a flag indicating whether the normalizer
 * overrode it (the runtime-tool-pending path is the only one that does). The
 * caller writes the status back onto the result ONLY when it was overridden, so
 * status-less results are not given a spurious empty `status` key — preserving
 * byte-for-byte parity with the prior inline logic.
 */
final class ConversationResultNormalization {

	/** @var array<string,mixed> Derived metadata.datamachine diagnostics. */
	public array $metadata;

	/** @var string The conversation status the normalizer reasoned about. */
	public string $status;

	/** @var bool Whether the normalizer overrode the result status. */
	public bool $status_overridden;

	/**
	 * @param array<string,mixed> $metadata          Derived diagnostics.
	 * @param string              $status            Final status (possibly overridden).
	 * @param bool                $status_overridden Whether the status was overridden.
	 */
	public function __construct( array $metadata, string $status, bool $status_overridden = false ) {
		$this->metadata          = $metadata;
		$this->status            = $status;
		$this->status_overridden = $status_overridden;
	}
}
