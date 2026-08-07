<?php
/**
 * Delete Taxonomy Term Ability
 *
 * Handles deleting taxonomy terms.
 *
 * @package DataMachine\Abilities\Taxonomy
 * @since 0.13.7
 */

namespace DataMachine\Abilities\Taxonomy;

use DataMachine\Core\WordPress\TaxonomyHandler;

defined( 'ABSPATH' ) || exit;

class DeleteTaxonomyTermAbility extends AbstractTaxonomyAbility {

	protected function getAbilityName(): string {
		return 'datamachine/delete-taxonomy-term';
	}

	protected function getAbilityArgs(): array {
		return array(
			'label'               => __( 'Delete Taxonomy Term', 'data-machine' ),
			'description'         => __( 'Delete an existing taxonomy term. Optionally reassign posts to another term.', 'data-machine' ),
			'category'            => 'datamachine-taxonomy',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'term'     => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'Term identifier (ID, name, or slug)', 'data-machine' ),
					),
					'taxonomy' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'Taxonomy slug (category, post_tag, custom taxonomy)', 'data-machine' ),
					),
					'reassign' => array(
						'type'        => 'integer',
						'description' => __( 'Term ID to reassign posts to (optional)', 'data-machine' ),
					),
				),
				'required'   => array( 'term', 'taxonomy' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'term_id'    => array( 'type' => 'integer' ),
					'term_name'  => array( 'type' => 'string' ),
					'taxonomy'   => array( 'type' => 'string' ),
					'deleted'    => array( 'type' => 'boolean' ),
					'reassigned' => array( 'type' => 'integer' ),
					'error'      => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'checkPermission' ),
			'meta'                => array( 'show_in_rest' => true ),
		);
	}

	/**
	 * Execute delete taxonomy term ability.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error Result with deletion status or failure.
	 */
	public function execute( array $input ): array|\WP_Error {
		$term_identifier = $input['term'] ?? null;
		$taxonomy        = $input['taxonomy'] ?? null;
		$reassign        = $input['reassign'] ?? null;

		// Validate required fields
		if ( empty( $term_identifier ) ) {
			return $this->abilityError( 'term_required', 'term parameter is required', 400 );
		}

		if ( empty( $taxonomy ) ) {
			return $this->abilityError( 'taxonomy_required', 'taxonomy parameter is required', 400 );
		}

		// Validate taxonomy
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->abilityError( 'taxonomy_not_found', "Taxonomy '{$taxonomy}' does not exist", 404 );
		}

		if ( TaxonomyHandler::shouldSkipTaxonomy( $taxonomy ) ) {
			return $this->abilityError( 'taxonomy_not_modifiable', "Taxonomy '{$taxonomy}' is a system taxonomy and cannot be modified", 403 );
		}

		// Find the term using centralized resolver
		$resolved = ResolveTermAbility::resolve( $term_identifier, $taxonomy, false );
		if ( ! $resolved['success'] ) {
			return $this->abilityError( 'term_not_found', $resolved['error'] ?? "Term '{$term_identifier}' not found in taxonomy '{$taxonomy}'", 404 );
		}
		$term = get_term( $resolved['term_id'], $taxonomy );

		// Validate reassign term if provided
		if ( null !== $reassign ) {
			$reassign_term = get_term( absint( $reassign ), $taxonomy );
			if ( ! $reassign_term || is_wp_error( $reassign_term ) ) {
				if ( is_wp_error( $reassign_term ) ) {
					return $reassign_term;
				}

				return $this->abilityError( 'reassign_term_not_found', "Reassign term ID '{$reassign}' not found in taxonomy '{$taxonomy}'", 404 );
			}

			// Cannot reassign to itself
			if ( $reassign_term->term_id === $term->term_id ) {
				return $this->abilityError( 'reassign_term_invalid', 'Cannot reassign term to itself', 400 );
			}
		}

		// Delete the term
		$args = array();
		if ( null !== $reassign ) {
			$args['default'] = absint( $reassign );
		}

		$result = wp_delete_term( $term->term_id, $taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			return $this->abilityError( 'term_delete_failed', 'Failed to delete term (unknown error)', 500 );
		}

		do_action(
			'datamachine_log',
			'info',
			'Taxonomy term deleted via ability',
			array(
				'term_id'    => $term->term_id,
				'term_name'  => $term->name,
				'taxonomy'   => $taxonomy,
				'reassigned' => $reassign,
			)
		);

		return array(
			'success'    => true,
			'term_id'    => $term->term_id,
			'term_name'  => $term->name,
			'taxonomy'   => $taxonomy,
			'deleted'    => true,
			'reassigned' => null !== $reassign ? absint( $reassign ) : null,
		);
	}
}
