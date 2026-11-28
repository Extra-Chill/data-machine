<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Incident recovery requires fresh, cursor-bounded scheduler evidence.
/**
 * Bounded recovery for obsolete pipeline batch actions.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachine\Core\Database\BatchItems\BatchItems;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;

defined( 'ABSPATH' ) || exit;

class BatchActionRecoveryAbilities {

	private const HOOK               = 'datamachine_pipeline_batch_chunk';
	private const DEFAULT_SCAN_LIMIT = 250;
	private const MAX_SCAN_LIMIT     = 1000;
	private const DEFAULT_MUTATIONS  = 25;
	private const MAX_MUTATIONS      = 100;

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbility();
			self::$registered = true;
		}
	}

	private function registerAbility(): void {
		$register_callback = function (): void {
			wp_register_ability(
					'data-machine-events/recover-batch-actions',
					array(
						'label'               => __( 'Recover Pipeline Batch Actions', 'data-machine-events' ),
						'description'         => __( 'Classify and remove exact obsolete pipeline batch action paths in bounded batches.', 'data-machine-events' ),
						'category'            => AbilityCategories::EVENTS,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(
								'apply'          => array(
									'type'    => 'boolean',
									'default' => false,
								),
								'cursor'         => array(
									'type'    => 'integer',
									'minimum' => 0,
									'default' => 0,
								),
								'review_through' => array(
									'type'    => 'integer',
									'minimum' => 0,
									'default' => 0,
								),
								'scan_limit'     => array(
									'type'    => 'integer',
									'minimum' => 1,
									'maximum' => self::MAX_SCAN_LIMIT,
									'default' => self::DEFAULT_SCAN_LIMIT,
								),
								'mutation_limit' => array(
									'type'    => 'integer',
									'minimum' => 1,
									'maximum' => self::MAX_MUTATIONS,
									'default' => self::DEFAULT_MUTATIONS,
								),
							),
						),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => array( $this, 'execute' ),
						'permission_callback' => AbilityPermissions::canWrite(),
					)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Classify and optionally remove one bounded scheduler page.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	public function execute( array $input ): array {
		if ( ! class_exists( Jobs::class ) || ! class_exists( BatchItems::class ) ) {
			return array( 'error' => 'Data Machine batch recovery primitives are unavailable.' );
		}

		global $wpdb;
		$apply          = ! empty( $input['apply'] );
		$cursor         = max( 0, (int) ( $input['cursor'] ?? 0 ) );
		$review_through = max( 0, (int) ( $input['review_through'] ?? 0 ) );
		$scan_limit     = max( 1, min( self::MAX_SCAN_LIMIT, (int) ( $input['scan_limit'] ?? self::DEFAULT_SCAN_LIMIT ) ) );
		$mutation_limit = max( 1, min( self::MAX_MUTATIONS, (int) ( $input['mutation_limit'] ?? self::DEFAULT_MUTATIONS ) ) );
		$actions_table  = $wpdb->prefix . 'actionscheduler_actions';
		$jobs           = new Jobs();
		$batch_items    = new BatchItems();
		$mutations      = 0;

		if ( $apply && $review_through <= $cursor ) {
			return array( 'error' => 'Apply requires --review-through greater than --cursor from a reviewed dry-run window.' );
		}

		// The primary-key window bounds rows examined even when hook/status indexes are unhealthy.
		$sql = $apply
			? $wpdb->prepare(
				'SELECT action_id, hook, status, claim_id, args FROM %i WHERE action_id > %d AND action_id <= %d ORDER BY action_id ASC LIMIT %d',
				$actions_table,
				$cursor,
				$review_through,
				$scan_limit
			)
			: $wpdb->prepare(
				'SELECT action_id, hook, status, claim_id, args FROM %i WHERE action_id > %d ORDER BY action_id ASC LIMIT %d',
				$actions_table,
				$cursor,
				$scan_limit
			);
		$wpdb->flush();
		$actions = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Both query shapes are prepared directly above.
			$sql,
			ARRAY_A
		);
		if ( ! empty( $wpdb->last_error ) || ! is_array( $actions ) ) {
			return array( 'error' => 'Unable to read the Action Scheduler recovery window.' );
		}

		$parent_cache = array();
		$outstanding  = array();
		foreach ( $actions as $action ) {
			if ( self::HOOK !== $action['hook'] || 'pending' !== $action['status'] || 0 !== (int) $action['claim_id'] ) {
				continue;
			}
			$args = self::decodeActionArgs( (string) $action['args'] );
			if ( null === $args ) {
				continue;
			}
			$parent_job_id = (int) $args['parent_job_id'];
			if ( ! array_key_exists( $parent_job_id, $parent_cache ) ) {
				$wpdb->flush();
				$parent_cache[ $parent_job_id ] = $jobs->get_job( $parent_job_id );
				if ( ! empty( $wpdb->last_error ) ) {
					return array( 'error' => 'Unable to verify a batch parent; no actions were changed.' );
				}
			}
		}
		foreach ( $parent_cache as $parent_job_id => $parent ) {
			if ( ! is_array( $parent ) || JobStatus::PROCESSING !== (string) ( $parent['status'] ?? '' ) ) {
				continue;
			}
			$wpdb->flush();
			$outstanding[ $parent_job_id ] = $batch_items->first_outstanding_index( (int) $parent_job_id );
			if ( ! empty( $wpdb->last_error ) ) {
				return array( 'error' => 'Unable to verify a batch worklist; no actions were changed.' );
			}
		}

		$details       = array();
		$counts        = array(
			'rows_scanned'      => count( $actions ),
			'chunk_actions'     => 0,
			'claimed_preserved' => 0,
			'eligible'          => 0,
			'deleted'           => 0,
			'preserved'         => 0,
			'race_skipped'      => 0,
			'malformed'         => 0,
		);
		$next_cursor   = $apply && empty( $actions ) ? $review_through : $cursor;
		$limit_reached = false;

		foreach ( $actions as $action ) {
			$action_id   = (int) $action['action_id'];
			$last_cursor = $next_cursor;
			$next_cursor = $action_id;
			if ( self::HOOK !== $action['hook'] || 'pending' !== $action['status'] ) {
				continue;
			}
			++$counts['chunk_actions'];
			if ( 0 !== (int) $action['claim_id'] ) {
				++$counts['claimed_preserved'];
				++$counts['preserved'];
				$details[] = array(
					'action_id'   => $action_id,
					'disposition' => 'preserved',
					'reason'      => 'action_claimed',
				);
				continue;
			}

			$args = self::decodeActionArgs( (string) $action['args'] );
			if ( null === $args ) {
				++$counts['malformed'];
				++$counts['preserved'];
				$details[] = array(
					'action_id'   => $action_id,
					'disposition' => 'preserved',
					'reason'      => 'malformed_args',
				);
				continue;
			}

			$parent_job_id = (int) $args['parent_job_id'];
			$parent_job    = $parent_cache[ $parent_job_id ];

			$classification = self::classifyPendingAction(
				$args,
				is_array( $parent_job ) ? $parent_job : null,
				$outstanding[ $parent_job_id ] ?? null
			);
			$detail         = array(
				'action_id'     => $action_id,
				'parent_job_id' => $parent_job_id,
				'offset'        => (int) $args['offset'],
				'disposition'   => $classification['eligible'] ? ( $apply ? 'delete' : 'would_delete' ) : 'preserved',
				'reason'        => $classification['reason'],
			);

			if ( ! $classification['eligible'] ) {
				++$counts['preserved'];
				$details[] = $detail;
				continue;
			}
			if ( $apply && $mutations >= $mutation_limit ) {
				$limit_reached = true;
				$next_cursor   = $last_cursor;
				break;
			}
			++$counts['eligible'];

			if ( $apply ) {
				$deleted = $wpdb->delete(
					$actions_table,
					array(
						'action_id' => $action_id,
						'hook'      => self::HOOK,
						'status'    => 'pending',
						'claim_id'  => 0,
						'args'      => (string) $action['args'],
					),
					array( '%d', '%s', '%s', '%d', '%s' )
				);
				if ( 1 === $deleted ) {
					++$mutations;
					++$counts['deleted'];
					$detail['disposition'] = 'deleted';
					do_action( 'action_scheduler_deleted_action', $action_id );
				} else {
					++$counts['race_skipped'];
					$detail['disposition'] = 'race_skipped';
				}
			}
			$details[] = $detail;
		}

		return array(
			'success'        => true,
			'dry_run'        => ! $apply,
			'cursor'         => $cursor,
			'next_cursor'    => $next_cursor,
			'review_through' => $apply ? $review_through : $next_cursor,
			'has_more'       => $limit_reached || ( $apply ? $next_cursor < $review_through : count( $actions ) === $scan_limit ),
			'limit_reached'  => $limit_reached,
			'mutation_limit' => $mutation_limit,
			'mutations'      => $mutations,
			'counts'         => $counts,
			'actions'        => $details,
		);
	}

	/** @return array{parent_job_id:int,offset:int}|null */
	private static function decodeActionArgs( string $raw ): ?array {
		$args = json_decode( $raw, true );
		if ( ! is_array( $args ) ) {
			$args = maybe_unserialize( $raw );
		}
		if ( ! is_array( $args ) ) {
			return null;
		}
		if ( ! isset( $args['parent_job_id'], $args['offset'] ) && isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		if ( ! isset( $args['parent_job_id'], $args['offset'] ) || ! is_numeric( $args['parent_job_id'] ) || ! is_numeric( $args['offset'] ) ) {
			return null;
		}
		$parent_job_id = (int) $args['parent_job_id'];
		$offset        = (int) $args['offset'];
		if ( $parent_job_id <= 0 || $offset < 0 ) {
			return null;
		}

		return array(
			'parent_job_id' => $parent_job_id,
			'offset'        => $offset,
		);
	}

	/**
	 * Select only paths proven obsolete from durable parent/worklist state.
	 *
	 * @param array{parent_job_id:int,offset:int} $args Action arguments.
	 * @param array<string,mixed>|null            $parent_job Parent job row.
	 * @return array{eligible:bool,reason:string}
	 */
	private static function classifyPendingAction( array $args, ?array $parent_job, ?int $first_outstanding ): array {
		if ( null === $parent_job ) {
			return array(
				'eligible' => true,
				'reason'   => 'parent_missing',
			);
		}
		$status = (string) ( $parent_job['status'] ?? '' );
		if ( JobStatus::isStatusFinal( $status ) ) {
			return array(
				'eligible' => true,
				'reason'   => 'parent_terminal',
			);
		}
		if ( JobStatus::PROCESSING !== $status ) {
			return array(
				'eligible' => false,
				'reason'   => 'parent_not_processing',
			);
		}

		$engine_data = is_array( $parent_job['engine_data'] ?? null ) ? $parent_job['engine_data'] : array();
		if ( ! empty( $engine_data['batch_state']['worklist_complete'] ) ) {
			return array(
				'eligible' => true,
				'reason'   => 'worklist_complete',
			);
		}
		$chunk_size = max( 1, (int) ( $parent_job['engine_data']['batch_chunk_size'] ?? 10 ) );
		if ( null !== $first_outstanding && $args['offset'] + $chunk_size <= $first_outstanding ) {
			return array(
				'eligible' => true,
				'reason'   => 'offset_superseded',
			);
		}

		return array(
			'eligible' => false,
			'reason'   => null === $first_outstanding ? 'worklist_state_ambiguous' : 'active_or_future_path',
		);
	}
}
