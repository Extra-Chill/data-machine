<?php
/**
 * Data Machine Logger Functions
 *
 * Core logging implementation for the Data Machine system.
 * Provides centralized logging utilities using Monolog with WordPress integration.
 * Supports per-agent-type log files and log levels.
 *
 * ARCHITECTURE:
 * - datamachine_log action (DataMachineActions.php): Operations that modify state (write, clear, cleanup, set_level)
 * - Logger utilities (this file): Core logging implementation and utilities
 *
 * @package DataMachine
 * @subpackage Engine
 * @since 0.1.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use DataMachine\Engine\AI\AgentType;
use DataMachine\Engine\AI\AgentContext;

/**
 * Get Monolog instance for a specific agent type with request-level caching.
 *
 * @param string $agent_type Agent type (pipeline, chat)
 * @param bool   $force_refresh Force recreation of Monolog instance
 * @return MonologLogger Configured Monolog instance
 */
function datamachine_get_monolog_instance( string $agent_type = AgentType::PIPELINE, bool $force_refresh = false ): MonologLogger {
	static $monolog_instances = array();

	if ( ! AgentType::isValid( $agent_type ) ) {
		$agent_type = AgentType::PIPELINE;
	}

	if ( ! isset( $monolog_instances[ $agent_type ] ) || $force_refresh ) {
		$log_level_setting = datamachine_get_log_level( $agent_type );
		$log_level         = datamachine_get_monolog_level( $log_level_setting );

		$channel_name                     = 'DataMachine-' . ucfirst( $agent_type );
		$monolog_instances[ $agent_type ] = new MonologLogger( $channel_name );

		if ( null !== $log_level ) {
			$log_file = datamachine_get_log_file_path( $agent_type );
			$handler  = new StreamHandler( $log_file, $log_level );

			$formatter = new LineFormatter(
				"[%datetime%] [%channel%.%level_name%]: %message% %context% %extra%\n",
				'Y-m-d H:i:s',
				true,
				true
			);
			$handler->setFormatter( $formatter );
			$monolog_instances[ $agent_type ]->pushHandler( $handler );
		}
	}

	return $monolog_instances[ $agent_type ];
}

/**
 * Convert string log level to Monolog Level.
 *
 * @param string $level_string Log level string (debug, error, none)
 * @return Level|null Monolog level constant, null for 'none'
 */
function datamachine_get_monolog_level( string $level_string ): ?Level {
	switch ( strtolower( $level_string ) ) {
		case 'debug':
			return Level::Debug;
		case 'error':
			return Level::Error;
		case 'none':
			return null;
		default:
			return Level::Debug;
	}
}

/**
 * Resolve agent type from context, execution context, or default.
 *
 * @param array $context Log context array
 * @return string Resolved agent type
 */
function datamachine_resolve_agent_type( array $context = array() ): string {
	// Priority 1: Explicit agent_type in context
	if ( isset( $context['agent_type'] ) && AgentType::isValid( $context['agent_type'] ) ) {
		return $context['agent_type'];
	}

	// Priority 2: Current execution context
	$execution_context = AgentContext::get();
	if ( null !== $execution_context && AgentType::isValid( $execution_context ) ) {
		return $execution_context;
	}

	// Priority 3: Default to pipeline
	return AgentType::PIPELINE;
}

/**
 * Log a message using Monolog.
 *
 * Routes to the appropriate log file based on agent_type in context,
 * current AgentContext, or defaults to pipeline.
 *
 * @param Level              $level Monolog level
 * @param string|\Stringable $message Message to log
 * @param array              $context Optional context data
 */
function datamachine_log_message( Level $level, string|\Stringable $message, array $context = array() ): void {
	try {
		$agent_type = datamachine_resolve_agent_type( $context );
		datamachine_get_monolog_instance( $agent_type )->log( $level, $message, $context );
	} catch ( \Exception $e ) {
		// Prevent logging failures from crashing the application
	}
}

/**
 * Log an error message.
 *
 * @param string|\Stringable $message Error message
 * @param array              $context Optional context data
 */
function datamachine_log_error( string|\Stringable $message, array $context = array() ): void {
	datamachine_log_message( Level::Error, $message, $context );
}

/**
 * Log a warning message.
 *
 * @param string|\Stringable $message Warning message
 * @param array              $context Optional context data
 */
function datamachine_log_warning( string|\Stringable $message, array $context = array() ): void {
	datamachine_log_message( Level::Warning, $message, $context );
}

/**
 * Log an informational message.
 *
 * @param string|\Stringable $message Info message
 * @param array              $context Optional context data
 */
function datamachine_log_info( string|\Stringable $message, array $context = array() ): void {
	datamachine_log_message( Level::Info, $message, $context );
}

/**
 * Log a debug message.
 *
 * @param string|\Stringable $message Debug message
 * @param array              $context Optional context data
 */
function datamachine_log_debug( string|\Stringable $message, array $context = array() ): void {
	datamachine_log_message( Level::Debug, $message, $context );
}

/**
 * Log a critical message.
 *
 * @param string|\Stringable $message Critical message
 * @param array              $context Optional context data
 */
function datamachine_log_critical( string|\Stringable $message, array $context = array() ): void {
	datamachine_log_message( Level::Critical, $message, $context );
}

/**
 * Get the log file path for a specific agent type.
 *
 * @param string $agent_type Agent type (pipeline, chat)
 * @return string Full path to log file
 */
function datamachine_get_log_file_path( string $agent_type = AgentType::PIPELINE ): string {
	$upload_dir = wp_upload_dir();
	$filename   = AgentType::getLogFilename( $agent_type );
	return $upload_dir['basedir'] . DATAMACHINE_LOG_DIR . '/' . $filename;
}

/**
 * Get log file size in megabytes for a specific agent type.
 *
 * @param string $agent_type Agent type (pipeline, chat)
 * @return float File size in MB, 0 if file doesn't exist
 */
function datamachine_get_log_file_size( string $agent_type = AgentType::PIPELINE ): float {
	$log_file = datamachine_get_log_file_path( $agent_type );
	if ( ! file_exists( $log_file ) ) {
		return 0;
	}
	return round( filesize( $log_file ) / 1024 / 1024, 2 );
}

/**
 * Get recent log entries for a specific agent type.
 *
 * @param string $agent_type Agent type (pipeline, chat)
 * @param int    $lines Number of lines to retrieve
 * @return array Array of log lines
 */
function datamachine_get_recent_logs( string $agent_type = AgentType::PIPELINE, int $lines = 100 ): array {
	$log_file = datamachine_get_log_file_path( $agent_type );
	if ( ! file_exists( $log_file ) ) {
		return array( 'No log file found.' );
	}

	$fs      = \DataMachine\Core\FilesRepository\FilesystemHelper::get();
	$content = $fs ? $fs->get_contents( $log_file ) : false;
	if ( false === $content ) {
		return array( 'Unable to read log file.' );
	}

	$file_content = array_filter( explode( "\n", $content ), 'strlen' );

	return array_slice( $file_content, -$lines );
}

/**
 * Clean up log files based on size or age criteria.
 *
 * @param int $max_size_mb Maximum log file size in MB
 * @param int $max_age_days Maximum log file age in days
 * @return bool True if cleanup was performed on any file
 */
function datamachine_cleanup_log_files( int $max_size_mb = 10, int $max_age_days = 30 ): bool {
	$upload_dir = wp_upload_dir();
	$log_dir    = $upload_dir['basedir'] . DATAMACHINE_LOG_DIR;

	if ( ! file_exists( $log_dir ) ) {
		return false;
	}

	$cleaned        = false;
	$max_size_bytes = $max_size_mb * 1024 * 1024;

	foreach ( AgentType::getAll() as $agent_type => $info ) {
		$log_file = datamachine_get_log_file_path( $agent_type );

		if ( ! file_exists( $log_file ) ) {
			continue;
		}

		$size_exceeds = filesize( $log_file ) > $max_size_bytes;
		$age_exceeds  = ( time() - filemtime( $log_file ) ) / DAY_IN_SECONDS > $max_age_days;

		if ( $size_exceeds && $age_exceeds ) {
			datamachine_log_debug( "Log file cleanup triggered for {$agent_type}: Size and age limits exceeded" );
			if ( datamachine_clear_log_file( $agent_type ) ) {
				$cleaned = true;
			}
		}
	}

	return $cleaned;
}

/**
 * Clear a specific agent type's log file.
 *
 * @param string $agent_type Agent type (pipeline, chat)
 * @return bool True on success
 */
function datamachine_clear_log_file( string $agent_type ): bool {
	if ( ! AgentType::isValid( $agent_type ) ) {
		return false;
	}

	$log_file = datamachine_get_log_file_path( $agent_type );
	$log_dir  = dirname( $log_file );

	if ( ! file_exists( $log_dir ) ) {
		wp_mkdir_p( $log_dir );
	}

	$fs           = \DataMachine\Core\FilesRepository\FilesystemHelper::get();
	$clear_result = $fs ? $fs->put_contents( $log_file, '' ) : false;

	if ( false !== $clear_result ) {
		datamachine_log_debug( "Log file cleared successfully for agent type: {$agent_type}" );
		return true;
	} else {
		datamachine_log_error( "Failed to clear log file for agent type: {$agent_type}" );
		return false;
	}
}

/**
 * Clear all agent type log files.
 *
 * @return bool True if all files cleared successfully
 */
function datamachine_clear_all_log_files(): bool {
	$success = true;

	foreach ( AgentType::getAll() as $agent_type => $info ) {
		if ( ! datamachine_clear_log_file( $agent_type ) ) {
			$success = false;
		}
	}

	return $success;
}

/**
 * Get the log level for a specific agent type.
 *
 * @param string $agent_type Agent type (pipeline, chat)
 * @return string Log level (debug, error, none)
 */
function datamachine_get_log_level( string $agent_type = AgentType::PIPELINE ): string {
	if ( ! AgentType::isValid( $agent_type ) ) {
		$agent_type = AgentType::PIPELINE;
	}
	return get_option( "datamachine_log_level_{$agent_type}", 'error' );
}

/**
 * Set the log level for a specific agent type.
 *
 * @param string $agent_type Agent type (pipeline, chat)
 * @param string $level Log level (debug, error, none)
 * @return bool True on success
 */
function datamachine_set_log_level( string $agent_type, string $level ): bool {
	if ( ! AgentType::isValid( $agent_type ) ) {
		return false;
	}

	$available_levels = array_keys( datamachine_get_available_log_levels() );
	if ( ! in_array( $level, $available_levels, true ) ) {
		return false;
	}

	$updated = update_option( "datamachine_log_level_{$agent_type}", $level );

	// Force refresh of Monolog instance to apply new level
	if ( $updated ) {
		datamachine_get_monolog_instance( $agent_type, true );
	}

	return $updated;
}

/**
 * Get all valid log levels that can be used for logging operations.
 *
 * @return array Array of valid log level strings
 */
function datamachine_get_valid_log_levels(): array {
	return array( 'debug', 'info', 'warning', 'error', 'critical' );
}

/**
 * Get user-configurable log levels for admin interface.
 *
 * @return array Array of log levels with descriptions for user selection
 */
function datamachine_get_available_log_levels(): array {
	return array(
		'debug' => 'Debug (full logging)',
		'error' => 'Error (problems only)',
		'none'  => 'None (disable logging)',
	);
}
