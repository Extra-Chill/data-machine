<?php
/**
 * Cached WordPress site metadata for AI context injection.
 */

namespace DataMachine\Core\WordPress;

defined( 'ABSPATH' ) || exit;

class SiteContext {

	const CACHE_KEY = 'datamachine_site_context_data';

	/**
	 * Get site context data with automatic caching.
	 *
	 * Plugins can extend the context data via the 'datamachine_site_context' filter.
	 * Note: Filtering bypasses cache to ensure dynamic data is always fresh.
	 *
	 * @return array Site metadata, post types, and taxonomies
	 */
	public static function get_context(): array {
		$cached = get_transient( self::CACHE_KEY );

		// Clear stale cache if date has changed (ensures current_date is always accurate).
		if ( false !== $cached && isset( $cached['site']['current_date'] ) ) {
			if ( wp_date( 'Y-m-d' ) !== $cached['site']['current_date'] ) {
				delete_transient( self::CACHE_KEY );
				$cached = false;
			}
		}

		if ( false !== $cached ) {
			return $cached;
		}

		$context = array(
			'site'       => self::get_site_metadata(),
			'post_types' => self::get_post_types_data(),
			'taxonomies' => self::get_taxonomies_data(),
		);

		/**
		 * Filter site context data before caching.
		 *
		 * Plugins can use this hook to inject custom context data (e.g., events,
		 * analytics, custom post type summaries). Note: When this filter is used,
		 * caching is bypassed to ensure dynamic data remains fresh.
		 *
		 * @param array $context Site context data with 'site', 'post_types', 'taxonomies' keys
		 * @return array Modified context data
		 */
		$context = apply_filters( 'datamachine_site_context', $context );

		set_transient( self::CACHE_KEY, $context, 0 ); // 0 = permanent until invalidated

		return $context;
	}

	/**
	 * Get site metadata.
	 *
	 * @return array Site name, URL, language, timezone, current_date
	 */
	private static function get_site_metadata(): array {
		return array(
			'name'         => get_bloginfo( 'name' ),
			'tagline'      => get_bloginfo( 'description' ),
			'url'          => home_url(),
			'admin_url'    => admin_url(),
			'language'     => get_locale(),
			'timezone'     => wp_timezone_string(),
			'current_date' => wp_date( 'Y-m-d' ),
		);
	}

	/**
	 * Get public post types with published counts.
	 *
	 * @return array Post type labels, counts, and hierarchy status
	 */
	private static function get_post_types_data(): array {
		$post_types_data = array();
		$post_types      = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $post_types as $post_type ) {
			$count           = wp_count_posts( $post_type->name );
			$published_count = $count->publish ?? 0;

			$post_types_data[ $post_type->name ] = array(
				'label'          => $post_type->label,
				'singular_label' => ( is_object( $post_type->labels ) && isset( $post_type->labels->singular_name ) )
					? $post_type->labels->singular_name
					: $post_type->label,
				'count'          => (int) $published_count,
				'hierarchical'   => $post_type->hierarchical,
			);
		}

		return $post_types_data;
	}

	/**
	 * Get public taxonomies with metadata and term counts.
	 *
	 * Returns taxonomy structure without individual term listings to keep
	 * context payload small. Use search_taxonomy_terms tool for term discovery.
	 *
	 * @return array Taxonomy labels, term counts, hierarchy, post type associations
	 */
	private static function get_taxonomies_data(): array {
		$taxonomies_data = array();
		$taxonomies      = get_taxonomies( array( 'public' => true ), 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( \DataMachine\Core\WordPress\TaxonomyHandler::shouldSkipTaxonomy( $taxonomy->name ) ) {
				continue;
			}

			$term_count = wp_count_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $term_count ) ) {
				$term_count = 0;
			}

			$taxonomies_data[ $taxonomy->name ] = array(
				'label'          => $taxonomy->label,
				'singular_label' => ( is_object( $taxonomy->labels ) && isset( $taxonomy->labels->singular_name ) )
					? $taxonomy->labels->singular_name
					: $taxonomy->label,
				'term_count'     => (int) $term_count,
				'hierarchical'   => $taxonomy->hierarchical,
				'post_types'     => $taxonomy->object_type ?? array(),
			);
		}

		return $taxonomies_data;
	}

	/**
	 * Clear site context cache.
	 */
	public static function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Register automatic cache invalidation hooks.
	 *
	 * Clears cache when posts, terms, or site options change.
	 * Comprehensive invalidation hooks eliminate need for time-based expiration.
	 */
	public static function register_cache_invalidation(): void {
		add_action( 'save_post', array( __CLASS__, 'clear_cache' ) );
		add_action( 'delete_post', array( __CLASS__, 'clear_cache' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'clear_cache' ) );
		add_action( 'untrash_post', array( __CLASS__, 'clear_cache' ) );

		add_action( 'create_term', array( __CLASS__, 'clear_cache' ) );
		add_action( 'edit_term', array( __CLASS__, 'clear_cache' ) );
		add_action( 'delete_term', array( __CLASS__, 'clear_cache' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'clear_cache' ) );

		add_action( 'user_register', array( __CLASS__, 'clear_cache' ) );
		add_action( 'delete_user', array( __CLASS__, 'clear_cache' ) );
		add_action( 'set_user_role', array( __CLASS__, 'clear_cache' ) );

		add_action( 'switch_theme', array( __CLASS__, 'clear_cache' ) );

		add_action( 'update_option_blogname', array( __CLASS__, 'clear_cache' ) );
		add_action( 'update_option_blogdescription', array( __CLASS__, 'clear_cache' ) );
		add_action( 'update_option_home', array( __CLASS__, 'clear_cache' ) );
		add_action( 'update_option_siteurl', array( __CLASS__, 'clear_cache' ) );
	}
}
