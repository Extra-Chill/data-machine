<?php
/**
 * Consecutive spin detector.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects a repeated identical tool-call signature within one loop run.
 */
final class WP_Agent_Consecutive_Spin_Detector implements WP_Agent_Spin_Detector {

	private int $threshold;
	private int $repeat_count = 0;
	private string $last_hash = '';

	public function __construct( int $threshold = 3 ) {
		$this->threshold = max( 2, $threshold );
	}

	/** @inheritDoc */
	public function record_signature( WP_Agent_Spin_Signature $signature, array $context = array() ): bool {
		unset( $context );

		if ( $signature->hash() === $this->last_hash ) {
			++$this->repeat_count;
		} else {
			$this->last_hash    = $signature->hash();
			$this->repeat_count = 1;
		}

		return $this->repeat_count >= $this->threshold;
	}

	public function repeat_count(): int {
		return $this->repeat_count;
	}

	public function threshold(): int {
		return $this->threshold;
	}
}
