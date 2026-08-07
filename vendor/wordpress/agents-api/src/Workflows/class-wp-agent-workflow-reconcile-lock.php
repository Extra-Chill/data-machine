<?php
/**
 * Cross-process serialization lock for the branch-reconcile critical section.
 *
 * WHY THIS EXISTS. {@see agents_reconcile_workflow_branch()} merges one finished
 * branch into the suspended run's `metadata._suspension.completed[]` map, then
 * decides whether EVERY branch is now terminal (and, if so, aggregates + resumes).
 * That merge is a read-modify-write on shared per-run state:
 *
 *     $frame     = load();          // read completed[]
 *     $completed = merge( $me );    // modify: add my handle
 *     save( $frame );               // write completed[] back
 *     if ( count($completed) === count($handles) ) resume();  // decide
 *
 * When N branches finish CONCURRENTLY in N separate processes (the async Action
 * Scheduler path — each branch runs in its own claimed AS worker), two reconciles
 * can both read the frame BEFORE either writes. Each merges only its OWN handle
 * and the later write CLOBBERS the earlier one (a classic lost update). The frame
 * then permanently shows fewer than N completed handles, the "all terminal →
 * enqueue resume" transition NEVER fires, and the run hangs SUSPENDED forever —
 * observed twice in a real MySQL multi-page fanout A/B.
 *
 * AS's atomic action-claim already de-duplicates the RESUME action, but it does
 * NOT guard THIS write — a different write, on the frame, not the resume action.
 * This lock closes that gap by serializing the reconcile critical section per
 * run so each reconcile reads the frame AFTER the previous one committed.
 *
 * TABLE-FREE. Under the substrate's no-new-tables constraint the lock uses
 * `add_option()` as the atomic compare-and-set — `add_option()` performs an
 * INSERT that fails (returns false) when the option row already exists, because
 * `option_name` is a UNIQUE key. That is exactly the primitive the CPT
 * conversation lock uses (`add_post_meta( ..., $unique = true )`); this is its
 * option-scoped sibling, chosen because reconcile is core and cannot assume a
 * consumer's recorder storage. No new table, no hand-rolled file/APCu lock.
 *
 * PLUGGABLE. A consumer with a stronger primitive (MySQL `GET_LOCK`, Redis) can
 * replace acquisition/release via the `wp_agent_workflow_reconcile_lock` filter.
 * The default here is correct and dependency-free.
 *
 * @package AgentsAPI
 * @since   0.5.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

/**
 * Default `add_option()`-CAS per-run reconcile lock.
 *
 * A held lock stores an expiry so a crashed holder's lock is reclaimable after
 * its TTL (a process that dies mid-critical-section must not strand every future
 * reconcile for the run). Acquisition blocks with bounded retries because a
 * reconcile critical section is short (an in-memory merge + a recorder write),
 * so a waiter reliably wins within a few spins rather than dropping the branch.
 */
final class WP_Agent_Workflow_Reconcile_Lock {

	/**
	 * Option-name prefix for the per-run lock row. The run id is appended so
	 * locks never collide across runs and are trivially inspectable/cleanable.
	 *
	 * @since 0.5.0
	 */
	private const OPTION_PREFIX = 'agents_wf_reconcile_lock_';

	/**
	 * Lock time-to-live (seconds). After this a stale lock (crashed holder) is
	 * reclaimable. Generous relative to a reconcile's real duration so a healthy
	 * holder is never evicted mid-section, yet short enough that a crash does not
	 * strand the run for long.
	 *
	 * @since 0.5.0
	 */
	private const TTL_SECONDS = 60;

	/**
	 * Max acquisition attempts before giving up. With the sleep below this is a
	 * bounded wait; a reconcile section is short, so contenders win quickly.
	 *
	 * @since 0.5.0
	 */
	private const MAX_ATTEMPTS = 50;

	/**
	 * Per-attempt backoff (microseconds). 20ms × 50 attempts ≈ 1s worst-case
	 * wait — well under the TTL, so a waiter either wins or reclaims a stale lock.
	 *
	 * @since 0.5.0
	 */
	private const RETRY_USLEEP = 20000;

	/**
	 * Run one callback under an exclusive per-run lock. The callback runs at most
	 * once and only while the lock is held; the lock is always released, even if
	 * the callback throws. When the lock genuinely cannot be acquired (a wedged
	 * holder that never expires) the callback still runs — stranding the branch
	 * would be strictly worse than a best-effort merge, and the TTL makes a real
	 * crash reclaimable so this fallback is rare.
	 *
	 * @since 0.5.0
	 *
	 * @template T
	 * @param string           $run_id   Run whose reconcile section is serialized.
	 * @param callable():T     $critical The critical section (find → merge → decide).
	 * @return T The callback's return value.
	 */
	public static function with_lock( string $run_id, callable $critical ) {
		$token = self::acquire( $run_id );
		try {
			return $critical();
		} finally {
			if ( null !== $token ) {
				self::release( $run_id, $token );
			}
		}
	}

	/**
	 * Acquire the per-run lock, blocking with bounded retries. Returns the lock
	 * token on success, or null when acquisition ultimately failed (the caller
	 * proceeds without the lock rather than dropping the branch — see
	 * {@see self::with_lock()}).
	 *
	 * @since 0.5.0
	 *
	 * @param string $run_id Run id.
	 * @return string|null Lock token, or null if unacquired after MAX_ATTEMPTS.
	 */
	private static function acquire( string $run_id ): ?string {
		if ( ! function_exists( 'add_option' ) || ! function_exists( 'get_option' ) ) {
			// No option layer (e.g. a non-WordPress harness) — nothing to lock
			// against; callers are already single-process there.
			return null;
		}

		$option = self::OPTION_PREFIX . md5( $run_id );

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$token   = self::mint_token();
			$expires = time() + self::TTL_SECONDS;
			$payload = array(
				'token'   => $token,
				'expires' => $expires,
			);

			// Fast path: no lock row → atomic INSERT wins. add_option() returns
			// false if the row already exists (the option_name unique key is the
			// compare-and-set), so exactly one concurrent caller sees true.
			if ( add_option( $option, $payload, '', false ) ) {
				return $token;
			}

			// A lock row exists. Reclaim it only if it has expired (a crashed
			// holder). update_option() is not itself a CAS, so re-read after
			// writing and confirm OUR token won — two racers reclaiming the same
			// stale lock both write, but only the last writer's token survives the
			// re-read, and the loser retries.
			$existing   = get_option( $option, false );
			$expires_at = is_array( $existing ) && is_numeric( $existing['expires'] ?? null ) ? (int) $existing['expires'] : 0;
			if ( $expires_at <= time() ) {
				update_option( $option, $payload, false );
				$confirm = get_option( $option, false );
				if ( is_array( $confirm ) && ( $confirm['token'] ?? '' ) === $token ) {
					return $token;
				}
			}

			self::backoff();
		}

		return null;
	}

	/**
	 * Release the per-run lock only if the supplied token still owns it. A token
	 * that no longer matches (the lock expired and was reclaimed by another
	 * process) must NOT delete the current holder's lock.
	 *
	 * @since 0.5.0
	 *
	 * @param string $run_id Run id.
	 * @param string $token  Token returned by acquire().
	 * @return void
	 */
	private static function release( string $run_id, string $token ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'delete_option' ) ) {
			return;
		}
		$option   = self::OPTION_PREFIX . md5( $run_id );
		$existing = get_option( $option, false );
		if ( is_array( $existing ) && ( $existing['token'] ?? '' ) === $token ) {
			delete_option( $option );
		}
	}

	/**
	 * Mint a unique lock token.
	 *
	 * @since 0.5.0
	 */
	private static function mint_token(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return uniqid( 'lock_', true );
		}
	}

	/**
	 * Sleep briefly between acquisition attempts.
	 *
	 * @since 0.5.0
	 */
	private static function backoff(): void {
		usleep( self::RETRY_USLEEP );
	}
}
