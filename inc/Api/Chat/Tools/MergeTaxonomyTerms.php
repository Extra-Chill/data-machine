<?php
/**
 * Merge Taxonomy Terms Tool
 *
 * Merges two taxonomy terms into one by reassigning all posts from the source
 * term to the target term, optionally merging meta data, then deleting the source.
 *
 * @package DataMachine\Api\Chat\Tools
 */

namespace DataMachine\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Core\WordPress\TaxonomyHandler;
use DataMachine\Engine\AI\Tools\BaseTool;

class MergeTaxonomyTerms extends BaseTool {

	public function __construct() {
		$this->registerTool( 'merge_taxonomy_terms', array( $this, 'getToolDefinition' ), array( 'chat' ), array( 'access_level' => 'editor' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Merge two taxonomy terms into one. Reassigns all posts from source term to target term, optionally merges meta data, then deletes the source term. Useful for consolidating duplicates.',
			'parameters'  => array(
				'source_term' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Term to merge FROM (will be deleted) - ID, name, or slug',
				),
				'target_term' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Term to merge INTO (will be kept) - ID, name, or slug',
				),
				'taxonomy'    => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Taxonomy slug (venue, artist, category, post_tag, etc.)',
				),
				'merge_meta'  => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Fill empty target meta from source (default: true)',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$source_identifier = $parameters['source_term'] ?? null;
		$target_identifier = $parameters['target_term'] ?? null;
		$taxonomy          = $parameters['taxonomy'] ?? null;
		$merge_meta        = $parameters['merge_meta'] ?? true;

		// Validate taxonomy
		if ( empty( $taxonomy ) ) {
			return array(
				'success'   => false,
				'error'     => 'taxonomy parameter is required',
				'tool_name' => 'merge_taxonomy_terms',
			);
		}

		$taxonomy = sanitize_key( $taxonomy );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array(
				'success'   => false,
				'error'     => "Taxonomy '{$taxonomy}' does not exist",
				'tool_name' => 'merge_taxonomy_terms',
			);
		}

		if ( TaxonomyHandler::shouldSkipTaxonomy( $taxonomy ) ) {
			return array(
				'success'   => false,
				'error'     => "Taxonomy '{$taxonomy}' is a system taxonomy and cannot be modified",
				'tool_name' => 'merge_taxonomy_terms',
			);
		}

		// Validate term identifiers
		if ( empty( $source_identifier ) ) {
			return array(
				'success'   => false,
				'error'     => 'source_term parameter is required',
				'tool_name' => 'merge_taxonomy_terms',
			);
		}

		if ( empty( $target_identifier ) ) {
			return array(
				'success'   => false,
				'error'     => 'target_term parameter is required',
				'tool_name' => 'merge_taxonomy_terms',
			);
		}

		// Resolve terms
		$source_term = $this->resolveTerm( $source_identifier, $taxonomy );
		if ( ! $source_term ) {
			return array(
				'success'   => false,
				'error'     => "Source term '{$source_identifier}' not found in taxonomy '{$taxonomy}'",
				'tool_name' => 'merge_taxonomy_terms',
			);
		}

		$target_term = $this->resolveTerm( $target_identifier, $taxonomy );
		if ( ! $target_term ) {
			return array(
				'success'   => false,
				'error'     => "Target term '{$target_identifier}' not found in taxonomy '{$taxonomy}'",
				'tool_name' => 'merge_taxonomy_terms',
			);
		}

		// Validate terms are different
		if ( $source_term->term_id === $target_term->term_id ) {
			return array(
				'success'   => false,
				'error'     => 'Source and target terms must be different',
				'tool_name' => 'merge_taxonomy_terms',
			);
		}

		// Get all posts with source term
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'tax_query'      => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $source_term->term_id,
					),
				),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$posts_reassigned = 0;

		// Reassign posts from source to target
		foreach ( $posts as $post_id ) {
			wp_remove_object_terms( $post_id, $source_term->term_id, $taxonomy );
			$result = wp_set_object_terms( $post_id, $target_term->term_id, $taxonomy, true );
			if ( ! is_wp_error( $result ) ) {
				++$posts_reassigned;
			}
		}

		// Merge meta if requested
		$meta_merged = array();
		if ( $merge_meta ) {
			$meta_merged = $this->mergeTermMeta( $source_term->term_id, $target_term->term_id );
		}

		// Delete source term
		$delete_result  = wp_delete_term( $source_term->term_id, $taxonomy );
		$source_deleted = ! is_wp_error( $delete_result ) && false !== $delete_result;

		// Build message
		$message = "Merged '{$source_term->name}' into '{$target_term->name}'.";
		if ( $posts_reassigned > 0 ) {
			$message .= " Reassigned {$posts_reassigned} post" . ( 1 !== $posts_reassigned ? 's' : '' ) . '.';
		} else {
			$message .= ' No posts to reassign.';
		}
		if ( ! empty( $meta_merged ) ) {
			$message .= ' Merged meta: ' . implode( ', ', $meta_merged ) . '.';
		}
		if ( $source_deleted ) {
			$message .= ' Source term deleted.';
		}

		return array(
			'success'   => true,
			'data'      => array(
				'source_term_id'   => $source_term->term_id,
				'source_term_name' => $source_term->name,
				'target_term_id'   => $target_term->term_id,
				'target_term_name' => $target_term->name,
				'posts_reassigned' => $posts_reassigned,
				'meta_merged'      => $meta_merged,
				'source_deleted'   => $source_deleted,
				'message'          => $message,
			),
			'tool_name' => 'merge_taxonomy_terms',
		);
	}

	/**
	 * Resolve term by ID, name, or slug.
	 */
	private function resolveTerm( string $identifier, string $taxonomy ): ?\WP_Term {
		// Try as ID
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
	 * Merge meta from source term to target term.
	 * Only fills empty values in target - does not overwrite existing data.
	 *
	 * @return array List of meta keys that were merged
	 */
	private function mergeTermMeta( int $source_term_id, int $target_term_id ): array {
		$merged = array();

		$source_meta = get_term_meta( $source_term_id );
		if ( empty( $source_meta ) ) {
			return $merged;
		}

		foreach ( $source_meta as $meta_key => $meta_values ) {
			if ( empty( $meta_values ) ) {
				continue;
			}

			$target_value = get_term_meta( $target_term_id, $meta_key, true );

			// Only fill if target is empty
			if ( empty( $target_value ) ) {
				$source_value = $meta_values[0];
				update_term_meta( $target_term_id, $meta_key, $source_value );
				$merged[] = $meta_key;
			}
		}

		return $merged;
	}
}
