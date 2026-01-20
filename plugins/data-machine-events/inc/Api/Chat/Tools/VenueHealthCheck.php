<?php
/**
 * Venue Health Check Tool
 *
 * Chat tool wrapper for VenueAbilities. Scans venues for data quality issues:
 * missing address, coordinates, timezone, or website. Also detects suspicious
 * websites where a ticket URL was mistakenly stored as the venue website.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\ToolRegistrationTrait;
use DataMachineEvents\Abilities\VenueAbilities;

class VenueHealthCheck {
	use ToolRegistrationTrait;

	public function __construct() {
		$this->registerTool( 'chat', 'venue_health_check', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Check venues for data quality issues: missing address, coordinates, timezone, or website. Also detects suspicious websites where a ticket URL was mistakenly stored as venue website. Returns counts and lists of problematic venues.',
			'parameters'  => array(
				'limit' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Max venues to return per issue category (default: 25)',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$abilities = new VenueAbilities();
		$result    = $abilities->executeHealthCheck( $parameters );

		if ( isset( $result['error'] ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'],
				'tool_name' => 'venue_health_check',
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'venue_health_check',
		);
	}
}
