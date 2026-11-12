<?php
/**
 * Pipeline AI concurrency lease.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use DataMachine\Core\OptionLeaseStore;

defined( 'ABSPATH' ) || exit;

/**
 * Releases one or more acquired AI concurrency slots.
 */
class PipelineAIConcurrencyLease {

	/**
	 * @var string[]
	 */
	private array $option_names;

	/**
	 * @var string
	 */
	private string $token;

	/**
	 * @param string[] $option_names Acquired option names.
	 * @param string   $token        Lease token.
	 */
	public function __construct( array $option_names, string $token ) {
		$this->option_names = $option_names;
		$this->token        = $token;
	}

	/**
	 * Release all slots owned by this lease.
	 */
	public function release(): void {
		foreach ( $this->option_names as $option_name ) {
			OptionLeaseStore::release( $option_name, $this->token );
		}
	}
}
