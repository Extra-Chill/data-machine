<?php
/**
 * Source inventory ability.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use DataMachine\Core\Database\TrackedItems\TrackedItems;
use DataMachine\Core\SourceAggregation\PageableSourceAggregator;
use DataMachine\Core\SourceAggregation\SourceInventoryProfiler;

defined( 'ABSPATH' ) || exit;

class SourceInventoryAbility {

	private static bool $registered = false;

	private ?TrackedItems $tracked_items;

	public function __construct( ?TrackedItems $tracked_items = null ) {
		$this->tracked_items = $tracked_items;

		if ( self::$registered ) {
			return;
		}

		$this->registerAbility();
		self::$registered = true;
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/source-inventory',
				array(
					'label'               => __( 'Inspect Source Inventory', 'data-machine' ),
					'description'         => __( 'Profile source inventory capabilities and optionally scan a pageable source for denominator diagnostics.', 'data-machine' ),
					'category'            => 'datamachine-fetch',
					'input_schema'        => $this->getInputSchema(),
					'output_schema'       => $this->getOutputSchema(),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute source inventory profiling.
	 *
	 * @param array $input Ability input.
	 * @return array<string,mixed>
	 */
	public function execute( array $input ): array {
		$source  = is_array( $input['source'] ?? null ) ? $input['source'] : array();
		$profile = ( new SourceInventoryProfiler() )->profile( $source );
		$result  = array(
			'success' => true,
			'profile' => $profile,
		);

		if ( ! empty( $input['scan'] ) ) {
			$page_callback = $this->resolvePageCallback( $source, $input );
			if ( ! is_callable( $page_callback ) ) {
				$result['scan'] = array(
					'success'     => false,
					'error'       => 'No source inventory page executor is available for this source.',
					'diagnostics' => array(
						'source_kind' => (string) ( $source['kind'] ?? '' ),
					),
				);

				return $result;
			}

			$aggregator        = new PageableSourceAggregator();
			$config            = $input;
			$config['params']  = is_array( $source['params'] ?? null ) ? $source['params'] : array();
			$tracking_callback = $this->buildTrackingCallback( $input, $aggregator );
			if ( null !== $tracking_callback ) {
				$config['item_callback'] = $tracking_callback;
			}

			$scan = $aggregator->aggregate( $page_callback, $config );
			if ( isset( $scan['item_callback'] ) ) {
				$scan['tracked_items'] = $scan['item_callback'];
				unset( $scan['item_callback'] );
			}
			$result['scan'] = array_merge( array( 'success' => true ), $scan );
		}

		return $result;
	}

	private function resolvePageCallback( array $source, array $input ): ?callable {
		// @phpstan-ignore-next-line WP stubs expose a narrower apply_filters() signature than WordPress supports.
		$callback = apply_filters( 'datamachine_source_inventory_page_callback', null, $source, $input );
		if ( is_callable( $callback ) ) {
			return $callback;
		}

		// Share the existing aggregation callback seam so adapters do not need to
		// register two identical callbacks for simple pageable inventory scans.
		// @phpstan-ignore-next-line WP stubs expose a narrower apply_filters() signature than WordPress supports.
		$callback = apply_filters( 'datamachine_source_aggregate_page_callback', null, $source, $input );
		if ( is_callable( $callback ) ) {
			return $callback;
		}

		if ( 'static_pages' === ( $source['kind'] ?? '' ) && is_array( $source['pages'] ?? null ) ) {
			$pages = array_values( $source['pages'] );
			return static function ( array $params, array $state ) use ( $pages ): array {
				$page_index = (int) ( $state['page_index'] ?? 0 );
				return is_array( $pages[ $page_index ] ?? null ) ? $pages[ $page_index ] : array();
			};
		}

		return null;
	}

	private function buildTrackingCallback( array $input, PageableSourceAggregator $aggregator ): ?callable {
		$tracking  = is_array( $input['track_items'] ?? null ) ? $input['track_items'] : array();
		$namespace = trim( (string) ( $tracking['namespace'] ?? '' ) );
		if ( '' === $namespace ) {
			return null;
		}

		$repository   = $this->tracked_items ?? new TrackedItems();
		$item_id_path = (string) ( $tracking['item_id_path'] ?? 'id' );

		return static function ( array $item ) use ( $tracking, $namespace, $item_id_path, $repository, $aggregator ): bool {
			$item_id = $aggregator->getPath( $item, $item_id_path );
			if ( null === $item_id || '' === (string) $item_id ) {
				return false;
			}

			$tracked_item = array(
				'namespace'       => $namespace,
				'item_id'         => (string) $item_id,
				'item_type'       => (string) ( $tracking['item_type'] ?? '' ),
				'state'           => (string) ( $tracking['state'] ?? TrackedItems::STATE_DISCOVERED ),
				'source_ref'      => (string) ( $tracking['source_ref'] ?? '' ),
				'source_revision' => (string) ( $tracking['source_revision'] ?? '' ),
				'source_path'     => (string) ( $tracking['source_path'] ?? '' ),
				'source_line'     => max( 0, (int) ( $tracking['source_line'] ?? 0 ) ),
				'output_ref'      => (string) ( $tracking['output_ref'] ?? '' ),
				'last_job_id'     => max( 0, (int) ( $tracking['last_job_id'] ?? 0 ) ),
			);

			foreach ( array( 'item_type', 'source_ref', 'source_revision', 'source_path', 'source_line', 'output_ref' ) as $field ) {
				$path = (string) ( $tracking[ $field . '_path' ] ?? '' );
				if ( '' === $path ) {
					continue;
				}

				$value = $aggregator->getPath( $item, $path );
				if ( null !== $value ) {
					$tracked_item[ $field ] = $value;
				}
			}

			$metadata_paths = is_array( $tracking['metadata_paths'] ?? null ) ? $tracking['metadata_paths'] : array();
			foreach ( $metadata_paths as $metadata_key => $path ) {
				$metadata_key = trim( (string) $metadata_key );
				if ( '' === $metadata_key ) {
					continue;
				}

				$value = $aggregator->getPath( $item, (string) $path );
				if ( null !== $value ) {
					$tracked_item['metadata'][ $metadata_key ] = $value;
				}
			}

			return null !== $repository->upsert( $tracked_item );
		};
	}

	private function getInputSchema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'source' ),
			'properties' => array(
				'source'                  => array(
					'type'        => 'object',
					'description' => 'Source descriptor with optional capabilities. Core supports kind=static_pages for scans; extensions can provide inventory executors through datamachine_source_inventory_page_callback.',
				),
				'scan'                    => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true, page through the source and return count/group diagnostics.',
				),
				'pagination'              => array(
					'type'       => 'object',
					'properties' => array(
						'type'         => array( 'type' => 'string' ),
						'limit'        => array( 'type' => 'integer' ),
						'item_path'    => array( 'type' => 'string' ),
						'total_path'   => array( 'type' => 'string' ),
						'offset_param' => array( 'type' => 'string' ),
						'limit_param'  => array( 'type' => 'string' ),
					),
				),
				'group_by'                => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Top-level or dotted item fields to count when scan=true.',
				),
				'sample_limit_per_bucket' => array( 'type' => 'integer' ),
				'max_items'               => array( 'type' => 'integer' ),
				'max_pages'               => array( 'type' => 'integer' ),
				'track_items'             => array(
					'type'        => 'object',
					'description' => 'When provided with scan=true, upsert each scanned item into the generic tracked-items ledger using path-based field mapping.',
					'properties'  => array(
						'namespace'            => array( 'type' => 'string' ),
						'item_id_path'         => array( 'type' => 'string' ),
						'item_type'            => array( 'type' => 'string' ),
						'item_type_path'       => array( 'type' => 'string' ),
						'state'                => array( 'type' => 'string' ),
						'source_ref'           => array( 'type' => 'string' ),
						'source_ref_path'      => array( 'type' => 'string' ),
						'source_revision_path' => array( 'type' => 'string' ),
						'source_path_path'     => array( 'type' => 'string' ),
						'source_line_path'     => array( 'type' => 'string' ),
						'output_ref_path'      => array( 'type' => 'string' ),
						'metadata_paths'       => array( 'type' => 'object' ),
						'last_job_id'          => array( 'type' => 'integer' ),
					),
				),
			),
		);
	}

	private function getOutputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'profile' => array( 'type' => 'object' ),
				'scan'    => array( 'type' => 'object' ),
			),
		);
	}
}
