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
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

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
					'category'            => 'datamachine',
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
					'category'            => 'datamachine',
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
					'category'            => 'datamachine',
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
					'category'            => 'datamachine',
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
}
