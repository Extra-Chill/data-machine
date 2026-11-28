<?php
/**
 * EntityTitleRepairAbilities tests.
 *
 * Covers the dry-run/execute contract for decoding entity-bearing event
 * titles (issue #844): dry run must be a no-op, execute must store the
 * decoded title without re-encoding, decoded rows are untouched, and
 * title-derived dedup hashes are verified stable rather than blindly
 * rewritten.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.65.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\EntityTitleRepairAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use WP_UnitTestCase;

class EntityTitleRepairAbilitiesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		EventDatesTable::create_table();
	}

	private function insert_event( string $title ): int {
		// Seed through the same bounded-write suspension the repair itself
		// uses, so the stored title is exactly the requested one regardless of
		// whether kses filters are active in the test context.
		$post_id = \DataMachineEvents\Core\TextNormalization::with_kses_suspended(
			static fn(): int => (int) wp_insert_post(
				array(
					'post_title'   => $title,
					'post_type'    => Event_Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:data-machine-events/event-details ' . wp_json_encode(
						array(
							'startDate' => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
							'startTime' => '20:00',
							'venue'     => 'Repair Test Venue',
						)
					) . ' /-->',
				)
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	public function test_dry_run_reports_changes_without_mutating_anything(): void {
		$post_id = $this->insert_event( 'Jordan Igoe &amp; Friends' );
		$before  = get_post( $post_id )->post_title;

		$result = ( new EntityTitleRepairAbilities() )->executeRepair(
			array(
				'dry_run' => true,
				'scope'   => 'all',
			)
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertContains( $post_id, array_column( $result['changes'], 'post_id' ) );

		$change = $result['changes'][ array_search( $post_id, array_column( $result['changes'], 'post_id' ), true ) ];
		$this->assertSame( 'Jordan Igoe &amp; Friends', $change['old_title'] );
		$this->assertSame( 'Jordan Igoe & Friends', $change['new_title'] );
		$this->assertSame( $before, get_post( $post_id )->post_title, 'Dry run must not modify the stored title.' );
	}

	public function test_execute_decodes_stored_title(): void {
		$post_id = $this->insert_event( 'Move Wellness - Power Pilates &amp; Matcha' );

		$result = ( new EntityTitleRepairAbilities() )->executeRepair(
			array(
				'dry_run' => false,
				'scope'   => 'all',
			)
		);

		$this->assertFalse( $result['dry_run'] );
		$this->assertContains( $post_id, array_column( $result['changes'], 'post_id' ) );
		$this->assertSame( 'Move Wellness - Power Pilates & Matcha', get_post( $post_id )->post_title );
		$this->assertSame( 0, $result['identity_hash_drift'], 'Hash of the encoded and decoded titles must be identical.' );
	}

	public function test_execute_decodes_numeric_entities_and_dashes(): void {
		$post_id = $this->insert_event( 'Sam&#8217;s Grill &#8211; Live &#038; Local' );

		( new EntityTitleRepairAbilities() )->executeRepair(
			array(
				'dry_run' => false,
				'scope'   => 'all',
			)
		);

		$this->assertSame( 'Sam’s Grill – Live & Local', get_post( $post_id )->post_title );
	}

	public function test_decoded_titles_are_left_alone(): void {
		$post_id = $this->insert_event( 'Power Pilates & Matcha — Live' );

		$result = ( new EntityTitleRepairAbilities() )->executeRepair(
			array(
				'dry_run' => false,
				'scope'   => 'all',
			)
		);

		$this->assertNotContains( $post_id, array_column( $result['changes'], 'post_id' ) );
		$this->assertSame( 'Power Pilates & Matcha — Live', get_post( $post_id )->post_title );
	}

	public function test_active_kses_filter_cannot_reencode_repaired_title(): void {
		$post_id = $this->insert_event( 'Jordan Igoe &amp; Friends' );

		add_filter( 'title_save_pre', 'wp_filter_kses' );

		try {
			( new EntityTitleRepairAbilities() )->executeRepair(
				array(
					'dry_run' => false,
					'scope'   => 'all',
				)
			);
		} finally {
			remove_filter( 'title_save_pre', 'wp_filter_kses' );
		}

		$this->assertSame( 'Jordan Igoe & Friends', get_post( $post_id )->post_title );
	}

	public function test_change_records_report_hash_stability(): void {
		$post_id = $this->insert_event( 'The &quot;Quiet&quot; Show' );

		$result = ( new EntityTitleRepairAbilities() )->executeRepair(
			array(
				'dry_run' => false,
				'scope'   => 'all',
			)
		);

		$change = $result['changes'][ array_search( $post_id, array_column( $result['changes'], 'post_id' ), true ) ];
		$this->assertTrue( $change['hash_stable'], 'Every repair should verify title-hash stability.' );
	}
}
