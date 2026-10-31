<?php
/**
 * Merged-Bill Inspect Tool
 *
 * Read-only chat tool that returns both posts in a merged-bill candidate
 * pair so the agent can reason about merge / distinct / needs_human.
 *
 * Wraps data-machine-events/merged-bill-inspect ability.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 * @since   0.34.0
 */

namespace DataMachineEvents\Api\Chat\Tools;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineEvents\Abilities\MergedBillDecideAbilities;

class MergedBillInspect extends BaseTool {

	public function __construct() {
		$this->registerTool(
			'merged_bill_inspect',
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array( 'ability' => 'data-machine-events/merged-bill-inspect' )
		);
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Inspect a merged-bill candidate pair (issue #256). Returns both posts\' titles, body text, performer, price, ticket URL, start/end datetimes, and the detector\'s scored signals. Use this to reason about whether the two posts represent the same bill (verdict=merge), genuinely different shows at the same venue/time (verdict=distinct), or an ambiguous case the operator should review (verdict=needs_human).',
			'parameters'  => array(
				'type'       => 'object',
				'required'   => array( 'pair_id' ),
				'properties' => array(
					'pair_id' => array(
						'type'        => 'string',
						'description' => 'The pending_action action_id for the candidate pair.',
					),
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$abilities = new MergedBillDecideAbilities();
		$result    = $abilities->executeInspect( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'merged_bill_inspect' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'merged_bill_inspect',
		);
	}
}
