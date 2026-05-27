<?php
/**
 * ArtistUrlImportAbilities Tests
 *
 * Covers the four artist-URL-import abilities. Where the behavior depends
 * on the UniversalWebScraper HTTP path (preview / submit success path),
 * we mock `pre_http_request` so the scraper sees a synthetic payload —
 * the same pattern EventScraperTestAbilityTest uses against committed
 * fixtures.
 *
 * The approve-pipeline-creation path is exercised at the level we can
 * verify in isolation: that the ability rejects with `artist_required`
 * when no artist input is provided AND no suggested_artist_term_id is on
 * the submission row. The full create-pipeline+create-flow integration
 * is covered end-to-end against a live Data Machine install (manual
 * curl matrix in the PR description); recreating those abilities here
 * just to test that we call them with the right shape would be a fake
 * test.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.40.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Abilities\ArtistUrlImportAbilities;
use DataMachineEvents\Core\ArtistUrlSubmissionsTable;

class ArtistUrlImportAbilitiesTest extends WP_UnitTestCase {

	private ArtistUrlImportAbilities $abilities;

	public function setUp(): void {
		parent::setUp();
		if ( ! ArtistUrlSubmissionsTable::table_exists() ) {
			ArtistUrlSubmissionsTable::create_table();
		}
		// Reset table per test.
		global $wpdb;
		$table = ArtistUrlSubmissionsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		if ( ! taxonomy_exists( 'artist' ) ) {
			register_taxonomy(
				'artist',
				array( 'data_machine_events' ),
				array(
					'public'       => true,
					'hierarchical' => false,
					'rewrite'      => array( 'slug' => 'artist' ),
				)
			);
		}

		$this->abilities = new ArtistUrlImportAbilities();
	}

	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	// ────────────────────────────────────────────────────────────────────
	// preview-artist-url
	// ────────────────────────────────────────────────────────────────────

	public function test_preview_rejects_empty_url(): void {
		$result = $this->abilities->executePreview( array( 'url' => '' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_preview_rejects_non_http_protocol(): void {
		$result = $this->abilities->executePreview( array( 'url' => 'ftp://example.com/tour' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		// esc_url_raw drops disallowed schemes entirely, so we get either
		// invalid_url or invalid_protocol depending on which guard fires
		// first. Both are correct rejections.
		$this->assertContains( $result->get_error_code(), array( 'invalid_url', 'invalid_protocol' ) );
	}

	public function test_preview_rejects_url_already_tracked(): void {
		$normalized = ArtistUrlSubmissionsTable::normalize_url( 'https://example.com/tour' );
		$hash       = ArtistUrlSubmissionsTable::url_hash( $normalized );
		ArtistUrlSubmissionsTable::insert(
			array(
				'url'      => $normalized,
				'url_hash' => $hash,
				'status'   => ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
			)
		);

		$result = $this->abilities->executePreview( array( 'url' => 'https://example.com/tour' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'url_already_tracked', $result->get_error_code() );
	}

	public function test_preview_returns_no_events_found_for_bad_url(): void {
		// Mock pre_http_request to return an empty 200 — UniversalWebScraper
		// will get nothing parseable.
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => '<html><body>nothing parseable here</body></html>',
					'headers'  => array( 'content-type' => 'text/html' ),
				);
			},
			10
		);

		$result = $this->abilities->executePreview( array( 'url' => 'https://no-events.test/page' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_events_found', $result->get_error_code() );
	}

	// ────────────────────────────────────────────────────────────────────
	// submit-artist-url
	// ────────────────────────────────────────────────────────────────────

	public function test_submit_requires_logged_in_user(): void {
		wp_set_current_user( 0 );

		$result = $this->abilities->executeSubmit( array( 'url' => 'https://example.com/tour' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'login_required', $result->get_error_code() );
	}

	public function test_submit_records_scraping_failed_when_no_events_found(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Same empty-page mock as the preview "no events" test.
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => '<html><body>nothing here</body></html>',
					'headers'  => array( 'content-type' => 'text/html' ),
				);
			},
			10
		);

		$result = $this->abilities->executeSubmit( array( 'url' => 'https://nothing.test/page' ) );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( ArtistUrlSubmissionsTable::STATUS_SCRAPING_FAILED, $result['status'] );
		$this->assertGreaterThan( 0, $result['submission_id'] );

		$row = ArtistUrlSubmissionsTable::get( (int) $result['submission_id'] );
		$this->assertSame( ArtistUrlSubmissionsTable::STATUS_SCRAPING_FAILED, $row['status'] );
		$this->assertSame( $user_id, (int) $row['user_id'] );
	}

	public function test_submit_returns_url_already_tracked_for_duplicate(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$normalized = ArtistUrlSubmissionsTable::normalize_url( 'https://dup.test/tour' );
		ArtistUrlSubmissionsTable::insert(
			array(
				'url'      => $normalized,
				'url_hash' => ArtistUrlSubmissionsTable::url_hash( $normalized ),
				'status'   => ArtistUrlSubmissionsTable::STATUS_APPROVED,
			)
		);

		$result = $this->abilities->executeSubmit( array( 'url' => 'https://dup.test/tour' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'url_already_tracked', $result->get_error_code() );
	}

	// ────────────────────────────────────────────────────────────────────
	// approve-artist-url-submission
	// ────────────────────────────────────────────────────────────────────

	public function test_approve_requires_artist_when_none_provided(): void {
		// Seed a pending submission with no suggested artist term.
		$normalized = ArtistUrlSubmissionsTable::normalize_url( 'https://needs-artist.test/tour' );
		$id         = ArtistUrlSubmissionsTable::insert(
			array(
				'url'      => $normalized,
				'url_hash' => ArtistUrlSubmissionsTable::url_hash( $normalized ),
				'status'   => ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
			)
		);

		// Approve with no artist_term_id and no artist_name.
		$result = $this->abilities->executeApprove( array( 'submission_id' => $id ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'artist_required', $result->get_error_code() );

		// Submission must still be pending_review (no side effects).
		$row = ArtistUrlSubmissionsTable::get( $id );
		$this->assertSame( ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW, $row['status'] );
	}

	public function test_approve_rejects_non_pending_submission(): void {
		$normalized = ArtistUrlSubmissionsTable::normalize_url( 'https://already.test/tour' );
		$id         = ArtistUrlSubmissionsTable::insert(
			array(
				'url'      => $normalized,
				'url_hash' => ArtistUrlSubmissionsTable::url_hash( $normalized ),
				'status'   => ArtistUrlSubmissionsTable::STATUS_REJECTED,
			)
		);

		$result = $this->abilities->executeApprove( array( 'submission_id' => $id ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_state', $result->get_error_code() );
	}

	public function test_approve_rejects_unknown_submission(): void {
		$result = $this->abilities->executeApprove( array( 'submission_id' => 99999 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	// ────────────────────────────────────────────────────────────────────
	// reject-artist-url-submission
	// ────────────────────────────────────────────────────────────────────

	public function test_reject_sets_status_and_reason(): void {
		$normalized = ArtistUrlSubmissionsTable::normalize_url( 'https://rejected.test/tour' );
		$id         = ArtistUrlSubmissionsTable::insert(
			array(
				'url'      => $normalized,
				'url_hash' => ArtistUrlSubmissionsTable::url_hash( $normalized ),
				'status'   => ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
			)
		);

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$result = $this->abilities->executeReject(
			array(
				'submission_id' => $id,
				'reason'        => 'Not a music artist tour page.',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$row = ArtistUrlSubmissionsTable::get( $id );
		$this->assertSame( ArtistUrlSubmissionsTable::STATUS_REJECTED, $row['status'] );
		$this->assertSame( 'Not a music artist tour page.', $row['rejection_reason'] );
		$this->assertSame( $admin, (int) $row['reviewed_by'] );
		$this->assertNotEmpty( $row['reviewed_at'] );
	}

	public function test_reject_rejects_unknown_submission(): void {
		$result = $this->abilities->executeReject( array( 'submission_id' => 99999 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}
}
