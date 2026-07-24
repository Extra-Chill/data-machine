<?php
/**
 * PHPStan stubs for the optional Action Scheduler class surface used by the
 * scoped-drain service. Action Scheduler is an optional runtime dependency (the
 * substrate detects it and no-ops when absent). Its classes are not part of the
 * WordPress core stubs, so the minimal surface the scoped drain touches — the
 * store, the queue runner, the claim, and the queue cleaner — is stubbed here for
 * static analysis. Only the members agents-api actually calls are declared.
 *
 * @see https://actionscheduler.org/
 *
 * phpcs:disable
 */

/**
 * A staked claim over a set of Action Scheduler actions.
 */
class ActionScheduler_ActionClaim {
	public function get_id(): string {
		return '';
	}

	/**
	 * @return array<int,int>
	 */
	public function get_actions(): array {
		return array();
	}
}

/**
 * Action Scheduler's persistent action store.
 */
abstract class ActionScheduler_Store {
	const STATUS_COMPLETE = 'complete';
	const STATUS_PENDING  = 'pending';
	const STATUS_RUNNING  = 'in-progress';
	const STATUS_FAILED   = 'failed';

	public static function instance(): ActionScheduler_Store {
		throw new \RuntimeException( 'stub' );
	}

	/**
	 * @param int                 $max_actions Maximum actions to claim.
	 * @param \DateTime|null      $before_date Claim actions scheduled before this date.
	 * @param array<int,string>   $hooks       Hook scope.
	 * @param string              $group       Group scope.
	 */
	public function stake_claim( int $max_actions = 10, ?\DateTime $before_date = null, array $hooks = array(), string $group = '' ): ActionScheduler_ActionClaim {
		return new ActionScheduler_ActionClaim();
	}

	public function release_claim( ActionScheduler_ActionClaim $claim ): void {}
}

/**
 * Action Scheduler's queue runner (returned by ActionScheduler::runner()).
 */
class ActionScheduler_Abstract_QueueRunner {
	/**
	 * @param int    $action_id Action id.
	 * @param string $context   Execution context label.
	 */
	public function process_action( int $action_id, string $context = '' ): void {}
}

/**
 * Action Scheduler facade.
 */
class ActionScheduler {
	public static function runner(): ActionScheduler_Abstract_QueueRunner {
		return new ActionScheduler_Abstract_QueueRunner();
	}

	public static function is_initialized( ?string $function_name = null ): bool {
		return false;
	}
}

/**
 * Resets stale claims / marks abandoned actions failed.
 */
class ActionScheduler_QueueCleaner {
	public function __construct( ?ActionScheduler_Store $store = null, int $batch_size = 20 ) {}

	/**
	 * @return array<int,int|string>
	 */
	public function reset_timeouts( int $time_limit = 300 ): array {
		return array();
	}

	/**
	 * @return array<int,int|string>
	 */
	public function mark_failures( int $time_limit = 300 ): array {
		return array();
	}
}
