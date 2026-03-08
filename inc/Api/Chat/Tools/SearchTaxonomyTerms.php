<?php
/**
 * Search Taxonomy Terms Tool
 *
 * Provides on-demand taxonomy term lookup for chat agents.
 * Returns top terms by count with optional search filtering.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Core\WordPress\TaxonomyHandler;
use DataMachine\Engine\AI\Tools\BaseTool;

class SearchTaxonomyTerms extends BaseTool {

	public function __construct() {
		$this->registerTool( 'search_taxonomy_terms', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Search existing taxonomy terms. Use to discover what terms exist before creating new ones or when configuring handler term assignments.',
			'parameters'  => array(
				'taxonomy' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Taxonomy slug (category, post_tag, venue, artist, or other custom taxonomy)',
				),
				'search'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Search string to filter terms by name (partial match)',
				),
				'limit'    => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Maximum number of terms to return (default 20, max 100)',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$taxonomy = $parameters['taxonomy'] ?? null;
		$search   = $parameters['search'] ?? '';
		$limit    = $parameters['limit'] ?? 20;

		if ( empty( $taxonomy ) || ! is_string( $taxonomy ) ) {
			return array(
				'success'   => false,
				'error'     => 'taxonomy is required and must be a non-empty string',
				'tool_name' => 'search_taxonomy_terms',
			);
		}

		$taxonomy = sanitize_key( $taxonomy );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array(
				'success'   => false,
				'error'     => "Taxonomy '{$taxonomy}' does not exist",
				'tool_name' => 'search_taxonomy_terms',
			);
		}

		if ( TaxonomyHandler::shouldSkipTaxonomy( $taxonomy ) ) {
			return array(
				'success'   => false,
				'error'     => "Taxonomy '{$taxonomy}' is a system taxonomy and cannot be queried",
				'tool_name' => 'search_taxonomy_terms',
			);
		}

		$limit = max( 1, min( 100, (int) $limit ) );

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => $limit,
			'orderby'    => 'count',
			'order'      => 'DESC',
		);

		if ( ! empty( $search ) ) {
			$args['search'] = sanitize_text_field( $search );
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return array(
				'success'   => false,
				'error'     => $terms->get_error_message(),
				'tool_name' => 'search_taxonomy_terms',
			);
		}

		$taxonomy_obj = get_taxonomy( $taxonomy );
		$term_data    = array();

		foreach ( $terms as $term ) {
			$term_entry = array(
				'term_id' => $term->term_id,
				'name'    => $term->name,
				'slug'    => $term->slug,
				'count'   => (int) $term->count,
			);

			if ( $taxonomy_obj->hierarchical && $term->parent > 0 ) {
				$parent_term = get_term( $term->parent, $taxonomy );
				if ( $parent_term && ! is_wp_error( $parent_term ) ) {
					$term_entry['parent'] = $parent_term->name;
				}
			}

			$term_data[] = $term_entry;
		}

		$total_count = wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $total_count ) ) {
			$total_count = count( $term_data );
		}

		return array(
			'success'   => true,
			'data'      => array(
				'taxonomy'       => $taxonomy,
				'taxonomy_label' => $taxonomy_obj->label,
				'hierarchical'   => $taxonomy_obj->hierarchical,
				'total_terms'    => (int) $total_count,
				'returned_count' => count( $term_data ),
				'search_query'   => $search ? $search : null,
				'terms'          => $term_data,
			),
			'tool_name' => 'search_taxonomy_terms',
		);
	}
}
