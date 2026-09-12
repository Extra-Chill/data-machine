<?php
/**
 * Hash-gated decorator for the Agents API routine scheduling backend.
 *
 * The Agents API routine registry is in-memory per request: consumers must
 * re-declare their routines on every boot. The default Action Scheduler
 * backend treats every `register()` as authoritative — it unschedules the
 * existing chain and schedules a fresh one, minting a new generation each
 * time. For a consumer with ~700 persisted flow routines that would thrash
 * the Action Scheduler tables on every request.
 *
 * This decorator sits between the registry and the default backend and
 * no-ops `register()` when the routine's schedule-significant fingerprint
 * (trigger type, interval seconds / cron expression, stagger window, wake
 * target, and input) matches the last value this backend actually
 * scheduled. Unrelated flow updates therefore leave timers untouched,
 * which preserves the pre-convergence `scheduling_unchanged` behavior.
 *
 * The fingerprint lives in one autoloaded-off option keyed by routine id —
 * a consumer-side cache, not a scheduling substrate. Upstream tracking:
 * Registry needs a non-persisting adopt/declare path for consumers with
 * many persisted routines (Automattic/agents-api).
 *
 * Lifecycle rules:
 *  - `register()` on a matching hash is a no-op returning true.
 *  - `unregister()` and `pause()` delete the hash entry, so a later
 *    `register()` (resume, boot self-heal) passes through and rebuilds.
 *  - `resume()` delegates and records the hash.
 *  - Verification mode (see {@see set_verification_mode()}) makes every
 *    `register()` pass through unconditionally; reconciliation only calls
 *    `register()` when coverage is already known to be missing, so its
 *    repairs must never be swallowed by the hash gate.
 *
 * @package DataMachine\Engine\Scheduling
 * @since   1.0.0
 */

namespace DataMachine\Engine\Scheduling;

use AgentsAPI\AI\Routines\WP_Agent_Routine;
use AgentsAPI\AI\Routines\WP_Agent_Routine_Backend;

defined( 'ABSPATH' ) || exit;

final class HashGatedRoutineBackend implements WP_Agent_Routine_Backend {

	/**
	 * Option holding the per-routine schedule fingerprints.
	 *
	 * Shape: array<string routine_id, string md5 hash>.
	 */
	private const HASH_OPTION = 'datamachine_routine_schedule_hashes';

	/**
	 * The wrapped backend.
	 */
	private WP_Agent_Routine_Backend $backend;

	/**
	 * Cached persisted fingerprints.
	 *
	 * @var array<string, string>
	 */
	private static array $hashes = array();

	/**
	 * Whether the persisted fingerprint cache has been loaded.
	 */
	private static bool $hashes_loaded = false;

	/**
	 * Fingerprints recorded this request but not yet persisted, keyed by
	 * routine id. A null value marks a deletion.
	 *
	 * @var array<string, string|null>
	 */
	private static array $dirty = array();

	/**
	 * When true, register() passes through unconditionally (reconciliation).
	 */
	private static bool $verification_mode = false;

	/**
	 * Wrap a real backend.
	 *
	 * @param WP_Agent_Routine_Backend $backend The decorated backend.
	 */
	public function __construct( WP_Agent_Routine_Backend $backend ) {
		$this->backend = $backend;
	}

	/**
	 * Test-only: forget cached fingerprints, dirty writes, and mode.
	 */
	public static function reset_state(): void {
		self::$hashes            = array();
		self::$hashes_loaded     = false;
		self::$dirty             = array();
		self::$verification_mode = false;
	}

	/**
	 * Whether register() currently passes through unconditionally.
	 *
	 * Enabled around reconciliation so repairs are never swallowed.
	 *
	 * @param bool $mode True to enable verification (pass-through) mode.
	 */
	public static function set_verification_mode( bool $mode ): void {
		self::$verification_mode = $mode;
	}

	/**
	 * Schedule-significant fingerprint of a routine.
	 *
	 * Deliberately excludes label, prompt, session id, and meta: renaming a
	 * flow must not reset its timers.
	 *
	 * @param WP_Agent_Routine $routine Routine value object.
	 */
	public static function schedule_hash( WP_Agent_Routine $routine ): string {
		$significant = array(
			'target'   => '' !== $routine->get_ability() ? $routine->get_ability() : $routine->get_agent_slug(),
			'input'    => $routine->get_input(),
			'trigger'  => $routine->get_trigger_type(),
			'interval' => $routine->get_interval_seconds(),
			'express'  => $routine->get_expression(),
			'stagger'  => $routine->get_stagger_window(),
		);

		$encoded = wp_json_encode( $significant );
		return md5( is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * Persist any fingerprints recorded during this request.
	 *
	 * A failed or killed write is safe: stale fingerprints only cause the
	 * next boot to pass through and re-schedule, never to skip a schedule.
	 */
	public static function persist(): void {
		if ( array() === self::$dirty ) {
			return;
		}

		$hashes = self::load_hashes();
		foreach ( self::$dirty as $routine_id => $hash ) {
			if ( null === $hash ) {
				unset( $hashes[ $routine_id ] );
			} else {
				$hashes[ $routine_id ] = $hash;
			}
		}

		self::$dirty  = array();
		self::$hashes = $hashes;

		if ( array() === $hashes ) {
			delete_option( self::HASH_OPTION );
			return;
		}

		update_option( self::HASH_OPTION, $hashes, false );
	}

	public function is_available(): bool {
		return $this->backend->is_available();
	}

	public function register( WP_Agent_Routine $routine ): bool {
		if ( ! self::$verification_mode ) {
			$hashes     = self::load_hashes();
			$routine_id = $routine->get_id();
			if ( isset( $hashes[ $routine_id ] )
				&& hash_equals( $hashes[ $routine_id ], self::schedule_hash( $routine ) )
			) {
				return true;
			}
		}

		$registered = $this->backend->register( $routine );
		if ( $registered ) {
			self::$dirty[ $routine->get_id() ] = self::schedule_hash( $routine );
		}

		return $registered;
	}

	public function unregister( string $routine_id ): void {
		$this->backend->unregister( $routine_id );
		self::$dirty[ $routine_id ] = null;
	}

	public function pause( string $routine_id ): void {
		$this->backend->pause( $routine_id );
		// Invalidate the fingerprint: a later register() (resume, boot) must
		// pass through and rebuild the cancelled chain.
		self::$dirty[ $routine_id ] = null;
	}

	public function resume( WP_Agent_Routine $routine ): bool {
		$registered = $this->backend->resume( $routine );
		if ( $registered ) {
			self::$dirty[ $routine->get_id() ] = self::schedule_hash( $routine );
		}

		return $registered;
	}

	public function run_now( WP_Agent_Routine $routine ): bool {
		return $this->backend->run_now( $routine );
	}

	public function is_paused( string $routine_id ): bool {
		return $this->backend->is_paused( $routine_id );
	}

	public function current_generation( string $routine_id ): ?string {
		return $this->backend->current_generation( $routine_id );
	}

	/**
	 * @return array<string, list<int>>
	 */
	public function pending_by_routine(): array {
		return $this->backend->pending_by_routine();
	}

	public function cancel( int $handle ): bool {
		return $this->backend->cancel( $handle );
	}

	/**
	 * @return array<string, string>
	 */
	private static function load_hashes(): array {
		if ( self::$hashes_loaded ) {
			return self::$hashes;
		}

		self::$hashes_loaded = true;
		$stored              = get_option( self::HASH_OPTION, array() );

		if ( is_array( $stored ) ) {
			foreach ( $stored as $routine_id => $hash ) {
				if ( is_string( $routine_id ) && '' !== $routine_id && is_string( $hash ) && '' !== $hash ) {
					self::$hashes[ $routine_id ] = $hash;
				}
			}
		}

		return self::$hashes;
	}
}
