<?php
/**
 * Get Venue Events Tool
 *
 * Chat tool wrapper for EventQueryAbilities. Get events attached to a specific
 * venue - useful for investigating venue terms before merging or cleanup.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\ToolRegistrationTrait;
use DataMachineEvents\Abilities\EventQueryAbilities;

class GetVenueEvents {
	use ToolRegistrationTrait;

	public function __construct() {
		$this->registerTool( 'chat', 'get_venue_events', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Get events attached to a specific venue. Useful for investigating venue terms before merging or cleanup.',
			'parameters'  => array(
				'venue'            => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Venue identifier (term ID, name, or slug)',
				),
				'limit'            => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Maximum events to return (default: 25, max: 100)',
				),
				'status'           => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Post status filter: any, publish, future, draft (default: any)',
				),
				'published_before' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Only return events published before this date (YYYY-MM-DD format)',
				),
				'published_after'  => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Only return events published after this date (YYYY-MM-DD format)',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$abilities = new EventQueryAbilities();
		$result    = $abilities->executeGetVenueEvents( $parameters );

		if ( isset( $result['error'] ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'],
				'tool_name' => 'get_venue_events',
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'get_venue_events',
		);
	}
}
