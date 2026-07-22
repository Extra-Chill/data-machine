<?php
/**
 * Calendar Query Cache Invalidator
 *
 * Automatically invalidates calendar transient caches when events or related
 * taxonomy terms are created, updated, or deleted.
 *
 * @package DataMachineEvents\Blocks\Calendar\Cache
 * @since 0.10.20
 */

namespace DataMachineEvents\Blocks\Calendar\Cache;

use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CacheInvalidator {

	private static bool $initialized = false;

	/** @var array<string,array<array<int>>> LIFO removal frames scoped by object and taxonomy. */
	private static array $pending_removed_terms = array();

	/** @var array<string,array<int>> Relationships actually inserted by the current set operation. */
	private static array $added_terms = array();

	/**
	 * Initialize cache invalidation hooks
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'save_post_' . Event_Post_Type::POST_TYPE, array( __CLASS__, 'invalidate_all' ), 10, 0 );
		add_action( 'delete_post', array( __CLASS__, 'on_delete_post' ), 10, 1 );
		add_action( 'trashed_post', array( __CLASS__, 'on_delete_post' ), 10, 1 );
		add_action( 'untrashed_post', array( __CLASS__, 'on_delete_post' ), 10, 1 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_event_terms_set' ), 10, 6 );
		add_action( 'added_term_relationship', array( __CLASS__, 'on_event_term_added' ), 10, 3 );
		add_action( 'delete_term_relationships', array( __CLASS__, 'on_event_terms_removing' ), 10, 3 );
		add_action( 'deleted_term_relationships', array( __CLASS__, 'on_event_terms_removed' ), 10, 3 );

		add_action( 'edited_venue', array( __CLASS__, 'invalidate_all' ), 10, 0 );
		add_action( 'created_venue', array( __CLASS__, 'invalidate_all' ), 10, 0 );
		add_action( 'delete_venue', array( __CLASS__, 'invalidate_all' ), 10, 0 );

		add_action( 'edited_promoter', array( __CLASS__, 'invalidate_all' ), 10, 0 );
		add_action( 'created_promoter', array( __CLASS__, 'invalidate_all' ), 10, 0 );
		add_action( 'delete_promoter', array( __CLASS__, 'invalidate_all' ), 10, 0 );

		add_action( 'edited_genre', array( __CLASS__, 'invalidate_all' ), 10, 0 );
		add_action( 'created_genre', array( __CLASS__, 'invalidate_all' ), 10, 0 );
		add_action( 'delete_genre', array( __CLASS__, 'invalidate_all' ), 10, 0 );
	}

	/**
	 * Handle post deletion - only invalidate for event post type
	 *
	 * @param int $post_id Post ID being deleted
	 */
	public static function on_delete_post( int $post_id ): void {
		$post_type = get_post_type( $post_id );
		if ( Event_Post_Type::POST_TYPE === $post_type ) {
			self::invalidate_all();
		}
	}

	/**
	 * Invalidate caches when an event's final taxonomy relationships changed.
	 *
	 * Replacements call wp_remove_object_terms() internally before this hook.
	 * When that removal already invalidated caches, suppress the duplicate flush.
	 *
	 * @param int          $post_id   Post ID whose terms were set.
	 * @param array|string $terms     Requested terms.
	 * @param array        $tt_ids    Requested term-taxonomy IDs.
	 * @param string       $taxonomy  Taxonomy slug.
	 * @param bool         $append    Whether terms were appended.
	 * @param array        $old_tt_ids Previous term-taxonomy IDs.
	 */
	public static function on_event_terms_set( $post_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( Event_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$key = self::relationship_key( (int) $post_id, (string) $taxonomy );
		if ( $append ) {
			$added_ids = self::$added_terms[ $key ] ?? array();
			unset( self::$added_terms[ $key ] );
			if ( empty( $added_ids ) ) {
				return;
			}

			self::invalidate_all();
			return;
		}

		unset( self::$added_terms[ $key ] );
		$old_tt_ids = self::normalize_term_ids( $old_tt_ids );
		$new_tt_ids = self::normalize_term_ids( $tt_ids );

		if ( $old_tt_ids === $new_tt_ids ) {
			return;
		}
		if ( array_diff( $old_tt_ids, $new_tt_ids ) ) {
			// The relationship deletion hook already invalidated this replacement.
			return;
		}

		self::invalidate_all();
	}

	/**
	 * Record relationships WordPress actually inserted during a set operation.
	 *
	 * Identical appends do not fire this hook, which makes it the authoritative
	 * change signal when set_object_terms provides no old IDs in append mode.
	 *
	 * @param int    $post_id  Post ID.
	 * @param int    $tt_id    Inserted term-taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function on_event_term_added( $post_id, $tt_id, $taxonomy ): void {
		if ( Event_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$key                         = self::relationship_key( (int) $post_id, (string) $taxonomy );
		self::$added_terms[ $key ]   = self::$added_terms[ $key ] ?? array();
		self::$added_terms[ $key ][] = (int) $tt_id;
		self::$added_terms[ $key ]   = self::normalize_term_ids( self::$added_terms[ $key ] );
	}

	/**
	 * Capture only relationships that exist before WordPress removes them.
	 *
	 * @param int    $post_id  Post ID.
	 * @param array  $tt_ids   Requested term-taxonomy IDs.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function on_event_terms_removing( $post_id, $tt_ids, $taxonomy ): void {
		if ( Event_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$current_ids = wp_get_object_terms(
			$post_id,
			$taxonomy,
			array(
				'fields'                 => 'tt_ids',
				'update_term_meta_cache' => false,
			)
		);
		$key         = self::relationship_key( (int) $post_id, (string) $taxonomy );

		$removed_ids = is_wp_error( $current_ids )
			? array()
			: self::normalize_term_ids( array_intersect( self::normalize_term_ids( $current_ids ), self::normalize_term_ids( $tt_ids ) ) );

		self::$pending_removed_terms[ $key ]   = self::$pending_removed_terms[ $key ] ?? array();
		self::$pending_removed_terms[ $key ][] = $removed_ids;
	}

	/**
	 * Invalidate once after a real standalone or replacement removal.
	 *
	 * @param int    $post_id  Post ID.
	 * @param array  $tt_ids   Removed term-taxonomy IDs.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function on_event_terms_removed( $post_id, $tt_ids, $taxonomy ): void {
		$key         = self::relationship_key( (int) $post_id, (string) $taxonomy );
		$stack       = self::$pending_removed_terms[ $key ] ?? array();
		$removed_ids = array_pop( $stack ) ?? array();

		if ( empty( $stack ) ) {
			unset( self::$pending_removed_terms[ $key ] );
		} else {
			self::$pending_removed_terms[ $key ] = $stack;
		}

		if ( Event_Post_Type::POST_TYPE === get_post_type( $post_id ) && ! empty( $removed_ids ) ) {
			self::invalidate_all();
		}
	}

	/**
	 * Build a request-local key for one post/taxonomy relationship operation.
	 */
	private static function relationship_key( int $post_id, string $taxonomy ): string {
		return $post_id . ':' . $taxonomy;
	}

	/**
	 * Normalize term-taxonomy IDs for order-independent comparisons.
	 *
	 * @param array $term_ids Term-taxonomy IDs.
	 * @return array<int>
	 */
	private static function normalize_term_ids( array $term_ids ): array {
		$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
		sort( $term_ids, SORT_NUMERIC );
		return $term_ids;
	}

	/**
	 * Invalidate all calendar caches
	 *
	 * Uses database query to find and delete all calendar transients.
	 */
	public static function invalidate_all(): void {
		global $wpdb;

		// Find calendar-specific transient keys before deleting from DB.
		$transient_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT REPLACE(option_name, '_transient_', '') FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_' . CalendarCache::PREFIX . '%'
			)
		);

		// Delete from DB (for non-persistent cache environments).
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . CalendarCache::PREFIX . '%',
				'_transient_timeout_' . CalendarCache::PREFIX . '%'
			)
		);

		// Delete specific keys from object cache instead of flushing the entire
		// transient group. Flushing the group killed ALL transients site-wide on
		// every event save, preventing any transient from surviving pipeline activity.
		foreach ( $transient_keys as $key ) {
			wp_cache_delete( $key, 'transient' );
			wp_cache_delete( $key, 'site-transient' );
		}

		// Flush the dedicated full-response cache group. This is safe to
		// flush wholesale because the group is private to the calendar —
		// nothing else writes to `data-machine-calendar`.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( CalendarCache::GROUP );
		} else {
			// On WP < 6.1 / object-cache drop-ins lacking flush_group support,
			// the transient layer above still serves as the source of truth.
			// The wp_cache entries will age out within TTL_FULL_PAST (24h).
			// Acceptable downside for a fallback path that won't hit on
			// extrachill.com (Redis Object Cache supports flush_group).
			$noop = true;
			unset( $noop );
		}
	}
}

CacheInvalidator::init();
