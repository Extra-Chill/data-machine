<?php
/**
 * Create Taxonomy Term Tool
 *
 * Creates taxonomy terms on-demand during flow configuration.
 * Handles categories, tags, and custom taxonomies.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\BaseTool;

class CreateTaxonomyTerm extends BaseTool {

	public function __construct() {
		$this->registerTool( 'create_taxonomy_term', array( $this, 'getToolDefinition' ), array( 'chat' ), array( 'access_level' => 'editor' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Create a taxonomy term if it does not exist. Use when configuring flows that need categories, tags, or custom taxonomy terms that are not yet on the site.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy'    => array(
						'type'        => 'string',
						'description' => 'Taxonomy slug (category, post_tag, or custom taxonomy slug)',
					),
					'name'        => array(
						'type'        => 'string',
						'description' => 'Term name to create',
					),
					'parent'      => array(
						'type'        => 'string',
						'description' => 'Parent term name, slug, or ID (hierarchical taxonomies only)',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'Term description',
					),
				),
				'required'   => array( 'taxonomy', 'name' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$taxonomy    = $parameters['taxonomy'] ?? null;
		$name        = $parameters['name'] ?? null;
		$parent      = $parameters['parent'] ?? null;
		$description = $parameters['description'] ?? '';

		// Parent names and slugs are a chat convenience; the ability accepts an ID.
		$parent_id = 0;
		if ( ! empty( $parent ) && is_string( $taxonomy ) ) {
			$taxonomy_obj = get_taxonomy( $taxonomy );
			if ( $taxonomy_obj && ! $taxonomy_obj->hierarchical ) {
				return array(
					'success'   => false,
					'error'     => "Cannot set parent: taxonomy '{$taxonomy}' is not hierarchical",
					'tool_name' => 'create_taxonomy_term',
				);
			}

			if ( $taxonomy_obj ) {
				$parent_id = $this->resolveParentTerm( $parent, $taxonomy );
				if ( false === $parent_id ) {
					return array(
						'success'   => false,
						'error'     => "Parent term '{$parent}' not found in taxonomy '{$taxonomy}'",
						'tool_name' => 'create_taxonomy_term',
					);
				}
			}
		}

		$ability = wp_get_ability( 'datamachine/create-taxonomy-term' );
		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'datamachine/create-taxonomy-term ability not found',
				'tool_name' => 'create_taxonomy_term',
			);
		}

		$input = array(
			'taxonomy' => $taxonomy,
			'name'     => $name,
			'parent'   => $parent_id,
		);

		if ( ! empty( $description ) ) {
			$input['description'] = $description;
		}

		$result = $ability->execute( $input );

		if ( ! $this->isAbilitySuccess( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $this->getAbilityError( $result, 'Failed to create taxonomy term' ),
				'tool_name' => 'create_taxonomy_term',
			);
		}

		$term           = get_term( $result['term_id'], $result['taxonomy'] ?? $taxonomy );
		$already_exists = ! empty( $result['existed'] );
		$term_name      = $term && ! is_wp_error( $term ) ? $term->name : $result['term_name'];
		$term_slug      = $term && ! is_wp_error( $term ) ? $term->slug : $result['term_slug'];
		$term_parent    = $term && ! is_wp_error( $term ) ? $term->parent : $parent_id;
		$message        = $already_exists
			? "Term '{$term_name}' already exists in taxonomy '{$taxonomy}'."
			: "Created term '{$term_name}' in taxonomy '{$taxonomy}'.";

		$data = array(
			'term_id'   => $result['term_id'],
			'taxonomy'  => $taxonomy,
			'name'      => $term_name,
			'slug'      => $term_slug,
			'parent_id' => $term_parent,
			'message'   => $message,
		);

		if ( $already_exists ) {
			$data['already_exists'] = true;
		}

		if ( $term && ! is_wp_error( $term ) ) {
			$data['term_taxonomy_id'] = $term->term_taxonomy_id;
		}

		return array(
			'success'   => true,
			'data'      => $data,
			'tool_name' => 'create_taxonomy_term',
		);
	}

	/**
	 * Resolve parent term by ID, name, or slug.
	 *
	 * @param string|int $parent Parent identifier
	 * @param string     $taxonomy Taxonomy slug
	 * @return int|false Term ID or false if not found
	 */
	private function resolveParentTerm( $parent_item, string $taxonomy ) {
		if ( is_numeric( $parent_item ) ) {
			$term = get_term( absint( $parent_item ), $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->term_id;
			}
		}

		$term = get_term_by( 'name', (string) $parent_item, $taxonomy );
		if ( ! $term ) {
			$term = get_term_by( 'slug', sanitize_title( (string) $parent_item ), $taxonomy );
		}

		return $term ? $term->term_id : false;
	}
}
