<?php
/**
 * EventUpsert title noise tests.
 *
 * The upsert stores the clean title, fills empty endTime/price from what the
 * title carried, never overwrites source-provided values, and dedups the
 * noisy and clean forms of one listing to a single event (issue #932).
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.68.0
 */

namespace DataMachineEvents\Tests\Unit;

class EventUpsertTitleNoiseTest extends EventUpsertTestCase {

	/**
	 * Event Details block attributes of a stored event.
	 *
	 * @param int $post_id Event post ID.
	 * @return array Block attributes.
	 */
	private function details_attrs( int $post_id ): array {
		foreach ( parse_blocks( (string) get_post( $post_id )->post_content ) as $block ) {
			if ( 'data-machine-events/event-details' === $block['blockName'] ) {
				return (array) $block['attrs'];
			}
		}

		return array();
	}

	public function test_noisy_title_is_stored_clean_with_recovered_details(): void {
		$name   = 'Sean Smith ' . uniqid();
		$result = $this->invoke_upsert(
			array(
				'title'     => $name . ' 6-9pm',
				'venue'     => 'Title Noise Venue ' . uniqid(),
				'startDate' => '2026-11-12',
				'startTime' => '18:00',
			)
		);

		$this->assertTrue( $result['success'] ?? false, (string) wp_json_encode( $result ) );
		$post_id = (int) $result['data']['post_id'];
		$this->assertSame( $name, get_post( $post_id )->post_title );
		$this->assertSame( '21:00', $this->details_attrs( $post_id )['endTime'] ?? '' );
	}

	public function test_trailing_price_fills_empty_price(): void {
		$name   = 'David Peterson ' . uniqid();
		$result = $this->invoke_upsert(
			array(
				'title'     => $name . ' $20',
				'venue'     => 'Title Noise Venue ' . uniqid(),
				'startDate' => '2026-11-13',
				'startTime' => '19:00',
			)
		);

		$this->assertTrue( $result['success'] ?? false, (string) wp_json_encode( $result ) );
		$post_id = (int) $result['data']['post_id'];
		$this->assertSame( $name, get_post( $post_id )->post_title );
		$this->assertSame( '$20', $this->details_attrs( $post_id )['price'] ?? '' );
	}

	public function test_source_price_and_end_time_are_not_overwritten(): void {
		$name   = 'Cooper River Band ' . uniqid();
		$result = $this->invoke_upsert(
			array(
				'title'     => $name . ' 7pm-10pm',
				'venue'     => 'Title Noise Venue ' . uniqid(),
				'startDate' => '2026-11-14',
				'startTime' => '19:00',
				'endTime'   => '22:30',
			)
		);

		$this->assertTrue( $result['success'] ?? false, (string) wp_json_encode( $result ) );
		$post_id = (int) $result['data']['post_id'];
		$this->assertSame( $name, get_post( $post_id )->post_title );
		$this->assertSame( '22:30', $this->details_attrs( $post_id )['endTime'] ?? '' );
	}

	public function test_midnight_crossing_range_does_not_set_end_time(): void {
		$name   = 'Ugly Beats ' . uniqid();
		$result = $this->invoke_upsert(
			array(
				'title'     => $name . ' 10pm-1am',
				'venue'     => 'Title Noise Venue ' . uniqid(),
				'startDate' => '2026-11-15',
				'startTime' => '22:00',
			)
		);

		$this->assertTrue( $result['success'] ?? false, (string) wp_json_encode( $result ) );
		$post_id = (int) $result['data']['post_id'];
		$this->assertSame( $name, get_post( $post_id )->post_title );
		$this->assertSame( '', (string) ( $this->details_attrs( $post_id )['endTime'] ?? '' ) );
	}

	public function test_noisy_and_clean_listings_resolve_to_one_event(): void {
		$name     = 'Dave Landeo ' . uniqid();
		$occasion = array(
			'venue'     => 'Title Noise Venue ' . uniqid(),
			'startDate' => '2026-11-16',
			'startTime' => '18:30',
		);

		$noisy = $this->invoke_upsert( array_merge( $occasion, array( 'title' => $name . ' 6:30-9:30pm' ) ) );
		$clean = $this->invoke_upsert( array_merge( $occasion, array( 'title' => $name ) ) );

		$this->assertTrue( $noisy['success'] ?? false, (string) wp_json_encode( $noisy ) );
		$this->assertTrue( $clean['success'] ?? false, (string) wp_json_encode( $clean ) );
		$this->assertSame( $noisy['data']['post_id'], $clean['data']['post_id'] );
		$this->assertSame( 1, $this->countEventsWithTitle( $name ) );
	}

	public function test_legitimate_title_is_stored_unchanged(): void {
		$result = $this->invoke_upsert(
			array(
				'title'     => '$uicideboy$ - Grey Day Tour',
				'venue'     => 'Title Noise Venue ' . uniqid(),
				'startDate' => '2026-11-17',
				'startTime' => '20:00',
			)
		);

		$this->assertTrue( $result['success'] ?? false, (string) wp_json_encode( $result ) );
		$this->assertSame( '$uicideboy$ - Grey Day Tour', get_post( (int) $result['data']['post_id'] )->post_title );
	}

	public function test_ai_title_wins_over_raw_source_title(): void {
		$venue  = 'Title Authority Venue ' . uniqid();
		$artist = 'Tour Artist ' . uniqid();
		$method = new \ReflectionMethod( $this->handler, 'executeUpsert' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->handler,
			array(
				'title'     => $artist . ' at ' . $venue . ' — Phoenix',
				'venue'     => $venue,
				'startDate' => '2026-11-15',
				'startTime' => '19:00',
				'engine'    => new \DataMachine\Core\EngineData(
					array(
						'title'     => $artist,
						'startDate' => '2026-11-15',
						'startTime' => '19:00',
					),
					0
				),
				'job_id'    => 0,
			),
			array(
				'post_author' => self::factory()->user->create( array( 'role' => 'administrator' ) ),
				'post_status' => 'publish',
			)
		);

		$this->assertTrue( $result['success'] ?? false, (string) wp_json_encode( $result ) );
		$this->assertSame( $artist . ' at ' . $venue . ' — Phoenix', get_post( (int) $result['data']['post_id'] )->post_title );
	}
}
