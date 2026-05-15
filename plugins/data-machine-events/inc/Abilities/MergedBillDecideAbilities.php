<?php
/**
 * Merged-Bill Decision Abilities
 *
 * Two abilities that drive the agent decision step of the merged-bill
 * resolver pipeline (issue #256):
 *
 *   - data-machine-events/merged-bill-inspect
 *     Read-only. Given a pair_id (= pending action_id), returns both posts'
 *     titles, body text, performer, price, source URL, start/end datetimes,
 *     and the detector's scored signals. The agent uses this to decide
 *     verdict.
 *
 *   - data-machine-events/merged-bill-decide
 *     Commits a verdict for a pair. verdict ∈ { merge, distinct,
 *     needs_human }. On 'merge': executes 'data-machine-events/merge-event-posts'
 *     and records the resolution. On 'distinct'/'needs_human': records the
 *     resolution so the detector skips the pair on subsequent runs.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.34.0
 */

namespace DataMachineEvents\Abilities;

use DataMachine\Engine\AI\Actions\PendingActionStore;

defined( 'ABSPATH' ) || exit;

class MergedBillDecideAbilities {

	public const VERDICT_MERGE        = 'merge';
	public const VERDICT_DISTINCT     = 'distinct';
	public const VERDICT_NEEDS_HUMAN  = 'needs_human';

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/merged-bill-inspect',
				array(
					'label'               => __( 'Inspect a merged-bill candidate pair', 'data-machine-events' ),
					'description'         => __( 'Return both posts\' titles, body text, performer, price, source URL, and start/end datetimes for an agent to reason about a merged-bill candidate pair queued in datamachine_pending_actions.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'pair_id' ),
						'properties' => array(
							'pair_id' => array(
								'type'        => 'string',
								'description' => 'The pending_action action_id for the candidate pair.',
							),
						),
					),
					'execute_callback'    => array( $this, 'executeInspect' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'data-machine-events/merged-bill-decide',
				array(
					'label'               => __( 'Decide a merged-bill candidate pair', 'data-machine-events' ),
					'description'         => __( 'Commit a verdict for a merged-bill candidate pair. verdict=merge trashes the loser and forward-merges the ticket URL. verdict=distinct or needs_human records the decision so the detector skips the pair on subsequent runs.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'pair_id', 'verdict' ),
						'properties' => array(
							'pair_id'        => array(
								'type'        => 'string',
								'description' => 'The pending_action action_id for the candidate pair.',
							),
							'verdict'        => array(
								'type'        => 'string',
								'enum'        => array( self::VERDICT_MERGE, self::VERDICT_DISTINCT, self::VERDICT_NEEDS_HUMAN ),
								'description' => 'merge | distinct | needs_human',
							),
							'winner_post_id' => array(
								'type'        => 'integer',
								'description' => 'Required when verdict=merge. Must be one of the two posts in the pair.',
							),
							'reason'         => array(
								'type'        => 'string',
								'description' => 'Free-form rationale for audit.',
							),
						),
					),
					'execute_callback'    => array( $this, 'executeDecide' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Inspect a pair: load both posts' lineups and the detector signals.
	 *
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function executeInspect( array $input ): array|\WP_Error {
		$pair_id = trim( (string) ( $input['pair_id'] ?? '' ) );
		if ( '' === $pair_id ) {
			return new \WP_Error( 'invalid_input', 'pair_id is required.', array( 'status' => 400 ) );
		}

		$action = PendingActionStore::get( $pair_id );
		if ( null === $action ) {
			return new \WP_Error( 'not_found', 'Pending action not found or already resolved.', array( 'status' => 404 ) );
		}

		$apply = is_array( $action['apply_input'] ?? null ) ? $action['apply_input'] : array();
		$post_a_id = (int) ( $apply['post_a_id'] ?? 0 );
		$post_b_id = (int) ( $apply['post_b_id'] ?? 0 );

		if ( $post_a_id <= 0 || $post_b_id <= 0 ) {
			return new \WP_Error( 'corrupt_action', 'Pending action does not contain both post IDs.', array( 'status' => 500 ) );
		}

		$detector = new MergedBillDetectAbilities();
		$post_a   = $this->describePost( $post_a_id, $detector );
		$post_b   = $this->describePost( $post_b_id, $detector );

		$preview = is_array( $action['preview_data'] ?? null ) ? $action['preview_data'] : array();

		return array(
			'pair_id'        => $pair_id,
			'pair_key'       => $apply['pair_key'] ?? null,
			'venue_term_id'  => (int) ( $preview['venue_term_id'] ?? 0 ),
			'start_datetime' => (string) ( $preview['start_datetime'] ?? '' ),
			'score'          => (int) ( $preview['score'] ?? 0 ),
			'signals'        => $preview['signals'] ?? array(),
			'post_a'         => $post_a,
			'post_b'         => $post_b,
		);
	}

	/**
	 * Apply a verdict to a pair.
	 *
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function executeDecide( array $input ): array|\WP_Error {
		$pair_id = trim( (string) ( $input['pair_id'] ?? '' ) );
		$verdict = trim( (string) ( $input['verdict'] ?? '' ) );
		$reason  = trim( (string) ( $input['reason'] ?? '' ) );
		$winner  = (int) ( $input['winner_post_id'] ?? 0 );

		if ( '' === $pair_id ) {
			return new \WP_Error( 'invalid_input', 'pair_id is required.', array( 'status' => 400 ) );
		}

		$valid_verdicts = array( self::VERDICT_MERGE, self::VERDICT_DISTINCT, self::VERDICT_NEEDS_HUMAN );
		if ( ! in_array( $verdict, $valid_verdicts, true ) ) {
			return new \WP_Error(
				'invalid_verdict',
				'verdict must be one of: ' . implode( ', ', $valid_verdicts ),
				array( 'status' => 400 )
			);
		}

		$action = PendingActionStore::get( $pair_id );
		if ( null === $action ) {
			return new \WP_Error( 'not_found', 'Pending action not found or already resolved.', array( 'status' => 404 ) );
		}

		$apply     = is_array( $action['apply_input'] ?? null ) ? $action['apply_input'] : array();
		$post_a_id = (int) ( $apply['post_a_id'] ?? 0 );
		$post_b_id = (int) ( $apply['post_b_id'] ?? 0 );

		if ( $post_a_id <= 0 || $post_b_id <= 0 ) {
			return new \WP_Error( 'corrupt_action', 'Pending action does not contain both post IDs.', array( 'status' => 500 ) );
		}

		$result = array(
			'pair_id' => $pair_id,
			'verdict' => $verdict,
			'reason'  => $reason,
		);

		if ( self::VERDICT_MERGE === $verdict ) {
			if ( $winner <= 0 || ( $winner !== $post_a_id && $winner !== $post_b_id ) ) {
				return new \WP_Error(
					'invalid_winner',
					'winner_post_id is required for verdict=merge and must equal one of the pair post IDs.',
					array( 'status' => 400 )
				);
			}

			$loser = ( $winner === $post_a_id ) ? $post_b_id : $post_a_id;

			$merge_ability = wp_get_ability( 'data-machine-events/merge-event-posts' );
			if ( ! $merge_ability ) {
				return new \WP_Error(
					'ability_missing',
					'data-machine-events/merge-event-posts ability is not registered.',
					array( 'status' => 500 )
				);
			}

			$merge_result = $merge_ability->execute(
				array(
					'winner_post_id' => $winner,
					'loser_post_id'  => $loser,
					'reason'         => $reason,
				)
			);

			if ( is_wp_error( $merge_result ) ) {
				PendingActionStore::record_resolution(
					$pair_id,
					'rejected',
					null,
					'Merge failed: ' . $merge_result->get_error_message(),
					'agent:merged_bill_decide',
					array(
						'verdict' => $verdict,
						'reason'  => $reason,
					)
				);
				return $merge_result;
			}

			PendingActionStore::record_resolution(
				$pair_id,
				'accepted',
				$merge_result,
				null,
				'agent:merged_bill_decide',
				array(
					'verdict'   => $verdict,
					'reason'    => $reason,
					'winner_id' => $winner,
					'loser_id'  => $loser,
				)
			);

			$result['winner_post_id']    = $winner;
			$result['loser_post_id']     = $loser;
			$result['trashed']           = $merge_result['trashed'] ?? false;
			$result['ticket_url_merged'] = $merge_result['ticket_url_merged'] ?? false;

			return $result;
		}

		// verdict=distinct or needs_human: record decision; no post mutations.
		$decision_status = self::VERDICT_DISTINCT === $verdict ? 'rejected' : 'pending'; // needs_human leaves the action pending in spirit, but we mark a metadata flag.

		if ( self::VERDICT_DISTINCT === $verdict ) {
			PendingActionStore::record_resolution(
				$pair_id,
				'rejected',
				null,
				'Verdict: distinct shows at same venue/time.',
				'agent:merged_bill_decide',
				array(
					'verdict' => $verdict,
					'reason'  => $reason,
				)
			);
		} else {
			// needs_human: keep row but stamp metadata so detector treats it as decided-skip.
			PendingActionStore::record_resolution(
				$pair_id,
				'expired',
				null,
				'Verdict: needs human review.',
				'agent:merged_bill_decide',
				array(
					'verdict' => $verdict,
					'reason'  => $reason,
				)
			);
		}

		return $result;
	}

	/**
	 * Describe a post for the agent: title, body, performer, price, source URL,
	 * and start/end datetimes.
	 */
	private function describePost( int $post_id, MergedBillDetectAbilities $detector ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'id'       => $post_id,
				'missing'  => true,
			);
		}

		$detail = $detector->loadEventDetail( $post_id );
		$dates  = \DataMachineEvents\Core\EventDatesTable::get( $post_id );

		return array(
			'id'             => $post_id,
			'title'          => $post->post_title,
			'body_text'      => $detail['body_text'],
			'performer'      => $detail['performer'],
			'price'          => $detail['price'],
			'start_datetime' => $dates ? $dates->start_datetime : '',
			'end_datetime'   => $dates ? $dates->end_datetime : '',
			'post_date'      => $post->post_date,
			'has_thumbnail'  => has_post_thumbnail( $post_id ),
			'ticket_url'     => (string) get_post_meta( $post_id, \DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY, true ),
		);
	}
}
