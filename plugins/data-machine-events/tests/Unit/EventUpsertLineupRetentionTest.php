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
	/** A festival-day bill tags every artist even without an artist selection (#3934). */
	public function test_lineup_string_tags_every_artist_without_selection(): void {
		$parameters              = $this->event_parameters();
		$parameters['performer'] = "Fitz and the Tantrums | Plain White T's | Vundabar, Beach Weather | Hotel Fiction | Dani Rucker | Rohna";
		$result                  = $this->invoke_upsert( $parameters, array() );

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$this->assertEqualsCanonicalizing(
			array( 'Fitz and the Tantrums', "Plain White T's", 'Vundabar', 'Beach Weather', 'Hotel Fiction', 'Dani Rucker', 'Rohna' ),
			array_map( 'html_entity_decode', wp_get_post_terms( $result['data']['post_id'], 'artist', array( 'fields' => 'names' ) ) )
		);
	}

	/** An explicit skip selection still tags the payload lineup (#3934). */
	public function test_skip_selection_still_tags_lineup(): void {
		$parameters              = $this->event_parameters();
		$parameters['performer'] = 'Deerhoof, Sol, Iron Lung, J.R.C.G.';
		$result                  = $this->invoke_upsert( $parameters, array( 'taxonomy_artist_selection' => 'skip' ) );

		$this->assertEqualsCanonicalizing( array( 'Deerhoof', 'Sol', 'Iron Lung', 'J.R.C.G.' ), wp_get_post_terms( $result['data']['post_id'], 'artist', array( 'fields' => 'names' ) ) );
	}

	/** A pre-selected artist is merged with the payload lineup, not replacing it. */
	public function test_explicit_selection_is_merged_with_lineup(): void {
		$selected                = wp_insert_term( 'Pipeline Artist', 'artist' );
		$parameters              = $this->event_parameters();
		$parameters['performer'] = 'Lineup One | Lineup Two';
		$result                  = $this->invoke_upsert( $parameters, array( 'taxonomy_artist_selection' => (string) $selected['term_id'] ) );

		$this->assertEqualsCanonicalizing( array( 'Pipeline Artist', 'Lineup One', 'Lineup Two' ), wp_get_post_terms( $result['data']['post_id'], 'artist', array( 'fields' => 'names' ) ) );
	}

	/** Updates without an artist selection keep previously retained artists. */
	public function test_update_without_selection_keeps_retained_artists(): void {
		$parameters = $this->event_parameters();
		$created    = $this->invoke_upsert( $parameters, array() );
		$post_id    = $created['data']['post_id'];
		wp_set_object_terms( $post_id, 'Manual Artist', 'artist', true );

		$parameters['event_id']    = $post_id;
		$parameters['performer']   = 'New Artist | Second New Artist';
		$parameters['description'] = 'Updated festival description';
		$result                    = $this->invoke_upsert( $parameters, array() );

		$this->assertSame( 'updated', $result['data']['action'] );
		$this->assertEqualsCanonicalizing( array( 'Original Artist', 'Manual Artist', 'New Artist', 'Second New Artist' ), wp_get_post_terms( $post_id, 'artist', array( 'fields' => 'names' ) ) );
	}
}
