<?php
/**
 * Storage contract for workflow specs.
 *
 * agents-api ships no default implementation. Consumers implement this
 * against their preferred storage layer — a custom post type, a custom
 * table, an external service, an in-memory store for tests. Each consumer
 * picks indexes and durability guarantees that match its product surface.
 *
 * This interface deliberately covers durable persistence (find, save,
 * delete, list). Code-defined workflows registered via
 * {@see WP_Agent_Workflow_Registry::register()} are an in-memory layer
 * that sits in front of any store; consumers compose the two however they
 * like.
 *
 * ## Lifecycle event contract
 *
 * Implementations MUST notify the substrate after each durable state
 * change by calling the matching method on
 * {@see WP_Agent_Workflow_Lifecycle}:
 *
 *   - After a successful `save()`     → `WP_Agent_Workflow_Lifecycle::saved( $spec )`
 *   - After a successful `delete()`   → `WP_Agent_Workflow_Lifecycle::deleted( $id, $spec )`
 *   - After taking a workflow out of active scheduling
 *                                       without deleting → `disabled( $spec )`
 *   - After re-activating a disabled workflow → `enabled( $spec )`
 *
 * Subscribers (the Action Scheduler bridge, audit logs, cache
 * invalidators) rely on these hooks to keep external state in sync. The
 * substrate does not fire them from inside this interface because it does
 * not own the storage, but the contract is mandatory: a store that skips
 * the lifecycle calls will leak scheduled work on delete and miss
 * scheduling on save.
 *
 * The lifecycle hooks intentionally live separately from
 * `wp_agent_workflow_registered`, which fires when a code-defined
 * workflow is added to the in-memory registry during request boot. That
 * hook says "this workflow is known for this request"; the lifecycle
 * hooks say "this workflow is durably active and should have scheduled
 * work attached".
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

use WP_Error;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Workflow_Store {

	/**
	 * Look up a workflow by its id. Returns null when not found.
	 *
	 * @since 0.103.0
	 *
	 * @param string $workflow_id
	 * @return WP_Agent_Workflow_Spec|null
	 */
	public function find( string $workflow_id ): ?WP_Agent_Workflow_Spec;

	/**
	 * Persist a workflow spec, creating or updating in place.
	 *
	 * @since 0.103.0
	 *
	 * @param WP_Agent_Workflow_Spec $spec
	 * @return true|WP_Error
	 */
	public function save( WP_Agent_Workflow_Spec $spec );

	/**
	 * Remove a workflow spec by id.
	 *
	 * @since 0.103.0
	 *
	 * @param string $workflow_id
	 * @return true|WP_Error
	 */
	public function delete( string $workflow_id );

	/**
	 * List stored workflows. Implementations may paginate / filter via
	 * `$args` — accepted keys are `limit`, `offset`, and arbitrary
	 * implementation-specific keys (the substrate doesn't enforce a
	 * uniform query DSL — consumers know their own indexes best).
	 *
	 * @since 0.103.0
	 *
	 * @param array<mixed> $args
	 * @return WP_Agent_Workflow_Spec[]
	 */
	public function all( array $args = array() ): array;
}
