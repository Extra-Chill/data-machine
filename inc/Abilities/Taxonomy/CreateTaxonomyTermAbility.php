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

use DataMachine\Core\WordPress\TaxonomyHandler;

defined( 'ABSPATH' ) || exit;

class CreateTaxonomyTermAbility extends AbstractTaxonomyAbility {

	protected function getAbilityName(): string {
		return 'datamachine/create-taxonomy-term';
	}

	protected function getAbilityArgs(): array {
		return array(
			'label'               => __( 'Create Taxonomy Term', 'data-machine' ),
			'description'         => __( 'Create a new taxonomy term. The term will be created if it does not already exist.', 'data-machine' ),
			'category'            => 'datamachine-taxonomy',
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
				'required'   => array( 'taxonomy', 'name' ),
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
		);
	}

	/**
	 * Execute create taxonomy term ability.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error Result with created taxonomy term data or failure.
	 */
	public function execute( array $input ): array|\WP_Error {
		$taxonomy    = $input['taxonomy'] ?? null;
		$name        = $input['name'] ?? null;
		$slug        = $input['slug'] ?? null;
		$description = $input['description'] ?? '';
		$parent      = $input['parent'] ?? 0;

		// Validate required fields
		if ( empty( $taxonomy ) ) {
			return $this->abilityError( 'taxonomy_required', 'taxonomy parameter is required', 400 );
		}

		if ( empty( $name ) ) {
			return $this->abilityError( 'term_name_required', 'name parameter is required', 400 );
		}

		// Validate taxonomy
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->abilityError( 'taxonomy_not_found', "Taxonomy '{$taxonomy}' does not exist", 404 );
		}

		if ( TaxonomyHandler::shouldSkipTaxonomy( $taxonomy ) ) {
			return $this->abilityError( 'taxonomy_not_modifiable', "Taxonomy '{$taxonomy}' is a system taxonomy and cannot be modified", 403 );
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
			return $result;
		}

		$term_id = $result['term_id'];
		$term    = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			if ( is_wp_error( $term ) ) {
				return $term;
			}

			return $this->abilityError( 'term_retrieval_failed', 'Failed to retrieve created term', 500 );
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
}
