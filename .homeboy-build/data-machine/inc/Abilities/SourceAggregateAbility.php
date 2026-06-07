<?php
/**
 * Source aggregation ability.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use DataMachine\Core\SourceAggregation\PageableSourceAggregator;

defined( 'ABSPATH' ) || exit;

class SourceAggregateAbility {

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
				'datamachine/source-aggregate',
				array(
					'label'               => __( 'Aggregate Pageable Source', 'data-machine' ),
					'description'         => __( 'Page through a source response, group dotted fields, and return bucket samples.', 'data-machine' ),
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
	 * Execute source aggregation.
	 *
	 * Source execution is deliberately pluggable. Core ships a `static_pages`
	 * source for deterministic fixtures; live source runners can supply a page
	 * callback through `datamachine_source_aggregate_page_callback`.
	 *
	 * @param array $input Ability input.
	 * @return array Ability result.
	 */
	public function execute( array $input ): array {
		$source = is_array( $input['source'] ?? null ) ? $input['source'] : array();

		$page_callback = $this->resolvePageCallback( $source, $input );
		if ( ! is_callable( $page_callback ) ) {
			return array(
				'success'     => false,
				'error'       => 'No source aggregation page executor is available for this source.',
				'diagnostics' => array(
					'source_kind' => (string) ( $source['kind'] ?? '' ),
				),
			);
		}

		$config           = $input;
		$config['params'] = is_array( $source['params'] ?? null ) ? $source['params'] : array();

		$aggregator = new PageableSourceAggregator();
		$result     = $aggregator->aggregate( $page_callback, $config );

		return array_merge( array( 'success' => true ), $result );
	}

	private function resolvePageCallback( array $source, array $input ): ?callable {
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
			'required'   => array( 'source', 'pagination' ),
			'properties' => array(
				'source'                  => array(
					'type'        => 'object',
					'description' => 'Source descriptor. Core supports kind=static_pages; extensions can provide executors through datamachine_source_aggregate_page_callback.',
				),
				'pagination'              => array(
					'type'       => 'object',
					'required'   => array( 'item_path' ),
					'properties' => array(
						'type'         => array(
							'type'    => 'string',
							'default' => 'offset_limit',
						),
						'limit'        => array(
							'type'    => 'integer',
							'default' => 100,
						),
						'item_path'    => array( 'type' => 'string' ),
						'total_path'   => array( 'type' => 'string' ),
						'offset_param' => array(
							'type'    => 'string',
							'default' => 'offset',
						),
						'limit_param'  => array(
							'type'    => 'string',
							'default' => 'limit',
						),
					),
				),
				'group_by'                => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Top-level or dotted item fields to count.',
				),
				'sample_limit_per_bucket' => array(
					'type'    => 'integer',
					'default' => 3,
				),
				'max_items'               => array(
					'type'    => 'integer',
					'default' => 1000,
				),
				'max_pages'               => array(
					'type'    => 'integer',
					'default' => 100,
				),
			),
		);
	}

	private function getOutputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success'     => array( 'type' => 'boolean' ),
				'total'       => array( 'type' => 'integer' ),
				'processed'   => array( 'type' => 'integer' ),
				'pages'       => array( 'type' => 'integer' ),
				'groups'      => array( 'type' => 'object' ),
				'samples'     => array( 'type' => 'object' ),
				'diagnostics' => array( 'type' => 'object' ),
				'error'       => array( 'type' => 'string' ),
			),
		);
	}
}
