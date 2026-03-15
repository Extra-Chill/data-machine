<?php
/**
 * Task Registry - Central registry for all Data Machine tasks.
 *
 * Manages registration and lookup of task type => handler class mappings.
 * Tasks are registered via the `datamachine_tasks` filter. The registry
 * is loaded once and cached for the request lifecycle.
 *
 * This replaces the task registration functionality that was previously
 * embedded in the SystemAgent singleton.
 *
 * @package DataMachine\Engine\Tasks
 * @since 0.37.0
 */

namespace DataMachine\Engine\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\PluginSettings;

class TaskRegistry {

	/**
	 * Cached task handlers.
	 *
	 * @var array<string, string>|null Task type => handler class name mapping.
	 */
	private static ?array $handlers = null;

	/**
	 * Load task handlers from the filter.
	 *
	 * Called once per request. Subsequent calls return the cached result.
	 *
	 * @return void
	 */
	public static function load(): void {
		if ( null !== self::$handlers ) {
			return;
		}

		/**
		 * Filter to register task handlers.
		 *
		 * Third-party plugins add their tasks here:
		 *
		 *     add_filter( 'datamachine_tasks', function( $tasks ) {
		 *         $tasks['my_custom_task'] = MyCustomTask::class;
		 *         return $tasks;
		 *     } );
		 *
		 * @since 0.37.0
		 *
		 * @param array<string, string> $handlers Task type => handler class name mapping.
		 */
		self::$handlers = apply_filters( 'datamachine_tasks', array() );
	}

	/**
	 * Get all registered task handlers.
	 *
	 * @return array<string, string> Task type => handler class name mapping.
	 */
	public static function getHandlers(): array {
		self::load();
		return self::$handlers;
	}

	/**
	 * Check if a task type is registered.
	 *
	 * @param string $taskType Task type identifier.
	 * @return bool
	 */
	public static function isRegistered( string $taskType ): bool {
		self::load();
		return isset( self::$handlers[ $taskType ] );
	}

	/**
	 * Get the handler class for a task type.
	 *
	 * @param string $taskType Task type identifier.
	 * @return string|null Handler class name, or null if not registered.
	 */
	public static function getHandler( string $taskType ): ?string {
		self::load();
		return self::$handlers[ $taskType ] ?? null;
	}

	/**
	 * Get the full task registry with metadata for admin UI and REST API.
	 *
	 * Iterates registered handlers, reads static getTaskMeta() from each,
	 * and merges with current enabled state from PluginSettings.
	 *
	 * @return array<string, array> Task type => metadata array.
	 */
	public static function getRegistry(): array {
		self::load();
		$registry = array();

		foreach ( self::$handlers as $task_type => $handler_class ) {
			$meta = array(
				'label'           => '',
				'description'     => '',
				'setting_key'     => null,
				'default_enabled' => true,
				'trigger'         => 'On demand',
				'trigger_type'    => 'manual',
				'supports_run'    => false,
			);

			if ( method_exists( $handler_class, 'getTaskMeta' ) ) {
				$meta = array_merge( $meta, $handler_class::getTaskMeta() );
			}

			// Resolve current enabled state from settings.
			$enabled = true;
			if ( ! empty( $meta['setting_key'] ) ) {
				$enabled = (bool) PluginSettings::get(
					$meta['setting_key'],
					$meta['default_enabled']
				);
			}

			$registry[ $task_type ] = array(
				'task_type'       => $task_type,
				'label'           => $meta['label'] ? $meta['label'] : ucfirst( str_replace( '_', ' ', $task_type ) ),
				'description'     => $meta['description'],
				'setting_key'     => $meta['setting_key'],
				'default_enabled' => $meta['default_enabled'],
				'enabled'         => $enabled,
				'trigger'         => $meta['trigger'],
				'trigger_type'    => $meta['trigger_type'],
				'supports_run'    => $meta['supports_run'],
			);
		}

		return $registry;
	}

	/**
	 * Reset the cached handlers (for testing).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$handlers = null;
	}
}
