<?php
/**
 * Action Scheduler WP-CLI compatibility shims.
 *
 * @package DataMachine\Cli
 */

namespace Action_Scheduler\WP_CLI;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\WP_CLI', false ) && class_exists( '\\WP_CLI', false ) ) {
	/**
	 * Proxies unqualified WP_CLI calls from bundled Action Scheduler namespaced commands.
	 */
	class WP_CLI {
		/**
		 * Forward static calls to the global WP_CLI class.
		 *
		 * @param string $method    Method name.
		 * @param array  $arguments Method arguments.
		 * @return mixed
		 */
		public static function __callStatic( string $method, array $arguments ) {
			return call_user_func_array( array( '\\WP_CLI', $method ), $arguments );
		}
	}
}
