<?php
/**
 * Event search term matching.
 *
 * WordPress post search only looks at an event's title, excerpt and content.
 * Event identity also lives in taxonomy terms (venue, artist, location, ...),
 * so a search for "lofi" never finds events at the "Lo-Fi Brewing" venue
 * unless the post body happens to spell the name the same way.
 *
 * This resolves a search string to the term_taxonomy_ids of event taxonomy
 * terms whose names match it, ignoring case, accents, spacing and punctuation.
 * A term matches when the normalized search starts at a word boundary of the
 * normalized name ("lofi" matches "Lo-Fi Brewing" and "Hest Lofi", but not
 * "Pablo Fierro", where the letters only line up across a word break).
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

defined( 'ABSPATH' ) || exit;

class EventSearchTerms {

	/** Shortest normalized search that may match term names. */
	public const MIN_NEEDLE_LENGTH = 3;

	/** Candidate rows read from the term table per search. */
	public const MAX_CANDIDATES = 5000;

	/** Matched terms applied to one search, most-used first. */
	public const MAX_MATCHES = 200;

	private const CACHE_GROUP = 'data_machine_events_search_terms';

	/**
	 * Split text into lowercase ASCII alphanumeric words.
	 *
	 * @param string $text Raw text.
	 * @return string[] Words, in order.
	 */
	public static function words( string $text ): array {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}
		$text = strtolower( $text );

		$parts = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $parts ) ? $parts : array();
	}

	/**
	 * Collapse text to one normalized needle ("Lo-Fi" and "lo fi" become "lofi").
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function needle( string $text ): string {
		return implode( '', self::words( $text ) );
	}

	/**
	 * Whether the needle starts at a word boundary of the name.
	 *
	 * @param string $name   Term name.
	 * @param string $needle Normalized needle from needle().
	 * @return bool
	 */
	public static function nameMatches( string $name, string $needle ): bool {
		if ( '' === $needle ) {
			return false;
		}

		$words = self::words( $name );
		$count = count( $words );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( str_starts_with( implode( '', array_slice( $words, $i ) ), $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Portable LIKE prefilter: the needle's characters in order ("%l%o%f%i%").
	 *
	 * Accent- and case-insensitive collations make this a superset of
	 * nameMatches(); the exact rule is applied in PHP afterwards.
	 *
	 * @param string $needle Normalized needle.
	 * @return string
	 */
	public static function likePattern( string $needle ): string {
		return '%' . implode( '%', str_split( $needle ) ) . '%';
	}

	/**
	 * Taxonomies whose term names are searched.
	 *
	 * @return string[]
	 */
	public static function taxonomies(): array {
		$taxonomies = array_values(
			array_filter(
				get_object_taxonomies( Event_Post_Type::POST_TYPE, 'objects' ),
				static fn( $taxonomy ) => ! empty( $taxonomy->public )
			)
		);
		$names      = array_map( static fn( $taxonomy ) => $taxonomy->name, $taxonomies );

		/**
		 * Filter the event taxonomies whose term names match calendar searches.
		 *
		 * @param string[] $names Public taxonomies registered for events.
		 */
		$names = (array) apply_filters( 'data_machine_events_search_taxonomies', $names );

		return array_values( array_filter( array_map( 'sanitize_key', $names ), 'taxonomy_exists' ) );
	}

	/**
	 * Resolve a search string to matching term_taxonomy_ids.
	 *
	 * @param string $search Raw search string.
	 * @return int[] term_taxonomy_ids, most-used first.
	 */
	public static function matchingTermTaxonomyIds( string $search ): array {
		global $wpdb;

		$needle = self::needle( $search );
		if ( strlen( $needle ) < self::MIN_NEEDLE_LENGTH ) {
			return array();
		}

		$taxonomies = self::taxonomies();
		if ( empty( $taxonomies ) ) {
			return array();
		}

		$cache_key = md5( $needle . '|' . implode( ',', $taxonomies ) . '|' . wp_cache_get_last_changed( 'terms' ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Scan names first (no taxonomy join), then narrow in PHP: the join-first
		// plan walks every term_taxonomy row and is several times slower.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id, name FROM {$wpdb->terms} WHERE name LIKE %s LIMIT %d",
				self::likePattern( $needle ),
				self::MAX_CANDIDATES
			)
		);

		$term_ids = array();
		foreach ( (array) $rows as $row ) {
			if ( self::nameMatches( (string) $row->name, $needle ) ) {
				$term_ids[] = (int) $row->term_id;
			}
		}

		$ids = array();
		if ( ! empty( $term_ids ) ) {
			$term_placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
			$tax_placeholders  = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
						WHERE term_id IN ({$term_placeholders}) AND taxonomy IN ({$tax_placeholders}) AND count > 0
						ORDER BY count DESC LIMIT %d",
						array_merge( $term_ids, $taxonomies, array( self::MAX_MATCHES ) )
					)
				)
			);
			// phpcs:enable
		}

		wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, 10 * MINUTE_IN_SECONDS );

		return $ids;
	}

	/**
	 * Widen WordPress search SQL so term-matched events also qualify.
	 *
	 * @param string $search_sql The WHERE fragment produced by WP_Query::parse_search().
	 * @param int[]  $tt_ids     Matching term_taxonomy_ids.
	 * @param string $posts      Posts table name.
	 * @param string $relations  Term relationships table name.
	 * @return string
	 */
	public static function widenSearchSql( string $search_sql, array $tt_ids, string $posts, string $relations ): string {
		$tt_ids = array_values( array_unique( array_filter( array_map( 'intval', $tt_ids ) ) ) );
		if ( empty( $tt_ids ) ) {
			return $search_sql;
		}

		$password = '';
		if ( preg_match( '/\s+AND\s+\(' . preg_quote( $posts, '/' ) . "\.post_password = ''\)\s*$/", $search_sql, $m ) ) {
			$password   = $m[0];
			$search_sql = substr( $search_sql, 0, -strlen( $m[0] ) );
		}

		$text = preg_replace( '/^\s*AND\s+/', '', $search_sql, 1 );
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $search_sql . $password;
		}

		$in = implode( ',', $tt_ids );

		return " AND ( {$text} OR {$posts}.ID IN ( SELECT object_id FROM {$relations} WHERE term_taxonomy_id IN ({$in}) ) ) {$password}";
	}
}
