<?php
/**
 * Handler for the datamachine_log action.
 *
 * @package DataMachine\Engine\Actions\Handlers
 * @since   0.11.0
 */

namespace DataMachine\Engine\Actions\Handlers;

/**
 * Central logging handler — delegates to abilities-based logging.
 *
 * Handles both write operations (info/error/warning/debug) and management
 * operations (clear_all, cleanup, set_level).
 */
class LogHandler {

	/**
	 * Handle the log action.
	 *
	 * @param string     $operation Log level or management operation.
	 * @param mixed      $param2    Message string (for writes) or first management param.
	 * @param mixed      $param3    Context array (for writes) or second management param.
	 * @param mixed|null $result    Reference parameter for management operation results.
	 * @return bool|mixed
	 */
	public static function handle( $operation, $param2 = null, $param3 = null, &$result = null ) {
		$management_operations = array( 'clear_all', 'cleanup', 'set_level' );

		if ( in_array( $operation, $management_operations, true ) ) {
			return self::handleManagement( $operation, $param2, $param3, $result );
		}

		return self::handleWrite( $operation, $param2, $param3 );
	}

	/**
	 * Handle log write operations (info, error, warning, debug, etc.).
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 * @return bool
	 */
	public static function handleWrite( $level, $message = null, $context = null ) {
		$context      = $context ?? array();
		$valid_levels = datamachine_get_valid_log_levels();

		if ( ! in_array( $level, $valid_levels, true ) ) {
			if ( class_exists( 'WP_Ability' ) ) {
				$ability = wp_get_ability( 'datamachine/write-to-log' );
				$result  = $ability->execute(
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

	/**
	 * Handle log management operations (clear_all, cleanup, set_level).
	 *
	 * @param string     $operation Management operation.
	 * @param mixed      $param2    First parameter.
	 * @param mixed      $param3    Second parameter.
	 * @param mixed|null $result    Reference for operation result.
	 * @return mixed
	 */
	public static function handleManagement( $operation, $param2 = null, $param3 = null, &$result = null ) {
		switch ( $operation ) {
			case 'clear_all':
				if ( class_exists( 'WP_Ability' ) ) {
					$ability        = wp_get_ability( 'datamachine/clear-logs' );
					$ability_result = $ability->execute( array( 'agent_type' => 'all' ) );
					$result         = is_wp_error( $ability_result ) ? false : $ability_result['success'];
				} else {
					$result = datamachine_clear_all_log_files();
				}
				return $result;

			case 'cleanup':
				$max_size_mb  = $param2 ?? 10;
				$max_age_days = $param3 ?? 30;
				$result       = datamachine_cleanup_log_files( $max_size_mb, $max_age_days );
				return $result;

			case 'set_level':
				$result = datamachine_set_log_level( $param2, $param3 );
				return $result;
		}

		return null;
	}
}
