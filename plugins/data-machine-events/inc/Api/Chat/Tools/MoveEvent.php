<?php
/**
 * Move Event Tool
 *
 * Chat tool wrapper for MoveEventAbilities. Use when a single event post
 * needs its venue changed (the show moved venues). Records an audit-log
 * entry capturing the from/to venue and reason.
 *
 * For wrong-venue DUPLICATES (two posts exist for the same show), use
 * delete_event (issue #286) instead. For generic block-attribute updates
 * use update_event.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 * @since   0.39.0
 */

namespace DataMachineEvents\Api\Chat\Tools;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineEvents\Abilities\MoveEventAbilities;

class MoveEvent extends BaseTool {

	public function __construct() {
		$this->registerTool(
			'move_event',
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array( 'ability' => 'data-machine-events/move-event' )
		);
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Use when a single event post needs its venue changed (the show moved venues). Records an audit-log entry capturing the from/to venue and reason. For wrong-venue DUPLICATES (two posts exist), use delete_event instead. For generic block-attribute updates, use update_event.',
			'parameters'  => array(
				'type'       => 'object',
				'required'   => array( 'event', 'to_venue' ),
				'properties' => array(
					'event'    => array(
						'type'        => 'integer',
						'description' => 'Event post ID to move.',
					),
					'to_venue' => array(
						'type'        => 'integer',
						'description' => 'Destination venue term ID. Must exist in the venue taxonomy.',
					),
					'reason'   => array(
						'type'        => 'string',
						'description' => 'Free-form rationale for the move (e.g. "moved from Firefly to Refinery per @qrisg"). Recorded in the venue history.',
					),
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$abilities = new MoveEventAbilities();
		$result    = $abilities->execute( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'move_event' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'move_event',
		);
	}
}
