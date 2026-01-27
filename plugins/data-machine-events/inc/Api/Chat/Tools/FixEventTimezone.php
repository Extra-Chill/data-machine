<?php
/**
 * Fix Event Timezone Tool
 *
 * Chat tool wrapper for TimezoneAbilities. Updates venue timezone with
 * geocoding support. Supports batch updates with inline errors.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineEvents\Abilities\TimezoneAbilities;

class FixEventTimezone extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'fix_event_timezone', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Fix event timezone by updating venue metadata. Supports batch updates. Event times remain as-is (8pm stays 8pm) regardless of timezone. Calls geocoding if venue lacks coordinates. Returns inline errors and continues processing.',
			'parameters'  => array(
				'event'       => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Single event post ID to fix',
				),
				'events'      => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Array of event updates. Each item must have "event" (post ID) and optionally "timezone" and "auto_derive".',
				),
				'timezone'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'IANA timezone identifier (e.g., "America/Chicago"). If omitted and auto_derive is false, no change is made.',
				),
				'auto_derive' => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'If true, derive timezone from venue coordinates via GeoNames API (requires GeoNames username configured). Default: false.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$abilities = new TimezoneAbilities();

		if ( empty( $parameters['event'] ) && empty( $parameters['events'] ) ) {
			return array(
				'success'   => false,
				'error'     => 'Either "event" (single) or "events" (batch) parameter is required',
				'tool_name' => 'fix_event_timezone',
			);
		}

		$result = $abilities->executeFixAbility( $parameters );

		if ( isset( $result['success'] ) && false === $result['success'] ) {
			return array(
				'success'   => false,
				'error'     => $result['error'] ?? 'Unknown error',
				'tool_name' => 'fix_event_timezone',
			);
		}

		$updated_count = 0;
		$failed_count  = 0;

		if ( isset( $result['results'] ) && is_array( $result['results'] ) ) {
			foreach ( $result['results'] as $item ) {
				if ( 'updated' === ( $item['status'] ?? '' ) ) {
					++$updated_count;
				} else {
					++$failed_count;
				}
			}
		}

		return array(
			'success'   => $updated_count > 0,
			'data'      => array(
				'results' => $result['results'] ?? array(),
				'summary' => array(
					'updated' => $updated_count,
					'failed'  => $failed_count,
					'total'   => count( $result['results'] ?? array() ),
				),
			),
			'tool_name' => 'fix_event_timezone',
		);
	}
}
