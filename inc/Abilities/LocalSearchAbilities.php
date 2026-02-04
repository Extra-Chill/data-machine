<?php
/**
 * Local Search Abilities
 *
 * WordPress site search ability for AI agents.
 * Provides standardized search with automatic fallbacks for improved accuracy.
 *
 * @package DataMachine\Abilities
 * @since 0.12.0
 */

namespace DataMachine\Abilities;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class LocalSearchAbilities {

	private const MAX_RESULTS     = 10;
	private const MAX_SPLIT_TERMS = 5;

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbility();
		self::$registered = true;
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/local-search',
				array(
					'label'               => __( 'Local Search', 'data-machine' ),
					'description'         => __( 'Search WordPress site for posts by title or content', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'query' ),
						'properties' => array(
							'query'      => array(
								'type'        => 'string',
								'description' => __( 'Search terms to find relevant posts', 'data-machine' ),
							),
							'post_types' => array(
								'type'        => 'array',
								'default'     => array( 'post', 'page' ),
								'description' => __( 'Post types to search', 'data-machine' ),
							),
							'title_only' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Search only post titles', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'message'             => array( 'type' => 'string' ),
							'query'               => array( 'type' => 'string' ),
							'results_count'       => array( 'type' => 'integer' ),
							'post_types_searched' => array( 'type' => 'array' ),
							'search_method'       => array( 'type' => 'string' ),
							'results'             => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( $this, 'executeLocalSearch' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
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

	public function executeLocalSearch( array $input ): array {
		$raw_query = $input['query'] ?? '';

		if ( empty( $raw_query ) ) {
			return array(
				'message'             => 'Local Search requires a query parameter.',
				'query'               => '',
				'results_count'       => 0,
				'post_types_searched' => array(),
				'search_method'       => 'none',
				'results'             => array(),
				'error'               => 'Missing required parameter: query',
			);
		}

		$raw_query  = sanitize_text_field( $raw_query );
		$query      = $this->normalizeQuery( $raw_query );
		$post_types = $this->normalizePostTypes( $input['post_types'] ?? array( 'post', 'page' ) );
		$title_only = ! empty( $input['title_only'] );

		if ( $title_only ) {
			$results = $this->searchByTitle( $query, $post_types, self::MAX_RESULTS );
			return $this->buildResult( $results, $raw_query, $post_types, 'title_only' );
		}

		$results = $this->standardSearch( $query, $post_types, self::MAX_RESULTS );
		if ( ! empty( $results ) ) {
			return $this->buildResult( $results, $raw_query, $post_types, 'standard' );
		}

		$results = $this->searchByTitle( $query, $post_types, self::MAX_RESULTS );
		if ( ! empty( $results ) ) {
			return $this->buildResult( $results, $raw_query, $post_types, 'title_fallback' );
		}

		if ( $this->hasMultipleTerms( $raw_query ) ) {
			$results = $this->splitAndSearch( $raw_query, $post_types, self::MAX_RESULTS );
			if ( ! empty( $results ) ) {
				return $this->buildResult( $results, $raw_query, $post_types, 'split_query' );
			}
		}

		return $this->buildResult( array(), $raw_query, $post_types, 'none' );
	}

	private function normalizeQuery( string $query ): string {
		$query = str_replace( array( '&amp;', '&#038;', '&#38;' ), '&', $query );
		$query = preg_replace( '/\s+/', ' ', trim( $query ) );

		return $query;
	}

	private function normalizePostTypes( $post_types ): array {
		if ( ! is_array( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}
		return array_map( 'sanitize_text_field', $post_types );
	}

	private function hasMultipleTerms( string $query ): bool {
		return str_contains( $query, ',' ) || str_contains( $query, ';' );
	}

	private function standardSearch( string $query, array $post_types, int $limit ): array {
		$query_args = array(
			's'                      => $query,
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'orderby'                => 'relevance',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$wp_query = new \WP_Query( $query_args );

		if ( is_wp_error( $wp_query ) || ! $wp_query->have_posts() ) {
			return array();
		}

		return $this->extractResults( $wp_query );
	}

	private function searchByTitle( string $query, array $post_types, int $limit ): array {
		global $wpdb;

		$post_type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$like_query             = '%' . $wpdb->esc_like( $query ) . '%';
		$prepare_args           = array_merge( $post_types, array( $like_query, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are dynamically generated for IN clause.
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
             WHERE post_type IN ({$post_type_placeholders})
             AND post_status = 'publish'
             AND post_title LIKE %s
             ORDER BY post_date DESC
             LIMIT %d",
			$prepare_args
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above; caching handled at ability level.
		$post_ids = $wpdb->get_col( $sql );

		if ( empty( $post_ids ) ) {
			return array();
		}

		$wp_query = new \WP_Query(
			array(
				'post__in'               => $post_ids,
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'orderby'                => 'post__in',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return $this->extractResults( $wp_query );
	}

	private function splitAndSearch( string $query, array $post_types, int $total_limit ): array {
		$terms = preg_split( '/[,;]+/', $query );
		$terms = array_map( 'trim', $terms );
		$terms = array_filter( $terms, fn( $t ) => ! empty( $t ) );
		$terms = array_slice( $terms, 0, self::MAX_SPLIT_TERMS );

		if ( empty( $terms ) ) {
			return array();
		}

		$all_results    = array();
		$seen_ids       = array();
		$per_term_limit = max( 3, intval( $total_limit / count( $terms ) ) );

		foreach ( $terms as $term ) {
			$normalized_term = $this->normalizeQuery( $term );

			$term_results = $this->searchByTitle( $normalized_term, $post_types, $per_term_limit );

			if ( empty( $term_results ) ) {
				$term_results = $this->standardSearch( $normalized_term, $post_types, $per_term_limit );
			}

			foreach ( $term_results as $result ) {
				$post_id = $this->extractPostIdFromLink( $result['link'] );
				if ( $post_id && ! isset( $seen_ids[ $post_id ] ) ) {
					$seen_ids[ $post_id ] = true;
					$all_results[]        = $result;
				}
			}

			if ( count( $all_results ) >= $total_limit ) {
				break;
			}
		}

		return array_slice( $all_results, 0, $total_limit );
	}

	private function extractPostIdFromLink( string $link ): ?int {
		$post_id = url_to_postid( $link );
		return $post_id > 0 ? $post_id : null;
	}

	private function extractResults( \WP_Query $wp_query ): array {
		$results = array();

		while ( $wp_query->have_posts() ) {
			$wp_query->the_post();
			$post = get_post();

			$excerpt = get_the_excerpt( $post->ID );
			if ( empty( $excerpt ) ) {
				$content = wp_strip_all_tags( get_the_content( '', false, $post ) );
				$excerpt = wp_trim_words( $content, 25, '...' );
			}

			$results[] = array(
				'post_id'      => $post->ID,
				'title'        => get_the_title( $post->ID ),
				'link'         => get_permalink( $post->ID ),
				'excerpt'      => $excerpt,
				'post_type'    => get_post_type( $post->ID ),
				'publish_date' => get_the_date( 'Y-m-d H:i:s', $post->ID ),
				'author'       => get_the_author_meta( 'display_name', (int) $post->post_author ),
			);
		}

		wp_reset_postdata();

		return $results;
	}

	private function buildResult( array $results, string $query, array $post_types, string $search_method ): array {
		$results_count = count( $results );

		if ( $results_count > 0 ) {
			$message = "SEARCH COMPLETE: Found {$results_count} WordPress posts matching \"{$query}\".";
		} else {
			$message = "SEARCH COMPLETE: No WordPress posts/pages found matching \"{$query}\".";
		}

		return array(
			'message'             => $message,
			'query'               => $query,
			'results_count'       => $results_count,
			'post_types_searched' => $post_types,
			'search_method'       => $search_method,
			'results'             => $results,
		);
	}
}
