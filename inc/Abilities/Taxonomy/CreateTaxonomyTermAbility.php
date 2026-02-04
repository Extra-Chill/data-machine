<?php
/**
 * Create Taxonomy Term Ability
 *
 * Handles creating new taxonomy terms.
 *
 * @package DataMachine\Abilities\Taxonomy
 * @since 0.13.7
 */

namespace DataMachine\Abilities\Taxonomy;

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Core\WordPress\TaxonomyHandler;

defined( 'ABSPATH' ) || exit;

class CreateTaxonomyTermAbility {

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/create-taxonomy-term',
				array(
					'label'               => __( 'Create Taxonomy Term', 'data-machine' ),
					'description'         => __( 'Create a new taxonomy term. The term will be created if it does not already exist.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'taxonomy'    => array(
								'type'        => 'string',
								'required'    => true,
								'description' => __( 'Taxonomy slug (category, post_tag, custom taxonomy)', 'data-machine' ),
							),
							'name'        => array(
								'type'        => 'string',
								'required'    => true,
								'description' => __( 'Term name', 'data-machine' ),
							),
							'slug'        => array(
								'type'        => 'string',
								'description' => __( 'Term slug (auto-generated if not provided)', 'data-machine' ),
							),
							'description' => array(
								'type'        => 'string',
								'description' => __( 'Term description', 'data-machine' ),
							),
							'parent'      => array(
								'type'        => 'integer',
								'description' => __( 'Parent term ID for hierarchical taxonomies', 'data-machine' ),
							),
						),
						'required' => array( 'taxonomy', 'name' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'term_id'   => array( 'type' => 'integer' ),
							'term_name' => array( 'type' => 'string' ),
							'term_slug' => array( 'type' => 'string' ),
							'taxonomy'  => array( 'type' => 'string' ),
							'created'   => array( 'type' => 'boolean' ),
							'existed'   => array( 'type' => 'boolean' ),
							'error'     => array( 'type' => 'string' ),
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
	 * Execute create taxonomy term ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with created taxonomy term data.
	 */
	public function execute( array $input ): array {
		$taxonomy    = $input['taxonomy'] ?? null;
		$name        = $input['name'] ?? null;
		$slug        = $input['slug'] ?? null;
		$description = $input['description'] ?? '';
		$parent      = $input['parent'] ?? 0;

		// Validate required fields
		if ( empty( $taxonomy ) ) {
			return array(
				'success' => false,
				'error'   => 'taxonomy parameter is required',
			);
		}

		if ( empty( $name ) ) {
			return array(
				'success' => false,
				'error'   => 'name parameter is required',
			);
		}

		// Validate taxonomy
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array(
				'success' => false,
				'error'   => "Taxonomy '{$taxonomy}' does not exist",
			);
		}

		if ( TaxonomyHandler::shouldSkipTaxonomy( $taxonomy ) ) {
			return array(
				'success' => false,
				'error'   => "Taxonomy '{$taxonomy}' is a system taxonomy and cannot be modified",
			);
		}

		// Sanitize inputs
		$name        = sanitize_text_field( wp_unslash( $name ) );
		$slug        = ! empty( $slug ) ? sanitize_title( wp_unslash( $slug ) ) : null;
		$description = sanitize_text_field( wp_unslash( $description ) );
		$parent      = absint( $parent );

		// Check if term already exists
		$existing_term = null;
		if ( ! empty( $slug ) ) {
			$existing_term = get_term_by( 'slug', $slug, $taxonomy );
		}
		if ( ! $existing_term ) {
			$existing_term = get_term_by( 'name', $name, $taxonomy );
		}

		if ( $existing_term ) {
			return array(
				'success'   => true,
				'term_id'   => $existing_term->term_id,
				'term_name' => $existing_term->name,
				'term_slug' => $existing_term->slug,
				'taxonomy'  => $taxonomy,
				'created'   => false,
				'existed'   => true,
			);
		}

		// Create the term
		$args = array(
			'description' => $description,
		);

		if ( ! empty( $slug ) ) {
			$args['slug'] = $slug;
		}

		if ( $parent > 0 ) {
			$args['parent'] = $parent;
		}

		$result = wp_insert_term( $name, $taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		$term_id = $result['term_id'];
		$term    = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to retrieve created term',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Taxonomy term created via ability',
			array(
				'term_id'   => $term_id,
				'term_name' => $term->name,
				'taxonomy'  => $taxonomy,
			)
		);

		return array(
			'success'   => true,
			'term_id'   => $term_id,
			'term_name' => $term->name,
			'term_slug' => $term->slug,
			'taxonomy'  => $taxonomy,
			'created'   => true,
			'existed'   => false,
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
