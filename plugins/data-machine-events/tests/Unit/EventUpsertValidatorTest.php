<?php
/**
 * EventUpsertValidator Tests
 *
 * Direct unit tests for the pre-publish validation gate collaborator
 * extracted from EventUpsert in #425.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\Upsert\Events\EventUpsertValidator;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use DataMachineEvents\Steps\EventImport\JunkPayloadFilter;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;

class EventUpsertValidatorTest extends WP_UnitTestCase {

	private EventUpsertValidator $validator;

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( 'data_machine_events' ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}

		// Inject EventUpsert::class so rejection `tool_name` matches the
		// value the handler emitted before extraction.
		$this->validator = new EventUpsertValidator( new JunkPayloadFilter(), EventUpsert::class );
	}

	public function test_validator_instantiation() {
		$this->assertInstanceOf( EventUpsertValidator::class, $this->validator );
	}

	public function test_is_junk_title_refuses_rejected_prefix() {
		$this->assertTrue( $this->validator->isJunkTitle( 'Rejected: 2026 Premium Season Tickets' ) );
		$this->assertTrue( $this->validator->isJunkTitle( '   rejected: parking pass' ) );
	}

	public function test_is_junk_title_refuses_dedup_markers() {
		$this->assertTrue( $this->validator->isJunkTitle( 'Duplicate: ÉLA-Vated Saturdays (merged)' ) );
		$this->assertTrue( $this->validator->isJunkTitle( 'Kev G Mor (duplicate)' ) );
		$this->assertTrue( $this->validator->isJunkTitle( 'Some Event — See Canonical Listing' ) );
	}

	public function test_is_junk_title_allows_normal_title() {
		$this->assertFalse( $this->validator->isJunkTitle( 'Eggy at Charleston Pour House' ) );
		$this->assertFalse( $this->validator->isJunkTitle( 'Duplicate Minds at The Sinkhole' ) );
	}

	public function test_datetime_confidence_none_without_start_date() {
		$engine = new \DataMachine\Core\EngineData( array(), 0 );
		$this->assertSame( 'none', $this->validator->getDateTimeConfidence( array( 'title' => 'Weak Event' ), $engine ) );
	}

	public function test_datetime_confidence_date_only_without_start_time() {
		$engine = new \DataMachine\Core\EngineData( array(), 0 );
		$this->assertSame( 'date_only', $this->validator->getDateTimeConfidence( array( 'startDate' => '2026-03-20' ), $engine ) );
	}

	public function test_datetime_confidence_full_with_date_and_time() {
		$engine = new \DataMachine\Core\EngineData( array(), 0 );
		$this->assertSame( 'full', $this->validator->getDateTimeConfidence( array( 'startDate' => '2026-07-05', 'startTime' => '20:00' ), $engine ) );
	}

	public function test_gate_passes_valid_event() {
		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$result = $this->validator->validateForPublish(
			array(
				'title'       => 'Eggy at Charleston Pour House',
				'venue'       => 'Charleston Pour House',
				'startDate'   => '2026-08-01',
				'startTime'   => '20:00',
				'source_type' => '',
				'artist'      => 'Eggy',
			),
			array(
				'title'     => 'Eggy at Charleston Pour House',
				'startDate' => '2026-08-01',
				'startTime' => '20:00',
			),
			$engine
		);

		$this->assertNull( $result, 'A valid event must pass the validation gate.' );
	}

	public function test_gate_rejects_missing_title() {
		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$result = $this->validator->validateForPublish(
			array(
				'title'       => '',
				'venue'       => 'Some Venue',
				'startDate'   => '2026-08-01',
				'startTime'   => '',
				'source_type' => '',
				'artist'      => '',
			),
			array( 'startDate' => '2026-08-01' ),
			$engine
		);

		$this->assertFalse( $result['success'] ?? null );
		$this->assertSame( 'missing_title', $result['rule'] ?? '' );
	}

	public function test_gate_rejects_junk_marker_title() {
		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$result = $this->validator->validateForPublish(
			array(
				'title'       => 'Rejected: Parking Pass',
				'venue'       => 'Some Venue',
				'startDate'   => '2026-08-01',
				'startTime'   => '20:00',
				'source_type' => '',
				'artist'      => '',
			),
			array( 'title' => 'Rejected: Parking Pass', 'startDate' => '2026-08-01', 'startTime' => '20:00' ),
			$engine
		);

		$this->assertFalse( $result['success'] ?? null );
		$this->assertSame( 'junk_marker_title', $result['rule'] ?? '' );
	}

	public function test_gate_rejects_placeholder_title() {
		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$result = $this->validator->validateForPublish(
			array(
				'title'       => 'Test Event',
				'venue'       => 'Some Venue',
				'startDate'   => '2026-08-01',
				'startTime'   => '20:00',
				'source_type' => '',
				'artist'      => '',
			),
			array( 'title' => 'Test Event', 'startDate' => '2026-08-01', 'startTime' => '20:00' ),
			$engine
		);

		$this->assertFalse( $result['success'] ?? null );
		$this->assertSame( 'placeholder_title', $result['rule'] ?? '' );
	}

	public function test_gate_rejects_invalid_start_date() {
		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$result = $this->validator->validateForPublish(
			array(
				'title'       => 'Bad Date Event',
				'venue'       => 'Some Venue',
				'startDate'   => '',
				'startTime'   => '',
				'source_type' => '',
				'artist'      => '',
			),
			array( 'title' => 'Bad Date Event' ),
			$engine
		);

		$this->assertFalse( $result['success'] ?? null );
		$this->assertSame( 'invalid_start_date', $result['rule'] ?? '' );
		$this->assertStringContainsString( 'startDate', $result['error'] ?? '' );
	}

	/**
	 * The rejection response must carry the injected tool class as
	 * `tool_name`, preserving the exact value EventUpsert emitted before
	 * extraction (it used `static::class`).
	 */
	public function test_gate_rejection_preserves_tool_name() {
		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$result = $this->validator->validateForPublish(
			array(
				'title'       => '',
				'venue'       => '',
				'startDate'   => '',
				'startTime'   => '',
				'source_type' => '',
				'artist'      => '',
			),
			array(),
			$engine
		);

		$this->assertIsArray( $result );
		$this->assertSame( EventUpsert::class, $result['tool_name'] ?? '' );
	}

	/**
	 * The junk-payload filter runs at the gate for every source, not only
	 * inside the Ticketmaster handler.
	 */
	public function test_gate_rejects_junk_payload_from_non_ticketmaster_source() {
		$callback = function ( array $patterns, string $source_type ): array {
			if ( 'dice' !== $source_type ) {
				return $patterns;
			}
			$patterns['title'][] = 'QA Sandbox';

			return $patterns;
		};
		add_filter( 'data_machine_events_junk_payload_patterns', $callback, 10, 2 );

		$engine = new \DataMachine\Core\EngineData( array( 'source_type' => 'dice' ), 0 );

		$result = $this->validator->validateForPublish(
			array(
				'title'       => 'QA Sandbox Preview Night',
				'venue'       => 'Dice Venue',
				'startDate'   => '2026-09-01',
				'startTime'   => '21:00',
				'source_type' => 'dice',
				'artist'      => '',
			),
			array(
				'title'     => 'QA Sandbox Preview Night',
				'startDate' => '2026-09-01',
				'startTime' => '21:00',
			),
			$engine
		);

		remove_filter( 'data_machine_events_junk_payload_patterns', $callback, 10 );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] ?? null );
		$this->assertSame( 'junk_payload', $result['rule'] ?? '' );
	}
}
