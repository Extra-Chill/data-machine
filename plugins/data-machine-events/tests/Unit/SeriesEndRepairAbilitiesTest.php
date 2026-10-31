<?php
/**
 * SeriesEndRepairAbilities tests.
 *
 * Covers the dry-run/execute contract for stripping fabricated series-range
 * ends (issue #199): dry run must be a no-op, execute must strip the
 * endDate/endTime block attributes and clear the table end, and rows with
 * explicit occurrenceDates must be left alone.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.64.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\SeriesEndRepairAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use WP_UnitTestCase;

class SeriesEndRepairAbilitiesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		EventDatesTable::create_table();
	}

	private function insert_leaked_event(): int {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Leaked Series End ' . uniqid(),
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details ' . wp_json_encode(
					array(
						'startDate' => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
						'startTime' => '16:00',
						'endDate'   => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
						'endTime'   => '21:00',
						'venue'     => 'Capital Hotel',
					)
				) . ' /-->',
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	private function insert_occurrence_envelope_event(): int {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Explicit Occurrences ' . uniqid(),
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details ' . wp_json_encode(
					array(
						'startDate'       => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
						'startTime'       => '16:00',
						'endDate'         => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
						'endTime'         => '21:00',
						'occurrenceDates' => array(
							gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
							gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
						),
						'venue'           => 'Lo-Fi Brewing',
					)
				) . ' /-->',
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	public function test_dry_run_reports_changes_without_mutating_anything(): void {
		$post_id    = $this->insert_leaked_event();
		$before     = get_post( $post_id )->post_content;
		$before_row = EventDatesTable::get( $post_id );

		$result = ( new SeriesEndRepairAbilities() )->executeRepair(
			array(
				'dry_run' => true,
				'scope'   => 'upcoming',
			)
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertContains( $post_id, array_column( $result['changes'], 'post_id' ) );

		$after = get_post( $post_id )->post_content;
		$this->assertSame( $before, $after );
		$this->assertSame( $before_row ? $before_row->end_datetime : null, EventDatesTable::get( $post_id )->end_datetime );
	}

	public function test_execute_strips_fabricated_end_from_block_and_table(): void {
		$post_id = $this->insert_leaked_event();

		$result = ( new SeriesEndRepairAbilities() )->executeRepair(
			array(
				'dry_run' => false,
				'scope'   => 'upcoming',
			)
		);

		$ids = array_column( $result['changes'], 'post_id' );
		$this->assertContains( $post_id, $ids );

		$content = get_post( $post_id )->post_content;
		$this->assertStringNotContainsString( 'endDate', $content );
		$this->assertStringNotContainsString( 'endTime', $content );
		$this->assertStringContainsString( 'startDate', $content );

		// save_post re-derived the row from the stripped block: no end left.
		$stored = EventDatesTable::get( $post_id );
		$this->assertNotNull( $stored );
		$this->assertNull( $stored->end_datetime );
		$this->assertNotNull( $stored->start_datetime );
	}

	public function test_execute_leaves_occurrence_envelope_rows_alone(): void {
		$post_id = $this->insert_occurrence_envelope_event();
		$before  = get_post( $post_id )->post_content;

		( new SeriesEndRepairAbilities() )->executeRepair(
			array(
				'dry_run' => false,
				'scope'   => 'upcoming',
			)
		);

		$this->assertSame( $before, get_post( $post_id )->post_content );
	}

	public function test_threshold_is_respected(): void {
		$post_id = $this->insert_leaked_event();

		$result = ( new SeriesEndRepairAbilities() )->executeRepair(
			array(
				'dry_run'        => false,
				'scope'          => 'upcoming',
				'max_span_hours' => 100000,
			)
		);

		$this->assertNotContains( $post_id, array_column( $result['changes'], 'post_id' ) );
		$this->assertStringContainsString( 'endDate', get_post( $post_id )->post_content );
	}
}
