<?php
namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\InstagramHandle;
use DataMachineEvents\Abilities\VenueAbilities;
use WP_UnitTestCase;

class InstagramHandleTest extends WP_UnitTestCase {
	public function test_normalization(): void {
		foreach ( array( '@MusicFarm', ' https://www.instagram.com/MusicFarm/ ', 'instagram.com/MusicFarm/', 'musicfarm' ) as $input ) {
			$this->assertSame( 'musicfarm', InstagramHandle::normalize( $input ) );
		}
		$this->assertSame( 'artist.name_1', InstagramHandle::normalize( '@Artist.Name_1' ) );
		$this->assertSame( '', InstagramHandle::normalize( '' ) );
		foreach ( array( 'bad-name', 'two names', 'https://evil.com/name', 'instagram.com/name/post', '@@name', str_repeat( 'a', 31 ), array(), null ) as $input ) {
			$this->assertWPError( InstagramHandle::normalize( $input ) );
		}
	}

	public function test_artist_persistence_and_clearing(): void {
		if ( ! taxonomy_exists( 'artist' ) ) {
			register_taxonomy( 'artist', 'event' );
		}
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'artist' ) );
		$ability = new VenueAbilities();
		$input = array( 'taxonomy' => 'artist', 'term_id' => $term_id, 'instagram' => '@Artist.Name' );
		$this->assertNotWPError( $ability->executeSetTermInstagram( $input ) );
		$this->assertSame( 'artist.name', get_term_meta( $term_id, 'instagram', true ) );
		$this->assertNotWPError( $ability->executeSetTermInstagram( $input ) );
		$input['instagram'] = 'bad-name';
		$this->assertWPError( $ability->executeSetTermInstagram( $input ) );
		$this->assertSame( 'artist.name', InstagramHandle::read( $term_id, 'artist' ) );
		$input['instagram'] = '';
		$this->assertNotWPError( $ability->executeSetTermInstagram( $input ) );
		$this->assertSame( '', InstagramHandle::read( $term_id, 'artist' ) );
	}
}
