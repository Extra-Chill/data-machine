<?php
/**
 * Optional Action Scheduler bridge for `cron`-triggered workflows.
 *
 * agents-api does not require Action Scheduler. Consumers that want
 * scheduled workflow runs can either:
 *
 *   - Install Action Scheduler themselves (it ships with WooCommerce, can
 *     be installed standalone via composer) — this bridge will detect the
 *     `as_*` functions and use them.
 *   - Skip cron entirely and rely on `on_demand` / `wp_action` triggers.
 *   - Wire their own scheduler — the bridge fires
 *     `wp_agent_workflow_schedule_requested` so a custom scheduler can
 *     pick up the same trigger declaration.
 *
 * The bridge is a thin static helper, not a hard dependency. Calling
 * `is_available()` first is the polite check; `register()` no-ops cleanly
 * if AS isn't loaded.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Workflow_Action_Scheduler_Bridge {

	/** @since 0.103.0 */
	public const SCHEDULED_HOOK = 'wp_agent_workflow_run_scheduled';

	/** @since 0.103.0 */
	public const GROUP = 'agents-api';

	public static function is_available(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_schedule_cron_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Register every `cron` trigger on the spec with Action Scheduler.
	 * Existing schedules for the same workflow are unscheduled first to
	 * make this idempotent — call freely on every plugin boot.
	 *
	 * Cron triggers may use either `interval` (seconds, recurring) or
	 * `expression` (cron string). Mixed usage in a single trigger entry
	 * was rejected by the validator.
	 *
	 * @since 0.103.0
	 *
	 * @param WP_Agent_Workflow_Spec $spec
	 * @return int Number of schedules registered.
	 */
	public static function register( WP_Agent_Workflow_Spec $spec ): int {
		$count = 0;
		foreach ( $spec->get_triggers() as $trigger ) {
			if ( 'cron' !== ( $trigger['type'] ?? '' ) ) {
				continue;
			}

			/**
			 * Fires whenever the bridge would schedule a cron trigger,
			 * regardless of whether Action Scheduler is loaded. Custom
			 * schedulers can hook this to take over.
			 *
			 * @since 0.103.0
			 *
			 * @param WP_Agent_Workflow_Spec $spec
			 * @param array<mixed>                  $trigger
			 */
			do_action( 'wp_agent_workflow_schedule_requested', $spec, $trigger );

			if ( ! self::is_available() ) {
				continue;
			}

			$args = array( 'workflow_id' => $spec->get_id() );

			// Unschedule prior occurrences for idempotency.
			as_unschedule_all_actions( self::SCHEDULED_HOOK, $args, self::GROUP );

			$scheduled = null;
			if ( ! empty( $trigger['expression'] ) && is_scalar( $trigger['expression'] ) ) {
				$scheduled = as_schedule_cron_action(
					time(),
					(string) $trigger['expression'],
					self::SCHEDULED_HOOK,
					$args,
					self::GROUP
				);
			} elseif ( ! empty( $trigger['interval'] ) && is_numeric( $trigger['interval'] ) ) {
				$scheduled = as_schedule_recurring_action(
					time(),
					(int) $trigger['interval'],
					self::SCHEDULED_HOOK,
					$args,
					self::GROUP
				);
			}

			if ( ! empty( $scheduled ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Re-sync the AS schedule for a durable workflow spec.
	 *
	 * Unconditionally unschedules every existing AS action keyed on this
	 * workflow id, then registers the cron triggers declared on the new
	 * spec. Distinct from {@see register()} because it tears down stale
	 * schedules even when the new spec has no cron triggers — the case
	 * an update that switches from `cron` to `on_demand` would otherwise
	 * leak. This is the method durable-store lifecycle subscribers want.
	 *
	 * @since 0.108.0
	 *
	 * @return int Number of schedules registered (zero if the new spec
	 *             has no cron triggers; the unschedule step still ran).
	 */
	public static function sync( WP_Agent_Workflow_Spec $spec ): int {
		self::unregister( $spec->get_id() );
		return self::register( $spec );
	}

	/**
	 * Cancel every scheduled action this bridge owns for the given
	 * workflow id. Useful on workflow deletion or version bump.
	 *
	 * @since 0.103.0
	 */
	public static function unregister( string $workflow_id ): void {
		if ( ! self::is_available() ) {
			return;
		}
		as_unschedule_all_actions(
			self::SCHEDULED_HOOK,
			array( 'workflow_id' => $workflow_id ),
			self::GROUP
		);
	}
}
