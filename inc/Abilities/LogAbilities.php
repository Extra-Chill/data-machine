<?php
/**
 * Log Abilities
 *
 * WordPress 6.9 Abilities API primitives for logging operations.
 * Centralizes all logging (write and clear) through abilities.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;
use DataMachine\Abilities\PermissionHelper;

use DataMachine\Engine\Logger;
use DataMachine\Engine\AI\AgentType;

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
					'description'         => 'Write log entries with level routing to system, pipeline, or chat logs',
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
								'description' => 'Additional context (agent_type, job_id, flow_id, etc.)',
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
					'description'         => 'Clear log files for specified agent type or all logs',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_type' => array(
								'type'        => 'string',
								'enum'        => array( 'pipeline', 'chat', 'system', 'all' ),
								'description' => 'Agent type log to clear (or "all")',
							),
						),
						'required'   => array( 'agent_type' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'message'       => array( 'type' => 'string' ),
							'files_cleared' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
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
					'description'         => 'Read log content with optional filtering by job, pipeline, or flow',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_type'  => array(
								'type'        => 'string',
								'enum'        => array( 'pipeline', 'chat', 'system' ),
								'description' => 'Agent type to read logs for',
							),
							'mode'        => array(
								'type'        => 'string',
								'enum'        => array( 'full', 'recent' ),
								'description' => 'Content mode: full or recent',
							),
							'limit'       => array(
								'type'        => 'integer',
								'description' => 'Number of entries when mode is recent',
							),
							'job_id'      => array(
								'type'        => 'integer',
								'description' => 'Filter by job ID',
							),
							'pipeline_id' => array(
								'type'        => 'integer',
								'description' => 'Filter by pipeline ID',
							),
							'flow_id'     => array(
								'type'        => 'integer',
								'description' => 'Filter by flow ID',
							),
						),
						'required'   => array( 'agent_type' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'content'        => array( 'type' => 'string' ),
							'total_lines'    => array( 'type' => 'integer' ),
							'filtered_lines' => array( 'type' => 'integer' ),
							'mode'           => array( 'type' => 'string' ),
							'agent_type'     => array( 'type' => 'string' ),
							'message'        => array( 'type' => 'string' ),
							'error'          => array( 'type' => 'string' ),
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
					'description'         => 'Get log file metadata and configuration for agent type(s)',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_type' => array(
								'type'        => 'string',
								'enum'        => array( 'pipeline', 'chat', 'system' ),
								'description' => 'Agent type to get metadata for. If omitted, returns all.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'agent_type'  => array( 'type' => 'string' ),
							'agent_types' => array( 'type' => 'object' ),
							'log_file'    => array( 'type' => 'object' ),
							'error'       => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getMetadata' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/set-log-level',
				array(
					'label'               => 'Set Log Level',
					'description'         => 'Set the log level for a specific agent type',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_type' => array(
								'type'        => 'string',
								'enum'        => array( 'pipeline', 'chat', 'system' ),
								'description' => 'Agent type to set level for',
							),
							'level'      => array(
								'type'        => 'string',
								'enum'        => array( 'debug', 'error', 'none' ),
								'description' => 'Log level to set',
							),
						),
						'required'   => array( 'agent_type', 'level' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'agent_type' => array( 'type' => 'string' ),
							'level'      => array( 'type' => 'string' ),
							'message'    => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'setLevel' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/get-log-level',
				array(
					'label'               => 'Get Log Level',
					'description'         => 'Get the current log level for a specific agent type',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'agent_type' => array(
								'type'        => 'string',
								'enum'        => array( 'pipeline', 'chat', 'system' ),
								'description' => 'Agent type to get level for',
							),
						),
						'required'   => array( 'agent_type' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'agent_type' => array( 'type' => 'string' ),
							'level'      => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getLevel' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	public static function write( array $input ): array {
		$level   = $input['level'];
		$message = $input['message'];
		$context = $input['context'] ?? array();

		$logged = Logger::write( $level, $message, $context );

		if ( $logged ) {
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

	public static function clear( array $input ): array {
		$agent_type = $input['agent_type'];

		if ( 'all' === $agent_type ) {
			$cleared       = datamachine_clear_all_log_files();
			$files_cleared = array( 'all' );
		} else {
			$cleared       = datamachine_clear_log_file( $agent_type );
			$files_cleared = array( $agent_type );
		}

		if ( $cleared ) {
			do_action(
				'datamachine_log',
				'info',
				'Logs cleared',
				array(
					'agent_type'         => 'system',
					'agent_type_cleared' => $agent_type,
					'files_cleared'      => $files_cleared,
				)
			);

			return array(
				'success'       => true,
				'message'       => 'Logs cleared successfully',
				'files_cleared' => $files_cleared,
			);
		}

		return array(
			'success' => false,
			'error'   => 'Failed to clear logs',
		);
	}

	public static function readLogs( array $input ): array {
		$agent_type  = $input['agent_type'];
		$mode        = $input['mode'] ?? 'full';
		$limit       = $input['limit'] ?? 200;
		$job_id      = $input['job_id'] ?? null;
		$pipeline_id = $input['pipeline_id'] ?? null;
		$flow_id     = $input['flow_id'] ?? null;

		if ( ! AgentType::isValid( $agent_type ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_agent_type',
				'message' => __( 'Invalid agent type specified.', 'data-machine' ),
			);
		}

		$log_file = datamachine_get_log_file_path( $agent_type );

		if ( ! file_exists( $log_file ) ) {
			return array(
				'success'     => true,
				'content'     => '',
				'total_lines' => 0,
				'mode'        => $mode,
				'agent_type'  => $agent_type,
				'message'     => __( 'No log entries found.', 'data-machine' ),
			);
		}

		$file_content = @file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		if ( false === $file_content ) {
			return array(
				'success' => false,
				'error'   => 'log_file_read_error',
				'message' => __( 'Unable to read log file.', 'data-machine' ),
			);
		}

		$file_content = array_reverse( $file_content );
		$total_lines  = count( $file_content );

		$has_filters = null !== $job_id || null !== $pipeline_id || null !== $flow_id;
		if ( null !== $job_id ) {
			$file_content = self::filterByJobId( $file_content, $job_id );
		}
		if ( null !== $pipeline_id ) {
			$file_content = self::filterByPipelineId( $file_content, $pipeline_id );
		}
		if ( null !== $flow_id ) {
			$file_content = self::filterByFlowId( $file_content, $flow_id );
		}
		$filtered_lines = $has_filters ? count( $file_content ) : null;

		if ( 'recent' === $mode ) {
			$file_content = array_slice( $file_content, 0, $limit );
		}

		$content = implode( "\n", $file_content );

		$response = array(
			'success'     => true,
			'content'     => $content,
			'total_lines' => $total_lines,
			'mode'        => $mode,
			'agent_type'  => $agent_type,
		);

		if ( null !== $filtered_lines ) {
			$response['filtered_lines'] = $filtered_lines;
			if ( null !== $job_id ) {
				$response['job_id'] = $job_id;
			}
			if ( null !== $pipeline_id ) {
				$response['pipeline_id'] = $pipeline_id;
			}
			if ( null !== $flow_id ) {
				$response['flow_id'] = $flow_id;
			}
		}

		if ( null !== $job_id || null !== $pipeline_id || null !== $flow_id ) {
			$filter_parts = array();
			if ( null !== $job_id ) {
				$filter_parts[] = sprintf( 'job %d', $job_id );
			}
			if ( null !== $pipeline_id ) {
				$filter_parts[] = sprintf( 'pipeline %d', $pipeline_id );
			}
			if ( null !== $flow_id ) {
				$filter_parts[] = sprintf( 'flow %d', $flow_id );
			}
			$response['message'] = sprintf(
				/* translators: %1$d is the number of log entries, %2$s is the filter criteria */
				__( 'Retrieved %1$d log entries for %2$s.', 'data-machine' ),
				count( $file_content ),
				implode( ', ', $filter_parts )
			);
		} else {
			$response['message'] = sprintf(
				/* translators: %1$d is the number of log entries, %2$s is either "recent" or "total" */
				__( 'Loaded %1$d %2$s log entries.', 'data-machine' ),
				count( $file_content ),
				'recent' === $mode ? 'recent' : 'total'
			);
		}

		return $response;
	}

	public static function getMetadata( array $input ): array {
		$agent_type = $input['agent_type'] ?? null;

		if ( null === $agent_type ) {
			$all_metadata = array();
			foreach ( AgentType::getAll() as $type => $info ) {
				$all_metadata[ $type ] = self::getSingleMetadata( $type );
			}

			return array(
				'success'     => true,
				'agent_types' => $all_metadata,
			);
		}

		if ( ! AgentType::isValid( $agent_type ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_agent_type',
				'message' => __( 'Invalid agent type specified.', 'data-machine' ),
			);
		}

		return self::getSingleMetadata( $agent_type );
	}

	public static function setLevel( array $input ): array {
		$agent_type = $input['agent_type'];
		$level      = $input['level'];

		if ( ! AgentType::isValid( $agent_type ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_agent_type',
				'message' => __( 'Invalid agent type specified.', 'data-machine' ),
			);
		}

		$available_levels = datamachine_get_available_log_levels();
		if ( ! array_key_exists( $level, $available_levels ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_level',
				'message' => __( 'Invalid log level specified.', 'data-machine' ),
			);
		}

		$result = datamachine_set_log_level( $agent_type, $level );

		if ( $result ) {
			$level_display = $available_levels[ $level ] ?? ucfirst( $level );
			$agent_types   = AgentType::getAll();
			$agent_label   = $agent_types[ $agent_type ]['label'] ?? ucfirst( $agent_type );

			return array(
				'success'    => true,
				'agent_type' => $agent_type,
				'level'      => $level,
				'message'    => sprintf(
					/* translators: %1$s: agent label, %2$s: level display */
					__( '%1$s log level updated to %2$s.', 'data-machine' ),
					$agent_label,
					$level_display
				),
			);
		}

		return array(
			'success' => false,
			'error'   => 'Failed to set log level',
		);
	}

	public static function getLevel( array $input ): array {
		$agent_type = $input['agent_type'];

		if ( ! AgentType::isValid( $agent_type ) ) {
			return array(
				'success' => false,
				'error'   => 'invalid_agent_type',
				'message' => __( 'Invalid agent type specified.', 'data-machine' ),
			);
		}

		$level = datamachine_get_log_level( $agent_type );

		return array(
			'success'    => true,
			'agent_type' => $agent_type,
			'level'      => $level,
		);
	}

	private static function getSingleMetadata( string $agent_type ): array {
		$log_file_path   = datamachine_get_log_file_path( $agent_type );
		$log_file_exists = file_exists( $log_file_path );
		$log_file_size   = $log_file_exists ? filesize( $log_file_path ) : 0;

		$size_formatted = $log_file_size > 0
			? size_format( $log_file_size, 2 )
			: '0 bytes';

		$current_level    = datamachine_get_log_level( $agent_type );
		$available_levels = datamachine_get_available_log_levels();

		$agent_types = AgentType::getAll();
		$agent_info  = $agent_types[ $agent_type ] ?? array();

		return array(
			'success'       => true,
			'agent_type'    => $agent_type,
			'agent_label'   => $agent_info['label'] ?? ucfirst( $agent_type ),
			'log_file'      => array(
				'path'           => $log_file_path,
				'exists'         => $log_file_exists,
				'size'           => $log_file_size,
				'size_formatted' => $size_formatted,
			),
			'configuration' => array(
				'current_level'    => $current_level,
				'available_levels' => $available_levels,
			),
		);
	}

	private static function filterByJobId( array $lines, int $job_id ): array {
		$filtered = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '/"job_id"\s*:\s*' . preg_quote( (string) $job_id, '/' ) . '(?:[,\}])/', $line ) ) {
				$filtered[] = $line;
			}
		}
		return $filtered;
	}

	private static function filterByPipelineId( array $lines, int $pipeline_id ): array {
		$filtered = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '/"pipeline_id"\s*:\s*' . preg_quote( (string) $pipeline_id, '/' ) . '(?:[,\}])/', $line ) ) {
				$filtered[] = $line;
			}
		}
		return $filtered;
	}

	private static function filterByFlowId( array $lines, int $flow_id ): array {
		$filtered = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '/"flow_id"\s*:\s*' . preg_quote( (string) $flow_id, '/' ) . '(?:[,\}])/', $line ) ) {
				$filtered[] = $line;
			}
		}
		return $filtered;
	}
}
