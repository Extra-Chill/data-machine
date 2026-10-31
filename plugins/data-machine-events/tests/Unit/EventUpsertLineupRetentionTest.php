<?php
/**
 * Regression coverage for cumulative event lineups on re-import.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\Event_Post_Type;

require_once __DIR__ . '/EventUpsertTestCase.php';

/** Tests that re-imports retain accumulated event terms. */
class EventUpsertLineupRetentionTest extends EventUpsertTestCase {

	/** Register the lineup taxonomies used by these tests. */
	public function setUp(): void {
		parent::setUp();
		foreach ( array( 'artist', 'festival' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, Event_Post_Type::POST_TYPE );
			}
		}
	}

	/**
	 * Build a canonical festival import.
	 *
	 * @return array Event parameters.
	 */
	private function event_parameters(): array {
		return array(
			'title'       => 'Lineup Retention Festival',
			'startDate'   => '2027-10-31',
			'startTime'   => '13:00',
			'venue'       => 'Lineup Retention Venue',
			'performer'   => 'Original Artist',
			'festival'    => 'Original Festival',
			'description' => 'Festival description',
		);
	}

	/**
	 * Use extracted terms rather than fixed pipeline selections.
	 *
	 * @return array Handler configuration.
	 */
	private function taxonomy_config(): array {
		return array(
			'taxonomy_artist_selection'   => 'ai_decides',
			'taxonomy_festival_selection' => 'ai_decides',
		);
	}

	/** Smaller incoming lineups must not replace accumulated terms. */
	public function test_existing_event_keeps_lineup_and_adds_incoming_terms(): void {
		$parameters = $this->event_parameters();
		$created    = $this->invoke_upsert( $parameters, $this->taxonomy_config() );
		$post_id    = $created['data']['post_id'];
		wp_set_object_terms( $post_id, 'Manual Artist', 'artist', true );

		$parameters['event_id']    = $post_id;
		$parameters['performer']   = 'New Artist';
		$parameters['festival']    = 'New Festival';
		$parameters['description'] = 'Updated festival description';
		$result                    = $this->invoke_upsert( $parameters, $this->taxonomy_config() );

		$this->assertSame( $post_id, $result['data']['post_id'] );
		$this->assertSame( 'updated', $result['data']['action'] );
		$this->assertEqualsCanonicalizing( array( 'Original Artist', 'Manual Artist', 'New Artist' ), wp_get_post_terms( $post_id, 'artist', array( 'fields' => 'names' ) ) );
		$this->assertEqualsCanonicalizing( array( 'Original Festival', 'New Festival' ), wp_get_post_terms( $post_id, 'festival', array( 'fields' => 'names' ) ) );
	}

	/** Unchanged content still reconciles taxonomy without losing manual terms. */
	public function test_no_change_reimport_keeps_manually_added_lineup(): void {
		$parameters = $this->event_parameters();
		$created    = $this->invoke_upsert( $parameters, $this->taxonomy_config() );
		$post_id    = $created['data']['post_id'];
		wp_set_object_terms( $post_id, 'Manual Artist', 'artist', true );
		wp_set_object_terms( $post_id, 'Manual Festival', 'festival', true );

		$parameters['event_id'] = $post_id;
		$result                 = $this->invoke_upsert( $parameters, $this->taxonomy_config() );

		$this->assertSame( 'no_change', $result['data']['action'] );
		$this->assertEqualsCanonicalizing( array( 'Original Artist', 'Manual Artist' ), wp_get_post_terms( $post_id, 'artist', array( 'fields' => 'names' ) ) );
		$this->assertEqualsCanonicalizing( array( 'Original Festival', 'Manual Festival' ), wp_get_post_terms( $post_id, 'festival', array( 'fields' => 'names' ) ) );
	}

	/** Artist-feed additions must survive the festival's own source import. */
	public function test_festival_lineup_merge_survives_venue_reimport(): void {
		$parameters = $this->event_parameters();
		$created    = $this->invoke_upsert( $parameters, $this->taxonomy_config() );
		$post_id    = $created['data']['post_id'];
		$artist_set = array_merge(
			$parameters,
			array(
				'title'     => 'Tour Artist at Lineup Retention Festival',
				'venue'     => 'Lineup Retention Festival @ Lineup Retention Venue',
				'performer' => 'Tour Artist',
				'startTime' => '19:00',
			)
		);
		$merged     = $this->invoke_upsert( $artist_set, $this->taxonomy_config() );
		$this->assertSame( $post_id, $merged['data']['post_id'] );
		$this->assertEqualsCanonicalizing( array( 'Original Artist', 'Tour Artist' ), wp_get_post_terms( $post_id, 'artist', array( 'fields' => 'names' ) ) );

		$parameters['event_id'] = $post_id;
		$parameters['festival'] = 'Venue Festival Label';
		$result                 = $this->invoke_upsert( $parameters, $this->taxonomy_config() );

		$this->assertSame( $post_id, $result['data']['post_id'] );
		$this->assertEqualsCanonicalizing( array( 'Original Artist', 'Tour Artist' ), wp_get_post_terms( $post_id, 'artist', array( 'fields' => 'names' ) ) );
		$this->assertEqualsCanonicalizing( array( 'Original Festival', 'Lineup Retention Festival', 'Venue Festival Label' ), wp_get_post_terms( $post_id, 'festival', array( 'fields' => 'names' ) ) );
	}

	/** Fresh creates must not inherit unrelated artist terms. */
	public function test_new_event_gets_exactly_incoming_terms(): void {
		wp_insert_term( 'Unrelated Artist', 'artist' );
		$result  = $this->invoke_upsert( $this->event_parameters(), $this->taxonomy_config() );
		$post_id = $result['data']['post_id'];

		$this->assertSame( 'created', $result['data']['action'] );
		$this->assertSame( array( 'Original Artist' ), wp_get_post_terms( $post_id, 'artist', array( 'fields' => 'names' ) ) );
		$this->assertSame( array( 'Original Festival' ), wp_get_post_terms( $post_id, 'festival', array( 'fields' => 'names' ) ) );
	}
}
