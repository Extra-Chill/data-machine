<?php
/**
 * Handler for the datamachine_log action (write operations only).
 *
 * @package DataMachine\Engine\Actions\Handlers
 * @since   0.11.0
 */

namespace DataMachine\Engine\Actions\Handlers;

/**
 * Central log-write handler — delegates to database-backed logging.
 *
 * Write operations only (info, error, warning, debug, etc.).
 * Management operations (clear, metadata) use LogAbilities directly.
 */
class LogHandler {

	/**
	 * Handle a log write operation.
	 *
	 * @param string $level   Log level (info, error, warning, debug, etc.).
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 * @return bool
	 */
	public static function handle( $level, $message = null, $context = null ) {
		$context      = $context ?? array();
		$valid_levels = datamachine_get_valid_log_levels();

		if ( ! in_array( $level, $valid_levels, true ) ) {
			if ( class_exists( 'WP_Ability' ) ) {
				$ability = wp_get_ability( 'datamachine/write-to-log' );
				if ( ! $ability ) {
					return false;
				}
				$result = $ability->execute(
					array(
						'level'   => $level,
						'message' => $message,
						'context' => $context,
					)
				);
				return ! is_wp_error( $result );
			}
			return false;
		}

		$function_name = 'datamachine_log_' . $level;
		if ( function_exists( $function_name ) ) {
			$function_name( $message, $context );
			return true;
		}

		return false;
	}
}
