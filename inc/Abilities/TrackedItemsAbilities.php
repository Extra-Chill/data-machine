<?php
/**
 * Abilities for durable tracked source/entity items.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use DataMachine\Core\Database\TrackedItems\TrackedItems;

defined( 'ABSPATH' ) || exit;

class TrackedItemsAbilities {

	private static bool $registered = false;

	private TrackedItems $tracked_items;

	public function __construct( ?TrackedItems $tracked_items = null ) {
		$this->tracked_items = null !== $tracked_items ? $tracked_items : new TrackedItems();

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerUpsertTrackedItem();
			$this->registerGetTrackedItem();
			$this->registerListTrackedItems();
			$this->registerTrackedItemsSummary();
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	private function registerUpsertTrackedItem(): void {
		wp_register_ability(
			'datamachine/upsert-tracked-item',
			array(
				'label'               => __( 'Upsert Tracked Item', 'data-machine' ),
				'description'         => __( 'Create or update durable source/entity coverage state for a stable item.', 'data-machine' ),
				'category'            => 'datamachine-agent',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'namespace', 'item_id' ),
					'properties' => self::item_schema_properties(),
				),
				'output_schema'       => self::item_output_schema(),
				'execute_callback'    => array( $this, 'executeUpsertTrackedItem' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerGetTrackedItem(): void {
		wp_register_ability(
			'datamachine/get-tracked-item',
			array(
				'label'               => __( 'Get Tracked Item', 'data-machine' ),
				'description'         => __( 'Read one durable tracked source/entity coverage item.', 'data-machine' ),
				'category'            => 'datamachine-agent',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'namespace', 'item_id' ),
					'properties' => array(
						'namespace' => array( 'type' => 'string' ),
						'item_id'   => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => self::item_output_schema(),
				'execute_callback'    => array( $this, 'executeGetTrackedItem' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerListTrackedItems(): void {
		wp_register_ability(
			'datamachine/list-tracked-items',
			array(
				'label'               => __( 'List Tracked Items', 'data-machine' ),
				'description'         => __( 'List durable tracked source/entity coverage items by namespace, type, state, or output.', 'data-machine' ),
				'category'            => 'datamachine-agent',
				'input_schema'        => self::query_input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'items'   => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( $this, 'executeListTrackedItems' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerTrackedItemsSummary(): void {
		wp_register_ability(
			'datamachine/tracked-items-summary',
			array(
				'label'               => __( 'Tracked Items Summary', 'data-machine' ),
				'description'         => __( 'Summarize durable tracked source/entity coverage state by type and state.', 'data-machine' ),
				'category'            => 'datamachine-agent',
				'input_schema'        => self::query_input_schema(),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'executeTrackedItemsSummary' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/** @param array<string,mixed> $input Input. */
	public function executeUpsertTrackedItem( array $input ): array {
		$item = $this->tracked_items->upsert( $input );
		return $item ? array(
			'success' => true,
			'item'    => $item,
		) : array(
			'success' => false,
			'error'   => 'Could not upsert tracked item.',
		);
	}

	/** @param array<string,mixed> $input Input. */
	public function executeGetTrackedItem( array $input ): array {
		$item = $this->tracked_items->get( (string) ( $input['namespace'] ?? '' ), (string) ( $input['item_id'] ?? '' ) );
		return array(
			'success' => null !== $item,
			'item'    => $item,
		);
	}

	/** @param array<string,mixed> $input Input. */
	public function executeListTrackedItems( array $input ): array {
		return array(
			'success' => true,
			'items'   => $this->tracked_items->list( $input ),
		);
	}

	/** @param array<string,mixed> $input Input. */
	public function executeTrackedItemsSummary( array $input ): array {
		return array_merge( array( 'success' => true ), $this->tracked_items->summary( $input ) );
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'manage_flows' );
	}

	/** @return array<string,mixed> */
	private static function item_schema_properties(): array {
		return array(
			'namespace'       => array( 'type' => 'string' ),
			'item_id'         => array( 'type' => 'string' ),
			'item_type'       => array( 'type' => 'string' ),
			'state'           => array(
				'type' => 'string',
				'enum' => TrackedItems::states(),
			),
			'source_ref'      => array( 'type' => 'string' ),
			'source_revision' => array( 'type' => 'string' ),
			'source_path'     => array( 'type' => 'string' ),
			'source_line'     => array( 'type' => 'integer' ),
			'output_ref'      => array( 'type' => 'string' ),
			'metadata'        => array( 'type' => 'object' ),
			'last_job_id'     => array( 'type' => 'integer' ),
		);
	}

	/** @return array<string,mixed> */
	private static function query_input_schema(): array {
		$properties           = self::item_schema_properties();
		$properties['limit']  = array( 'type' => 'integer' );
		$properties['offset'] = array( 'type' => 'integer' );
		return array(
			'type'       => 'object',
			'properties' => $properties,
		);
	}

	/** @return array<string,mixed> */
	private static function item_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'item'    => array(
					'type' => array( 'object', 'null' ),
				),
				'error'   => array( 'type' => 'string' ),
			),
		);
	}
}
