<?php

namespace DataMachine\Api\Traits;

/**
 * Shared trait for the `register` method.
 *
 * Extracted by homeboy audit --fix from duplicate implementations.
 */
trait HasRegister {
	/**
	 * Register REST API routes
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}
}
