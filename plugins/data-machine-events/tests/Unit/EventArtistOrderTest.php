<?php
/**
 * Lineup billing order persists through core term_order (#3989).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

require_once __DIR__ . '/EventUpsertTestCase.php';

class EventArtistOrderTest extends EventUpsertTestCase {

	/** @var \WP_Taxonomy|null */
	private $previous_artist;

	public function setUp(): void {
		parent::setUp();
		global $wp_taxonomies;
		$this->previous_artist = $wp_taxonomies['artist'] ?? null;
		register_taxonomy( 'artist', 'data_machine_events', array( 'sort' => true ) );
	}

	public function tearDown(): void {
		global $wp_taxonomies;
		if ( $this->previous_artist ) {
			$wp_taxonomies['artist'] = $this->previous_artist; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		} else {
			unregister_taxonomy( 'artist' );
		}
		parent::tearDown();
	}

	private function event( array $artists, int $event_id = 0 ): array {
		$event = array(
			'title'     => 'Lineup Order Show',
			'venue'     => 'Order Test Hall',
			'startDate' => '2027-09-10',
			'startTime' => '19:00',
			'artist'    => $artists,
		);
		if ( $event_id > 0 ) {
			$event['event_id'] = $event_id;
		}
		return $event;
	}

	/** @return string[] */
	private function ordered_names( int $post_id ): array {
		return wp_get_object_terms(
			$post_id,
			'artist',
			array(
				'orderby' => 'term_order',
				'fields'  => 'names',
			)
		);
	}

	/** @return int[] */
	private function term_orders( int $post_id ): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT tr.term_order FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = 'artist' ORDER BY tr.term_order",
					$post_id
				)
			)
		);
	}

	public function test_create_stores_lineup_billing_order(): void {
		$result = $this->invoke_upsert( $this->event( array( 'Order Alpha', 'Order Bravo', 'Order Charlie' ) ) );

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$post_id = (int) $result['data']['post_id'];
		$this->assertSame( array( 'Order Alpha', 'Order Bravo', 'Order Charlie' ), $this->ordered_names( $post_id ) );
		$this->assertSame( array( 1, 2, 3 ), $this->term_orders( $post_id ) );
	}

	public function test_update_keeps_existing_order_and_appends_new_artists(): void {
		$created = $this->invoke_upsert( $this->event( array( 'Order Alpha', 'Order Bravo', 'Order Charlie' ) ) );
		$this->assertTrue( $created['success'], wp_json_encode( $created ) );
		$post_id = (int) $created['data']['post_id'];

		$updated = $this->invoke_upsert( $this->event( array( 'Order Delta', 'Order Alpha' ), $post_id ) );

		$this->assertTrue( $updated['success'], wp_json_encode( $updated ) );
		$this->assertSame( array( 'Order Alpha', 'Order Bravo', 'Order Charlie', 'Order Delta' ), $this->ordered_names( $post_id ) );
		$this->assertNotContains( 0, $this->term_orders( $post_id ) );
	}

	public function test_preselected_artist_follows_the_lineup(): void {
		$this->assertNotWPError( wp_insert_term( 'Order Xray', 'artist' ) );

		$result = $this->invoke_upsert(
			$this->event( array( 'Order Alpha', 'Order Bravo', 'Order Charlie' ) ),
			array( 'taxonomy_artist_selection' => 'Order Xray' )
		);

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$post_id = (int) $result['data']['post_id'];
		$this->assertSame( array( 'Order Alpha', 'Order Bravo', 'Order Charlie', 'Order Xray' ), $this->ordered_names( $post_id ) );
		$this->assertNotContains( 0, $this->term_orders( $post_id ) );
	}

	public function test_legacy_unordered_lineup_is_led_by_incoming_billing(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'data_machine_events' ) );
		wp_set_object_terms( $post_id, array( 'Order Bravo' ), 'artist', true );
		wp_set_object_terms( $post_id, array( 'Order Charlie' ), 'artist', true );
		$this->assertSame( array( 0, 0 ), $this->term_orders( $post_id ) );

		$result = $this->invoke_upsert( $this->event( array( 'Order Alpha' ), $post_id ) );

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$names = $this->ordered_names( $post_id );
		$this->assertSame( 'Order Alpha', $names[0] );
		$this->assertEqualsCanonicalizing( array( 'Order Alpha', 'Order Bravo', 'Order Charlie' ), $names );
		$this->assertNotContains( 0, $this->term_orders( $post_id ) );
	}
}
