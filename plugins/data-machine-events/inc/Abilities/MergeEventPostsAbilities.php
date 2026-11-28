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
 * Callable from the merged-bill-decide ability and the REST API.
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
				'data-machine-events/backfill-merged-identities',
				array(
					'label'               => __( 'Backfill merged event identities', 'data-machine-events' ),
					'description'         => __( 'Hand source identities and reservations from trashed merged events to their published winners. Dry-run by default; use next_after_id to continue.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'  => array(
								'type'    => 'boolean',
								'default' => true,
							),
							'limit'    => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 100,
								'default' => 50,
							),
							'after_id' => array(
								'type'    => 'integer',
								'minimum' => 0,
								'default' => 0,
							),
						),
					),
					'execute_callback'    => array( $this, 'backfill' ),
					'permission_callback' => AbilityPermissions::canWrite(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
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

	/** Repair one bounded, ID-paginated batch of historical merged losers. */
	public function backfill( array $input ): array {
		global $wpdb;
		$limit   = max( 1, min( 100, (int) ( $input['limit'] ?? 50 ) ) );
		$after   = max( 0, (int) ( $input['after_id'] ?? 0 ) );
		$dry_run = (bool) ( $input['dry_run'] ?? true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded maintenance scan with a stable cursor.
		$ids     = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT p.ID FROM %i p INNER JOIN %i pm ON pm.post_id = p.ID WHERE p.post_type = %s AND p.post_status = %s AND pm.meta_key = %s AND p.ID > %d ORDER BY p.ID ASC LIMIT %d',
				$wpdb->posts,
				$wpdb->postmeta,
				Event_Post_Type::POST_TYPE,
				'trash',
				EventMergeHelper::MERGED_INTO_META_KEY,
				$after,
				$limit
			)
		);
		$results = array();
		foreach ( $ids as $id ) {
			$after  = (int) $id;
			$winner = EventMergeHelper::resolveMergedWinner( $after );
			$result = array(
				'loser_id'  => $after,
				'winner_id' => $winner,
				'status'    => 'skipped',
			);
			if ( $winner && Event_Post_Type::POST_TYPE === get_post_type( $winner ) ) {
				$handoff          = $dry_run ? array() : EventMergeHelper::handoffSourceIdentities( $winner, $after );
				$result['status'] = $dry_run ? 'would_transfer' : 'transferred';
				if ( is_wp_error( $handoff ) ) {
					$result['status'] = 'error';
					$result['error']  = $handoff->get_error_message();
				}
			}
			$results[] = $result;
		}
		return array(
			'dry_run'       => $dry_run,
			'results'       => $results,
			'next_after_id' => $after,
		);
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
