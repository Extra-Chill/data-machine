<?php
/**
 * Ability Categories
 *
 * Centralized registration of Data Machine Events ability categories.
 * Follows the same pattern as Data Machine core's AbilityCategories.
 *
 * @package DataMachineEvents\Abilities
 * @since 0.29.0
 */

namespace DataMachineEvents\Abilities;

defined( 'ABSPATH' ) || exit;

class AbilityCategories {

	/**
	 * Category slug constants for use in ability registrations.
	 */
	public const EVENTS   = 'datamachine-events/events';
	public const VENUES   = 'datamachine-events/venues';
	public const TESTING  = 'datamachine-events/testing';
	public const SETTINGS = 'datamachine-events/settings';

	private static bool $registered = false;

	/**
	 * Register all Data Machine Events ability categories.
	 *
	 * Safe to call multiple times — uses a static guard.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		$categories = array(
			self::EVENTS   => array(
				'label'       => __( 'Events', 'data-machine-events' ),
				'description' => __( 'Event CRUD, queries, health checks, quality audits, updates, and date operations.', 'data-machine-events' ),
			),
			self::VENUES   => array(
				'label'       => __( 'Venues', 'data-machine-events' ),
				'description' => __( 'Venue CRUD, health checks, geocoding, map queries, and duplicate detection.', 'data-machine-events' ),
			),
			self::TESTING  => array(
				'label'       => __( 'Testing', 'data-machine-events' ),
				'description' => __( 'Handler testing and scraper compatibility checks.', 'data-machine-events' ),
			),
			self::SETTINGS => array(
				'label'       => __( 'Settings', 'data-machine-events' ),
				'description' => __( 'Plugin settings read and write.', 'data-machine-events' ),
			),
		);

		foreach ( $categories as $slug => $args ) {
			wp_register_ability_category( $slug, $args );
		}

		self::$registered = true;
	}

	/**
	 * Ensure categories are registered.
	 *
	 * Handles timing: if the categories_init hook already fired, registers
	 * immediately. Otherwise hooks into it.
	 */
	public static function ensure_registered(): void {
		if ( self::$registered ) {
			return;
		}

		if ( did_action( 'wp_abilities_api_categories_init' ) ) {
			self::register();
		} else {
			add_action( 'wp_abilities_api_categories_init', array( self::class, 'register' ) );
		}
	}
}
