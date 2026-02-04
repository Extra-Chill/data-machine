<?php
/**
 * Get Taxonomy Terms Ability
 *
 * Handles retrieving taxonomy terms with filtering and pagination.
 *
 * @package DataMachine\Abilities\Taxonomy
 * @since 0.13.7
 */

namespace DataMachine\Abilities\Taxonomy;

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Core\WordPress\TaxonomyHandler;

defined( 'ABSPATH' ) || exit;

class GetTaxonomyTermsAbility {

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/get-taxonomy-terms',
				array(
					'label'               => __( 'Get Taxonomy Terms', 'data-machine' ),
					'description'         => __( 'Retrieve taxonomy terms with optional filtering by taxonomy, search, and pagination.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'taxonomy'    => array(
								'type'        => 'string',
								'description' => __( 'Taxonomy slug (category, post_tag, custom taxonomy)', 'data-machine' ),
							),
							'search'      => array(
								'type'        => 'string',
								'description' => __( 'Search term to filter terms by name', 'data-machine' ),
							),
							'parent'      => array(
								'type'        => 'integer',
								'description' => __( 'Parent term ID to get child terms', 'data-machine' ),
							),
							'hide_empty'  => array(
								'type'        => 'boolean',
								'description' => __( 'Hide terms with no posts (default: false)', 'data-machine' ),
							),
							'number'      => array(
								'type'        => 'integer',
								'description' => __( 'Maximum number of terms to return', 'data-machine' ),
							),
							'offset'      => array(
								'type'        => 'integer',
								'description' => __( 'Number of terms to skip', 'data-machine' ),
							),
							'orderby'     => array(
								'type'        => 'string',
								'enum'        => array( 'name', 'slug', 'term_id', 'count' ),
								'description' => __( 'Sort field (default: name)', 'data-machine' ),
							),
							'order'       => array(
								'type'        => 'string',
								'enum'        => array( 'ASC', 'DESC' ),
								'description' => __( 'Sort order (default: ASC)', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'terms'   => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'term_id'    => array( 'type' => 'integer' ),
										'name'       => array( 'type' => 'string' ),
										'slug'       => array( 'type' => 'string' ),
										'count'      => array( 'type' => 'integer' ),
										'parent'     => array( 'type' => 'integer' ),
										'term_group' => array( 'type' => 'integer' ),
									),
								),
							),
							'total'   => array( 'type' => 'integer' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
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
	 * Execute get taxonomy terms ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with taxonomy terms data.
	 */
	public function execute( array $input ): array {
		$taxonomy   = $input['taxonomy'] ?? null;
		$search     = $input['search'] ?? '';
		$parent     = $input['parent'] ?? null;
		$hide_empty = $input['hide_empty'] ?? false;
		$number     = $input['number'] ?? null;
		$offset     = $input['offset'] ?? null;
		$orderby    = $input['orderby'] ?? 'name';
		$order      = $input['order'] ?? 'ASC';

		// Validate taxonomy
		if ( ! empty( $taxonomy ) && ! taxonomy_exists( $taxonomy ) ) {
			return array(
				'success' => false,
				'error'   => "Taxonomy '{$taxonomy}' does not exist",
			);
		}

		if ( ! empty( $taxonomy ) && TaxonomyHandler::shouldSkipTaxonomy( $taxonomy ) ) {
			return array(
				'success' => false,
				'error'   => "Taxonomy '{$taxonomy}' is a system taxonomy and cannot be accessed",
			);
		}

		// Build get_terms arguments
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => $hide_empty,
			'orderby'    => $orderby,
			'order'      => $order,
			'fields'     => 'all',
		);

		if ( ! empty( $search ) ) {
			$args['search'] = $search;
		}

		if ( null !== $parent ) {
			$args['parent'] = $parent;
		}

		if ( null !== $number ) {
			$args['number'] = $number;
		}

		if ( null !== $offset ) {
			$args['offset'] = $offset;
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return array(
				'success' => false,
				'error'   => $terms->get_error_message(),
			);
		}

		// Format terms data
		$formatted_terms = array();
		foreach ( $terms as $term ) {
			$formatted_terms[] = array(
				'term_id'    => $term->term_id,
				'name'       => $term->name,
				'slug'       => $term->slug,
				'count'      => $term->count,
				'parent'     => $term->parent,
				'term_group' => $term->term_group,
			);
		}

		return array(
			'success' => true,
			'terms'   => $formatted_terms,
			'total'   => count( $formatted_terms ),
		);
	}

	/**
	 * Check permission for this ability.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}
}
