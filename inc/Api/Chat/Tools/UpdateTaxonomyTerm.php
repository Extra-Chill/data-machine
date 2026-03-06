<?php
/**
 * Update Taxonomy Term Tool
 *
 * Updates existing taxonomy terms including core fields and custom meta.
 * Allows AI agents to modify term data like venue addresses, capacities, etc.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Core\WordPress\TaxonomyHandler;
use DataMachine\Engine\AI\Tools\BaseTool;

class UpdateTaxonomyTerm extends BaseTool {

	public function __construct() {
		$this->registerTool( 'chat', 'update_taxonomy_term', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update an existing taxonomy term. Can modify core fields (name, slug, description, parent) and custom term meta (venue_address, venue_capacity, etc.). Use search_taxonomy_terms first to find the term to update.',
			'parameters'  => array(
				'term'        => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Term identifier - can be term ID, name, or slug',
				),
				'taxonomy'    => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Taxonomy slug (venue, artist, category, post_tag, or custom taxonomy)',
				),
				'name'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'New term name',
				),
				'slug'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'New term slug (URL-friendly identifier)',
				),
				'description' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'New term description',
				),
				'parent'      => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'New parent term (ID, name, or slug) - hierarchical taxonomies only',
				),
				'meta'        => array(
					'type'        => 'object',
					'required'    => false,
					'description' => 'Key-value pairs of term meta to update (e.g., venue_address, venue_capacity). Keys starting with "_" are protected and cannot be modified.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$term_identifier = $parameters['term'] ?? null;
		$taxonomy        = $parameters['taxonomy'] ?? null;
		$name            = $parameters['name'] ?? null;
		$slug            = $parameters['slug'] ?? null;
		$description     = $parameters['description'] ?? null;
		$parent          = $parameters['parent'] ?? null;
		$meta            = $parameters['meta'] ?? null;

		// Validate taxonomy
		if ( empty( $taxonomy ) || ! is_string( $taxonomy ) ) {
			return array(
				'success'   => false,
				'error'     => 'taxonomy is required and must be a non-empty string',
				'tool_name' => 'update_taxonomy_term',
			);
		}

		$taxonomy = sanitize_key( $taxonomy );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array(
				'success'   => false,
				'error'     => "Taxonomy '{$taxonomy}' does not exist",
				'tool_name' => 'update_taxonomy_term',
			);
		}

		if ( TaxonomyHandler::shouldSkipTaxonomy( $taxonomy ) ) {
			return array(
				'success'   => false,
				'error'     => "Taxonomy '{$taxonomy}' is a system taxonomy and cannot be modified",
				'tool_name' => 'update_taxonomy_term',
			);
		}

		// Validate term identifier
		if ( empty( $term_identifier ) || ! is_string( $term_identifier ) ) {
			return array(
				'success'   => false,
				'error'     => 'term is required and must be a non-empty string',
				'tool_name' => 'update_taxonomy_term',
			);
		}

		// Resolve term
		$term = $this->resolveTerm( $term_identifier, $taxonomy );
		if ( ! $term ) {
			return array(
				'success'   => false,
				'error'     => "Term '{$term_identifier}' not found in taxonomy '{$taxonomy}'",
				'tool_name' => 'update_taxonomy_term',
			);
		}

		// Check that at least one field is being updated
		$has_core_updates = ! empty( $name ) || ! empty( $slug ) || null !== $description || ! empty( $parent );
		$has_meta_updates = ! empty( $meta ) && is_array( $meta );

		if ( ! $has_core_updates && ! $has_meta_updates ) {
			return array(
				'success'   => false,
				'error'     => 'At least one field to update is required (name, slug, description, parent, or meta)',
				'tool_name' => 'update_taxonomy_term',
			);
		}

		// Validate meta keys (block underscore-prefixed keys)
		if ( $has_meta_updates ) {
			$blocked_keys = $this->getBlockedMetaKeys( $meta );
			if ( ! empty( $blocked_keys ) ) {
				return array(
					'success'   => false,
					'error'     => "Cannot update protected meta keys starting with '_': " . implode( ', ', $blocked_keys ),
					'tool_name' => 'update_taxonomy_term',
				);
			}
		}

		$updated_fields = array();
		$updated_meta   = array();

		// Update core fields
		if ( $has_core_updates ) {
			$core_result = $this->updateCoreFields( $term, $taxonomy, $name, $slug, $description, $parent );
			if ( false === $core_result['success'] ) {
				return $core_result;
			}
			$updated_fields = $core_result['updated_fields'];
		}

		// Update meta fields
		if ( $has_meta_updates ) {
			$updated_meta = $this->updateMetaFields( $term->term_id, $meta );
		}

		// Refresh term data
		$updated_term = get_term( $term->term_id, $taxonomy );

		return array(
			'success'   => true,
			'data'      => array(
				'term_id'        => $updated_term->term_id,
				'taxonomy'       => $taxonomy,
				'name'           => $updated_term->name,
				'slug'           => $updated_term->slug,
				'description'    => $updated_term->description,
				'parent_id'      => $updated_term->parent,
				'updated_fields' => $updated_fields,
				'updated_meta'   => $updated_meta,
				'message'        => "Updated term '{$updated_term->name}' in taxonomy '{$taxonomy}'.",
			),
			'tool_name' => 'update_taxonomy_term',
		);
	}

	/**
	 * Resolve term by ID, name, or slug.
	 *
	 * @param string $identifier Term identifier
	 * @param string $taxonomy Taxonomy slug
	 * @return \WP_Term|null Term object or null if not found
	 */
	private function resolveTerm( string $identifier, string $taxonomy ): ?\WP_Term {
		// Try as ID first
		if ( is_numeric( $identifier ) ) {
			$term = get_term( (int) $identifier, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		// Try by name
		$term = get_term_by( 'name', $identifier, $taxonomy );
		if ( $term ) {
			return $term;
		}

		// Try by slug
		$term = get_term_by( 'slug', $identifier, $taxonomy );
		if ( $term ) {
			return $term;
		}

		return null;
	}

	/**
	 * Get meta keys that are blocked (start with underscore).
	 *
	 * @param array $meta Meta key-value pairs
	 * @return array Blocked key names
	 */
	private function getBlockedMetaKeys( array $meta ): array {
		$blocked = array();
		foreach ( array_keys( $meta ) as $key ) {
			if ( strpos( $key, '_' ) === 0 ) {
				$blocked[] = $key;
			}
		}
		return $blocked;
	}

	/**
	 * Update core term fields.
	 *
	 * @param \WP_Term    $term Term object
	 * @param string      $taxonomy Taxonomy slug
	 * @param string|null $name New name
	 * @param string|null $slug New slug
	 * @param string|null $description New description
	 * @param string|null $parent New parent identifier
	 * @return array Result with success status and updated fields
	 */
	private function updateCoreFields( \WP_Term $term, string $taxonomy, ?string $name, ?string $slug, ?string $description, ?string $parent_item ): array {
		$args           = array();
		$updated_fields = array();

		if ( ! empty( $name ) ) {
			$args['name']     = sanitize_text_field( $name );
			$updated_fields[] = 'name';
		}

		if ( ! empty( $slug ) ) {
			$args['slug']     = sanitize_title( $slug );
			$updated_fields[] = 'slug';
		}

		if ( null !== $description && '' !== $description ) {
			$args['description'] = sanitize_textarea_field( $description );
			$updated_fields[]    = 'description';
		}

		if ( ! empty( $parent_item ) ) {
			$taxonomy_obj = get_taxonomy( $taxonomy );
			if ( ! $taxonomy_obj->hierarchical ) {
				return array(
					'success'   => false,
					'error'     => "Cannot set parent: taxonomy '{$taxonomy}' is not hierarchical",
					'tool_name' => 'update_taxonomy_term',
				);
			}

			$parent_term = $this->resolveTerm( $parent_item, $taxonomy );
			if ( ! $parent_term ) {
				return array(
					'success'   => false,
					'error'     => "Parent term '{$parent_item}' not found in taxonomy '{$taxonomy}'",
					'tool_name' => 'update_taxonomy_term',
				);
			}

			if ( $parent_term->term_id === $term->term_id ) {
				return array(
					'success'   => false,
					'error'     => 'A term cannot be its own parent',
					'tool_name' => 'update_taxonomy_term',
				);
			}

			$args['parent']   = $parent_term->term_id;
			$updated_fields[] = 'parent';
		}

		if ( empty( $args ) ) {
			return array(
				'success'        => true,
				'updated_fields' => array(),
			);
		}

		$result = wp_update_term( $term->term_id, $taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'update_taxonomy_term',
			);
		}

		return array(
			'success'        => true,
			'updated_fields' => $updated_fields,
		);
	}

	/**
	 * Update term meta fields.
	 *
	 * @param int   $term_id Term ID
	 * @param array $meta Key-value pairs to update
	 * @return array List of updated meta keys
	 */
	private function updateMetaFields( int $term_id, array $meta ): array {
		$updated = array();

		foreach ( $meta as $key => $value ) {
			// Skip null or empty string values (no change)
			if ( null === $value || '' === $value ) {
				continue;
			}

			$sanitized_key = sanitize_key( $key );

			// Update the meta value (WordPress handles serialization for arrays/objects)
			update_term_meta( $term_id, $sanitized_key, $value );
			$updated[] = $sanitized_key;
		}

		return $updated;
	}
}
