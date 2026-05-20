<?php
/**
 * Delete Event Ability
 *
 * Soft-deletes (trashes) one or more event posts so they can be recovered
 * from the trash if the action was wrong. Designed for the agent flow:
 *
 *   1. get_venue_events finds duplicates at the wrong venue
 *   2. delete_event trashes the stale post
 *   3. (optional) update_event corrects the kept one
 *
 * Uses wp_trash_post() — never wp_delete_post() — so the dedupe/upsert
 * system can still find the post if it reappears in a scrape, and so an
 * operator can restore from wp-admin if the agent picked the wrong one.
 *
 * Chat tool wrapper lives in inc/Api/Chat/Tools/DeleteEvent.php.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.39.0
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DeleteEventAbilities {

	private const BLOCK_NAME = 'data-machine-events/event-details';

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
				'data-machine-events/delete-event',
				array(
					'label'               => __( 'Delete event', 'data-machine-events' ),
					'description'         => __( 'Soft-delete (trash) one or more event posts. Use for wrong-venue duplicates or cancelled events; not a hard delete.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'event'  => array(
								'type'        => 'integer',
								'description' => 'Single event post ID to trash.',
							),
							'events' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'integer' ),
								'description' => 'Array of event post IDs to trash.',
							),
							'reason' => array(
								'type'        => 'string',
								'description' => 'Free-form reason recorded in the audit log and echoed back in the response.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'deleted' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'id'         => array( 'type' => 'integer' ),
										'title'      => array( 'type' => 'string' ),
										'venue_name' => array( 'type' => 'string' ),
										'start_date' => array( 'type' => 'string' ),
									),
								),
							),
							'skipped' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'id'     => array( 'type' => 'integer' ),
										'reason' => array( 'type' => 'string' ),
									),
								),
							),
							'reason'  => array( 'type' => 'string' ),
							'summary' => array(
								'type'       => 'object',
								'properties' => array(
									'deleted' => array( 'type' => 'integer' ),
									'skipped' => array( 'type' => 'integer' ),
									'total'   => array( 'type' => 'integer' ),
								),
							),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => AbilityPermissions::canWrite(),
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
	 * Execute the trash operation.
	 *
	 * @param array $input {
	 *     @type int      $event  Single event post ID (alternative to events[]).
	 *     @type int[]    $events Array of event post IDs.
	 *     @type string   $reason Free-form audit reason.
	 * }
	 * @return array|\WP_Error
	 */
	public function execute( array $input ): array|\WP_Error {
		$ids    = $this->normalizeIds( $input );
		$reason = trim( (string) ( $input['reason'] ?? '' ) );

		if ( empty( $ids ) ) {
			return new \WP_Error(
				'missing_event',
				'Either "event" (single post ID) or "events" (array of post IDs) is required.',
				array( 'status' => 400 )
			);
		}

		$deleted = array();
		$skipped = array();

		foreach ( $ids as $post_id ) {
			$result = $this->trashSingle( $post_id, $reason );
			if ( isset( $result['deleted'] ) ) {
				$deleted[] = $result['deleted'];
			} else {
				$skipped[] = $result['skipped'];
			}
		}

		$summary = array(
			'deleted' => count( $deleted ),
			'skipped' => count( $skipped ),
			'total'   => count( $ids ),
		);

		return array(
			'deleted' => $deleted,
			'skipped' => $skipped,
			'reason'  => $reason,
			'summary' => $summary,
			'message' => $this->buildSummaryMessage( $summary['deleted'], $summary['skipped'] ),
		);
	}

	/**
	 * Normalize input to a flat array of unique positive integer post IDs.
	 *
	 * @param array $input Raw input.
	 * @return int[]
	 */
	private function normalizeIds( array $input ): array {
		$ids = array();

		if ( ! empty( $input['events'] ) && is_array( $input['events'] ) ) {
			foreach ( $input['events'] as $value ) {
				$id = (int) $value;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		if ( ! empty( $input['event'] ) ) {
			$id = (int) $input['event'];
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Trash a single event post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $reason  Audit reason.
	 * @return array{deleted?: array, skipped?: array}
	 */
	private function trashSingle( int $post_id, string $reason ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array(
				'skipped' => array(
					'id'     => $post_id,
					'reason' => 'Post not found.',
				),
			);
		}

		if ( Event_Post_Type::POST_TYPE !== $post->post_type ) {
			return array(
				'skipped' => array(
					'id'     => $post_id,
					'reason' => sprintf( 'Wrong post_type: expected "%s", got "%s".', Event_Post_Type::POST_TYPE, $post->post_type ),
				),
			);
		}

		if ( 'trash' === $post->post_status ) {
			return array(
				'skipped' => array(
					'id'     => $post_id,
					'reason' => 'Already in trash.',
				),
			);
		}

		// Snapshot identity before trashing so the agent has something to confirm to the user.
		$title      = $post->post_title;
		$venue_name = $this->lookupVenueName( $post_id );
		$start_date = $this->lookupStartDate( $post );

		$trashed = wp_trash_post( $post_id );

		if ( ! $trashed ) {
			return array(
				'skipped' => array(
					'id'     => $post_id,
					'reason' => 'wp_trash_post() returned false; nothing was changed.',
				),
			);
		}

		// Mirror the audit pattern used by MergeEventPostsAbilities — emit a
		// datamachine_log entry and fire a dedicated action so future audit
		// primitives can subscribe without us having to invent storage here.
		do_action(
			'datamachine_log',
			'info',
			'Trashed event post.',
			array(
				'post_id'    => $post_id,
				'title'      => $title,
				'venue_name' => $venue_name,
				'start_date' => $start_date,
				'reason'     => $reason,
				'source'     => 'delete-event-ability',
			)
		);

		/**
		 * Fires after an event post is trashed by the delete-event ability.
		 *
		 * Audit/logging consumers can listen here without us coupling to a
		 * specific storage primitive. Mirrors `datamachine_log` so existing
		 * subscribers stay valid.
		 *
		 * @since 0.39.0
		 *
		 * @param int    $post_id    Trashed post ID.
		 * @param string $title      Post title at trash time.
		 * @param string $venue_name Venue name at trash time (may be empty).
		 * @param string $start_date startDate block attribute (may be empty).
		 * @param string $reason     Operator-supplied reason (may be empty).
		 */
		do_action( 'data_machine_events_event_trashed', $post_id, $title, $venue_name, $start_date, $reason );

		return array(
			'deleted' => array(
				'id'         => $post_id,
				'title'      => $title,
				'venue_name' => $venue_name,
				'start_date' => $start_date,
			),
		);
	}

	/**
	 * Look up the first assigned venue term name for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Venue name or empty string.
	 */
	private function lookupVenueName( int $post_id ): string {
		$terms = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'names' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}
		return (string) $terms[0];
	}

	/**
	 * Pull startDate out of the event-details block.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string YYYY-MM-DD or empty string.
	 */
	private function lookupStartDate( \WP_Post $post ): string {
		$blocks = parse_blocks( $post->post_content );
		foreach ( $blocks as $block ) {
			if ( self::BLOCK_NAME === ( $block['blockName'] ?? '' ) ) {
				return (string) ( $block['attrs']['startDate'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Build a human-readable summary for the agent.
	 *
	 * @param int $deleted Count of trashed posts.
	 * @param int $skipped Count of skipped posts.
	 * @return string
	 */
	private function buildSummaryMessage( int $deleted, int $skipped ): string {
		$parts = array();

		if ( $deleted > 0 ) {
			$parts[] = sprintf( 'Trashed %d event%s', $deleted, 1 === $deleted ? '' : 's' );
		}

		if ( $skipped > 0 ) {
			$parts[] = sprintf( '%d skipped', $skipped );
		}

		if ( empty( $parts ) ) {
			return 'No events processed.';
		}

		return implode( ', ', $parts ) . '.';
	}
}
