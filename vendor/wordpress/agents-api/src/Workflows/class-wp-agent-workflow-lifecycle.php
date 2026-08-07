<?php
/**
 * Durable workflow lifecycle events.
 *
 * `WP_Agent_Workflow_Registry::register()` already fires
 * `wp_agent_workflow_registered` for code-defined workflows. That hook only
 * covers in-memory registrations made during plugin boot — which is the
 * right hook for "this workflow is known to this request", but is the
 * wrong hook for "this workflow is durably active and should have
 * scheduled work attached".
 *
 * Durable, store-backed workflows (custom post types, custom tables,
 * external services — see {@see WP_Agent_Workflow_Store}) need a separate
 * event surface that fires when the persisted record changes:
 *
 *   - saved    — created or updated in the store
 *   - deleted  — removed from the store
 *   - disabled — kept in the store but should not have scheduled work
 *   - enabled  — re-activated after being disabled
 *
 * This class is the canonical place to fire those events. Store
 * implementations call into the matching static method after each
 * operation; subscribers (the Action Scheduler bridge sync, audit logs,
 * cache invalidation) listen for the corresponding action.
 *
 * Keeping the event surface in one place avoids hook-name typos and gives
 * the substrate a single contract to document, evolve, and test.
 *
 * @package AgentsAPI
 * @since   0.108.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

final class WP_Agent_Workflow_Lifecycle {

	/**
	 * Fires after a durable workflow record is created or updated.
	 *
	 * Subscribers should treat this as the canonical "this workflow is
	 * durably active" signal. The Action Scheduler bridge listens here
	 * to (re-)sync any cron schedules attached to the spec.
	 *
	 * @since 0.108.0
	 *
	 * @param WP_Agent_Workflow_Spec $spec The persisted spec.
	 */
	public static function saved( WP_Agent_Workflow_Spec $spec ): void {
		/**
		 * Fires after a workflow is saved to a durable store.
		 *
		 * Distinct from `wp_agent_workflow_registered`, which only fires
		 * for code-defined registrations made during plugin boot.
		 *
		 * @since 0.108.0
		 *
		 * @param WP_Agent_Workflow_Spec $spec
		 */
		do_action( 'wp_agent_workflow_saved', $spec );
	}

	/**
	 * Fires after a durable workflow record is removed from its store.
	 *
	 * Accepts either the deleted spec (when the store had it loaded at
	 * delete time) or just the id (when the store only knows the id).
	 * The hook payload is the spec when available, falling back to a
	 * minimal spec wrapper carrying just the id.
	 *
	 * @since 0.108.0
	 *
	 * @param string                       $workflow_id The deleted workflow id.
	 * @param WP_Agent_Workflow_Spec|null  $spec        The deleted spec, if known.
	 */
	public static function deleted( string $workflow_id, ?WP_Agent_Workflow_Spec $spec = null ): void {
		/**
		 * Fires after a workflow is deleted from a durable store.
		 *
		 * Subscribers (e.g. the Action Scheduler bridge) should treat
		 * this as a signal to tear down any scheduled work keyed on the
		 * workflow id.
		 *
		 * @since 0.108.0
		 *
		 * @param string                      $workflow_id
		 * @param WP_Agent_Workflow_Spec|null $spec
		 */
		do_action( 'wp_agent_workflow_deleted', $workflow_id, $spec );
	}

	/**
	 * Fires when a durable workflow is taken out of active scheduling
	 * without being deleted. The store record stays; cron-attached work
	 * should stop.
	 *
	 * @since 0.108.0
	 *
	 * @param WP_Agent_Workflow_Spec $spec The disabled spec (still persisted).
	 */
	public static function disabled( WP_Agent_Workflow_Spec $spec ): void {
		/**
		 * Fires when a durable workflow is disabled.
		 *
		 * @since 0.108.0
		 *
		 * @param WP_Agent_Workflow_Spec $spec
		 */
		do_action( 'wp_agent_workflow_disabled', $spec );
	}

	/**
	 * Fires when a previously-disabled durable workflow is re-activated.
	 *
	 * @since 0.108.0
	 *
	 * @param WP_Agent_Workflow_Spec $spec The re-enabled spec.
	 */
	public static function enabled( WP_Agent_Workflow_Spec $spec ): void {
		/**
		 * Fires when a previously-disabled durable workflow is re-enabled.
		 *
		 * @since 0.108.0
		 *
		 * @param WP_Agent_Workflow_Spec $spec
		 */
		do_action( 'wp_agent_workflow_enabled', $spec );
	}
}
