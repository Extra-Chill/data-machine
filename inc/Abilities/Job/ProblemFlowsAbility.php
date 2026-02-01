<?php
/**
 * Problem Flows Ability
 *
 * Identifies flows with issues: consecutive failures or consecutive no-items runs.
 *
 * @package DataMachine\Abilities\Job
 * @since 0.17.0
 */

namespace DataMachine\Abilities\Job;

use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

class ProblemFlowsAbility {

	use JobHelpers;

	public function __construct() {
		$this->initDatabases();

		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$default_threshold = PluginSettings::get( 'problem_flow_threshold', 3 );

		$register_callback = function () use ( $default_threshold ) {
			wp_register_ability(
				'datamachine/get-problem-flows',
				array(
					'label'               => __( 'Get Problem Flows', 'data-machine' ),
					'description'         => sprintf(
						/* translators: %d: default threshold */
						__( 'Identify flows with issues: consecutive failures (broken) or consecutive no-items runs (source exhausted). Default threshold: %d.', 'data-machine' ),
						$default_threshold
					),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'threshold' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'default'     => $default_threshold,
								'description' => sprintf(
									/* translators: %d: default threshold */
									__( 'Minimum consecutive count to report (default: %d from settings)', 'data-machine' ),
									$default_threshold
								),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'failing'   => array( 'type' => 'array' ),
							'idle'      => array( 'type' => 'array' ),
							'count'     => array( 'type' => 'integer' ),
							'threshold' => array( 'type' => 'integer' ),
							'message'   => array( 'type' => 'string' ),
							'error'     => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
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

	/**
	 * Execute get-problem-flows ability.
	 *
	 * @param array $input Input parameters with optional threshold.
	 * @return array Result with problem flows.
	 */
	public function execute( array $input ): array {
		$threshold = $input['threshold'] ?? null;

		if ( null === $threshold || ! is_numeric( $threshold ) || (int) $threshold <= 0 ) {
			$threshold = PluginSettings::get( 'problem_flow_threshold', 3 );
		}

		$threshold = (int) $threshold;

		$problem_flows = $this->db_flows->get_problem_flows( $threshold );

		if ( empty( $problem_flows ) ) {
			return array(
				'success'   => true,
				'failing'   => array(),
				'idle'      => array(),
				'count'     => 0,
				'threshold' => $threshold,
				'message'   => sprintf(
					/* translators: %d: threshold */
					__( 'No problem flows detected. All flows are below the threshold of %d.', 'data-machine' ),
					$threshold
				),
			);
		}

		$failing_flows = array();
		$idle_flows    = array();

		foreach ( $problem_flows as $flow ) {
			$failures = $flow['consecutive_failures'] ?? 0;
			$no_items = $flow['consecutive_no_items'] ?? 0;

			if ( $failures >= $threshold ) {
				$failing_flows[] = array(
					'flow_id'              => (int) $flow['flow_id'],
					'flow_name'            => $flow['flow_name'] ?? '',
					'consecutive_failures' => $failures,
					'description'          => sprintf(
						'%s (Flow #%d) - %d consecutive failures - investigate errors',
						$flow['flow_name'] ?? '',
						$flow['flow_id'],
						$failures
					),
				);
			}

			if ( $no_items >= $threshold ) {
				$idle_flows[] = array(
					'flow_id'              => (int) $flow['flow_id'],
					'flow_name'            => $flow['flow_name'] ?? '',
					'consecutive_no_items' => $no_items,
					'description'          => sprintf(
						'%s (Flow #%d) - %d runs with no new items - consider lowering interval',
						$flow['flow_name'] ?? '',
						$flow['flow_id'],
						$no_items
					),
				);
			}
		}

		$message_parts = array();

		if ( ! empty( $failing_flows ) ) {
			$descriptions    = array_map( fn( $f ) => $f['description'], $failing_flows );
			$message_parts[] = sprintf(
				"FAILING FLOWS (%d+ consecutive failures):\n- %s",
				$threshold,
				implode( "\n- ", $descriptions )
			);
		}

		if ( ! empty( $idle_flows ) ) {
			$descriptions    = array_map( fn( $f ) => $f['description'], $idle_flows );
			$message_parts[] = sprintf(
				"IDLE FLOWS (%d+ runs with no new items):\n- %s",
				$threshold,
				implode( "\n- ", $descriptions )
			);
		}

		$message = implode( "\n\n", $message_parts );

		return array(
			'success'   => true,
			'failing'   => $failing_flows,
			'idle'      => $idle_flows,
			'count'     => count( $problem_flows ),
			'threshold' => $threshold,
			'message'   => $message,
		);
	}
}
