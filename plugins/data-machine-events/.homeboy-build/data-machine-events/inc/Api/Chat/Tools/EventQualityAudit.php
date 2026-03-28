<?php
/**
 * Event Quality Audit Tool
 *
 * Chat tool wrapper for EventQualityAuditAbilities. Provides unified event
 * quality diagnostics including missing venue/start date/start time,
 * probable duplicates, and culprit flow summaries.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineEvents\Abilities\EventQualityAuditAbilities;

class EventQualityAudit extends BaseTool {

	public function __construct() {
		$this->registerTool( 'event_quality_audit', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Run a unified event quality audit: missing start date, missing start time, missing venue, probable duplicate groups, and culprit flows.',
			'parameters'  => array(
				'scope'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Which events to check: upcoming (default), all, or past.',
					'enum'        => array( 'upcoming', 'all', 'past' ),
				),
				'days_ahead' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Days to look ahead for upcoming scope (default: 90).',
				),
				'flow_id'    => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Optional flow ID filter.',
				),
				'location_term_id' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Optional location term ID filter.',
				),
				'issue'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Optional issue filter: all, missing_start_date, missing_start_time, missing_venue, or duplicates.',
				),
				'limit'      => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Max rows to return per category (default: 25).',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$abilities = new EventQualityAuditAbilities();
		$result    = $abilities->executeAudit( $parameters );

		if ( isset( $result['error'] ) ) {
			return array(
				'success'   => false,
				'error'     => $result['error'],
				'tool_name' => 'event_quality_audit',
			);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'event_quality_audit',
		);
	}
}
