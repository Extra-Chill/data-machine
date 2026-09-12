<?php
/**
 * The scheduling contract behind the routine registry.
 *
 * A routine backend is the ONLY thing the registry talks to for durable
 * schedule state. It knows nothing about the underlying mechanism — Action
 * Scheduler (the default backend when present), a caller's own cron table,
 * or a deterministic test double. The registry owns the in-memory routine
 * map and the reconcile algorithm; the backend owns only the mechanism that
 * actually stores schedules, answers "what is pending", and cancels handles.
 *
 * Handles are opaque ints. The registry never interprets them — it only
 * groups them by routine id (via {@see pending_by_routine()}) and passes
 * them back to {@see cancel()}.
 *
 * The default backend is resolved once per request by
 * {@see WP_Agent_Routine_Registry::backend()}; consumers can substitute
 * their own implementation through the `wp_agent_routine_backend` filter.
 * With no backend available, routines stay registered but inert: lifecycle
 * verbs still fire their hooks, and nothing is scheduled.
 *
 * @package AgentsAPI
 * @since   0.11.0
 */

namespace AgentsAPI\AI\Routines;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Routine_Backend {

	/**
	 * Whether this backend can durably schedule routines in the current
	 * environment (e.g. its scheduling engine is loaded).
	 *
	 * @since 0.11.0
	 */
	public function is_available(): bool;

	/**
	 * (Re-)register a routine's schedule. Idempotent and cheap to call on
	 * every boot: when the routine already has a matching schedule in
	 * place, implementations must treat this as a read-only no-op rather
	 * than unconditionally tearing down and recreating it — a backend that
	 * always replaces the schedule does not scale to consumers with
	 * hundreds of persisted routines calling register() on every request.
	 *
	 * @since 0.11.0
	 *
	 * @param WP_Agent_Routine $routine The routine to schedule.
	 * @return bool True when a schedule is in place (freshly registered or
	 *              already matching); false on no-op due to the backend
	 *              being unavailable or the schedule call failing.
	 */
	public function register( WP_Agent_Routine $routine ): bool;

	/**
	 * Cancel every schedule this backend owns for the routine and drop any
	 * bookkeeping (generation, paused marker) associated with it.
	 *
	 * @since 0.11.0
	 *
	 * @param string $routine_id The routine id to tear down.
	 */
	public function unregister( string $routine_id ): void;

	/**
	 * Cancel the routine's schedule without unregistering the routine
	 * itself. The pause is recorded durably so
	 * {@see WP_Agent_Routine_Registry::reconcile()} can tell "unscheduled on
	 * purpose" from "missing by drift".
	 *
	 * @since 0.11.0
	 *
	 * @param string $routine_id The routine id to pause.
	 */
	public function pause( string $routine_id ): void;

	/**
	 * Re-establish the schedule for a previously-paused routine. Idempotent.
	 *
	 * @since 0.11.0
	 *
	 * @param WP_Agent_Routine $routine The routine to resume.
	 * @return bool True when a schedule was (re-)established.
	 */
	public function resume( WP_Agent_Routine $routine ): bool;

	/**
	 * Enqueue a one-shot wake for the routine, in addition to its recurring
	 * schedule. The next scheduled wake is unaffected.
	 *
	 * @since 0.11.0
	 *
	 * @param WP_Agent_Routine $routine The routine to wake.
	 * @return bool True when the one-shot wake was enqueued.
	 */
	public function run_now( WP_Agent_Routine $routine ): bool;

	/**
	 * Whether the routine was durably paused via {@see pause()}.
	 *
	 * @since 0.11.0
	 *
	 * @param string $routine_id The routine id to check.
	 */
	public function is_paused( string $routine_id ): bool;

	/**
	 * The routine's current schedule generation, or null when none exists.
	 * Generations are how a backend implements fencing; a backend that does
	 * not fence may always return null.
	 *
	 * @since 0.11.0
	 *
	 * @param string $routine_id The routine id to read.
	 */
	public function current_generation( string $routine_id ): ?string;

	/**
	 * All pending schedule handles this backend owns, grouped by logical
	 * routine id. This is the one bulk read and belongs to
	 * {@see WP_Agent_Routine_Registry::reconcile()} only.
	 *
	 * @since 0.11.0
	 *
	 * @return array<string, list<int>> routine_id => pending backend handles.
	 */
	public function pending_by_routine(): array;

	/**
	 * Cancel one pending schedule handle previously returned by
	 * {@see pending_by_routine()}.
	 *
	 * @since 0.11.0
	 *
	 * @param int $handle The opaque backend handle to cancel.
	 * @return bool True when the cancel succeeded (or at least did not fail).
	 */
	public function cancel( int $handle ): bool;
}
