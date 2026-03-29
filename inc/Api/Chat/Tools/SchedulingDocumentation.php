<?php
/**
 * Scheduling Documentation Builder
 *
 * Provides JSON-formatted scheduling interval options for chat tools.
 * Ensures consistent, machine-readable interval documentation across all
 * tools that accept scheduling configuration.
 *
 * @package DataMachine\Api\Chat\Tools
 * @since 0.9.5
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SchedulingDocumentation {

	/**
	 * Cached intervals JSON string.
	 *
	 * @var string|null
	 */
	private static ?string $cached_json = null;

	/**
	 * Clear cached documentation.
	 */
	public static function clearCache(): void {
		self::$cached_json = null;
	}

	/**
	 * Get scheduling intervals as a JSON array.
	 *
	 * Returns a formatted JSON string suitable for inclusion in tool descriptions.
	 * The JSON format is more parseable by LLMs than pipe-delimited strings.
	 *
	 * @return string JSON array of valid scheduling intervals
	 */
	public static function getIntervalsJson(): string {
		if ( null !== self::$cached_json ) {
			return self::$cached_json;
		}

		$intervals = apply_filters( 'datamachine_scheduler_intervals', array() );

		$options = array(
			array(
				'value' => 'manual',
				'label' => 'Manual only',
			),
			array(
				'value' => 'one_time',
				'label' => 'One-time (requires timestamp)',
			),
		);

		foreach ( $intervals as $key => $config ) {
			$options[] = array(
				'value' => $key,
				'label' => $config['label'] ?? $key,
			);
		}

		self::$cached_json = wp_json_encode( $options, JSON_PRETTY_PRINT );

		return self::$cached_json;
	}

	public function __construct() {
		$this->registerTool( 'manage_jobs', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => $this->buildDescription(),
			'parameters'  => array(
				'action'      => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action to perform: "list", "summary", "delete", "fail", "retry", or "recover"',
				),
				'flow_id'     => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Filter jobs by flow ID (for list action)',
				),
				'pipeline_id' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Filter jobs by pipeline ID (for list action)',
				),
				'status'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Filter jobs by status: pending, processing, completed, failed, completed_no_items, agent_skipped (for list action)',
				),
				'limit'       => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Number of jobs to return (for list action, default 50, max 100)',
				),
				'offset'      => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Offset for pagination (for list action)',
				),
				'type'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'For delete action: "all" or "failed". Required for delete.',
				),
				'job_id'      => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Job ID (for fail and retry actions)',
				),
				'reason'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Reason for failure (for fail action)',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		unset( $tool_def );
		$action = $parameters['action'] ?? '';

		$ability_map = array(
			'list'    => 'datamachine/get-jobs',
			'summary' => 'datamachine/get-jobs-summary',
			'delete'  => 'datamachine/delete-jobs',
			'fail'    => 'datamachine/fail-job',
			'retry'   => 'datamachine/retry-job',
			'recover' => 'datamachine/recover-stuck-jobs',
		);

		if ( ! isset( $ability_map[ $action ] ) ) {
			return array(
				'success'   => false,
				'error'     => 'Invalid action. Use "list", "summary", "delete", "fail", "retry", or "recover"',
				'tool_name' => 'manage_jobs',
			);
		}

		$ability = wp_get_ability( $ability_map[ $action ] );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => sprintf( '%s ability not available', $ability_map[ $action ] ),
				'tool_name' => 'manage_jobs',
			);
		}

		$input = $this->buildInput( $action, $parameters );

		if ( isset( $input['error'] ) ) {
			return $this->buildErrorResponse( $input['error'], 'manage_jobs' );
		}

		$result = $ability->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			$error = $this->getAbilityError( $result, sprintf( 'Failed to %s jobs', $action ) );
			return $this->buildErrorResponse( $error, 'manage_jobs' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'manage_jobs',
		);
	}
}
