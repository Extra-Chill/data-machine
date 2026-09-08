<?php
/**
 * Deterministic event identity and pre-AI composition smoke test.
 *
 * Self-contained: no WordPress runtime, database, PHPUnit, or Codebox.
 *
 * Run directly:
 *   php tests/event-identity-pre-ai-smoke.php
 *
 * @package DataMachineEvents\Tests
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DATA_MACHINE_EVENTS_POST_TYPE', 'data_machine_events' );

	function do_action(): void {}

	function wp_get_ability( string $name ): ?object {
		if ( 'datamachine/check-duplicate' !== $name ) {
			return null;
		}

		return new class() {
			public function execute( array $input ): array {
				return \DataMachineEvents\Core\DuplicateDetection\EventDuplicateStrategy::check( $input )
					?? array( 'verdict' => 'clear' );
			}
		};
	}

	function wp_has_ability( string $name ): bool {
		return 'datamachine/check-duplicate' === $name;
	}
}

namespace DataMachine\Core {
	class EngineData {
		public function __construct( private array $data ) {}

		public function get( string $key ): mixed {
			return $this->data[ $key ] ?? null;
		}
	}

	class JobStatus {
		public const COMPLETED_NO_ITEMS = 'completed_no_items';
	}
}

namespace DataMachineEvents\Core\DuplicateDetection {
	class EventDuplicateStrategy {
		public static array $last_input = array();
		public static ?array $duplicate = null;

		public static function check( array $input ): ?array {
			self::$last_input = $input;
			return self::$duplicate;
		}
	}
}

namespace DataMachineEvents\Core {
	class EventDatesTable {
		public static array $rows = array();

		public static function get( int $post_id ): ?object {
			return self::$rows[ $post_id ] ?? null;
		}
	}
}

namespace {
	class WP_Post {
		public string $post_title;

		public function __construct( string $post_title ) {
			$this->post_title = $post_title;
		}
	}

	$GLOBALS['smoke_posts'] = array();

	function get_post( int $post_id ): ?WP_Post {
		return $GLOBALS['smoke_posts'][ $post_id ] ?? null;
	}

	function smoke_seed_post( int $post_id, string $title, string $start_datetime ): void {
		$GLOBALS['smoke_posts'][ $post_id ]                     = new WP_Post( $title );
		\DataMachineEvents\Core\EventDatesTable::$rows[ $post_id ] = (object) array(
			'start_datetime' => $start_datetime,
		);
	}
}

namespace DataMachineEvents\Core {
	class Event_Post_Type {
		public const POST_TYPE = \DATA_MACHINE_EVENTS_POST_TYPE;
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Utilities/EventIdentifierGenerator.php';
	require_once dirname( __DIR__ ) . '/inc/Core/DuplicateDetection/PreAIEventDedupGate.php';

	use DataMachine\Core\EngineData;
	use DataMachineEvents\Core\DuplicateDetection\EventDuplicateStrategy;
	use DataMachineEvents\Core\DuplicateDetection\PreAIEventDedupGate;
	use DataMachineEvents\Utilities\EventIdentifierGenerator;

	$passed = 0;
	$failed = 0;

	function identity_check( string $label, bool $condition ): void {
		global $passed, $failed;
		if ( $condition ) {
			++$passed;
			return;
		}

		++$failed;
		fwrite( STDERR, "FAIL: {$label}\n" );
	}

	$early = EventIdentifierGenerator::generate( 'Showcase', '2026-05-22', 'Exact Venue', '13:30', 'America/New_York' );
	$late  = EventIdentifierGenerator::generate( 'Showcase', '2026-05-22', 'Exact Venue', '21:30', 'America/New_York' );
	identity_check( 'different local times produce distinct source identities', $early !== $late );

	$local = EventIdentifierGenerator::generate( 'Showcase', '2026-05-22', 'Exact Venue', '13:30', 'America/New_York' );
	$utc   = EventIdentifierGenerator::generate( 'Showcase', '2026-05-22T17:30:00Z', 'Exact Venue', '', 'America/New_York' );
	identity_check( 'equivalent timezone representations normalize identically', $local === $utc );

	$engine = new EngineData(
		array(
			'title'         => 'Showcase',
			'venue'         => 'Exact Venue',
			'startDate'     => '2026-05-22',
			'startTime'     => '21:30',
			'venueTimezone' => 'America/New_York',
			'flow_config'   => array(
				'upsert' => array( 'handler_slugs' => array( 'upsert_event' ) ),
			),
		)
	);
	PreAIEventDedupGate::check( null, $engine, array(), 219 );
	identity_check(
		'pre-AI gate composes split local datetime',
		'2026-05-22 21:30' === ( EventDuplicateStrategy::$last_input['context']['startDate'] ?? '' )
	);

	$date_only_engine = new EngineData(
		array(
			'title'       => 'All Day Showcase',
			'venue'       => 'Exact Venue',
			'startDate'   => '2026-05-23',
			'flow_config' => array(
				'upsert' => array( 'handler_slugs' => array( 'upsert_event' ) ),
			),
		)
	);
	PreAIEventDedupGate::check( null, $date_only_engine, array(), 220 );
	identity_check(
		'pre-AI gate preserves genuine date-only identity',
		'2026-05-23' === ( EventDuplicateStrategy::$last_input['context']['startDate'] ?? '' )
	);

	// -----------------------------------------------------------------
	// #796: changed-revision pass-through.
	// -----------------------------------------------------------------

	$duplicate_shape = static fn( int $post_id, string $post_title ): array => array(
		'verdict'  => 'duplicate',
		'source'   => 'event_dates',
		'match'    => array(
			'post_id' => $post_id,
			'title'   => $post_title,
			'url'     => 'https://events.example.com/existing',
		),
		'reason'   => sprintf( 'Matched existing event "%1$s" (ID %2$d) via venue_date_fuzzy_title.', $post_title, $post_id ),
		// DuplicateCheckAbility overwrites this field with the registered
		// strategy id, which is what the gate matches on.
		'strategy' => 'event_identity_index',
	);

	$override_engine = new EngineData(
		array(
			'title'         => 'Burgundy: Extra Chill Wednesdays ft. Chris Wilcox',
			'venue'         => 'The Starlight Motor Inn',
			'startDate'     => '2026-09-09',
			'startTime'     => '21:00',
			'venueTimezone' => 'America/New_York',
			'flow_config'   => array(
				'upsert' => array( 'handler_slugs' => array( 'upsert_event' ) ),
			),
		)
	);

	smoke_seed_post( 236692, 'Burgundy: Extra Chill Wednesdays', '2026-09-09 20:00:00' );
	EventDuplicateStrategy::$duplicate = $duplicate_shape( 236692, 'Burgundy: Extra Chill Wednesdays' );

	identity_check(
		'pre-AI gate proceeds when the edited occurrence title differs from the matched post',
		null === PreAIEventDedupGate::check( null, $override_engine, array(), 994789 )
	);

	$shift_engine = new EngineData(
		array(
			'title'         => 'Burgundy: Extra Chill Wednesdays',
			'venue'         => 'The Starlight Motor Inn',
			'startDate'     => '2026-09-16',
			'startTime'     => '21:00',
			'venueTimezone' => 'America/New_York',
			'flow_config'   => array(
				'upsert' => array( 'handler_slugs' => array( 'upsert_event' ) ),
			),
		)
	);

	smoke_seed_post( 236700, 'Burgundy: Extra Chill Wednesdays', '2026-09-16 20:00:00' );

	identity_check(
		'pre-AI gate proceeds when the series start time shifts within the fuzzy window',
		null === PreAIEventDedupGate::check( null, $shift_engine, array(), 994790 )
	);

	smoke_seed_post( 236725, 'Burgundy: Extra Chill Wednesdays', '2026-09-23 21:00:00' );
	EventDuplicateStrategy::$duplicate = $duplicate_shape( 236725, 'Burgundy: Extra Chill Wednesdays' );

	$unchanged = PreAIEventDedupGate::check(
		null,
		new EngineData(
			array(
				'title'         => 'Burgundy: Extra Chill Wednesdays',
				'venue'         => 'The Starlight Motor Inn',
				'startDate'     => '2026-09-23',
				'startTime'     => '21:00',
				'venueTimezone' => 'America/New_York',
				'flow_config'   => array(
					'upsert' => array( 'handler_slugs' => array( 'upsert_event' ) ),
				),
			)
		),
		array(),
		994791
	);

	identity_check(
		'pre-AI gate still skips an unchanged duplicate',
		is_array( $unchanged )
			&& true === ( $unchanged['skip'] ?? null )
			&& 'completed_no_items' === ( $unchanged['status'] ?? '' )
			&& str_contains( (string) ( $unchanged['reason'] ?? '' ), '236725' )
	);

	EventDuplicateStrategy::$duplicate = null;
	$GLOBALS['smoke_posts']            = array();
	\DataMachineEvents\Core\EventDatesTable::$rows = array();

	printf( "%d passed, %d failed\n", $passed, $failed );
	exit( $failed > 0 ? 1 : 0 );
}
