<?php
/**
 * Calendar Query Cache Invalidator
 *
 * Automatically invalidates calendar transient caches when events or related
 * taxonomy terms are created, updated, or deleted.
 *
 * @package DataMachineEvents\Blocks\Calendar
 * @since 0.10.20
 */

namespace DataMachineEvents\Blocks\Calendar;

use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cache_Invalidator {

	private static bool $initialized = false;

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
	 * Invalidate all calendar caches
	 *
	 * Uses database query to find and delete all calendar transients.
	 */
	public static function invalidate_all(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . CACHE_PREFIX . '%',
				'_transient_timeout_' . CACHE_PREFIX . '%'
			)
		);

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'transient' );
		}
	}
}

Cache_Invalidator::init();
