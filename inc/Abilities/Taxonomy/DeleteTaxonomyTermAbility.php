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

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Core\WordPress\TaxonomyHandler;

defined( 'ABSPATH' ) || exit;

class DeleteTaxonomyTermAbility {

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/delete-taxonomy-term',
				array(
					'label'               => __( 'Delete Taxonomy Term', 'data-machine' ),
					'description'         => __( 'Delete an existing taxonomy term. Optionally reassign posts to another term.', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'term'      => array(
								'type'        => 'string',
								'required'    => true,
								'description' => __( 'Term identifier (ID, name, or slug)', 'data-machine' ),
							),
							'taxonomy'  => array(
								'type'        => 'string',
								'required'    => true,
								'description' => __( 'Taxonomy slug (category, post_tag, custom taxonomy)', 'data-machine' ),
							),
							'reassign'  => array(
								'type'        => 'integer',
								'description' => __( 'Term ID to reassign posts to (optional)', 'data-machine' ),
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
							'taxonomy'    => array( 'type' => 'string' ),
							'deleted'     => array( 'type' => 'boolean' ),
							'reassigned'  => array( 'type' => 'integer' ),
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
	 * Execute delete taxonomy term ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with deletion status.
	 */
	public function execute( array $input ): array {
		$term_identifier = $input['term'] ?? null;
		$taxonomy        = $input['taxonomy'] ?? null;
		$reassign        = $input['reassign'] ?? null;

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

		// Validate reassign term if provided
		if ( null !== $reassign ) {
			$reassign_term = get_term( absint( $reassign ), $taxonomy );
			if ( ! $reassign_term || is_wp_error( $reassign_term ) ) {
				return array(
					'success' => false,
					'error'   => "Reassign term ID '{$reassign}' not found in taxonomy '{$taxonomy}'",
				);
			}

			// Cannot reassign to itself
			if ( $reassign_term->term_id === $term->term_id ) {
				return array(
					'success' => false,
					'error'   => 'Cannot reassign term to itself',
				);
			}
		}

		// Delete the term
		$args = array();
		if ( null !== $reassign ) {
			$args['default'] = absint( $reassign );
		}

		$result = wp_delete_term( $term->term_id, $taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		if ( false === $result ) {
			return array(
				'success' => false,
				'error'   => 'Failed to delete term (unknown error)',
			);
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
			'success'   => true,
			'term_id'   => $term->term_id,
			'term_name' => $term->name,
			'taxonomy'  => $taxonomy,
			'deleted'   => true,
			'reassigned' => null !== $reassign ? absint( $reassign ) : null,
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
