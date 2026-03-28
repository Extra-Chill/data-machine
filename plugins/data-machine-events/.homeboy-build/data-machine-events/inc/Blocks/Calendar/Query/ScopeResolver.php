<?php
/**
 * Scope Resolver
 *
 * Resolves named time scopes (today, tonight, this-weekend, this-week)
 * into concrete date_start/date_end values for calendar queries.
 *
 * Returns null for unrecognized or default scopes, allowing the caller
 * to fall through to existing behavior.
 *
 * @package DataMachineEvents\Blocks\Calendar\Query
 * @since   0.15.0
 */

namespace DataMachineEvents\Blocks\Calendar\Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScopeResolver {

	/**
	 * Valid scope identifiers.
	 *
	 * @var string[]
	 */
	const VALID_SCOPES = array( 'today', 'tonight', 'this-weekend', 'this-week' );

	/**
	 * Resolve a scope name to concrete date boundaries.
	 *
	 * Returns null for 'current' (the default) or unrecognized scopes,
	 * which signals the caller to use existing unscoped behavior.
	 *
	 * @param string $scope The scope identifier.
	 * @return array|null Array with 'date_start', 'date_end', and optionally
	 *                    'time_start', 'time_end' for sub-day precision. Null if no scope.
	 */
	public static function resolve( string $scope ): ?array {
		$scope = sanitize_key( $scope );

		if ( 'current' === $scope || '' === $scope ) {
			return null;
		}

		$now   = current_time( 'timestamp' );
		$today = gmdate( 'Y-m-d', $now );

		switch ( $scope ) {
			case 'today':
				return array(
					'date_start' => $today,
					'date_end'   => $today,
				);

			case 'tonight':
				return self::resolve_tonight( $now, $today );

			case 'this-weekend':
				return self::resolve_this_weekend( $now, $today );

			case 'this-week':
				return self::resolve_this_week( $now, $today );

			default:
				return null;
		}
	}

	/**
	 * Check whether a scope string is valid.
	 *
	 * @param string $scope The scope to check.
	 * @return bool
	 */
	public static function is_valid( string $scope ): bool {
		return 'current' === $scope || '' === $scope || in_array( $scope, self::VALID_SCOPES, true );
	}

	/**
	 * Resolve "tonight" — events starting from 5 PM today through 3:59 AM tomorrow.
	 *
	 * Before 5 PM, tonight means today 5 PM – tomorrow 3:59 AM.
	 * After 5 PM, tonight means now – tomorrow 3:59 AM.
	 *
	 * @param int    $now   Current timestamp (site timezone).
	 * @param string $today Today's date in Y-m-d format.
	 * @return array Resolved date boundaries with time precision.
	 */
	private static function resolve_tonight( int $now, string $today ): array {
		$current_hour = (int) gmdate( 'G', $now );
		$tomorrow     = gmdate( 'Y-m-d', $now + DAY_IN_SECONDS );

		// Before 5 PM: show from 5 PM today onward.
		// After 5 PM: show from now onward (events already in progress or starting soon).
		$time_start = $current_hour < 17 ? '17:00:00' : gmdate( 'H:i:s', $now );

		return array(
			'date_start' => $today,
			'date_end'   => $tomorrow,
			'time_start' => $time_start,
			'time_end'   => '03:59:59',
		);
	}

	/**
	 * Resolve "this-weekend" — Friday through Sunday.
	 *
	 * If today is Mon–Thu, returns the upcoming Fri–Sun.
	 * If today is Fri–Sun, returns the current Fri–Sun (starting from today).
	 *
	 * @param int    $now   Current timestamp (site timezone).
	 * @param string $today Today's date in Y-m-d format.
	 * @return array Resolved date boundaries.
	 */
	private static function resolve_this_weekend( int $now, string $today ): array {
		$day_of_week = (int) gmdate( 'N', $now ); // 1 = Monday, 7 = Sunday.

		if ( $day_of_week >= 5 ) {
			// Already Fri/Sat/Sun — start from today, end on Sunday.
			$days_until_sunday = 7 - $day_of_week;
			$date_start        = $today;
			$date_end          = gmdate( 'Y-m-d', $now + ( $days_until_sunday * DAY_IN_SECONDS ) );
		} else {
			// Mon–Thu — jump to upcoming Friday.
			$days_until_friday = 5 - $day_of_week;
			$friday            = $now + ( $days_until_friday * DAY_IN_SECONDS );
			$date_start        = gmdate( 'Y-m-d', $friday );
			$date_end          = gmdate( 'Y-m-d', $friday + ( 2 * DAY_IN_SECONDS ) ); // Sunday.
		}

		return array(
			'date_start' => $date_start,
			'date_end'   => $date_end,
		);
	}

	/**
	 * Resolve "this-week" — today through 6 days from now (7-day window).
	 *
	 * @param int    $now   Current timestamp (site timezone).
	 * @param string $today Today's date in Y-m-d format.
	 * @return array Resolved date boundaries.
	 */
	private static function resolve_this_week( int $now, string $today ): array {
		$end_date = gmdate( 'Y-m-d', $now + ( 6 * DAY_IN_SECONDS ) );

		return array(
			'date_start' => $today,
			'date_end'   => $end_date,
		);
	}
}
