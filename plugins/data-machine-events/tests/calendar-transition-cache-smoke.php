<?php
/** Request/object-cache transition regression without a WordPress install. */
namespace DataMachineEvents\Blocks\Calendar\Cache {
	class CalendarGenerationFence {
		public static function currentState(): array { return array( 'generation' => $GLOBALS['generation'] ); }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	$GLOBALS['generation'] = 'one';
	$GLOBALS['now'] = '2099-01-01 12:00:00';
	$GLOBALS['site'] = 1;
	$GLOBALS['cache'] = array();
	function get_current_blog_id() { return $GLOBALS['site']; }
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
	function current_datetime() { return new DateTimeImmutable( $GLOBALS['now'], wp_timezone() ); }
	function current_time( $format ) { return current_datetime()->format( 'mysql' === $format ? 'Y-m-d H:i:s' : $format ); }
	function wp_cache_get( $key, $group ) { return $GLOBALS['cache'][ "$group:$key" ] ?? false; }
	function wp_cache_set( $key, $value, $group, $ttl ) { $GLOBALS['cache'][ "$group:$key" ] = $value; }
	class TransitionWpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public int $queries = 0;
		public ?string $value = '2099-01-01 12:00:05';
		public function prepare( $sql, ...$args ) { return $sql; }
		public function get_var( $sql ) {
			if ( ! str_contains( $sql, 'MIN(effective_end)' ) || str_contains( $sql, 'UNION' ) ) {
				throw new RuntimeException( 'Transition must be a covering MIN query' );
			}
			++$this->queries;
			return $this->value;
		}
	}
	require dirname( __DIR__ ) . '/inc/Core/EventDatesTable.php';
	require dirname( __DIR__ ) . '/inc/Blocks/Calendar/Cache/CalendarCache.php';
	require dirname( __DIR__ ) . '/inc/Blocks/Calendar/Cache/PageExpiry.php';
	use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
	use DataMachineEvents\Blocks\Calendar\Cache\PageExpiry;
	$wpdb = new TransitionWpdb();
	function check_queries( int $expected ): void {
		if ( $GLOBALS['wpdb']->queries !== $expected ) { throw new RuntimeException( 'Unexpected query count' ); }
	}
	CalendarCache::next_upcoming_transition();
	CalendarCache::ttl_for_upcoming_transition( 1800 );
	PageExpiry::max_age();
	check_queries( 1 );
	CalendarCache::reset_transitions();
	CalendarCache::next_upcoming_transition();
	check_queries( 1 );
	$GLOBALS['now'] = '2099-01-01 12:00:05';
	CalendarCache::next_upcoming_transition();
	check_queries( 1 );
	$GLOBALS['now'] = '2099-01-01 12:00:06';
	$wpdb->value = null;
	CalendarCache::next_upcoming_transition();
	check_queries( 2 );
	CalendarCache::next_upcoming_transition();
	check_queries( 2 );
	$GLOBALS['generation'] = 'two';
	CalendarCache::next_upcoming_transition();
	check_queries( 3 );
	$GLOBALS['site'] = 2;
	CalendarCache::next_upcoming_transition();
	check_queries( 4 );
	$GLOBALS['now'] = '2099-01-01 14:00:00';
	CalendarCache::next_upcoming_transition();
	check_queries( 5 );
	echo "PASS: transition memoization, object cache, inclusive boundary, expiry, empty results, generation and site isolation.\n";
}
