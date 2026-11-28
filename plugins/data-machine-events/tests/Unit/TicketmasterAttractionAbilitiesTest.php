<?php
/**
 * Ticketmaster attraction lookup response shaping.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\TicketmasterAttractionAbilities;
use WP_UnitTestCase;

class TicketmasterAttractionAbilitiesTest extends WP_UnitTestCase {

	private function attraction( string $id, string $name, int $upcoming ): array {
		return array(
			'id'              => $id,
			'name'            => $name,
			'url'             => 'https://www.ticketmaster.com/' . $id,
			'upcomingEvents'  => array( '_total' => $upcoming ),
			'classifications' => array( array( 'genre' => array( 'name' => 'Rock' ) ) ),
		);
	}

	public function test_best_match_is_exact_name_with_most_upcoming(): void {
		$data = array(
			'_embedded' => array(
				'attractions' => array(
					$this->attraction( 'A1', 'Phish Tribute Band', 40 ),
					$this->attraction( 'A2', 'Phish', 12 ),
					$this->attraction( 'A3', 'PHISH', 3 ),
				),
			),
		);

		$result = TicketmasterAttractionAbilities::parse_response( $data, 'Phish' );

		$this->assertCount( 3, $result['attractions'] );
		$this->assertSame( 'A2', $result['best_match']['id'] );
		$this->assertSame( 12, $result['best_match']['upcoming'] );
		$this->assertFalse( $result['attractions'][0]['exact'] );
		$this->assertSame( 'Rock', $result['attractions'][1]['genre'] );
	}

	public function test_fuzzy_hits_never_become_best_match(): void {
		$data = array( '_embedded' => array( 'attractions' => array( $this->attraction( 'B1', 'Nod to Bob', 9 ) ) ) );

		$this->assertNull( TicketmasterAttractionAbilities::parse_response( $data, 'Bob Dylan' )['best_match'] );
		$this->assertSame( array(), TicketmasterAttractionAbilities::parse_response( array(), 'Bob Dylan' )['attractions'] );
	}

	public function test_normalize_name_ignores_case_leading_the_and_punctuation(): void {
		$this->assertSame(
			TicketmasterAttractionAbilities::normalize_name( 'The Avett Brothers' ),
			TicketmasterAttractionAbilities::normalize_name( 'Avett Brothers' )
		);
		$this->assertSame(
			TicketmasterAttractionAbilities::normalize_name( 'Earth, Wind &amp; Fire' ),
			TicketmasterAttractionAbilities::normalize_name( 'Earth Wind and Fire' )
		);
		$this->assertNotSame(
			TicketmasterAttractionAbilities::normalize_name( 'Phish' ),
			TicketmasterAttractionAbilities::normalize_name( 'Phishbowl' )
		);
	}
}
