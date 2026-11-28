<?php
/**
 * Hetzner backup-status contributions to Data Machine's existing health surfaces.
 *
 * Plugs into two extension points Data Machine core already exposes — no new
 * surface is introduced:
 *
 *  - `datamachine_system_health_checks` (consumed by datamachine/system-health-check)
 *    gains a `hetzner_backups` check. It is part of the default "all" run only
 *    when Hetzner is configured, so unconfigured installs see no noise.
 *  - `datamachine_wake_briefing_signals` (WakeBriefingTask) gains a terse line
 *    when a backup is stale, missing, or disabled, or when the API cannot be
 *    read. Healthy backups add nothing, matching the briefing's
 *    threshold-crossing contract.
 *
 * @package DataMachineBusiness\Abilities\Hetzner
 */

namespace DataMachineBusiness\Abilities\Hetzner;

defined( 'ABSPATH' ) || exit;

final class HetznerHealth {

	/**
	 * Health check type ID.
	 *
	 * @var string
	 */
	public const CHECK_ID = 'hetzner_backups';

	private static bool $registered = false;

	/**
	 * Per-request memo so the health check and the briefing never hit the API twice.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $memo = null;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		add_filter( 'datamachine_system_health_checks', array( self::class, 'filter_health_checks' ) );
		add_filter( 'datamachine_wake_briefing_signals', array( self::class, 'filter_wake_briefing_signals' ) );
		self::$registered = true;
	}

	/**
	 * Contribute the hetzner_backups check.
	 *
	 * @param mixed $checks Registered checks.
	 * @return array<string,array<string,mixed>>
	 */
	public static function filter_health_checks( $checks ): array {
		$checks = is_array( $checks ) ? $checks : array();

		$checks[ self::CHECK_ID ] = array(
			'label'    => __( 'Hetzner Backups', 'data-machine-business' ),
			'callback' => array( self::class, 'run_health_check' ),
			'default'  => HetznerAbilities::is_configured(),
		);

		return $checks;
	}

	/**
	 * Health check callback.
	 *
	 * @param mixed $options Per-check options (supports stale_hours, server_id).
	 * @return array<string,mixed>
	 */
	public static function run_health_check( $options = array() ): array {
		$options     = is_array( $options ) ? $options : array();
		$server_id   = (int) ( $options['server_id'] ?? 0 );
		$stale_hours = isset( $options['stale_hours'] ) && is_numeric( $options['stale_hours'] ) ? (float) $options['stale_hours'] : null;

		if ( 0 !== $server_id || null !== $stale_hours ) {
			return HetznerAbilities::backup_status( $server_id, $stale_hours );
		}

		return self::default_status();
	}

	/**
	 * Append wake-briefing lines for stale/missing/disabled backups.
	 *
	 * @param mixed $signals Existing signal lines.
	 * @return string[]
	 */
	public static function filter_wake_briefing_signals( $signals ): array {
		$signals = is_array( $signals ) ? $signals : array();

		if ( ! HetznerAbilities::is_configured() ) {
			return $signals;
		}

		foreach ( self::briefing_lines( self::default_status() ) as $line ) {
			$signals[] = $line;
		}

		return $signals;
	}

	/**
	 * Render briefing lines from a backup-status result.
	 *
	 * @param array<string,mixed> $status Output of HetznerAbilities::backup_status().
	 * @return string[]
	 */
	public static function briefing_lines( array $status ): array {
		if ( empty( $status['success'] ) ) {
			return array( '⚠ **Hetzner backups** — status unavailable: ' . (string) ( $status['error'] ?? 'unknown error' ) );
		}

		$lines = array();
		foreach ( (array) ( $status['issues'] ?? array() ) as $issue ) {
			$lines[] = '⚠ **Hetzner backups** — ' . (string) $issue;
		}

		return $lines;
	}

	/**
	 * Reset the per-request memo (tests).
	 */
	public static function reset(): void {
		self::$memo = null;
	}

	/**
	 * Default (all servers, default threshold) status, memoized per request.
	 *
	 * @return array<string,mixed>
	 */
	private static function default_status(): array {
		if ( null === self::$memo ) {
			self::$memo = HetznerAbilities::backup_status();
		}

		return self::$memo;
	}
}
