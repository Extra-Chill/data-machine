<?php
/**
 * Publish-level duplicate detection for WordPress posts.
 *
 * Thin wrapper around the unified SimilarityEngine. All similarity math
 * is delegated to DataMachine\Core\Similarity\SimilarityEngine.
 *
 * Retained for backward compatibility — existing call sites
 * (WordPress publish handler, tests) continue to work unchanged.
 * New code should use DuplicateCheckAbility or SimilarityEngine directly.
 *
 * @package    DataMachine\Core\WordPress
 * @since      0.38.0
 * @deprecated 0.39.0 Use DuplicateCheckAbility or SimilarityEngine directly.
 */

namespace DataMachine\Core\WordPress;

use DataMachine\Core\Similarity\SimilarityEngine;

defined( 'ABSPATH' ) || exit;

class DuplicateDetection {

	/**
	 * Default number of days to look back for duplicate candidates.
	 */
	const DEFAULT_LOOKBACK_DAYS = 14;

	/**
	 * Find an existing published post with a matching title.
	 *
	 * @param string $title         Title of the post being published.
	 * @param string $post_type     WordPress post type to search.
	 * @param int    $lookback_days How many days back to search (default 14).
	 * @return int|null Post ID of the duplicate, or null if none found.
	 */
	public static function findExistingPostByTitle( string $title, string $post_type, int $lookback_days = 0 ): ?int {
		if ( empty( $title ) || empty( $post_type ) ) {
			return null;
		}

		if ( $lookback_days <= 0 ) {
			$lookback_days = self::DEFAULT_LOOKBACK_DAYS;
		}

		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$lookback_days} days" ) );

		$candidates = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- intentional batch query
				'posts_per_page' => 200,
				'date_query'     => array(
					array(
						'after'     => $cutoff_date,
						'inclusive' => true,
					),
				),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $candidates ) ) {
			return null;
		}

		foreach ( $candidates as $candidate_id ) {
			$candidate_title = get_the_title( $candidate_id );
			if ( self::titlesMatch( $title, $candidate_title ) ) {
				do_action(
					'datamachine_log',
					'info',
					'DuplicateDetection: Found existing post with matching title',
					array(
						'incoming_title' => $title,
						'incoming_core'  => self::extractCoreTitle( $title ),
						'existing_id'    => $candidate_id,
						'existing_title' => $candidate_title,
						'existing_core'  => self::extractCoreTitle( $candidate_title ),
					)
				);
				return $candidate_id;
			}
		}

		return null;
	}

	/**
	 * Compare two titles for semantic match.
	 *
	 * Delegates to SimilarityEngine::titlesMatch().
	 *
	 * @param string $title1 First title.
	 * @param string $title2 Second title.
	 * @return bool True if titles represent the same story.
	 */
	public static function titlesMatch( string $title1, string $title2 ): bool {
		return SimilarityEngine::titlesMatch( $title1, $title2 )->match;
	}

	/**
	 * Extract the core identifying portion of a title.
	 *
	 * Delegates to SimilarityEngine::normalizeTitle().
	 *
	 * @param string $title Post title.
	 * @return string Normalized core title for comparison.
	 */
	public static function extractCoreTitle( string $title ): string {
		return SimilarityEngine::normalizeTitle( $title );
	}

	/**
	 * Normalize unicode dash characters to ASCII hyphen.
	 *
	 * Delegates to SimilarityEngine::normalizeDashes().
	 *
	 * @param string $text Input text.
	 * @return string Text with all dashes normalized.
	 */
	public static function normalizeDashes( string $text ): string {
		return SimilarityEngine::normalizeDashes( $text );
	}
}
