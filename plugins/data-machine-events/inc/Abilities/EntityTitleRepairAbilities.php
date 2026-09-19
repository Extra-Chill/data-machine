<?php
/**
 * Entity Title Repair Abilities
 *
 * Backfills already-published events whose post_title carries HTML entity
 * references (issue #844). The ingestion decode contract closed by the same
 * release stops new rows; this repairs the ~5.7k legacy rows.
 *
 * Repair policy — decode, never strip, and keep identity keys honest:
 * the replacement title is the entity-decoded UTF-8 of the stored title.
 * Title-derived identity is verified, not blindly rewritten: dedup title
 * hashes are computed through EventIdentifierGenerator::normalizeBasic(),
 * which already entity-decodes before hashing, so the hash of the encoded
 * stored title equals the hash of the repaired title and no identity row
 * needs to change. Every repair verifies that equality (and the stored
 * datamachine_post_identity row when the DM core index is available) and
 * reports any drift loudly instead of silently assuming it away.
 *
 * Dry run by default; every change requires an explicit execute flag.
 * Writes suspend the kses save filters for the bounded update, because
 * wp_filter_kses on title_save_pre would re-encode the repaired bare "&"
 * straight back to "&amp;" for contexts without unfiltered_html.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.65.0
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\TextNormalization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntityTitleRepairAbilities {

	private const DEFAULT_LIMIT = -1;

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
				'data-machine-events/repair-entity-titles',
				array(
					'label'               => __( 'Repair Entity-Bearing Event Titles', 'data-machine-events' ),
					'description'         => __( 'Decode HTML entities stored in published event titles (dry run by default)', 'data-machine-events' ),
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
								'enum'        => array( 'upcoming', 'past', 'all' ),
								'description' => 'Which published events to scan (default: all)',
								'default'     => 'all',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'             => array( 'type' => 'boolean' ),
							'scanned'             => array( 'type' => 'integer' ),
							'repaired'            => array( 'type' => 'integer' ),
							'identity_hash_drift' => array( 'type' => 'integer' ),
							'changes'             => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'post_id'     => array( 'type' => 'integer' ),
										'old_title'   => array( 'type' => 'string' ),
										'new_title'   => array( 'type' => 'string' ),
										'hash_stable' => array( 'type' => 'boolean' ),
									),
								),
							),
							'message'             => array( 'type' => 'string' ),
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
	 * Execute the entity-title repair.
	 *
	 * @param array $input Input parameters (`dry_run`, `limit`, `scope`).
	 * @return array Scan summary with per-row changes.
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

		$scanned             = 0;
		$changes             = array();
		$identity_hash_drift = 0;

		foreach ( $events as $event ) {
			++$scanned;

			$post = get_post( $event->ID );
			if ( ! $post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
				continue;
			}

			$old_title = (string) $post->post_title;
			if ( ! TextNormalization::contains_entities( $old_title ) ) {
				continue;
			}

			$new_title   = trim( TextNormalization::decode_entities( $old_title ) );
			$hash_stable = self::title_hash_is_stable( $old_title, $new_title, (int) $post->ID );
			if ( ! $hash_stable ) {
				++$identity_hash_drift;
			}

			if ( ! $dry_run ) {
				TextNormalization::with_kses_suspended(
					static function () use ( $post, $new_title ): void {
						wp_update_post(
							array(
								'ID'         => $post->ID,
								'post_title' => $new_title,
							)
						);
					}
				);
			}

			$changes[] = array(
				'post_id'     => (int) $post->ID,
				'old_title'   => $old_title,
				'new_title'   => $new_title,
				'hash_stable' => $hash_stable,
			);
		}

		$repaired = count( $changes );

		$message = $dry_run
			? "{$repaired} event title(s) would be decoded. Run with --execute to apply."
			: "Decoded {$repaired} event title(s).";

		if ( $identity_hash_drift > 0 ) {
			$message .= " WARNING: {$identity_hash_drift} row(s) changed title hash — identity keys were assumed stable but drifted; investigate before relying on dedup.";
		}

		return array(
			'dry_run'             => (bool) $dry_run,
			'scanned'             => $scanned,
			'repaired'            => $repaired,
			'identity_hash_drift' => $identity_hash_drift,
			'changes'             => $changes,
			'message'             => $message,
		);
	}

	/**
	 * Verify that repairing the title does not move any title-derived key.
	 *
	 * The dedup title hash goes through EventIdentifierGenerator::normalizeBasic(),
	 * which entity-decodes before hashing, so encoded and repaired titles must
	 * hash identically. When the DM core identity index carries a row for the
	 * post, its stored title_hash is checked against both. Any drift is
	 * reported by the caller instead of being silently rewritten.
	 *
	 * @param string $old_title Encoded stored title.
	 * @param string $new_title Decoded repaired title.
	 * @param int    $post_id   Event post ID.
	 * @return bool True when every derived key is unchanged.
	 */
	private static function title_hash_is_stable( string $old_title, string $new_title, int $post_id ): bool {
		$old_hash = \DataMachineEvents\Core\DuplicateDetection\EventDuplicateStrategy::computeTitleHash( $old_title );
		$new_hash = \DataMachineEvents\Core\DuplicateDetection\EventDuplicateStrategy::computeTitleHash( $new_title );

		if ( $old_hash !== $new_hash ) {
			return false;
		}

		if ( class_exists( \DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex::class ) ) {
			$index = new \DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex();
			$row   = $index->get( $post_id );
			if ( is_array( $row ) && '' !== (string) ( $row['title_hash'] ?? '' ) ) {
				return $old_hash === (string) $row['title_hash'];
			}
		}

		return true;
	}
}
