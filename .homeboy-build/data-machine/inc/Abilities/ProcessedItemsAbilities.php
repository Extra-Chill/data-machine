<?php
/**
 * Processed Items Abilities
 *
 * Abilities API primitives for processed items (deduplication tracking) operations.
 * Centralizes clearing, checking, and history queries for REST API, CLI, and Chat tools.
 *
 * Self-contained ability class - business logic inlined from ProcessedItemsManager (deleted).
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Core\Database\ProcessedItems\ProcessedItems;

defined( 'ABSPATH' ) || exit;

class ProcessedItemsAbilities {

	private static bool $registered = false;

	private ProcessedItems $db_processed_items;

	public function __construct() {
		$this->db_processed_items = new ProcessedItems();

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerClearProcessedItems();
			$this->registerCheckProcessedItem();
			$this->registerHasProcessedHistory();
			$this->registerGetProcessedAt();
			$this->registerFindStale();
			$this->registerFindNeverProcessed();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Register datamachine/clear-processed-items ability.
	 */
	private function registerClearProcessedItems(): void {
		wp_register_ability(
			'datamachine/clear-processed-items',
			array(
				'label'               => __( 'Clear Processed Items', 'data-machine' ),
				'description'         => __( 'Clear processed items tracking by pipeline or flow scope. Used to reset deduplication so items can be re-processed.', 'data-machine' ),
				'category'            => 'datamachine-agent',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'clear_type', 'target_id' ),
					'properties' => array(
						'clear_type' => array(
							'type'        => 'string',
							'enum'        => array( 'pipeline', 'flow' ),
							'description' => __( 'Clear scope: "pipeline" to clear all flows in a pipeline, or "flow" for a single flow', 'data-machine' ),
						),
						'target_id'  => array(
							'type'        => 'integer',
							'description' => __( 'Pipeline ID when clear_type is "pipeline", or Flow ID when clear_type is "flow"', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'deleted_count' => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
						'error'         => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeClearProcessedItems' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register datamachine/check-processed-item ability.
	 */
	private function registerCheckProcessedItem(): void {
		wp_register_ability(
			'datamachine/check-processed-item',
			array(
				'label'               => __( 'Check Processed Item', 'data-machine' ),
				'description'         => __( 'Check if a specific item has already been processed for a given flow step and source type.', 'data-machine' ),
				'category'            => 'datamachine-agent',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_step_id', 'source_type', 'item_identifier' ),
					'properties' => array(
						'flow_step_id'    => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID in format "{pipeline_step_id}_{flow_id}"', 'data-machine' ),
						),
						'source_type'     => array(
							'type'        => 'string',
							'description' => __( 'Source type identifier (e.g., "rss", "reddit", "wordpress")', 'data-machine' ),
						),
						'item_identifier' => array(
							'type'        => 'string',
							'description' => __( 'Unique identifier for the item (e.g., GUID, URL, post ID)', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'is_processed' => array( 'type' => 'boolean' ),
						'error'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeCheckProcessedItem' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register datamachine/has-processed-history ability.
	 */
	private function registerHasProcessedHistory(): void {
		wp_register_ability(
			'datamachine/has-processed-history',
			array(
				'label'               => __( 'Has Processed History', 'data-machine' ),
				'description'         => __( 'Check if a flow step has any processed items history. Useful for distinguishing "no new items" from "first run with nothing".', 'data-machine' ),
				'category'            => 'datamachine-agent',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_step_id' ),
					'properties' => array(
						'flow_step_id' => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID in format "{pipeline_step_id}_{flow_id}"', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'has_history' => array( 'type' => 'boolean' ),
						'error'       => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeHasProcessedHistory' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register datamachine/processed-items-get-processed-at ability.
	 */
	private function registerGetProcessedAt(): void {
		wp_register_ability(
			'datamachine/processed-items-get-processed-at',
			array(
				'label'               => __( 'Get Processed-At Timestamp', 'data-machine' ),
				'description'         => __( 'Get the last-processed Unix timestamp for a specific item, or null if never processed.', 'data-machine' ),
				'category'            => 'datamachine-agent',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_step_id', 'source_type', 'item_identifier' ),
					'properties' => array(
						'flow_step_id'    => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID in format "{pipeline_step_id}_{flow_id}"', 'data-machine' ),
						),
						'source_type'     => array(
							'type'        => 'string',
							'description' => __( 'Source type identifier (e.g., "rss", "wiki_post", "venue")', 'data-machine' ),
						),
						'item_identifier' => array(
							'type'        => 'string',
							'description' => __( 'Unique identifier for the item', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'processed_at' => array(
							'type'        => array( 'integer', 'null' ),
							'description' => __( 'Unix timestamp, or null when never processed', 'data-machine' ),
						),
						'error'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetProcessedAt' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register datamachine/processed-items-find-stale ability.
	 */
	private function registerFindStale(): void {
		wp_register_ability(
			'datamachine/processed-items-find-stale',
			array(
				'label'               => __( 'Find Stale Processed Items', 'data-machine' ),
				'description'         => __( 'Given candidate identifiers, return those whose processed_timestamp is older than the given window. Enables maintenance pipelines.', 'data-machine' ),
				'category'            => 'datamachine-agent',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_step_id', 'source_type', 'candidate_identifiers', 'max_age_days' ),
					'properties' => array(
						'flow_step_id'          => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID in format "{pipeline_step_id}_{flow_id}"', 'data-machine' ),
						),
						'source_type'           => array(
							'type'        => 'string',
							'description' => __( 'Source type identifier', 'data-machine' ),
						),
						'candidate_identifiers' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Candidate item identifiers to evaluate', 'data-machine' ),
						),
						'max_age_days'          => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Staleness threshold in days', 'data-machine' ),
						),
						'limit'                 => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Maximum number of identifiers returned. Default 100.', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'stale_ids' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'count'     => array( 'type' => 'integer' ),
						'error'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeFindStale' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register datamachine/processed-items-find-never-processed ability.
	 */
	private function registerFindNeverProcessed(): void {
		wp_register_ability(
			'datamachine/processed-items-find-never-processed',
			array(
				'label'               => __( 'Find Never-Processed Items', 'data-machine' ),
				'description'         => __( 'Given candidate identifiers, return those that have never been processed for this flow step + source type. Enables backfill on first run.', 'data-machine' ),
				'category'            => 'datamachine-agent',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'flow_step_id', 'source_type', 'candidate_identifiers' ),
					'properties' => array(
						'flow_step_id'          => array(
							'type'        => 'string',
							'description' => __( 'Flow step ID in format "{pipeline_step_id}_{flow_id}"', 'data-machine' ),
						),
						'source_type'           => array(
							'type'        => 'string',
							'description' => __( 'Source type identifier', 'data-machine' ),
						),
						'candidate_identifiers' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Candidate item identifiers to evaluate', 'data-machine' ),
						),
						'limit'                 => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Maximum number of identifiers returned. Default 100.', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'         => array( 'type' => 'boolean' ),
						'never_processed' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'count'           => array( 'type' => 'integer' ),
						'error'           => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeFindNeverProcessed' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Permission callback for abilities.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute clear-processed-items ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with deletion count.
	 */
	public function executeClearProcessedItems( array $input ): array {
		$clear_type = $input['clear_type'] ?? null;
		$target_id  = $input['target_id'] ?? null;

		if ( empty( $clear_type ) ) {
			return array(
				'success' => false,
				'error'   => 'clear_type is required',
			);
		}

		if ( ! in_array( $clear_type, array( 'pipeline', 'flow' ), true ) ) {
			return array(
				'success' => false,
				'error'   => 'clear_type must be either "pipeline" or "flow"',
			);
		}

		if ( empty( $target_id ) || ! is_numeric( $target_id ) ) {
			return array(
				'success' => false,
				'error'   => 'target_id is required and must be an integer',
			);
		}

		$target_id = (int) $target_id;

		$criteria = 'pipeline' === $clear_type
			? array( 'pipeline_id' => $target_id )
			: array( 'flow_id' => $target_id );

		$result = $this->db_processed_items->delete_processed_items( $criteria );

		if ( false === $result ) {
			do_action( 'datamachine_log', 'error', 'Processed items deletion failed', array( 'criteria' => $criteria ) );
			return array(
				'success' => false,
				'error'   => 'Failed to delete processed items',
			);
		}

		$scope_label = 'pipeline' === $clear_type ? "pipeline {$target_id}" : "flow {$target_id}";

		do_action(
			'datamachine_log',
			'info',
			'Processed items cleared via ability',
			array(
				'clear_type'    => $clear_type,
				'target_id'     => $target_id,
				'deleted_count' => $result,
			)
		);

		return array(
			'success'       => true,
			'deleted_count' => $result,
			'message'       => sprintf( 'Deleted %d processed items for %s', $result, $scope_label ),
		);
	}

	/**
	 * Execute check-processed-item ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with is_processed flag.
	 */
	public function executeCheckProcessedItem( array $input ): array {
		$flow_step_id    = $input['flow_step_id'] ?? null;
		$source_type     = $input['source_type'] ?? null;
		$item_identifier = $input['item_identifier'] ?? null;

		if ( empty( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required',
			);
		}

		if ( empty( $source_type ) ) {
			return array(
				'success' => false,
				'error'   => 'source_type is required',
			);
		}

		if ( empty( $item_identifier ) ) {
			return array(
				'success' => false,
				'error'   => 'item_identifier is required',
			);
		}

		$flow_step_id    = sanitize_text_field( $flow_step_id );
		$source_type     = sanitize_text_field( $source_type );
		$item_identifier = sanitize_text_field( $item_identifier );

		$is_processed = $this->db_processed_items->has_item_been_processed(
			$flow_step_id,
			$source_type,
			$item_identifier
		);

		return array(
			'success'      => true,
			'is_processed' => $is_processed,
		);
	}

	/**
	 * Execute has-processed-history ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with has_history flag.
	 */
	public function executeHasProcessedHistory( array $input ): array {
		$flow_step_id = $input['flow_step_id'] ?? null;

		if ( empty( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required',
			);
		}

		$flow_step_id = sanitize_text_field( $flow_step_id );

		$has_history = $this->db_processed_items->has_processed_items( $flow_step_id );

		return array(
			'success'     => true,
			'has_history' => $has_history,
		);
	}

	/**
	 * Execute processed-items-get-processed-at ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with processed_at timestamp (or null).
	 */
	public function executeGetProcessedAt( array $input ): array {
		$flow_step_id    = $input['flow_step_id'] ?? null;
		$source_type     = $input['source_type'] ?? null;
		$item_identifier = $input['item_identifier'] ?? null;

		if ( empty( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required',
			);
		}

		if ( empty( $source_type ) ) {
			return array(
				'success' => false,
				'error'   => 'source_type is required',
			);
		}

		if ( empty( $item_identifier ) ) {
			return array(
				'success' => false,
				'error'   => 'item_identifier is required',
			);
		}

		$flow_step_id    = sanitize_text_field( $flow_step_id );
		$source_type     = sanitize_text_field( $source_type );
		$item_identifier = sanitize_text_field( $item_identifier );

		$processed_at = $this->db_processed_items->get_processed_at(
			$flow_step_id,
			$source_type,
			$item_identifier
		);

		return array(
			'success'      => true,
			'processed_at' => $processed_at,
		);
	}

	/**
	 * Execute processed-items-find-stale ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with stale_ids array.
	 */
	public function executeFindStale( array $input ): array {
		$flow_step_id = $input['flow_step_id'] ?? null;
		$source_type  = $input['source_type'] ?? null;
		$candidates   = $input['candidate_identifiers'] ?? null;
		$max_age_days = $input['max_age_days'] ?? null;
		$limit        = $input['limit'] ?? 100;

		if ( empty( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required',
			);
		}

		if ( empty( $source_type ) ) {
			return array(
				'success' => false,
				'error'   => 'source_type is required',
			);
		}

		if ( ! is_array( $candidates ) ) {
			return array(
				'success' => false,
				'error'   => 'candidate_identifiers must be an array',
			);
		}

		if ( ! is_numeric( $max_age_days ) || (int) $max_age_days < 1 ) {
			return array(
				'success' => false,
				'error'   => 'max_age_days must be an integer >= 1',
			);
		}

		$flow_step_id = sanitize_text_field( $flow_step_id );
		$source_type  = sanitize_text_field( $source_type );
		$candidates   = array_values( array_map( 'strval', $candidates ) );

		$stale_ids = $this->db_processed_items->find_stale(
			$flow_step_id,
			$source_type,
			$candidates,
			(int) $max_age_days,
			max( 1, (int) $limit )
		);

		return array(
			'success'   => true,
			'stale_ids' => $stale_ids,
			'count'     => count( $stale_ids ),
		);
	}

	/**
	 * Execute processed-items-find-never-processed ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with never_processed array.
	 */
	public function executeFindNeverProcessed( array $input ): array {
		$flow_step_id = $input['flow_step_id'] ?? null;
		$source_type  = $input['source_type'] ?? null;
		$candidates   = $input['candidate_identifiers'] ?? null;
		$limit        = $input['limit'] ?? 100;

		if ( empty( $flow_step_id ) ) {
			return array(
				'success' => false,
				'error'   => 'flow_step_id is required',
			);
		}

		if ( empty( $source_type ) ) {
			return array(
				'success' => false,
				'error'   => 'source_type is required',
			);
		}

		if ( ! is_array( $candidates ) ) {
			return array(
				'success' => false,
				'error'   => 'candidate_identifiers must be an array',
			);
		}

		$flow_step_id = sanitize_text_field( $flow_step_id );
		$source_type  = sanitize_text_field( $source_type );
		$candidates   = array_values( array_map( 'strval', $candidates ) );

		$never = $this->db_processed_items->find_never_processed(
			$flow_step_id,
			$source_type,
			$candidates,
			max( 1, (int) $limit )
		);

		return array(
			'success'         => true,
			'never_processed' => $never,
			'count'           => count( $never ),
		);
	}
}
