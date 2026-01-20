<?php
/**
 * Find Broken Timezone Events Tool
 *
 * Chat tool wrapper for TimezoneAbilities. Finds events where venue has
 * no timezone or coordinates.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\ToolRegistrationTrait;
use DataMachineEvents\Abilities\TimezoneAbilities;

class FindBrokenTimezoneEvents {
	use ToolRegistrationTrait;

	public function __construct() {
		$this->registerTool( 'chat', 'find_broken_timezone_events', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Find events where venue has no timezone or coordinates. Also separately notes events with no venue assigned. Returns actual timezone/coordinates values when present.',
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
					'description' => 'Max events to return (default: 50)',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$abilities = new TimezoneAbilities();
		$result    = $abilities->executeAbility( $parameters );

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'find_broken_timezone_events',
		);
	}
}
