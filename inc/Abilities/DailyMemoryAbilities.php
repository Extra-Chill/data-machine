<?php
/**
 * Daily Memory Abilities
 *
 * WordPress 6.9 Abilities API primitives for daily memory operations.
 * Provides read/write/list access to daily memory files (YYYY/MM/DD.md).
 *
 * @package DataMachine\Abilities
 * @since 0.32.0
 * @see https://github.com/Extra-Chill/data-machine/issues/348
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\DailyMemory;

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
							'date' => array(
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
						'properties' => array(),
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
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
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
		$daily = new DailyMemory();
		$date  = $input['date'] ?? gmdate( 'Y-m-d' );

		$parts = self::parseDate( $date );
		if ( ! $parts ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Invalid date format: %s. Use YYYY-MM-DD.', $date ),
			);
		}

		return $daily->read( $parts['year'], $parts['month'], $parts['day'] );
	}

	/**
	 * Write or append to a daily memory file.
	 *
	 * @param array $input Input parameters.
	 * @return array Result.
	 */
	public static function writeDaily( array $input ): array {
		$daily   = new DailyMemory();
		$content = $input['content'];
		$date    = $input['date'] ?? gmdate( 'Y-m-d' );
		$mode    = $input['mode'] ?? 'append';

		$parts = self::parseDate( $date );
		if ( ! $parts ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Invalid date format: %s. Use YYYY-MM-DD.', $date ),
			);
		}

		if ( 'write' === $mode ) {
			return $daily->write( $parts['year'], $parts['month'], $parts['day'], $content );
		}

		return $daily->append( $parts['year'], $parts['month'], $parts['day'], $content );
	}

	/**
	 * List all daily memory files.
	 *
	 * @param array $input Input parameters (unused).
	 * @return array Result.
	 */
	public static function listDaily( array $input ): array {
		$daily = new DailyMemory();
		return $daily->list_all();
	}

	/**
	 * Parse a YYYY-MM-DD date string.
	 *
	 * @param string $date Date string.
	 * @return array{year: string, month: string, day: string}|null
	 */
	private static function parseDate( string $date ): ?array {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
			return null;
		}

		return array(
			'year'  => $matches[1],
			'month' => $matches[2],
			'day'   => $matches[3],
		);
	}
}
