<?php
/**
 * Merge Event Posts Ability
 *
 * Pairwise merge executor: given a winner and loser post ID, trashes the
 * loser and forward-merges its ticket URL into the winner when the winner
 * has none. Reuses the EventMergeHelper primitive shared with
 * CleanDuplicatesCommand so behavior is identical across operator-driven
 * cleanup and agent-driven merged-bill resolution.
 *
 * Callable from the chat tool path (merged_bill_decide) and the REST API.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.34.0
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\DuplicateDetection\EventMergeHelper;
use DataMachineEvents\Core\Event_Post_Type;

defined( 'ABSPATH' ) || exit;

class MergeEventPostsAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbility();
			self::$registered = true;
		}
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/merge-event-posts',
				array(
					'label'               => __( 'Merge event posts', 'data-machine-events' ),
					'description'         => __( 'Merge a duplicate event pair: trashes the loser and forward-merges its ticket URL when the winner has none. Used by the merged-bill resolver and by clean-duplicates.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'winner_post_id', 'loser_post_id' ),
						'properties' => array(
							'winner_post_id'   => array(
								'type'        => 'integer',
								'description' => 'Post ID to keep. Must be an event post.',
							),
							'loser_post_id'    => array(
								'type'        => 'integer',
								'description' => 'Post ID to trash. Must be an event post.',
							),
							'merge_ticket_url' => array(
								'type'        => 'boolean',
								'description' => 'Whether to forward-merge the ticket URL when the winner has none. Default true.',
							),
							'reason'           => array(
								'type'        => 'string',
								'description' => 'Free-form reason recorded in the merge log.',
							),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => AbilityPermissions::canWrite(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute the merge.
	 *
	 * @param array $input {
	 *     @type int    $winner_post_id   Required.
	 *     @type int    $loser_post_id    Required.
	 *     @type bool   $merge_ticket_url Default true.
	 *     @type string $reason           Optional audit string.
	 * }
	 * @return array|\WP_Error
	 */
	public function execute( array $input ): array|\WP_Error {
		$winner_id        = (int) ( $input['winner_post_id'] ?? 0 );
		$loser_id         = (int) ( $input['loser_post_id'] ?? 0 );
		$merge_ticket_url = (bool) ( $input['merge_ticket_url'] ?? true );
		$reason           = trim( (string) ( $input['reason'] ?? '' ) );

		if ( $winner_id <= 0 || $loser_id <= 0 ) {
			return new \WP_Error(
				'invalid_input',
				'winner_post_id and loser_post_id are both required and must be positive integers.',
				array( 'status' => 400 )
			);
		}

		// Both posts must be events. We guard here so a generic agent
		// invocation cannot misuse this ability to trash arbitrary posts.
		$winner = get_post( $winner_id );
		$loser  = get_post( $loser_id );
		if ( ! $winner || ! $loser ) {
			return new \WP_Error( 'not_found', 'One or both posts do not exist.', array( 'status' => 404 ) );
		}
		if ( Event_Post_Type::POST_TYPE !== $winner->post_type || Event_Post_Type::POST_TYPE !== $loser->post_type ) {
			return new \WP_Error(
				'wrong_post_type',
				sprintf( 'Both posts must be of post_type "%s".', Event_Post_Type::POST_TYPE ),
				array( 'status' => 400 )
			);
		}

		$merge_result = EventMergeHelper::merge(
			$winner_id,
			$loser_id,
			array( 'merge_ticket_url' => $merge_ticket_url )
		);

		if ( ! $merge_result['success'] ) {
			return new \WP_Error(
				'merge_failed',
				$merge_result['error'] ?? 'Merge failed for an unknown reason.',
				array( 'status' => 500 )
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Merged event posts.',
			array(
				'winner_id'         => $winner_id,
				'loser_id'          => $loser_id,
				'ticket_url_merged' => $merge_result['ticket_url_merged'],
				'reason'            => $reason,
			)
		);

		return array(
			'success'           => true,
			'winner_id'         => $winner_id,
			'loser_id'          => $loser_id,
			'trashed'           => $merge_result['trashed'],
			'ticket_url_merged' => $merge_result['ticket_url_merged'],
		);
	}
}
