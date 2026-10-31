<?php
/**
 * Merged-Bill Decide Tool
 *
 * Chat tool that commits a verdict for a merged-bill candidate pair.
 *
 * - verdict=merge: requires winner_post_id; executes the merge via
 *   data-machine-events/merge-event-posts and records the resolution.
 * - verdict=distinct: records resolution; no post mutations.
 * - verdict=needs_human: records resolution as needs-review; no mutations.
 *
 * The detector will skip already-decided pair_keys on subsequent scans.
 *
 * Wraps data-machine-events/merged-bill-decide ability.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 * @since   0.34.0
 */

namespace DataMachineEvents\Api\Chat\Tools;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineEvents\Abilities\MergedBillDecideAbilities;

class MergedBillDecide extends BaseTool {

	public function __construct() {
		$this->registerTool(
			'merged_bill_decide',
			array( $this, 'getToolDefinition' ),
			array( 'chat' ),
			array( 'ability' => 'data-machine-events/merged-bill-decide' )
		);
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Commit a verdict for a merged-bill candidate pair (issue #256). Call merged_bill_inspect first to read both posts. Then call this tool with verdict=merge (and a winner_post_id picked from the two posts in the pair) to trash the loser and forward-merge its ticket URL; verdict=distinct to record that the two posts are genuinely different shows at the same venue/time; verdict=needs_human when the case is ambiguous (partial lineup overlap, "Night 1" / "Night 2" wording, bodies too short to extract a lineup).',
			'parameters'  => array(
				'type'       => 'object',
				'required'   => array( 'pair_id', 'verdict' ),
				'properties' => array(
					'pair_id'        => array(
						'type'        => 'string',
						'description' => 'The pending_action action_id for the candidate pair.',
					),
					'verdict'        => array(
						'type'        => 'string',
						'enum'        => array(
							MergedBillDecideAbilities::VERDICT_MERGE,
							MergedBillDecideAbilities::VERDICT_DISTINCT,
							MergedBillDecideAbilities::VERDICT_NEEDS_HUMAN,
						),
						'description' => 'merge | distinct | needs_human',
					),
					'winner_post_id' => array(
						'type'        => 'integer',
						'description' => 'Required when verdict=merge. The post to keep. Must equal one of the pair post IDs. Winner selection rule: prefer the post with more populated fields (longer body, has ticket_url, has featured image). Ties go to lower ID (more link equity, older URL).',
					),
					'reason'         => array(
						'type'        => 'string',
						'description' => 'Free-form rationale for audit. Be specific about the lineup overlap or distinction you observed.',
					),
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$abilities = new MergedBillDecideAbilities();
		$result    = $abilities->executeDecide( $parameters );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'merged_bill_decide' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'merged_bill_decide',
		);
	}
}
