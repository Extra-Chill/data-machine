<?php
/**
 * Data Machine Logger Functions
 *
 * Core logging implementation for the Data Machine system.
 * Logs are stored in the datamachine_logs database table with agent_id scoping.
 *
 * ARCHITECTURE:
 * - datamachine_log action (DataMachineActions.php): Public API for all log writes
 * - Logger utilities (this file): Core logging implementation and utilities
 * - LogRepository (Database/Logs): SQL storage backend
 *
 * @package DataMachine
 * @subpackage Engine
 * @since 0.1.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

use DataMachine\Core\Database\Logs\LogRepository;
use DataMachine\Abilities\PermissionHelper;

/**
 * Resolve agent_id from context array or PermissionHelper.
 *
 * Priority:
 * 1. Explicit agent_id in context
 * 2. PermissionHelper acting user → agent lookup
 *
 * @param array $context Log context array.
 * @return int|null Resolved agent_id, or null for system/unscoped.
 */
function datamachine_resolve_agent_id( array $context = array() ): ?int {
	// Priority 1: Explicit agent_id in context.
	if ( isset( $context['agent_id'] ) && is_numeric( $context['agent_id'] ) && $context['agent_id'] > 0 ) {
		return (int) $context['agent_id'];
	}

	// Priority 2: Resolve from PermissionHelper acting user.
	try {
		if ( class_exists( PermissionHelper::class ) ) {
			$user_id = PermissionHelper::acting_user_id();
			if ( $user_id > 0 && class_exists( \DataMachine\Core\Database\Agents\Agents::class ) ) {
				$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
				$agent       = $agents_repo->get_by_owner_id( $user_id );
				if ( $agent && ! empty( $agent['agent_id'] ) ) {
					return (int) $agent['agent_id'];
				}
			}
		}
	} catch ( \Exception $e ) {
		// Silently fail — don't let agent resolution crash logging.
		unset( $e );
	}

	return null;
}

/**
 * Log a message to the database.
 *
 * Resolves agent_id from context or PermissionHelper, resolves user_id,
 * and inserts into the datamachine_logs table via LogRepository.
 *
 * @param string             $level   Log level string (debug, info, warning, error, critical).
 * @param string|\Stringable $message Message to log.
 * @param array              $context Optional context data.
 */
function datamachine_log_message( string $level, string|\Stringable $message, array $context = array() ): void {
	try {
		$repo     = new LogRepository();
		$agent_id = datamachine_resolve_agent_id( $context );

		// Resolve user_id.
		$user_id = null;
		if ( isset( $context['user_id'] ) && is_numeric( $context['user_id'] ) && $context['user_id'] > 0 ) {
			$user_id = (int) $context['user_id'];
		} elseif ( class_exists( PermissionHelper::class ) ) {
			$acting = PermissionHelper::acting_user_id();
			if ( $acting > 0 ) {
				$user_id = $acting;
			}
		}

		// Clean context: remove routing fields that are now handled structurally.
		unset( $context['agent_type'] );

		$repo->log( $level, (string) $message, $context, $agent_id, $user_id );
	} catch ( \Exception $e ) {
		// Prevent logging failures from crashing the application.
		unset( $e );
	}
}

/**
 * Log an error message.
 *
 * @param string|\Stringable $message Error message.
 * @param array              $context Optional context data.
 */
function datamachine_log_error( string|\Stringable $message, array $context = array() ): void {
	datamachine_log_message( 'error', $message, $context );
}

/**
 * Log a warning message.
 *
 * @param string|\Stringable $message Warning message.
 * @param array              $context Optional context data.
 */
function datamachine_log_warning( string|\Stringable $message, array $context = array() ): void {
	datamachine_log_message( 'warning', $message, $context );
}

/**
 * Log an informational message.
 *
 * @param string|\Stringable $message Info message.
 * @param array              $context Optional context data.
 */
function datamachine_log_info( string|\Stringable $message, array $context = array() ): void {
	datamachine_log_message( 'info', $message, $context );
}

/**
 * Log a debug message.
 *
 * @param string|\Stringable $message Debug message.
 * @param array              $context Optional context data.
 */
function datamachine_log_debug( string|\Stringable $message, array $context = array() ): void {
	datamachine_log_message( 'debug', $message, $context );
}

/**
 * Log a critical message.
 *
 * @param string|\Stringable $message Critical message.
 * @param array              $context Optional context data.
 */
function datamachine_log_critical( string|\Stringable $message, array $context = array() ): void {
	datamachine_log_message( 'critical', $message, $context );
}

/**
 * Get all valid log levels that can be used for logging operations.
 *
 * @return array Array of valid log level strings.
 */
function datamachine_get_valid_log_levels(): array {
	return array( 'debug', 'info', 'warning', 'error', 'critical' );
}
