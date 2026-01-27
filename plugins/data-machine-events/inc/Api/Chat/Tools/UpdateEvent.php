<?php
/**
 * Update Event Tool
 *
 * Chat tool wrapper for EventUpdateAbilities. Updates event block attributes
 * and venue assignment. Supports single or batch updates.
 * Uses DateTimeParser for flexible datetime input handling.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineEvents\Abilities\EventUpdateAbilities;

class UpdateEvent extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'update_event', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update event details. Accepts a single event or batch of events. Only post IDs are accepted for event identification. Venue must be an existing venue term ID.',
			'parameters'  => array(
				'event'           => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Single event post ID to update',
				),
				'events'          => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Array of event updates. Each item must have "event" (post ID) plus fields to update.',
				),
				'startDate'       => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Start date (any parseable format, normalized to YYYY-MM-DD)',
				),
				'startTime'       => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Start time (any parseable format like "8pm", "20:00", normalized to HH:MM)',
				),
				'endDate'         => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'End date (any parseable format, normalized to YYYY-MM-DD)',
				),
				'endTime'         => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'End time (any parseable format, normalized to HH:MM)',
				),
				'venue'           => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Existing venue term ID to assign',
				),
				'description'     => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Event description (HTML allowed)',
				),
				'price'           => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Ticket price (e.g., "$25" or "$20 adv / $25 door")',
				),
				'ticketUrl'       => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'URL to purchase tickets',
				),
				'performer'       => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Performer name',
				),
				'performerType'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Performer type: Person, PerformingGroup, or MusicGroup',
					'enum'        => array( 'Person', 'PerformingGroup', 'MusicGroup' ),
				),
				'eventStatus'     => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Event status',
					'enum'        => array( 'EventScheduled', 'EventPostponed', 'EventCancelled', 'EventRescheduled' ),
				),
				'eventType'       => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Event type for Schema.org',
					'enum'        => array( 'Event', 'MusicEvent', 'Festival', 'ComedyEvent', 'DanceEvent', 'TheaterEvent', 'SportsEvent', 'ExhibitionEvent' ),
				),
				'occurrenceDates' => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Array of specific dates (YYYY-MM-DD) when the event occurs. For recurring events within a date range.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$abilities = new EventUpdateAbilities();
		$result    = $abilities->executeUpdateEvent( $parameters );

		if ( isset( $result['error'] ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'],
				'tool_name' => 'update_event',
			);
		}

		$summary = $result['summary'] ?? array();

		return array(
			'success'   => ( $summary['updated'] ?? 0 ) > 0 || ( $summary['failed'] ?? 0 ) === 0,
			'data'      => $result,
			'tool_name' => 'update_event',
		);
	}
}
