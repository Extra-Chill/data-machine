<?php
/**
 * Email fetch filters: shared keyword filters, timeframe mapping, and OR'd
 * sender/subject searches, against a deterministic in-memory IMAP mailbox.
 *
 * @package DataMachine\Tests
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	foreach ( array(
		'FT_UID'  => 1,
		'FT_PEEK' => 2,
		'SE_UID'  => 1,
	) as $constant => $value ) {
		if ( ! defined( $constant ) ) {
			define( $constant, $value );
		}
	}

	$GLOBALS['filters']  = array();
	$GLOBALS['logged']   = array();
	$GLOBALS['imap_log'] = array();

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		foreach ( $GLOBALS['filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
	function add_filter( string $hook, callable $callback ): void {
		$GLOBALS['filters'][ $hook ][] = $callback;
	}
	function do_action( string $hook, mixed ...$args ): void {
		$GLOBALS['logged'][] = array( $hook, $args );
	}
	function __( string $text, string $domain = '' ): string {
		return $text;
	}

	// Same contract as the shared filter in inc/Engine/Filters/Handlers.php.
	add_filter(
		'datamachine_timeframe_limit',
		static function ( $default_value, $timeframe ) {
			$map = array(
				'24_hours' => '-24 hours',
				'72_hours' => '-72 hours',
				'7_days'   => '-7 days',
				'30_days'  => '-30 days',
			);
			return isset( $map[ $timeframe ] ) ? strtotime( $map[ $timeframe ] ) : null;
		}
	);

	/*
	 * Fixture mailbox, keyed by UID. Bodies are only reachable through
	 * imap_fetchbody so the test can count how many bodies were read.
	 */
	$GLOBALS['mailbox'] = array(
		101 => array( 'Album out today', 'New single from a band', 'pr@label.test' ),
		102 => array( 'High Water Fest Announces 2027 Lineup', 'Jack Johnson and Mt. Joy headline', 'sue@thesyn.test' ),
		103 => array( 'WordCamp US Lineup Keeps Growing', 'Speakers announced', 'noreply@wordpress.test' ),
		104 => array( 'Press release', 'Rooster Walk festival lineup drops Oct 20', 'info@roosterwalk.test' ),
		105 => array( 'Invoice 55', 'Pay by Friday', 'billing@vendor.test' ),
		106 => array( 'Big Ears Festival Expands 2027 Lineup', 'The Roots join the bill', 'chad@pitchperfect.test' ),
	);
}

namespace DataMachine\Abilities\Fetch {
	function imap_search( $connection, string $criteria, int $flags ): array|false {
		$GLOBALS['imap_log'][] = array( 'search', $criteria );
		return $GLOBALS['search_results'][ $criteria ] ?? array_keys( $GLOBALS['mailbox'] );
	}
	function imap_msgno( $connection, int $uid ): int {
		return isset( $GLOBALS['mailbox'][ $uid ] ) ? $uid : 0;
	}
	function imap_headerinfo( $connection, int $msgno ): object {
		$GLOBALS['imap_log'][]    = array( 'headers', $msgno );
		list( $subject, , $from ) = $GLOBALS['mailbox'][ $msgno ];
		list( $mailbox, $host )   = explode( '@', $from );
		return (object) array(
			'subject'    => $subject,
			'from'       => array(
				(object) array(
					'mailbox' => $mailbox,
					'host'    => $host,
				),
			),
			'date'       => '2026-10-13 10:00:00',
			'message_id' => '<' . $msgno . '@fixture.test>',
		);
	}
	function imap_utf8( string $value ): string {
		return $value;
	}
	function imap_fetch_overview( $connection, string $uid, int $flags ): array {
		return array( (object) array( 'size' => 100 ) );
	}
	function imap_fetchstructure( $connection, int $uid, int $flags ): object {
		return (object) array( 'encoding' => 0 );
	}
	function imap_fetchbody( $connection, int $uid, string $section, int $flags ): string {
		$GLOBALS['imap_log'][] = array( 'body', $uid, $flags );
		return $GLOBALS['mailbox'][ $uid ][1];
	}
}

namespace DataMachine\Core\Steps {
	trait HandlerRegistrationTrait {
	}
}

namespace DataMachine\Core\Steps\Fetch\Handlers {
	class FetchHandler {
	}
}

namespace {
	$root = dirname( __DIR__ );
	require_once $root . '/inc/Abilities/Fetch/Traits/HasApplyKeywordSearch.php';
	require_once $root . '/inc/Abilities/Fetch/Traits/HasApplyKeywordExclusion.php';
	require_once $root . '/inc/Abilities/Fetch/FetchEmailAbility.php';
	require_once $root . '/inc/Core/Steps/Fetch/Handlers/Email/Email.php';

	$failures = array();
	$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
		echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
		if ( ! $condition ) {
			$failures[] = $message;
		}
	};

	$ability   = ( new ReflectionClass( DataMachine\Abilities\Fetch\FetchEmailAbility::class ) )->newInstanceWithoutConstructor();
	$handler   = ( new ReflectionClass( DataMachine\Core\Steps\Fetch\Handlers\Email\Email::class ) )->newInstanceWithoutConstructor();
	$call      = static function ( object $target, string $method, mixed ...$args ): mixed {
		$reflection = new ReflectionMethod( $target, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $target, $args );
	};
	$select    = static function ( array $config, int $offset, int $max ) use ( $ability ): array {
		$GLOBALS['imap_log'] = array();
		$logs                = array();
		$uids                = array( 106, 105, 104, 103, 102, 101 );
		$reflection          = new ReflectionMethod( $ability, 'selectUids' );
		$reflection->setAccessible( true );
		$result         = $reflection->invokeArgs( $ability, array( null, $uids, $config, $offset, $max, &$logs ) );
		$result['logs'] = $logs;
		return $result;
	};
	$count_ops = static function ( string $op ): int {
		return count( array_filter( $GLOBALS['imap_log'], static fn( $entry ) => $op === $entry[0] ) );
	};

	// No keywords: plain pagination, nothing fetched during selection.
	$plain = $select( array(
		'search'           => '',
		'exclude_keywords' => '',
	), 0, 2 );
	$assert( array( 106, 105 ) === $plain['uids'], 'without keywords the newest page is selected' );
	$assert( 6 === $plain['total_matches'] && true === $plain['has_more'], 'without keywords totals and has_more are exact' );
	$assert( 0 === $count_ops( 'headers' ) && 0 === $count_ops( 'body' ), 'without keywords selection fetches no headers or bodies' );

	// Include keywords: subject or body matches, filtered before the cap.
	$lineup = $select( array(
		'search'           => 'lineup',
		'exclude_keywords' => '',
	), 0, 10 );
	$assert( array( 106, 104, 103, 102 ) === $lineup['uids'], 'include keywords match subject or body text' );
	$assert( 3 === $count_ops( 'body' ), 'bodies are only read when the subject does not match' );
	foreach ( $GLOBALS['imap_log'] as $entry ) {
		if ( 'body' === $entry[0] && 0 === ( $entry[2] & FT_PEEK ) ) {
			$assert( false, 'keyword body reads must use FT_PEEK' );
		}
	}

	// Exclude keywords on top of include keywords.
	$festivals = $select( array(
		'search'           => 'lineup, festival',
		'exclude_keywords' => 'WordCamp',
	), 0, 10 );
	$assert( array( 106, 104, 102 ) === $festivals['uids'], 'exclude keywords drop matching messages' );

	$no_invoices = $select( array(
		'search'           => '',
		'exclude_keywords' => 'Friday',
	), 0, 10 );
	$assert( ! in_array( 105, $no_invoices['uids'], true ), 'exclude keywords also check body text' );

	// Matches are not starved by the cap: one match per page, newest first.
	$page_one = $select( array(
		'search'           => 'lineup',
		'exclude_keywords' => '',
	), 0, 1 );
	$assert( array( 106 ) === $page_one['uids'] && true === $page_one['has_more'], 'page one returns the newest match' );
	$assert( 1 === $count_ops( 'headers' ), 'scan stops as soon as the page is filled' );
	$page_two = $select( array(
		'search'           => 'lineup',
		'exclude_keywords' => '',
	), 1, 1 );
	$assert( array( 104 ) === $page_two['uids'], 'offset skips earlier matches, not earlier messages' );

	// Scan limit is bounded and logged.
	add_filter( 'datamachine_email_keyword_scan_limit', static fn() => 2 );
	$limited = $select( array(
		'search'           => 'Rooster',
		'exclude_keywords' => '',
	), 0, 5 );
	$assert( array() === $limited['uids'] && true === $limited['has_more'], 'scan limit stops early and reports has_more' );
	$assert( 2 === $count_ops( 'headers' ), 'scan limit caps candidates read' );
	$assert( 1 === count( $limited['logs'] ) && 'warning' === $limited['logs'][0]['level'], 'hitting the scan limit logs a warning' );
	unset( $GLOBALS['filters']['datamachine_email_keyword_scan_limit'] );

	// Multiple criteria: merged, deduped, newest first.
	$GLOBALS['search_results'] = array(
		'A' => array( 101, 104 ),
		'B' => array( 104, 106, 102 ),
		'C' => false,
	);
	$merged                    = $call( $ability, 'searchUids', null, array( 'A', 'B', 'C' ) );
	$assert( array( 106, 104, 102, 101 ) === $merged, 'OR searches merge, dedupe and sort newest first' );
	$single = $call( $ability, 'searchUids', null, 'A' );
	$assert( array( 104, 101 ) === $single, 'a single criteria string still works' );

	// Handler criteria building.
	$criteria = $call( $handler, 'buildSearchCriteria', array(
		'search_criteria' => 'ALL',
		'timeframe_limit' => 'all_time',
	) );
	$assert( 'ALL' === $criteria, 'all_time adds no SINCE' );

	$criteria = $call( $handler, 'buildSearchCriteria', array(
		'search_criteria' => 'ALL',
		'timeframe_limit' => '72_hours',
	) );
	$assert( 'ALL SINCE "' . gmdate( 'j-M-Y', strtotime( '-72 hours' ) ) . '"' === $criteria, '72_hours maps to a SINCE date through the shared filter' );

	$GLOBALS['logged'] = array();
	$criteria          = $call( $handler, 'buildSearchCriteria', array(
		'search_criteria' => 'ALL',
		'timeframe_limit' => '90_days',
	) );
	$assert( 'ALL' === $criteria, 'unknown timeframe adds no SINCE' );
	$assert( 1 === count( $GLOBALS['logged'] ) && 'warning' === $GLOBALS['logged'][0][1][0], 'unknown timeframe logs a warning' );

	$criteria = $call(
		$handler,
		'buildSearchCriteria',
		array(
			'search_criteria' => 'ALL',
			'from_filter'     => 'sue@thesyn.test, chad@pitchperfect.test',
			'subject_filter'  => 'lineup,festival',
			'timeframe_limit' => '7_days',
		)
	);
	$since    = ' SINCE "' . gmdate( 'j-M-Y', strtotime( '-7 days' ) ) . '"';
	$assert(
		array(
			'ALL FROM "sue@thesyn.test" SUBJECT "lineup"' . $since,
			'ALL FROM "sue@thesyn.test" SUBJECT "festival"' . $since,
			'ALL FROM "chad@pitchperfect.test" SUBJECT "lineup"' . $since,
			'ALL FROM "chad@pitchperfect.test" SUBJECT "festival"' . $since,
		) === $criteria,
		'comma-separated sender and subject filters expand to one search per combination'
	);

	$criteria = $call( $handler, 'buildSearchCriteria', array(
		'search_criteria' => 'UNSEEN',
		'subject_filter'  => 'say "hi"',
	) );
	$assert( 'UNSEEN SUBJECT "say \"hi\""' === $criteria, 'single subject stays a string and quotes are escaped' );

	if ( $failures ) {
		fwrite( STDERR, count( $failures ) . " failure(s)\n" );
		exit( 1 );
	}
	echo "All email fetch filter checks passed.\n";
}
