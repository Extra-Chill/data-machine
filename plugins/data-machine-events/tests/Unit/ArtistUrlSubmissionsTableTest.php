<?php
/**
 * ArtistUrlSubmissionsTable Tests
 *
 * Covers URL normalization, dedupe-by-hash, and CRUD on the
 * `artist_url_submissions` table introduced for extrachill-events#320.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.40.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\ArtistUrlSubmissionsTable;

class ArtistUrlSubmissionsTableTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! ArtistUrlSubmissionsTable::table_exists() ) {
			ArtistUrlSubmissionsTable::create_table();
		}
		// Make each test independent.
		global $wpdb;
		$table = ArtistUrlSubmissionsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	public function test_normalize_lowercases_scheme_and_host(): void {
		$this->assertSame(
			'https://example.com/tour',
			ArtistUrlSubmissionsTable::normalize_url( 'HTTPS://Example.COM/tour' )
		);
	}

	public function test_normalize_strips_fragment(): void {
		$this->assertSame(
			'https://example.com/tour',
			ArtistUrlSubmissionsTable::normalize_url( 'https://example.com/tour#shows' )
		);
	}

	public function test_normalize_trims_trailing_slash_on_non_root(): void {
		$this->assertSame(
			'https://example.com/tour',
			ArtistUrlSubmissionsTable::normalize_url( 'https://example.com/tour/' )
		);
	}

	public function test_normalize_keeps_root_slash(): void {
		$this->assertSame(
			'https://example.com/',
			ArtistUrlSubmissionsTable::normalize_url( 'https://example.com/' )
		);
	}

	public function test_normalize_preserves_query_string(): void {
		$this->assertSame(
			'https://example.com/events?year=2026',
			ArtistUrlSubmissionsTable::normalize_url( 'https://example.com/events?year=2026' )
		);
	}

	public function test_normalize_strips_default_ports(): void {
		$this->assertSame(
			'https://example.com/tour',
			ArtistUrlSubmissionsTable::normalize_url( 'https://example.com:443/tour' )
		);
		$this->assertSame(
			'http://example.com/tour',
			ArtistUrlSubmissionsTable::normalize_url( 'http://example.com:80/tour' )
		);
	}

	public function test_normalize_returns_empty_for_unparseable(): void {
		$this->assertSame( '', ArtistUrlSubmissionsTable::normalize_url( 'not a url' ) );
		$this->assertSame( '', ArtistUrlSubmissionsTable::normalize_url( '' ) );
	}

	public function test_url_hash_is_deterministic(): void {
		$a = ArtistUrlSubmissionsTable::url_hash( 'https://example.com/tour' );
		$b = ArtistUrlSubmissionsTable::url_hash( 'https://example.com/tour' );
		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, ArtistUrlSubmissionsTable::url_hash( 'https://example.com/other' ) );
		$this->assertSame( 64, strlen( $a ) ); // sha256 hex
	}

	public function test_insert_and_find_by_hash_round_trip(): void {
		$url        = 'https://example.com/tour';
		$normalized = ArtistUrlSubmissionsTable::normalize_url( $url );
		$hash       = ArtistUrlSubmissionsTable::url_hash( $normalized );

		$id = ArtistUrlSubmissionsTable::insert(
			array(
				'user_id'              => 1,
				'contact_email'        => 'fan@example.com',
				'contact_name'         => 'Fan',
				'url'                  => $normalized,
				'url_hash'             => $hash,
				'suggested_artist_name' => 'Theo Katzman',
				'detected_format'      => 'json_ld',
				'events_found_count'   => 12,
				'status'               => ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
			)
		);

		$this->assertGreaterThan( 0, $id );

		$found = ArtistUrlSubmissionsTable::find_by_hash( $hash );
		$this->assertIsArray( $found );
		$this->assertSame( $id, (int) $found['id'] );
		$this->assertSame( 'Theo Katzman', $found['suggested_artist_name'] );
		$this->assertSame( 12, (int) $found['events_found_count'] );
	}

	public function test_dedupe_unique_hash_rejects_duplicate_insert(): void {
		$hash = ArtistUrlSubmissionsTable::url_hash( 'https://example.com/tour' );

		$first = ArtistUrlSubmissionsTable::insert(
			array(
				'url'      => 'https://example.com/tour',
				'url_hash' => $hash,
			)
		);
		$this->assertGreaterThan( 0, $first );

		// Second insert with the same hash hits the UNIQUE KEY constraint
		// and returns null.
		$second = ArtistUrlSubmissionsTable::insert(
			array(
				'url'      => 'https://example.com/tour',
				'url_hash' => $hash,
			)
		);
		$this->assertNull( $second );

		// And find_by_hash still resolves to the first row.
		$found = ArtistUrlSubmissionsTable::find_by_hash( $hash );
		$this->assertSame( $first, (int) $found['id'] );
	}

	public function test_update_status_and_get(): void {
		$id = ArtistUrlSubmissionsTable::insert(
			array(
				'url'      => 'https://example.com/tour',
				'url_hash' => ArtistUrlSubmissionsTable::url_hash( 'https://example.com/tour' ),
			)
		);
		$this->assertGreaterThan( 0, $id );

		$ok = ArtistUrlSubmissionsTable::update(
			$id,
			array(
				'status'           => ArtistUrlSubmissionsTable::STATUS_REJECTED,
				'rejection_reason' => 'spam',
			)
		);
		$this->assertTrue( $ok );

		$row = ArtistUrlSubmissionsTable::get( $id );
		$this->assertSame( ArtistUrlSubmissionsTable::STATUS_REJECTED, $row['status'] );
		$this->assertSame( 'spam', $row['rejection_reason'] );
	}

	public function test_counts_by_status_groups_correctly(): void {
		$insert = function ( string $status, string $url ) {
			ArtistUrlSubmissionsTable::insert(
				array(
					'url'      => $url,
					'url_hash' => ArtistUrlSubmissionsTable::url_hash( $url ),
					'status'   => $status,
				)
			);
		};

		$insert( ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW, 'https://a.test/' );
		$insert( ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW, 'https://b.test/' );
		$insert( ArtistUrlSubmissionsTable::STATUS_APPROVED, 'https://c.test/' );
		$insert( ArtistUrlSubmissionsTable::STATUS_SCRAPING_FAILED, 'https://d.test/' );

		$counts = ArtistUrlSubmissionsTable::counts_by_status();
		$this->assertSame( 2, $counts[ ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW ] );
		$this->assertSame( 1, $counts[ ArtistUrlSubmissionsTable::STATUS_APPROVED ] );
		$this->assertSame( 0, $counts[ ArtistUrlSubmissionsTable::STATUS_REJECTED ] );
		$this->assertSame( 1, $counts[ ArtistUrlSubmissionsTable::STATUS_SCRAPING_FAILED ] );
	}
}
