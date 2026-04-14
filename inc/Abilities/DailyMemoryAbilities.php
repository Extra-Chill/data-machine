<?php
/**
 * Daily Memory Abilities
 *
 * WordPress 6.9 Abilities API primitives for daily memory operations.
 * Provides read/write/list/search/delete access to daily memory.
 *
 * Each operation fires a filter (`datamachine_daily_memory_{operation}`) that
 * allows plugins to completely replace the storage backend. If the filter
 * returns a non-null value, the flat-file operation is skipped entirely.
 * This enables consumers like Intelligence to store daily memory as
 * WordPress pages, external services, or anything else — Data Machine
 * doesn't need to know or care.
 *
 * @package DataMachine\Abilities
 * @since 0.32.0
 * @see https://github.com/Extra-Chill/data-machine/issues/348
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

class DailyMemoryAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/daily-memory-read',
				array(
					'label'               => 'Read Daily Memory',
					'description'         => 'Read a daily memory file by date. Defaults to today if no date provided.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'user_id' => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID for multi-agent scoping. Defaults to 0 (shared agent).',
								'default'     => 0,
							),
							'date'    => array(
								'type'        => 'string',
								'description' => 'Date in YYYY-MM-DD format. Defaults to today.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'date'    => array( 'type' => 'string' ),
							'content' => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'readDaily' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/daily-memory-write',
				array(
					'label'               => 'Write Daily Memory',
					'description'         => 'Write or append to a daily memory file. Use mode "append" to add without replacing.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'user_id' => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID for multi-agent scoping. Defaults to 0 (shared agent).',
								'default'     => 0,
							),
							'content' => array(
								'type'        => 'string',
								'description' => 'Content to write.',
							),
							'date'    => array(
								'type'        => 'string',
								'description' => 'Date in YYYY-MM-DD format. Defaults to today.',
							),
							'mode'    => array(
								'type'        => 'string',
								'enum'        => array( 'write', 'append' ),
								'description' => 'Write mode: "write" replaces the file, "append" adds to end. Defaults to "append".',
							),
						),
						'required'   => array( 'content' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'writeDaily' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/daily-memory-list',
				array(
					'label'               => 'List Daily Memory Files',
					'description'         => 'List all daily memory files grouped by month',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'user_id' => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID for multi-agent scoping. Defaults to 0 (shared agent).',
								'default'     => 0,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'months'  => array(
								'type'        => 'object',
								'description' => 'Object with month keys (YYYY/MM) mapping to arrays of day strings',
							),
						),
					),
					'execute_callback'    => array( self::class, 'listDaily' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/search-daily-memory',
				array(
					'label'               => 'Search Daily Memory',
					'description'         => 'Search across daily memory files with optional date range. Returns matching lines with context.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'query' ),
						'properties' => array(
							'user_id' => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID for multi-agent scoping. Defaults to 0 (shared agent).',
								'default'     => 0,
							),
							'query'   => array(
								'type'        => 'string',
								'description' => 'Search term (case-insensitive substring match).',
							),
							'from'    => array(
								'type'        => 'string',
								'description' => 'Start date (YYYY-MM-DD, inclusive). Omit for no lower bound.',
							),
							'to'      => array(
								'type'        => 'string',
								'description' => 'End date (YYYY-MM-DD, inclusive). Omit for no upper bound.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'query'       => array( 'type' => 'string' ),
							'match_count' => array( 'type' => 'integer' ),
							'matches'     => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'date'    => array( 'type' => 'string' ),
										'line'    => array( 'type' => 'integer' ),
										'content' => array( 'type' => 'string' ),
										'context' => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( self::class, 'searchDaily' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/daily-memory-delete',
				array(
					'label'               => 'Delete Daily Memory',
					'description'         => 'Delete a daily memory file by date.',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'user_id' => array(
								'type'        => 'integer',
								'description' => 'WordPress user ID for multi-agent scoping. Defaults to 0 (shared agent).',
								'default'     => 0,
							),
							'date'    => array(
								'type'        => 'string',
								'description' => 'Date in YYYY-MM-DD format. Defaults to today.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'deleteDaily' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Read a daily memory file.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function readDaily( array $input ): array {
		$date = $input['date'] ?? gmdate( 'Y-m-d' );

		$parts = DailyMemory::parse_date( $date );
		if ( ! $parts ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Invalid date format: %s. Use YYYY-MM-DD.', $date ),
			);
		}

		/**
		 * Filters the daily memory read result.
		 *
		 * Return a non-null array to completely replace the flat-file read.
		 * The array should match the standard result format:
		 * `{ success: bool, date?: string, content?: string, message?: string }`
		 *
		 * @since 0.47.0
		 *
		 * @param array|null $result   Default null (flat-file read proceeds).
		 *                             Return an array to short-circuit.
		 * @param string     $date     The requested date (YYYY-MM-DD).
		 * @param array      $input    Full input parameters (includes user_id, agent_id).
		 */
		$result = apply_filters( 'datamachine_daily_memory_read', null, $date, $input );

		if ( null !== $result ) {
			return $result;
		}

		$user_id  = (int) ( $input['user_id'] ?? 0 );
		$agent_id = (int) ( $input['agent_id'] ?? 0 );
		$daily    = new DailyMemory( $user_id, $agent_id );

		return $daily->read( $parts['year'], $parts['month'], $parts['day'] );
	}

	/**
	 * Write or append to a daily memory file.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function writeDaily( array $input ): array {
		if ( ! PluginSettings::get( 'daily_memory_enabled', false ) ) {
			return array(
				'success' => false,
				'message' => 'Daily memory is disabled. Enable it in Agent > Configuration.',
			);
		}

		$date    = $input['date'] ?? gmdate( 'Y-m-d' );
		$content = $input['content'];
		$mode    = $input['mode'] ?? 'append';

		$parts = DailyMemory::parse_date( $date );
		if ( ! $parts ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Invalid date format: %s. Use YYYY-MM-DD.', $date ),
			);
		}

		/**
		 * Filters the daily memory write result.
		 *
		 * Return a non-null array to completely replace the flat-file write.
		 * The array should match: `{ success: bool, message?: string }`
		 *
		 * @since 0.47.0
		 *
		 * @param array|null $result   Default null (flat-file write proceeds).
		 *                             Return an array to short-circuit.
		 * @param string     $date     The target date (YYYY-MM-DD).
		 * @param string     $content  The content to write.
		 * @param string     $mode     Write mode: 'write' or 'append'.
		 * @param array      $input    Full input parameters (includes user_id, agent_id).
		 */
		$result = apply_filters( 'datamachine_daily_memory_write', null, $date, $content, $mode, $input );

		if ( null !== $result ) {
			return $result;
		}

		$user_id  = (int) ( $input['user_id'] ?? 0 );
		$agent_id = (int) ( $input['agent_id'] ?? 0 );
		$daily    = new DailyMemory( $user_id, $agent_id );

		if ( 'write' === $mode ) {
			return $daily->write( $parts['year'], $parts['month'], $parts['day'], $content );
		}

		return $daily->append( $parts['year'], $parts['month'], $parts['day'], $content );
	}

	/**
	 * List all daily memory files.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function listDaily( array $input ): array {
		/**
		 * Filters the daily memory list result.
		 *
		 * Return a non-null array to completely replace the flat-file listing.
		 * The array should match: `{ months: { 'YYYY/MM': ['DD', ...], ... } }`
		 *
		 * @since 0.47.0
		 *
		 * @param array|null $result   Default null (flat-file list proceeds).
		 *                             Return an array to short-circuit.
		 * @param array      $input    Full input parameters (includes user_id, agent_id).
		 */
		$result = apply_filters( 'datamachine_daily_memory_list', null, $input );

		if ( null !== $result ) {
			return $result;
		}

		$user_id  = (int) ( $input['user_id'] ?? 0 );
		$agent_id = (int) ( $input['agent_id'] ?? 0 );
		$daily    = new DailyMemory( $user_id, $agent_id );
		return $daily->list_all();
	}

	/**
	 * Search across daily memory files.
	 *
	 * @param array $input Input parameters with 'query', optional 'from' and 'to'.
	 * @return array Search results.
	 */
	public static function searchDaily( array $input ): array {
		$query = $input['query'];
		$from  = $input['from'] ?? null;
		$to    = $input['to'] ?? null;

		/**
		 * Filters the daily memory search result.
		 *
		 * Return a non-null array to completely replace the flat-file search.
		 * The array should match:
		 * `{ success: bool, query: string, match_count: int, matches: [...] }`
		 *
		 * @since 0.47.0
		 *
		 * @param array|null  $result  Default null (flat-file search proceeds).
		 *                             Return an array to short-circuit.
		 * @param string      $query   The search term.
		 * @param string|null $from    Start date (YYYY-MM-DD) or null.
		 * @param string|null $to      End date (YYYY-MM-DD) or null.
		 * @param array       $input   Full input parameters (includes user_id, agent_id).
		 */
		$result = apply_filters( 'datamachine_daily_memory_search', null, $query, $from, $to, $input );

		if ( null !== $result ) {
			return $result;
		}

		$user_id  = (int) ( $input['user_id'] ?? 0 );
		$agent_id = (int) ( $input['agent_id'] ?? 0 );
		$daily    = new DailyMemory( $user_id, $agent_id );

		return $daily->search( $query, $from, $to );
	}

	/**
	 * Delete a daily memory file.
	 *
	 * Respects the daily_memory_enabled setting.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function deleteDaily( array $input ): array {
		if ( ! PluginSettings::get( 'daily_memory_enabled', false ) ) {
			return array(
				'success' => false,
				'message' => 'Daily memory is disabled. Enable it in Agent > Configuration.',
			);
		}

		$date = $input['date'] ?? gmdate( 'Y-m-d' );

		$parts = DailyMemory::parse_date( $date );
		if ( ! $parts ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Invalid date format: %s. Use YYYY-MM-DD.', $date ),
			);
		}

		/**
		 * Filters the daily memory delete result.
		 *
		 * Return a non-null array to completely replace the flat-file delete.
		 * The array should match: `{ success: bool, message?: string }`
		 *
		 * @since 0.47.0
		 *
		 * @param array|null $result   Default null (flat-file delete proceeds).
		 *                             Return an array to short-circuit.
		 * @param string     $date     The target date (YYYY-MM-DD).
		 * @param array      $input    Full input parameters (includes user_id, agent_id).
		 */
		$result = apply_filters( 'datamachine_daily_memory_delete', null, $date, $input );

		if ( null !== $result ) {
			return $result;
		}

		$user_id  = (int) ( $input['user_id'] ?? 0 );
		$agent_id = (int) ( $input['agent_id'] ?? 0 );
		$daily    = new DailyMemory( $user_id, $agent_id );

		return $daily->delete( $parts['year'], $parts['month'], $parts['day'] );
	}
}
