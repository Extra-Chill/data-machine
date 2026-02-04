<?php
/**
 * Update Taxonomy Term Ability
 *
 * Handles updating existing taxonomy terms.
 *
 * @package DataMachine\Abilities\Taxonomy
 * @since 0.13.7
 */

namespace DataMachine\Abilities\Taxonomy;

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Core\WordPress\TaxonomyHandler;

defined( 'ABSPATH' ) || exit;

class UpdateTaxonomyTermAbility {

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/update-taxonomy-term',
				array(
					'label'               => __( 'Update Taxonomy Term', 'data-machine' ),
					'description'         => __( 'Update an existing taxonomy term. Supports updating name, slug, description, and parent.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'term'        => array(
								'type'        => 'string',
								'required'    => true,
								'description' => __( 'Term identifier (ID, name, or slug)', 'data-machine' ),
							),
							'taxonomy'    => array(
								'type'        => 'string',
								'required'    => true,
								'description' => __( 'Taxonomy slug (category, post_tag, custom taxonomy)', 'data-machine' ),
							),
							'name'        => array(
								'type'        => 'string',
								'description' => __( 'New term name', 'data-machine' ),
							),
							'slug'        => array(
								'type'        => 'string',
								'description' => __( 'New term slug', 'data-machine' ),
							),
							'description' => array(
								'type'        => 'string',
								'description' => __( 'New term description', 'data-machine' ),
							),
							'parent'      => array(
								'type'        => 'integer',
								'description' => __( 'New parent term ID for hierarchical taxonomies', 'data-machine' ),
							),
						),
						'required' => array( 'term', 'taxonomy' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'term_id'     => array( 'type' => 'integer' ),
							'term_name'   => array( 'type' => 'string' ),
							'term_slug'   => array( 'type' => 'string' ),
							'taxonomy'    => array( 'type' => 'string' ),
							'updated'     => array( 'type' => 'boolean' ),
							'changes'     => array( 'type' => 'object' ),
							'error'       => array( 'type' => 'string' ),
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
	 * Execute update taxonomy term ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with updated taxonomy term data.
	 */
	public function execute( array $input ): array {
		$term_identifier = $input['term'] ?? null;
		$taxonomy        = $input['taxonomy'] ?? null;
		$name            = $input['name'] ?? null;
		$slug            = $input['slug'] ?? null;
		$description     = $input['description'] ?? null;
		$parent          = $input['parent'] ?? null;

		// Validate required fields
		if ( empty( $term_identifier ) ) {
			return array(
				'success' => false,
				'error'   => 'term parameter is required',
			);
		}

		if ( empty( $taxonomy ) ) {
			return array(
				'success' => false,
				'error'   => 'taxonomy parameter is required',
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

		// Find the term using centralized resolver
		$resolved = ResolveTermAbility::resolve( $term_identifier, $taxonomy, false );
		if ( ! $resolved['success'] ) {
			return array(
				'success' => false,
				'error'   => $resolved['error'] ?? "Term '{$term_identifier}' not found in taxonomy '{$taxonomy}'",
			);
		}
		$term = get_term( $resolved['term_id'], $taxonomy );

		// Check if any updates are requested
		if ( null === $name && null === $slug && null === $description && null === $parent ) {
			return array(
				'success' => true,
				'term_id' => $term->term_id,
				'term_name' => $term->name,
				'term_slug' => $term->slug,
				'taxonomy' => $taxonomy,
				'updated' => false,
				'changes' => array(),
			);
		}

		// Prepare update arguments
		$args = array();
		$changes = array();

		if ( null !== $name ) {
			$sanitized_name = sanitize_text_field( wp_unslash( $name ) );
			if ( $sanitized_name !== $term->name ) {
				$args['name'] = $sanitized_name;
				$changes['name'] = array(
					'from' => $term->name,
					'to' => $sanitized_name,
				);
			}
		}

		if ( null !== $slug ) {
			$sanitized_slug = sanitize_title( wp_unslash( $slug ) );
			if ( $sanitized_slug !== $term->slug ) {
				$args['slug'] = $sanitized_slug;
				$changes['slug'] = array(
					'from' => $term->slug,
					'to' => $sanitized_slug,
				);
			}
		}

		if ( null !== $description ) {
			$sanitized_description = sanitize_text_field( wp_unslash( $description ) );
			if ( $sanitized_description !== $term->description ) {
				$args['description'] = $sanitized_description;
				$changes['description'] = array(
					'from' => $term->description,
					'to' => $sanitized_description,
				);
			}
		}

		if ( null !== $parent ) {
			$sanitized_parent = absint( $parent );
			if ( $sanitized_parent !== $term->parent ) {
				$args['parent'] = $sanitized_parent;
				$changes['parent'] = array(
					'from' => $term->parent,
					'to' => $sanitized_parent,
				);
			}
		}

		// No changes needed
		if ( empty( $args ) ) {
			return array(
				'success' => true,
				'term_id' => $term->term_id,
				'term_name' => $term->name,
				'term_slug' => $term->slug,
				'taxonomy' => $taxonomy,
				'updated' => false,
				'changes' => array(),
			);
		}

		// Update the term
		$result = wp_update_term( $term->term_id, $taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		$updated_term = get_term( $term->term_id, $taxonomy );

		if ( ! $updated_term || is_wp_error( $updated_term ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to retrieve updated term',
			);
		}

		do_action(
			'datamachine_log',
			'info',
			'Taxonomy term updated via ability',
			array(
				'term_id'   => $updated_term->term_id,
				'term_name' => $updated_term->name,
				'taxonomy'  => $taxonomy,
				'changes'   => $changes,
			)
		);

		return array(
			'success' => true,
			'term_id' => $updated_term->term_id,
			'term_name' => $updated_term->name,
			'term_slug' => $updated_term->slug,
			'taxonomy' => $taxonomy,
			'updated' => true,
			'changes' => $changes,
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
