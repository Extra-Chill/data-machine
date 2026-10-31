<?php
/**
 * EventDatesTable read-through cache and merged-bill schedule gate smoke.
 *
 * Self-contained (no PHPUnit / WP test framework). Guards
 * Extra-Chill/data-machine#3712: one event page called EventDatesTable::get()
 * about 15 times for the same post, each a direct query.
 *
 * Run directly:
 *   php tests/event-dates-cache-smoke.php
 *
 * @package DataMachineEvents\Tests
 */

namespace DataMachineEvents\Core {
	// Minimal post-type stub so write paths accept the post.
	class Event_Post_Type {
		public const POST_TYPE = 'data_machine_events';
	}
	class EventSpanGuard {
		public static function is_end_plausible( $start, $end ): bool { unset( $start, $end ); return true; }
		public static function max_span_hours(): int { return 48; }
	}
	class DateTimeParser {
		public static function isValidYmd( string $date ): bool { return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ); }
	}
}

namespace DataMachineEvents\Steps\MergedBills {
	// Present so the flow file's use statements resolve if any are needed.
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );

	class WP_Post {
		public $ID;
		public $post_type   = 'data_machine_events';
		public $post_status = 'publish';
	}

	class EventDatesCacheWpdb {
		public string $prefix     = 'wp_7_';
		public string $last_error = '';
		public int $selects       = 0;
		public array $rows        = array();
		public function prepare( string $query, ...$args ): string {
			foreach ( $args as $arg ) { $query = preg_replace( '/%d/', (string) (int) $arg, $query, 1 ); }
			return $query;
		}
		public function get_row( string $query ) {
			++$this->selects;
			preg_match( '/post_id = (\d+)/', $query, $m );
			return isset( $this->rows[ (int) $m[1] ] ) ? (object) $this->rows[ (int) $m[1] ] : null;
		}
		public function replace( string $table, array $data ) {
			unset( $table );
			$this->rows[ (int) $data['post_id'] ] = $data;
			return 1;
		}
		public function update( string $table, array $data, array $where ) {
			unset( $table );
			$this->rows[ (int) $where['post_id'] ] = array_merge( $this->rows[ (int) $where['post_id'] ] ?? array(), $data );
			return 1;
		}
		public function delete( string $table, array $where ) {
			unset( $table );
			unset( $this->rows[ (int) $where['post_id'] ] );
			return 1;
		}
	}

	$GLOBALS['wpdb']       = new EventDatesCacheWpdb();
	$GLOBALS['wp_cache']   = array();
	$GLOBALS['transients'] = array();
	$GLOBALS['as_calls']   = array( 'next' => 0, 'schedule' => 0 );
	$GLOBALS['as_next']    = false;

	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		unset( $force );
		$found = array_key_exists( "$group:$key", $GLOBALS['wp_cache'] );
		return $found ? $GLOBALS['wp_cache'][ "$group:$key" ] : false;
	}
	function wp_cache_set( $key, $data, $group = '' ) { $GLOBALS['wp_cache'][ "$group:$key" ] = $data; return true; }
	function wp_cache_delete( $key, $group = '' ) { unset( $GLOBALS['wp_cache'][ "$group:$key" ] ); return true; }
	function get_post( $id ) { $p = new WP_Post(); $p->ID = $id; $p->post_status = $GLOBALS['post_status'] ?? 'publish'; return $p; }
	function do_action( ...$args ) { unset( $args ); }
	function add_action( ...$args ) { unset( $args ); }
	function apply_filters( $hook, $value ) { unset( $hook ); return $value; }
	function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
	function set_transient( $key, $value, $ttl = 0 ) { unset( $ttl ); $GLOBALS['transients'][ $key ] = $value; return true; }
	function as_next_scheduled_action( $hook ) { unset( $hook ); ++$GLOBALS['as_calls']['next']; return $GLOBALS['as_next']; }
	function as_schedule_recurring_action( ...$args ) { unset( $args ); ++$GLOBALS['as_calls']['schedule']; $GLOBALS['as_next'] = time() + 300; return 1; }

	require_once dirname( __DIR__ ) . '/inc/Core/EventDatesTable.php';
	require_once dirname( __DIR__ ) . '/inc/Steps/MergedBills/MergedBillResolverFlow.php';

	use DataMachineEvents\Core\EventDatesTable;
	use DataMachineEvents\Steps\MergedBills\MergedBillResolverFlow;

	$failures = array();
	$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
		echo ( $ok ? 'PASS' : 'FAIL' ) . ": {$label}\n";
		if ( ! $ok ) {
			$failures[] = $label;
		}
	};
	$wpdb = $GLOBALS['wpdb'];

	// Repeated reads of one post issue one query.
	$wpdb->rows[42] = array( 'post_id' => 42, 'start_datetime' => '2026-11-01 20:00:00', 'end_datetime' => null, 'post_status' => 'publish' );
	for ( $i = 0; $i < 15; $i++ ) {
		$row = EventDatesTable::get( 42 );
	}
	$check( 1 === $wpdb->selects, 'fifteen reads of one post issue one query' );
	$check( '2026-11-01 20:00:00' === $row->start_datetime, 'cached read returns the row' );

	// Misses are cached, and an insert is not hidden by the cached miss.
	$wpdb->selects = 0;
	$check( null === EventDatesTable::get( 7 ) && null === EventDatesTable::get( 7 ), 'missing row reads as null' );
	$check( 1 === $wpdb->selects, 'a cached miss does not requery' );
	EventDatesTable::upsert( 7, '2026-12-01 19:00:00' );
	$check( '2026-12-01 19:00:00' === ( EventDatesTable::get( 7 )->start_datetime ?? null ), 'upsert invalidates a cached miss' );

	// update_status and delete invalidate.
	$GLOBALS['post_status'] = 'draft';
	EventDatesTable::update_status( 42, 'draft' );
	$check( 'draft' === EventDatesTable::get( 42 )->post_status, 'update_status invalidates the cached row' );
	$GLOBALS['post_status'] = 'publish';
	EventDatesTable::delete( 42 );
	$check( null === EventDatesTable::get( 42 ), 'delete invalidates the cached row' );

	// A failed read is not cached.
	$wpdb->rows[99]   = array( 'post_id' => 99, 'start_datetime' => '2026-10-01 18:00:00', 'end_datetime' => null, 'post_status' => 'publish' );
	$wpdb->last_error = 'MySQL server has gone away';
	EventDatesTable::get( 99 );
	$wpdb->last_error = '';
	$wpdb->selects    = 0;
	EventDatesTable::get( 99 );
	$check( 1 === $wpdb->selects, 'a read that errored is retried, not served from cache' );

	// Merged-bill schedule gate.
	MergedBillResolverFlow::maybeScheduleRecurring();
	$check( 1 === $GLOBALS['as_calls']['next'] && 1 === $GLOBALS['as_calls']['schedule'], 'first request checks and schedules' );
	MergedBillResolverFlow::maybeScheduleRecurring();
	MergedBillResolverFlow::maybeScheduleRecurring();
	$check( 1 === $GLOBALS['as_calls']['next'], 'later requests skip the Action Scheduler lookup' );
	$GLOBALS['transients'] = array();
	MergedBillResolverFlow::maybeScheduleRecurring();
	$check( 2 === $GLOBALS['as_calls']['next'] && 1 === $GLOBALS['as_calls']['schedule'], 'expired gate rechecks without duplicating the schedule' );

	if ( $failures ) {
		fwrite( STDERR, count( $failures ) . " failure(s)\n" );
		exit( 1 );
	}

	echo "Event dates cache smoke passed.\n";
}
