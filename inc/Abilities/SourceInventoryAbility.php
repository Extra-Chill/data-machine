<?php
/**
 * Source inventory ability.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use DataMachine\Core\SourceAggregation\PageableSourceAggregator;
use DataMachine\Core\SourceAggregation\SourceInventoryProfiler;

defined( 'ABSPATH' ) || exit;

class SourceInventoryAbility {

	private static bool $registered = false;

	public function __construct() {
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
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

			$config           = $input;
			$config['params'] = is_array( $source['params'] ?? null ) ? $source['params'] : array();

			$scan           = ( new PageableSourceAggregator() )->aggregate( $page_callback, $config );
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
