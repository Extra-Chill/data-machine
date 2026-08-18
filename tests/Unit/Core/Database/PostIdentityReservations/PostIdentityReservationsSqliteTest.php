<?php
/**
 * SQLite post identity reservation coverage.
 *
 * @package DataMachine\Tests\Unit\Core\Database\PostIdentityReservations
 */

namespace DataMachine\Tests\Unit\Core\Database\PostIdentityReservations;

use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations;
use WP_UnitTestCase;

class PostIdentityReservationsSqliteTest extends WP_UnitTestCase {

	public function test_reservations_fail_closed_on_sqlite(): void {
		if ( ! BaseRepository::is_sqlite() ) {
			$this->markTestSkipped( 'SQLite-specific storage contract.' );
		}

		$result = ( new PostIdentityReservations() )->reserve_and_resolve(
			'post',
			array( 'key' => '_source', 'value' => 'sqlite-fail-closed' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'identity_storage_unsupported', $result->get_error_code() );
	}
}
