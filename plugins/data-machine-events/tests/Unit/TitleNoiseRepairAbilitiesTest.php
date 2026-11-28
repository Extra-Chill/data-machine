<?php
/**
 * TitleNoiseRepairAbilities tests.
 *
 * Dry run must be a no-op, execute must store the clean title, clean titles
 * are untouched, and structured fields are reported but never written
 * (issue #932).
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.68.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\TitleNoiseRepairAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use WP_UnitTestCase;

class TitleNoiseRepairAbilitiesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		EventDatesTable::create_table();
	}

	private function insert_event( string $title ): int {
		$post_id = \DataMachineEvents\Core\TextNormalization::with_kses_suspended(
			static fn(): int => (int) wp_insert_post(
				array(
					'post_title'   => $title,
					'post_type'    => Event_Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:data-machine-events/event-details ' . wp_json_encode(
						array(
							'startDate' => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
							'startTime' => '18:00',
							'venue'     => 'Title Repair Venue',
						)
					) . ' /-->',
				)
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	/**
	 * Change row for a post, or null.
	 *
	 * @param array $result  Repair result.
	 * @param int   $post_id Event post ID.
	 * @return array|null
	 */
	private function change_for( array $result, int $post_id ): ?array {
		foreach ( $result['changes'] as $change ) {
			if ( $post_id === $change['post_id'] ) {
				return $change;
			}
		}

		return null;
	}

	public function test_dry_run_reports_without_mutating(): void {
		$post_id = $this->insert_event( 'Gracious Day 3-6pm' );

		$result = ( new TitleNoiseRepairAbilities() )->executeRepair( array( 'scope' => 'upcoming' ) );

		$this->assertTrue( $result['dry_run'] );
		$change = $this->change_for( $result, $post_id );
		$this->assertNotNull( $change );
		$this->assertSame( 'Gracious Day', $change['new_title'] );
		$this->assertSame( '18:00', $change['end_time'] );
		$this->assertSame( 'Gracious Day 3-6pm', get_post( $post_id )->post_title );
	}

	public function test_execute_cleans_title_and_leaves_block_alone(): void {
		$post_id = $this->insert_event( 'Lemon Squares. Door @ 8. Show @ 9. $5 21+' );
		$content = (string) get_post( $post_id )->post_content;

		$result = ( new TitleNoiseRepairAbilities() )->executeRepair(
			array(
				'dry_run' => false,
				'scope'   => 'upcoming',
			)
		);

		$this->assertFalse( $result['dry_run'] );
		$this->assertSame( '$5', $this->change_for( $result, $post_id )['price'] ?? '' );
		$this->assertSame( 'Lemon Squares 21+', get_post( $post_id )->post_title );
		$this->assertSame( $content, (string) get_post( $post_id )->post_content );
	}

	public function test_clean_titles_are_not_reported(): void {
		$post_id = $this->insert_event( 'Blink-182' );

		$result = ( new TitleNoiseRepairAbilities() )->executeRepair( array( 'scope' => 'upcoming' ) );

		$this->assertNull( $this->change_for( $result, $post_id ) );
	}
}
