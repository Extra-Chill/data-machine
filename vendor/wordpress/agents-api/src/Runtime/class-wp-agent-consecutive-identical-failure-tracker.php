<?php
/**
 * Consecutive identical failure tracker.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nudges after the same failed tool-call signature repeats consecutively.
 */
final class WP_Agent_Consecutive_Identical_Failure_Tracker implements WP_Agent_Identical_Failure_Tracker {

	private int $threshold;
	private int $repeat_count = 0;
	private string $last_hash = '';

	public function __construct( int $threshold = 2 ) {
		$this->threshold = max( 2, $threshold );
	}

	/** @inheritDoc */
	public function record_failure( WP_Agent_Identical_Failure_Signature $signature, array $context = array() ): ?string {
		unset( $context );

		if ( $signature->hash() === $this->last_hash ) {
			++$this->repeat_count;
		} else {
			$this->last_hash    = $signature->hash();
			$this->repeat_count = 1;
		}

		if ( $this->repeat_count < $this->threshold ) {
			return null;
		}

		return sprintf(
			'The tool call `%s` has failed %d times with the same arguments and error `%s`. Try a different approach before repeating it.',
			$signature->tool_name(),
			$this->repeat_count,
			$signature->error_code()
		);
	}

	public function repeat_count(): int {
		return $this->repeat_count;
	}

	public function threshold(): int {
		return $this->threshold;
	}
}
