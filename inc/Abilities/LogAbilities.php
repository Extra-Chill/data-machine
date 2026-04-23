<?php
/**
 * Log Abilities
 *
 * WordPress 6.9 Abilities API primitives for logging operations.
 * Backed by LogRepository (database) — no file I/O.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Logs\LogRepository;

defined( 'ABSPATH' ) || exit;

class LogAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/write-to-log',
				array(
					'label'               => 'Write to Data Machine Logs',
					'description'         => 'Write log entries to the database',
					'category'            => 'datamachine-logging',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'level'   => array(
								'type'        => 'string',
								'enum'        => array( 'debug', 'info', 'warning', 'error', 'critical' ),
								'description' => 'Log level (severity)',
							),
							'message' => array(
								'type'        => 'string',
								'description' => 'Log message content',
							),
							'context' => array(
								'type'        => 'object',
								'description' => 'Additional context (agent_id, job_id, flow_id, etc.)',
							),
						),
						'required'   => array( 'level', 'message' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'write' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/clear-logs',
				array(
					'label'               => 'Clear Data Machine Logs',
					'description'         => 'Clear log entries for a specific agent or all logs',
					'category'            => 'datamachine-logging',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_id' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => 'Agent ID to clear logs for. Null or omitted clears all.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'message' => array( 'type' => 'string' ),
							'deleted' => array( 'type' => array( 'integer', 'null' ) ),
						),
					),
					'execute_callback'    => array( self::class, 'clear' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/read-logs',
				array(
					'label'               => 'Read Data Machine Logs',
					'description'         => 'Read log entries with filtering and pagination',
					'category'            => 'datamachine-logging',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_id'    => array(
								'type'        => array( 'integer', 'null' ),
								'description' => 'Filter by agent ID. Null = all agents.',
							),
							'level'       => array(
								'type'        => 'string',
								'enum'        => array( 'debug', 'info', 'warning', 'error', 'critical' ),
								'description' => 'Filter by log level',
							),
							'since'       => array(
								'type'        => 'string',
								'description' => 'ISO datetime — entries after this time',
							),
							'before'      => array(
								'type'        => 'string',
								'description' => 'ISO datetime — entries before this time',
							),
							'job_id'      => array(
								'type'        => 'integer',
								'description' => 'Filter by job ID (in context)',
							),
							'pipeline_id' => array(
								'type'        => 'integer',
								'description' => 'Filter by pipeline ID (in context)',
							),
							'flow_id'     => array(
								'type'        => 'integer',
								'description' => 'Filter by flow ID (in context)',
							),
							'search'      => array(
								'type'        => 'string',
								'description' => 'Free-text search in message',
							),
							'per_page'    => array(
								'type'        => 'integer',
								'description' => 'Items per page (default 50, max 500)',
							),
							'page'        => array(
								'type'        => 'integer',
								'description' => 'Page number (1-indexed)',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'items'   => array( 'type' => 'array' ),
							'total'   => array( 'type' => 'integer' ),
							'page'    => array( 'type' => 'integer' ),
							'pages'   => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( self::class, 'readLogs' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/get-log-metadata',
				array(
					'label'               => 'Get Log Metadata',
					'description'         => 'Get log entry counts and time range',
					'category'            => 'datamachine-logging',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_id' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => 'Agent ID to get metadata for. Null = all.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'total_entries' => array( 'type' => 'integer' ),
							'oldest'        => array( 'type' => array( 'string', 'null' ) ),
							'newest'        => array( 'type' => array( 'string', 'null' ) ),
							'level_counts'  => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'getMetadata' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/read-debug-log',
				array(
					'label'               => 'Read WordPress Debug Log',
					'description'         => 'Read PHP debug.log entries from wp-content/debug.log',
					'category'            => 'datamachine-logging',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'lines'   => array(
								'type'        => 'integer',
								'description' => 'Number of lines to read from end of file (default: 100, max: 1000)',
							),
							'level'   => array(
								'type'        => 'string',
								'enum'        => array( 'error', 'warning', 'notice', 'deprecated', 'fatal', 'parse', 'all' ),
								'description' => 'Filter by PHP error level (default: all)',
							),
							'since'   => array(
								'type'        => 'string',
								'description' => 'ISO datetime — entries after this time',
							),
							'search'  => array(
								'type'        => 'string',
								'description' => 'Free-text search in log messages',
							),
							'context' => array(
								'type'        => 'integer',
								'description' => 'Lines of context to include around each match (default: 0)',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'file'          => array( 'type' => 'string' ),
							'entries'       => array( 'type' => 'array' ),
							'total'         => array( 'type' => 'integer' ),
							'filtered'      => array( 'type' => 'integer' ),
							'file_size'     => array( 'type' => 'integer' ),
							'last_modified' => array( 'type' => 'string' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'readDebugLog' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Write a log entry via the Abilities API.
	 *
	 * @param array $input { level, message, context }.
	 * @return array Result.
	 */
	public static function write( array $input ): array {
		$level   = $input['level'];
		$message = $input['message'];
		$context = $input['context'] ?? array();

		$valid_levels = datamachine_get_valid_log_levels();
		if ( ! in_array( $level, $valid_levels, true ) ) {
			return array(
				'success'    => false,
				'error_code' => 'invalid_level',
				'error'      => 'Invalid log level: ' . $level,
			);
		}

		$function_name = 'datamachine_log_' . $level;
		if ( function_exists( $function_name ) ) {
			$function_name( $message, $context );
			return array(
				'success' => true,
				'message' => 'Log entry written',
			);
		}

		return array(
			'success' => false,
			'error'   => 'Failed to write log entry',
		);
	}

	/**
	 * Clear log entries.
	 *
	 * @param array $input { agent_id (optional) }.
	 * @return array Result.
	 */
	public static function clear( array $input ): array {
		$repo     = new LogRepository();
		$agent_id = $input['agent_id'] ?? null;

		if ( null !== $agent_id && $agent_id > 0 ) {
			$deleted = $repo->clear_for_agent( (int) $agent_id );
		} else {
			$deleted = $repo->clear_all();
		}

		if ( false === $deleted ) {
			return array(
				'success' => false,
				'error'   => 'Failed to clear logs',
			);
		}

		// Log the clear operation.
		do_action(
			'datamachine_log',
			'info',
			'Logs cleared',
			array(
				'agent_id_cleared' => $agent_id,
				'rows_deleted'     => $deleted,
			)
		);

		return array(
			'success' => true,
			'message' => 'Logs cleared successfully',
			'deleted' => (int) $deleted,
		);
	}

	/**
	 * Read log entries with filtering and pagination.
	 *
	 * @param array $input Filters (agent_id, level, since, before, job_id, flow_id, pipeline_id, search, per_page, page).
	 * @return array Paginated result.
	 */
	public static function readLogs( array $input ): array {
		$repo    = new LogRepository();
		$filters = array();

		// Map input to repository filters.
		$filter_keys = array( 'agent_id', 'level', 'since', 'before', 'job_id', 'flow_id', 'pipeline_id', 'search', 'per_page', 'page' );
		foreach ( $filter_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$filters[ $key ] = $input[ $key ];
			}
		}

		$result = $repo->get_logs( $filters );

		return array(
			'success' => true,
			'items'   => $result['items'],
			'total'   => $result['total'],
			'page'    => $result['page'],
			'pages'   => $result['pages'],
		);
	}

	/**
	 * Get log metadata (counts, time range, level distribution).
	 *
	 * @param array $input { agent_id (optional) }.
	 * @return array Metadata.
	 */
	public static function getMetadata( array $input ): array {
		$repo     = new LogRepository();
		$agent_id = isset( $input['agent_id'] ) ? (int) $input['agent_id'] : null;

		$metadata     = $repo->get_metadata( $agent_id );
		$level_counts = $repo->get_level_counts( $agent_id );

		return array(
			'success'       => true,
			'total_entries' => $metadata['total_entries'],
			'oldest'        => $metadata['oldest'],
			'newest'        => $metadata['newest'],
			'level_counts'  => $level_counts,
		);
	}

	/**
	 * Read WordPress debug.log file.
	 *
	 * Parses PHP error log entries and returns structured data.
	 * Supports filtering by level, time, and text search.
	 *
	 * @param array $input { lines, level, since, search, context }.
	 * @return array Structured log entries.
	 */
	public static function readDebugLog( array $input ): array {
		$log_file = WP_CONTENT_DIR . '/debug.log';

		// Check if debug.log exists.
		if ( ! file_exists( $log_file ) ) {
			return array(
				'success' => false,
				'error'   => 'debug.log not found at ' . $log_file,
				'file'    => $log_file,
			);
		}

		// Check if readable.
		if ( ! is_readable( $log_file ) ) {
			return array(
				'success' => false,
				'error'   => 'debug.log is not readable',
				'file'    => $log_file,
			);
		}

		// Get file metadata.
		$file_size     = filesize( $log_file );
		$last_modified = gmdate( 'c', filemtime( $log_file ) );

		// Parse parameters.
		$max_lines = min( (int) ( $input['lines'] ?? 100 ), 1000 );
		$level     = $input['level'] ?? 'all';
		$since     = $input['since'] ?? null;
		$search    = $input['search'] ?? null;
		$context   = (int) ( $input['context'] ?? 0 );

		// Read the file (tail approach for large files).
		$entries = self::tailDebugLog( $log_file, $max_lines * 10 ); // Read more to allow filtering.

		if ( empty( $entries ) ) {
			return array(
				'success'       => true,
				'file'          => $log_file,
				'entries'       => array(),
				'total'         => 0,
				'filtered'      => 0,
				'file_size'     => $file_size,
				'last_modified' => $last_modified,
			);
		}

		// Parse entries into structured data.
		$parsed = array();
		foreach ( $entries as $line ) {
			$entry = self::parseDebugLogLine( $line );
			if ( $entry ) {
				$parsed[] = $entry;
			}
		}

		// Filter by level.
		if ( 'all' !== $level ) {
			$parsed = array_filter( $parsed, function ( $entry ) use ( $level ) {
				return strtolower( $entry['level'] ) === strtolower( $level );
			});
		}

		// Filter by timestamp.
		if ( $since ) {
			$since_timestamp = strtotime( $since );
			if ( $since_timestamp ) {
				$parsed = array_filter( $parsed, function ( $entry ) use ( $since_timestamp ) {
					return $entry['timestamp'] >= $since_timestamp;
				});
			}
		}

		// Filter by search.
		if ( $search ) {
			$search_lower = strtolower( $search );
			$parsed       = array_filter( $parsed, function ( $entry ) use ( $search_lower ) {
				return str_contains( strtolower( $entry['message'] ), $search_lower )
					|| str_contains( strtolower( $entry['file'] ?? '' ), $search_lower );
			});
		}

		// Re-index array.
		$parsed = array_values( $parsed );

		// Limit to requested lines.
		$total_count = count( $parsed );
		$parsed      = array_slice( $parsed, 0, $max_lines );

		return array(
			'success'       => true,
			'file'          => $log_file,
			'entries'       => $parsed,
			'total'         => $total_count,
			'filtered'      => count( $parsed ),
			'file_size'     => $file_size,
			'last_modified' => $last_modified,
		);
	}

	/**
	 * Tail the debug.log file efficiently.
	 *
	 * @param string $file     File path.
	 * @param int    $lines    Number of lines to read.
	 * @return array Lines from the file.
	 */
	private static function tailDebugLog( string $file, int $lines ): array {
		$handle = fopen( $file, 'r' );
		if ( ! $handle ) {
			return array();
		}

		// Seek to end.
		fseek( $handle, 0, SEEK_END );
		$pos = ftell( $handle );

		// Read backwards to find line breaks.
		$found_lines = array();
		$line_buffer = '';

		while ( $pos > 0 && count( $found_lines ) < $lines ) {
			--$pos;
			fseek( $handle, $pos, SEEK_SET );
			$char = fgetc( $handle );

			if ( "\n" === $char ) {
				if ( '' !== trim( $line_buffer ) ) {
					array_unshift( $found_lines, strrev( $line_buffer ) );
				}
				$line_buffer = '';
			} else {
				$line_buffer .= $char;
			}
		}

		// Don't forget the last line if we hit the beginning.
		if ( '' !== trim( $line_buffer ) && count( $found_lines ) < $lines ) {
			array_unshift( $found_lines, strrev( $line_buffer ) );
		}

		fclose( $handle );

		return $found_lines;
	}

	/**
	 * Parse a debug.log line into structured data.
	 *
	 * Handles common WordPress/PHP log formats:
	 * - [datetime] PHP level: message in file on line N
	 * - [datetime] PHP Fatal error: message in file on line N
	 * - [datetime] PHP Warning: message in file on line N
	 * - [datetime] PHP Notice: message in file on line N
	 * - [datetime] PHP Deprecated: message in file on line N
	 *
	 * @param string $line Raw log line.
	 * @return array|null Structured entry or null if unparseable.
	 */
	private static function parseDebugLogLine( string $line ): ?array {
		$line = trim( $line );
		if ( empty( $line ) ) {
			return null;
		}

		// Common format: [datetime] PHP Level: message in /path/to/file.php on line N
		// WordPress format: [datetime] PHP Level: message
		// Handles multi-word levels like "Fatal error", "Parse error", etc.
		if ( preg_match( '/^\[([^\]]+)\]\s*(?:PHP\s+)?([A-Za-z]+(?:\s+[A-Za-z]+)?)?:\s*(.+)$/i', $line, $matches ) ) {
			$timestamp_str = $matches[1];
			$level         = strtoupper( $matches[2] ?? 'UNKNOWN' );
			$message_part  = $matches[3];

			// Parse timestamp (WordPress format: 01-Jan-2026 12:34:56+00:00).
			$timestamp = strtotime( $timestamp_str ) ? strtotime( $timestamp_str ) : 0;

			// Extract file and line from message if present.
			$file        = null;
			$line_number = null;
			if ( preg_match( '/in\s+(.+\.php)(?:\s+on\s+line\s+(\d+))?$/i', $message_part, $file_matches ) ) {
				$file        = $file_matches[1];
				$line_number = isset( $file_matches[2] ) ? (int) $file_matches[2] : null;
				// Remove file/line from message for cleaner output.
				$message_part = trim( preg_replace( '/\s*in\s+.+\.php(?:\s+on\s+line\s+\d+)?$/i', '', $message_part ) );
			}

			// Normalize level.
			$level = self::normalizeLogLevel( $level );

			return array(
				'raw'       => $line,
				'timestamp' => $timestamp,
				'datetime'  => $timestamp ? gmdate( 'c', $timestamp ) : $timestamp_str,
				'level'     => $level,
				'message'   => $message_part,
				'file'      => $file,
				'line'      => $line_number,
			);
		}

		// Stack trace line.
		if ( preg_match( '/^#\d+\s+/', $line ) ) {
			return array(
				'raw'       => $line,
				'timestamp' => null,
				'datetime'  => null,
				'level'     => 'STACK_TRACE',
				'message'   => $line,
				'file'      => null,
				'line'      => null,
			);
		}

		// Unrecognized format — return as raw.
		return array(
			'raw'       => $line,
			'timestamp' => null,
			'datetime'  => null,
			'level'     => 'UNKNOWN',
			'message'   => $line,
			'file'      => null,
			'line'      => null,
		);
	}

	/**
	 * Normalize PHP error level to standard terms.
	 *
	 * @param string $level Raw level string.
	 * @return string Normalized level.
	 */
	private static function normalizeLogLevel( string $level ): string {
		$level = strtoupper( trim( $level ) );

		$map = array(
			'FATAL ERROR'           => 'FATAL',
			'FATAL'                 => 'FATAL',
			'ERROR'                 => 'ERROR',
			'WARNING'               => 'WARNING',
			'PARSE ERROR'           => 'PARSE',
			'PARSE'                 => 'PARSE',
			'NOTICE'                => 'NOTICE',
			'STRICT'                => 'NOTICE',
			'DEPRECATED'            => 'DEPRECATED',
			'CORE ERROR'            => 'FATAL',
			'CORE WARNING'          => 'WARNING',
			'COMPILE ERROR'         => 'FATAL',
			'COMPILE WARNING'       => 'WARNING',
			'USER ERROR'            => 'ERROR',
			'USER WARNING'          => 'WARNING',
			'USER NOTICE'           => 'NOTICE',
			'USER DEPRECATED'       => 'DEPRECATED',
			'RECOVERABLE ERROR'     => 'ERROR',
			'CATCHABLE FATAL ERROR' => 'ERROR',
		);

		return $map[ $level ] ?? 'UNKNOWN';
	}
}
