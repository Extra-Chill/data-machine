<?php
/**
 * EventQualityAuditAbilities long-span rule tests.
 *
 * Covers the `long_span_no_occurrences` audit rule introduced for issue
 * #199: published upcoming occurrences whose block attrs span more than the
 * threshold with no occurrenceDates are listed for triage.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.64.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\EventQualityAuditAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use WP_UnitTestCase;

class EventQualityAuditLongSpanTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		EventDatesTable::create_table();
	}

	private function insert_event( array $attrs ): int {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Long Span Audit ' . uniqid(),
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details ' . wp_json_encode( $attrs ) . ' /-->',
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	public function test_long_span_without_occurrences_is_flagged(): void {
		$post_id = $this->insert_event(
			array(
				'startDate' => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
				'startTime' => '20:00',
				'endDate'   => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
				'endTime'   => '21:00',
				'venue'     => 'Capital Hotel',
			)
		);

		$result = ( new EventQualityAuditAbilities() )->executeAudit(
			array(
				'scope'          => 'upcoming',
				'days_ahead'     => 30,
				'issue'          => 'long_span_no_occurrences',
				'max_span_hours' => 48,
			)
		);

		$ids = array_column( $result['long_span_no_occurrences']['events'], 'id' );
		$this->assertContains( $post_id, $ids );
		$this->assertGreaterThan( 48, $result['long_span_no_occurrences']['events'][ array_search( $post_id, $ids, true ) ]['span_hours'] );
		$this->assertGreaterThan( 0, $result['long_span_no_occurrences']['count'] );
	}

	public function test_short_span_is_not_flagged(): void {
		$post_id = $this->insert_event(
			array(
				'startDate' => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
				'startTime' => '20:00',
				'endDate'   => gmdate( 'Y-m-d', strtotime( '+4 days' ) ),
				'endTime'   => '23:00',
				'venue'     => 'The Royal American',
			)
		);

		$result = ( new EventQualityAuditAbilities() )->executeAudit(
			array(
				'scope'          => 'upcoming',
				'days_ahead'     => 30,
				'issue'          => 'long_span_no_occurrences',
				'max_span_hours' => 48,
			)
		);

		$this->assertNotContains( $post_id, array_column( $result['long_span_no_occurrences']['events'], 'id' ) );
	}

	public function test_long_span_with_occurrence_dates_is_not_flagged(): void {
		$post_id = $this->insert_event(
			array(
				'startDate'       => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
				'startTime'       => '20:00',
				'endDate'         => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
				'endTime'         => '21:00',
				'occurrenceDates' => array(
					gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
					gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
				),
				'venue'           => 'Lo-Fi Brewing',
			)
		);

		$result = ( new EventQualityAuditAbilities() )->executeAudit(
			array(
				'scope'          => 'upcoming',
				'days_ahead'     => 30,
				'issue'          => 'long_span_no_occurrences',
				'max_span_hours' => 48,
			)
		);

		$this->assertNotContains( $post_id, array_column( $result['long_span_no_occurrences']['events'], 'id' ) );
	}

	public function test_threshold_input_is_respected(): void {
		$post_id = $this->insert_event(
			array(
				'startDate' => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
				'startTime' => '20:00',
				'endDate'   => gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
				'endTime'   => '22:00',
				'venue'     => 'Charleston Pour House',
			)
		);

		$run = static fn( int $hours ) => ( new EventQualityAuditAbilities() )->executeAudit(
			array(
				'scope'          => 'upcoming',
				'days_ahead'     => 30,
				'issue'          => 'long_span_no_occurrences',
				'max_span_hours' => $hours,
			)
		);

		// ~175-hour span: below a 200-hour threshold, above a 48-hour one.
		$this->assertNotContains( $post_id, array_column( $run( 200 )['long_span_no_occurrences']['events'], 'id' ) );
		$this->assertContains( $post_id, array_column( $run( 48 )['long_span_no_occurrences']['events'], 'id' ) );
	}
}
