<?php
/**
 * Delete Event Tool
 *
 * Chat tool wrapper for DeleteEventAbilities. Soft-deletes (trashes) one or
 * more event posts. Use when a duplicate event exists at the wrong venue or
 * when an event was cancelled — events go to trash, NOT hard-deleted, so they
 * can be restored from wp-admin if the wrong post was picked.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 * @since   0.39.0
 */

namespace DataMachineEvents\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineEvents\Abilities\DeleteEventAbilities;

class DeleteEvent extends BaseTool {

	public function __construct() {
		$this->registerTool(
			'delete_event',
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array( 'ability' => 'data-machine-events/delete-event' )
		);
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Soft-delete (trash) one or more event posts. Use when a duplicate event exists at the wrong venue, or when an event was cancelled. Events go to the WordPress trash (recoverable from wp-admin), NOT a hard delete. Accepts a single post ID via "event" or multiple via "events". Returns deleted[] with id/title/venue_name/start_date snapshots so you can confirm to the user which posts were removed.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'event'  => array(
						'type'        => 'integer',
						'description' => 'Single event post ID to trash.',
					),
					'events' => array(
						'type'        => 'array',
						'description' => 'Array of event post IDs to trash.',
						'items'       => array( 'type' => 'integer' ),
					),
					'reason' => array(
						'type'        => 'string',
						'description' => 'Free-form reason recorded in the audit log (e.g., "duplicate at wrong venue", "show cancelled").',
					),
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$abilities = new DeleteEventAbilities();
		$result    = $abilities->execute( $parameters );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'delete_event',
			);
		}

		$summary = $result['summary'] ?? array();
		$success = ( $summary['deleted'] ?? 0 ) > 0;

		return array(
			'success'   => $success,
			'data'      => $result,
			'tool_name' => 'delete_event',
		);
	}
}
