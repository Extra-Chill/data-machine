<?php

namespace DataMachine\Abilities\Analytics\Traits;

/**
 * Shared trait for the `get_config` method.
 *
 * Extracted by homeboy audit --fix from duplicate implementations.
 */
trait HasGetConfig {
	/**
	 * Get stored configuration.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		return get_site_option( self::CONFIG_OPTION, array() );
	}
}
