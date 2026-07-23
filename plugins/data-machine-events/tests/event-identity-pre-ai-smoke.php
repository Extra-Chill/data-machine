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

	function do_action(): void {}
}

namespace DataMachine\Core\Similarity {
	class SimilarityEngine {
		public static function normalizeBasic( string $value ): string {
			$value = strtolower( trim( $value ) );
			return (string) preg_replace( '/^(the|a|an)\s+/', '', $value );
		}
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

		public static function check( array $input ): ?array {
			self::$last_input = $input;
			return null;
		}
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

	printf( "%d passed, %d failed\n", $passed, $failed );
	exit( $failed > 0 ? 1 : 0 );
}
