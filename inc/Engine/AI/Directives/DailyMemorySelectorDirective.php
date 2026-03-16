<?php
/**
 * Daily Memory Selector Directive - Priority 46
 *
 * Injects daily memory files based on flow configuration.
 * Supports multiple selection modes: recent days, specific dates,
 * date ranges, and entire months.
 *
 * Priority Order in Directive System:
 * 1. Priority 10 - Plugin Core Directive (agent identity)
 * 2. Priority 20 - Core Memory Files (SITE.md, RULES.md, SOUL.md, MEMORY.md, USER.md)
 * 3. Priority 40 - Pipeline Memory Files (per-pipeline selectable)
 * 4. Priority 45 - Flow Memory Files (per-flow selectable)
 * 5. Priority 46 - Daily Memory Selector (THIS CLASS - per-flow daily config)
 * 6. Priority 50 - Pipeline System Prompt (pipeline instructions)
 * 7. Priority 60 - Pipeline Context Files
 * 8. Priority 70 - Tool Definitions (available tools and workflow)
 * 9. Priority 80 - Site Context (WordPress metadata)
 *
 * @package DataMachine\Engine\AI\Directives
 * @since   0.40.0
 * @see     https://github.com/Extra-Chill/data-machine/issues/761
 */

namespace DataMachine\Engine\AI\Directives;

use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Core\FilesRepository\DirectoryManager;

defined( 'ABSPATH' ) || exit;

class DailyMemorySelectorDirective implements DirectiveInterface {

	/**
	 * Maximum total size of daily memory content to inject (in bytes).
	 *
	 * @var int
	 */
	private const MAX_TOTAL_SIZE = 100 * 1024; // 100KB

	/**
	 * Get directive outputs for daily memory files based on flow config.
	 *
	 * @param string      $provider_name AI provider name.
	 * @param array       $tools         Available tools.
	 * @param string|null $step_id       Pipeline step ID.
	 * @param array       $payload       Additional payload data (contains flow_step_id).
	 * @return array Directive outputs.
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$flow_step_id = $payload['flow_step_id'] ?? null;

		if ( empty( $flow_step_id ) ) {
			return array();
		}

		// Extract flow_id from flow_step_id via the DM filter.
		$parts = apply_filters( 'datamachine_split_flow_step_id', null, $flow_step_id );
		if ( ! $parts || empty( $parts['flow_id'] ) ) {
			return array();
		}

		$flow_id         = (int) $parts['flow_id'];
		$user_id         = (int) ( $payload['user_id'] ?? 0 );
		$agent_id        = (int) ( $payload['agent_id'] ?? 0 );
		$daily_memory    = new DailyMemory( $user_id, $agent_id );
		$daily_config    = self::get_daily_memory_config( $flow_id );
		$dates_to_inject = self::resolve_dates( $daily_config, $daily_memory );

		if ( empty( $dates_to_inject ) ) {
			return array();
		}

		$outputs     = array();
		$total_size  = 0;
		$files_added = 0;

		// Sort dates descending (newest first).
		rsort( $dates_to_inject );

		foreach ( $dates_to_inject as $date ) {
			$parts = DailyMemory::parse_date( $date );
			if ( ! $parts ) {
				continue;
			}

			$result = $daily_memory->read( $parts['year'], $parts['month'], $parts['day'] );
			if ( ! $result['success'] || empty( trim( $result['content'] ?? '' ) ) ) {
				continue;
			}

			$content      = trim( $result['content'] );
			$content_size = strlen( $content );

			// Enforce total size limit.
			if ( $total_size + $content_size > self::MAX_TOTAL_SIZE ) {
				do_action(
					'datamachine_log',
					'warning',
					'Daily Memory Selector: Size limit reached, truncating',
					array(
						'flow_id'     => $flow_id,
						'files_added' => $files_added,
						'total_size'  => $total_size,
						'max_size'    => self::MAX_TOTAL_SIZE,
					)
				);
				break;
			}

			$outputs[]    = array(
				'type'    => 'system_text',
				'content' => "## Daily Memory: {$date}\n{$content}",
			);
			$total_size  += $content_size;
			$files_added += 1;
		}

		if ( ! empty( $outputs ) ) {
			do_action(
				'datamachine_log',
				'debug',
				'Daily Memory Selector: Injected daily memory files',
				array(
					'flow_id'     => $flow_id,
					'mode'        => $daily_config['mode'] ?? 'none',
					'files_added' => $files_added,
					'total_size'  => $total_size,
				)
			);
		}

		return $outputs;
	}

	/**
	 * Get the daily memory configuration for a flow.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array Daily memory config with 'mode' and mode-specific fields.
	 */
	private static function get_daily_memory_config( int $flow_id ): array {
		$db_flows = new Flows();
		$flow     = $db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			return array( 'mode' => 'none' );
		}

		$config = $flow['flow_config']['daily_memory'] ?? array();

		// Normalize legacy boolean to new format.
		if ( ! isset( $config['mode'] ) ) {
			// Check for legacy include_daily flag.
			$include_daily = $flow['flow_config']['include_daily'] ?? false;
			if ( $include_daily ) {
				return array(
					'mode' => 'recent_days',
					'days' => 7,
				);
			}
			return array( 'mode' => 'none' );
		}

		return $config;
	}

	/**
	 * Resolve the daily memory config to a list of dates.
	 *
	 * @param array        $config    Daily memory config.
	 * @param DailyMemory  $daily     Daily memory service.
	 * @return string[] Array of dates in YYYY-MM-DD format.
	 */
	private static function resolve_dates( array $config, DailyMemory $daily ): array {
		$mode = $config['mode'] ?? 'none';

		switch ( $mode ) {
			case 'recent_days':
				return self::get_recent_dates( (int) ( $config['days'] ?? 7 ) );

			case 'specific_dates':
				return self::validate_dates( $config['dates'] ?? array(), $daily );

			case 'date_range':
				return self::get_date_range(
					$config['from'] ?? null,
					$config['to'] ?? null,
					$daily
				);

			case 'months':
				return self::get_month_dates( $config['months'] ?? array(), $daily );

			case 'none':
			default:
				return array();
		}
	}

	/**
	 * Get the N most recent dates.
	 *
	 * @param int $days Number of days to include.
	 * @return string[] Array of dates.
	 */
	private static function get_recent_dates( int $days ): array {
		$dates = array();
		$max   = min( $days, 90 ); // Cap at 90 days.

		for ( $i = 0; $i < $max; $i++ ) {
			$dates[] = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
		}

		return $dates;
	}

	/**
	 * Validate and filter specific dates to only those that exist.
	 *
	 * @param string[]     $dates Array of YYYY-MM-DD dates.
	 * @param DailyMemory  $daily Daily memory service.
	 * @return string[] Valid dates that have files.
	 */
	private static function validate_dates( array $dates, DailyMemory $daily ): array {
		$valid = array();

		foreach ( $dates as $date ) {
			$parts = DailyMemory::parse_date( $date );
			if ( ! $parts ) {
				continue;
			}

			if ( $daily->exists( $parts['year'], $parts['month'], $parts['day'] ) ) {
				$valid[] = $date;
			}
		}

		return $valid;
	}

	/**
	 * Get all dates within a date range that have daily memory files.
	 *
	 * @param string|null  $from  Start date (YYYY-MM-DD).
	 * @param string|null  $to    End date (YYYY-MM-DD).
	 * @param DailyMemory  $daily Daily memory service.
	 * @return string[] Dates with files.
	 */
	private static function get_date_range( ?string $from, ?string $to, DailyMemory $daily ): array {
		if ( ! $from && ! $to ) {
			return array();
		}

		// Get all available dates.
		$all   = $daily->list_all();
		$dates = array();

		foreach ( $all['months'] as $month_key => $days ) {
			list( $year, $month ) = explode( '/', $month_key );
			foreach ( $days as $day ) {
				$date = "{$year}-{$month}-{$day}";

				// Apply range filter.
				if ( null !== $from && $date < $from ) {
					continue;
				}
				if ( null !== $to && $date > $to ) {
					continue;
				}

				$dates[] = $date;
			}
		}

		return $dates;
	}

	/**
	 * Get all dates for selected months.
	 *
	 * @param string[]     $months Array of month keys (YYYY/MM).
	 * @param DailyMemory  $daily  Daily memory service.
	 * @return string[] Dates in those months.
	 */
	private static function get_month_dates( array $months, DailyMemory $daily ): array {
		if ( empty( $months ) ) {
			return array();
		}

		$all   = $daily->list_all();
		$dates = array();

		foreach ( $months as $month_key ) {
			if ( ! isset( $all['months'][ $month_key ] ) ) {
				continue;
			}

			list( $year, $month ) = explode( '/', $month_key );
			foreach ( $all['months'][ $month_key ] as $day ) {
				$dates[] = "{$year}-{$month}-{$day}";
			}
		}

		return $dates;
	}
}

// Register at Priority 46 — after flow memory files (45), before pipeline system prompt (50).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => DailyMemorySelectorDirective::class,
			'priority' => 46,
			'contexts' => array( 'pipeline' ),
		);
		return $directives;
	}
);
