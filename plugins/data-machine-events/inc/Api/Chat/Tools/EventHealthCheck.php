<?php
/**
 * Event Health Check Tool
 *
 * Chat tool wrapper for EventHealthAbilities. Scans events for data quality issues:
 * missing time, suspicious midnight start, late night start (midnight-4am),
 * suspicious 11:59pm end time, missing venue, or missing description.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineEvents\Abilities\EventHealthAbilities;

class EventHealthCheck extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'event_health_check', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Check events for data quality issues: missing start time, suspicious midnight start, late night start (midnight-4am), suspicious 11:59pm end time, missing venue, missing description, or missing venue timezone. Returns counts and lists of problematic events.',
			'parameters'  => array(
				'scope'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Which events to check: "upcoming" (default), "all", or "past"',
					'enum'        => array( 'upcoming', 'all', 'past' ),
				),
				'days_ahead' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Days to look ahead for upcoming scope (default: 90)',
				),
				'limit'      => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Max events to return per issue category (default: 25)',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$abilities = new EventHealthAbilities();
		$result    = $abilities->executeHealthCheck( $parameters );

		if ( isset( $result['error'] ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'],
				'tool_name' => 'event_health_check',
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'event_health_check',
		);
	}
}
