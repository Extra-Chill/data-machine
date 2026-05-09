<?php
/**
 * Update Venue Tool
 *
 * Chat tool wrapper for VenueAbilities. Updates venue name and meta fields.
 * Triggers auto-geocoding when address fields change.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineEvents\Abilities\VenueAbilities;

class UpdateVenue extends BaseTool {

	public function __construct() {
		$this->registerTool( 'update_venue', array( $this, 'getToolDefinition' ), array( 'chat' ), array( 'ability' => 'data-machine-events/update-venue' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update a venue name and/or meta fields. Address changes trigger automatic geocoding.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'venue'       => array(
						'type'        => 'string',
						'description' => 'Venue identifier (term ID, name, or slug)',
					),
					'name'        => array(
						'type'        => 'string',
						'description' => 'New venue name',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'Venue description',
					),
					'address'     => array(
						'type'        => 'string',
						'description' => 'Street address',
					),
					'city'        => array(
						'type'        => 'string',
						'description' => 'City',
					),
					'state'       => array(
						'type'        => 'string',
						'description' => 'State/region',
					),
					'zip'         => array(
						'type'        => 'string',
						'description' => 'Postal/ZIP code',
					),
					'country'     => array(
						'type'        => 'string',
						'description' => 'Country',
					),
					'phone'       => array(
						'type'        => 'string',
						'description' => 'Phone number',
					),
					'website'     => array(
						'type'        => 'string',
						'description' => 'Website URL',
					),
					'capacity'    => array(
						'type'        => 'string',
						'description' => 'Venue capacity',
					),
					'coordinates' => array(
						'type'        => 'string',
						'description' => 'GPS coordinates as "lat,lng"',
					),
					'timezone'    => array(
						'type'        => 'string',
						'description' => 'IANA timezone identifier (e.g., America/New_York)',
					),
				),
				'required'   => array( 'venue' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$abilities = new VenueAbilities();
		$result    = $abilities->executeUpdateVenue( $parameters );

		if ( isset( $result['error'] ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'],
				'tool_name' => 'update_venue',
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'update_venue',
		);
	}
}
