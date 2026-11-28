<?php
/**
 * Venue abilities tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\VenueAbilities;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_UnitTestCase;

class VenueAbilitiesTest extends WP_UnitTestCase {
	/** @var int[] */
	private array $term_ids = array();

	public function setUp(): void {
		parent::setUp();
		Venue_Taxonomy::register();
	}

	public function tearDown(): void {
		foreach ( $this->term_ids as $term_id ) {
			wp_delete_term( $term_id, 'venue' );
		}
		parent::tearDown();
	}

	public function test_health_check_flags_known_ticketing_hosts_without_rewriting_values(): void {
		$ticketing_host = $this->venue( 'Ticketing Host Website', 'https://www.eventbrite.com/o/example-123' );
		$this->venue( 'Similar Host Website', 'https://not-eventbrite.com/venue' );

		$result = ( new VenueAbilities() )->executeHealthCheck( array() );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['suspicious_website']['count'] );
		$this->assertSame( $ticketing_host, $result['suspicious_website']['venues'][0]['term_id'] );
		$this->assertSame( 'ticket_platform_domain', $result['suspicious_website']['venues'][0]['suspicion_reason'] );
		$this->assertSame( 'https://www.eventbrite.com/o/example-123', get_term_meta( $ticketing_host, '_venue_website', true ) );
	}

	private function venue( string $name, string $website ): int {
		$term = wp_insert_term( $name, 'venue' );
		$this->assertNotWPError( $term );
		$term_id          = (int) $term['term_id'];
		$this->term_ids[] = $term_id;
		update_term_meta( $term_id, '_venue_website', $website );
		return $term_id;
	}
}
