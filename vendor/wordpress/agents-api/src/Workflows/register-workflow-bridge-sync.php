<?php
/**
 * Keep cron-triggered workflow schedules in sync with both registration
 * and durable-store lifecycle events.
 *
 * Two distinct surfaces feed the Action Scheduler bridge:
 *
 *   - `wp_agent_workflow_registered` — code-defined workflows added to the
 *     in-memory registry via `wp_register_workflow()` during plugin boot.
 *   - `wp_agent_workflow_saved` / `_deleted` / `_disabled` / `_enabled` —
 *     durable workflows persisted by a {@see WP_Agent_Workflow_Store}
 *     implementation. The substrate ships no default store; consumers
 *     call {@see WP_Agent_Workflow_Lifecycle::saved()} etc. after each
 *     store operation, and this subscriber keeps AS in sync.
 *
 * Both surfaces converge on the same scheduling primitive, but the lifecycle
 * hooks must be the way persisted workflows trigger scheduling — `_registered`
 * is for the "loaded into memory for this request" case and would lie about
 * persistence state otherwise.
 *
 * @package AgentsAPI
 * @since   0.107.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_agent_workflow_registered',
	static function ( WP_Agent_Workflow_Spec $spec ): void {
		WP_Agent_Workflow_Action_Scheduler_Bridge::register( $spec );
	}
);

add_action(
	'wp_agent_workflow_saved',
	static function ( WP_Agent_Workflow_Spec $spec ): void {
		WP_Agent_Workflow_Action_Scheduler_Bridge::sync( $spec );
	}
);

add_action(
	'wp_agent_workflow_deleted',
	static function ( string $workflow_id, ?WP_Agent_Workflow_Spec $spec = null ): void {
		unset( $spec );
		WP_Agent_Workflow_Action_Scheduler_Bridge::unregister( $workflow_id );
	},
	10,
	2
);

add_action(
	'wp_agent_workflow_disabled',
	static function ( WP_Agent_Workflow_Spec $spec ): void {
		WP_Agent_Workflow_Action_Scheduler_Bridge::unregister( $spec->get_id() );
	}
);

add_action(
	'wp_agent_workflow_enabled',
	static function ( WP_Agent_Workflow_Spec $spec ): void {
		WP_Agent_Workflow_Action_Scheduler_Bridge::sync( $spec );
	}
);
