<?php
/**
 * Request-local lifetime guard for recovery-owned step execution.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

use DataMachine\Core\Database\Jobs\Jobs;

defined( 'ABSPATH' ) || exit;

final class RecoveryExecutionFence {
	public function __construct(
		private readonly int $job_id,
		private readonly string $token,
		private readonly int $generation
	) {
		Jobs::begin_recovery_execution_fence( $job_id, $token, $generation );
	}

	public function __destruct() {
		Jobs::end_recovery_execution_fence( $this->job_id, $this->token, $this->generation );
	}
}
