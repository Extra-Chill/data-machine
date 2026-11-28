<?php
/**
 * Affiliate Redirect Repair Abilities
 *
 * Repairs published events whose Event Details block carries an
 * affiliate/redirect URL (ticketUrl or organizerUrl) whose redirect
 * parameter lost its URL punctuation. Detection and reconstruction are
 * delegated to `AffiliateRedirectShape`; this class owns the post-scan, the
 * dry-run/execute contract, and the block rewrite.
 *
 * Repair policy — skip, never guess: rows whose corrupted destination cannot
 * be reconstructed with confidence are reported as skipped with the reason,
 * because a wrong affiliate destination silently sends a real user to the
 * wrong event, which is strictly worse than a broken link that visibly
 * fails. See https://github.com/Extra-Chill/data-machine-events/issues/823.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.63.0
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\AffiliateRedirectShape;
use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AffiliateRedirectRepairAbilities {

	private const DEFAULT_LIMIT = -1;

	/**
	 * Block attributes that carry affiliate-wrapped URLs and are repaired.
	 */
	private const REPAIRED_URL_ATTRS = array( 'ticketUrl', 'organizerUrl' );

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbility();
			self::$registered = true;
		}
	}

	/**
	 * Output-schema shape shared by `changes` and `skipped`: both are lists
	 * of per-row objects that always carry `post_id`/`title`/`attribute`
	 * plus a small set of row-specific properties.
	 *
	 * @param array $extra_properties Row-specific properties appended after
	 *                                the shared `post_id`/`title`/`attribute` set.
	 * @return array JSON-schema fragment for an array of row objects.
	 */
	private static function rowListSchema( array $extra_properties ): array {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array_merge(
					array(
						'post_id'   => array( 'type' => 'integer' ),
						'title'     => array( 'type' => 'string' ),
						'attribute' => array( 'type' => 'string' ),
					),
					$extra_properties
				),
			),
		);
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/repair-affiliate-redirects',
				array(
					'label'               => __( 'Repair Affiliate Redirects', 'data-machine-events' ),
					'description'         => __( 'Reconstruct punctuation-stripped affiliate redirect destinations on published events (dry run by default)', 'data-machine-events' ),
					'category'            => AbilityCategories::EVENTS,
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run' => array(
								'type'        => 'boolean',
								'description' => 'Preview changes without applying (default: true)',
								'default'     => true,
							),
							'limit'   => array(
								'type'        => 'integer',
								'description' => 'Maximum events to process (default: -1 for all)',
								'default'     => -1,
							),
							'scope'   => array(
								'type'        => 'string',
								'enum'        => array( 'all', 'upcoming', 'past' ),
								'description' => 'Which published events to scan (default: all)',
								'default'     => 'all',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'           => array( 'type' => 'boolean' ),
							'scanned'           => array( 'type' => 'integer' ),
							'repaired'          => array( 'type' => 'integer' ),
							'skipped_ambiguous' => array( 'type' => 'integer' ),
							'changes'           => self::rowListSchema(
								array(
									'old'         => array( 'type' => 'string' ),
									'new'         => array( 'type' => 'string' ),
									'destination' => array( 'type' => 'string' ),
								)
							),
							'skipped'           => self::rowListSchema(
								array(
									'value'  => array( 'type' => 'string' ),
									'reason' => array( 'type' => 'string' ),
								)
							),
							'message'           => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeRepair' ),
					'permission_callback' => AbilityPermissions::canWrite(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute the affiliate redirect repair.
	 *
	 * @param array $input Input parameters (`dry_run`, `limit`, `scope`).
	 * @return array Scan summary with per-row changes and skipped rows.
	 */
	public function executeRepair( array $input ): array {
		$dry_run = $input['dry_run'] ?? true;
		$limit   = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );
		$scope   = $input['scope'] ?? 'all';

		$event_query = new EventDateQueryAbilities();
		$result      = $event_query->executeQueryEvents(
			array(
				'scope'    => $scope,
				'per_page' => $limit,
				'status'   => 'publish',
			)
		);
		$events      = is_array( $result['posts'] ) ? $result['posts'] : array();

		$scanned           = 0;
		$repaired_rows     = 0;
		$skipped_ambiguous = 0;
		$changes           = array();
		$skipped           = array();

		foreach ( $events as $event ) {
			++$scanned;

			$post = get_post( $event->ID );
			if ( ! $post ) {
				continue;
			}

			$blocks      = parse_blocks( $post->post_content );
			$block_index = null;

			foreach ( $blocks as $index => $block ) {
				if ( Event_Post_Type::EVENT_DETAILS_BLOCK_NAME === $block['blockName'] ) {
					$block_index = $index;
					break;
				}
			}

			if ( null === $block_index ) {
				continue;
			}

			$attr_updates = array();

			foreach ( self::REPAIRED_URL_ATTRS as $attr ) {
				if ( empty( $blocks[ $block_index ]['attrs'][ $attr ] ) ) {
					continue;
				}

				$stored = (string) $blocks[ $block_index ]['attrs'][ $attr ];

				$repair = AffiliateRedirectShape::repair_stored_url( $stored );
				if ( null !== $repair ) {
					$attr_updates[ $attr ] = $repair['after'];
					$changes[]             = array(
						'post_id'     => $event->ID,
						'title'       => $event->post_title,
						'attribute'   => $attr,
						'old'         => $stored,
						'new'         => $repair['after'],
						'destination' => $repair['destination'],
					);
					++$repaired_rows;
					continue;
				}

				// Detected-but-unreconstructable rows are reported and
				// skipped, never guessed.
				$corrupted = AffiliateRedirectShape::find_corrupted_redirect( $stored );
				if ( null !== $corrupted ) {
					++$skipped_ambiguous;
					$skipped[] = array(
						'post_id'   => $event->ID,
						'title'     => $event->post_title,
						'attribute' => $attr,
						'value'     => $corrupted['value'],
						'reason'    => 'ambiguous_destination',
					);
				}
			}

			if ( empty( $attr_updates ) || $dry_run ) {
				continue;
			}

			// The index came from the parse loop above, but asserting the
			// offset exists keeps the parsed block shape intact for
			// serialize_blocks instead of admitting a synthetic block.
			if ( ! isset( $blocks[ $block_index ] ) ) {
				continue;
			}

			foreach ( $attr_updates as $attr => $after ) {
				$blocks[ $block_index ]['attrs'][ $attr ] = $after;
			}

			wp_update_post(
				array(
					'ID'           => $event->ID,
					'post_content' => serialize_blocks( $blocks ),
				)
			);
		}

		return array(
			'dry_run'           => (bool) $dry_run,
			'scanned'           => $scanned,
			'repaired'          => $repaired_rows,
			'skipped_ambiguous' => $skipped_ambiguous,
			'changes'           => $changes,
			'skipped'           => $skipped,
			'message'           => $this->buildSummaryMessage( (bool) $dry_run, $repaired_rows, $skipped_ambiguous ),
		);
	}

	/**
	 * Build summary message.
	 *
	 * @param bool $dry_run           Whether this is a dry run.
	 * @param int  $repaired          Repaired row count.
	 * @param int  $skipped_ambiguous Skipped-ambiguous row count.
	 * @return string Summary message.
	 */
	private function buildSummaryMessage( bool $dry_run, int $repaired, int $skipped_ambiguous ): string {
		if ( $dry_run ) {
			return "{$repaired} URL rows would be repaired, {$skipped_ambiguous} skipped as ambiguous. Run with --execute to apply.";
		}

		return "Repaired: {$repaired}, Skipped as ambiguous: {$skipped_ambiguous}";
	}
}
