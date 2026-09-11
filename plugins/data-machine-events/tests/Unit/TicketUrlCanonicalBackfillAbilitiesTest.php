<?php
/**
 * Ticket URL canonical backfill tests (issue #818).
 *
 * Covers dry-run-by-default, the byte-identity round-trip guard, mangled-row
 * reporting (coordinated with #823), idempotent resumability, and the
 * derived-state consistency of the content write (dedup meta re-synced by
 * the save_post hook).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\ResolveTicketDestinationAbilities;
use DataMachineEvents\Abilities\TicketUrlCanonicalBackfillAbilities;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use WP_UnitTestCase;

class TicketUrlCanonicalBackfillAbilitiesTest extends WP_UnitTestCase {

	private TicketUrlCanonicalBackfillAbilities $ability;

	/**
	 * Real stored production wrapper, plain shape.
	 */
	private const STORED_WRAPPER = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fwww.ticketmaster.com%2Fevent%2FZ7r9jZ1A7Q4qb&utm_medium=affiliate';

	private const CANONICAL = 'https://www.ticketmaster.com/event/Z7r9jZ1A7Q4qb';

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}

		$this->ability = new TicketUrlCanonicalBackfillAbilities();
	}

	public function tearDown(): void {
		remove_all_filters( 'data_machine_events_ticket_wrapper_config' );
		parent::tearDown();
	}

	public function test_dry_run_is_the_default_and_writes_nothing(): void {
		$event_id = $this->makeEventWithRawTicketUrl( self::STORED_WRAPPER, '2099-08-15' );

		$result = $this->ability->executeBackfill( array() );

		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['scanned'] );
		$this->assertSame( 1, $result['updated'], 'Dry run counts the pending conversion.' );
		$this->assertSame( 1, count( $result['changes'] ) );
		$this->assertSame( self::STORED_WRAPPER, $result['changes'][0]['old'] );

		$this->assertStringContainsString( 'evyy.net', (string) get_post( $event_id )->post_content, 'Dry run must not modify content.' );
	}

	public function test_execute_writes_canonical_url_and_no_affiliate_host_remains(): void {
		$event_id = $this->makeEventWithRawTicketUrl( self::STORED_WRAPPER, '2099-08-15' );

		$result = $this->ability->executeBackfill( array( 'dry_run' => false ) );

		$this->assertFalse( $result['dry_run'] );
		$this->assertSame( 1, $result['updated'] );

		$content = (string) get_post( $event_id )->post_content;
		$this->assertStringNotContainsString( 'evyy.net', $content, 'No affiliate host may remain in post_content after conversion.' );
		$this->assertSame( self::CANONICAL, $this->blockTicketUrl( $event_id ), 'Block attr must carry the canonical URL after conversion.' );
	}

	/**
	 * The save_post hook fired by wp_update_post must re-sync derived state:
	 * the dedup ticket-URL meta (previously the wrapper's identity) must now
	 * carry the canonical identity.
	 */
	public function test_execute_resyncs_dedup_meta_from_new_content(): void {
		$event_id = $this->makeEventWithRawTicketUrl( self::STORED_WRAPPER, '2099-08-15' );

		$this->ability->executeBackfill( array( 'dry_run' => false ) );

		$meta = (string) get_post_meta( $event_id, '_datamachine_ticket_url', true );
		$this->assertSame( self::CANONICAL, $meta, 'Dedup meta must be re-synced to the canonical identity.' );
	}

	public function test_rerun_after_execute_reports_row_as_already_canonical(): void {
		$this->makeEventWithRawTicketUrl( self::STORED_WRAPPER, '2099-08-15' );

		$first = $this->ability->executeBackfill( array( 'dry_run' => false ) );
		$this->assertSame( 1, $first['updated'] );

		// Resumability: an interrupted/second run skips converted rows.
		$second = $this->ability->executeBackfill( array( 'dry_run' => false ) );
		$this->assertSame( 0, $second['updated'] );
		$this->assertSame( 1, $second['skipped_not_wrapper'] );
	}

	public function test_non_monetized_direct_url_is_skipped_untouched(): void {
		$content = 'https://link.dice.fm/abc123';
		$event_id = $this->makeEventWithRawTicketUrl( $content, '2099-08-15' );

		$result = $this->ability->executeBackfill( array( 'dry_run' => false ) );

		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 1, $result['skipped_not_wrapper'] );
		$this->assertStringContainsString( 'link.dice.fm', (string) get_post( $event_id )->post_content );
	}

	/**
	 * The 17 known mangled `u=httpswww...` rows (real production value):
	 * reported with reason `mangled_unrecoverable`, never written. #823
	 * owns the detector/repair conversation for this shape.
	 */
	public function test_mangled_row_is_reported_and_never_written(): void {
		$stored   = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=httpswww.ticketmaster.comeventZ7r9jZ1A7jv1d&utm_medium=affiliate';
		$event_id = $this->makeEventWithRawTicketUrl( $stored, '2099-08-15' );

		$result = $this->ability->executeBackfill( array( 'dry_run' => false ) );

		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 1, $result['mangled'] );
		$this->assertSame( 'mangled_unrecoverable', $result['report'][0]['reason'] );
		$this->assertStringContainsString( 'evyy.net', (string) get_post( $event_id )->post_content, 'Mangled rows must stay wrapper-stored.' );
	}

	/**
	 * Nested-encoding class (real production SeatGeek example): the
	 * round-trip guard must refuse conversion — reporting, not writing.
	 */
	public function test_roundtrip_mismatch_row_is_reported_and_never_written(): void {
		$stored   = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fseatgeek.com%2Fchris-brown-tickets%2Fsunrise-florida-amerant-bank-arena-2026-04-04-7-pm%2Fconcert%2F18082005%3Fdd_referrer%3Dhttps%253A%252F%252Famplify.seatgeek.com%252F&utm_medium=affiliate';
		$event_id = $this->makeEventWithRawTicketUrl( $stored, '2099-08-15' );

		$result = $this->ability->executeBackfill( array( 'dry_run' => false ) );

		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 1, $result['skipped_roundtrip_mismatch'] );
		$this->assertSame( 'roundtrip_mismatch', $result['report'][0]['reason'] );
		$this->assertStringContainsString( 'evyy.net', (string) get_post( $event_id )->post_content, 'Non-round-tripping rows must stay wrapper-stored.' );
	}

	public function test_future_only_scope_leaves_past_events_untouched(): void {
		$past_id   = $this->makeEventWithRawTicketUrl( self::STORED_WRAPPER, '2020-01-10' );
		$future_id = $this->makeEventWithRawTicketUrl( self::STORED_WRAPPER, '2099-08-15' );

		$result = $this->ability->executeBackfill(
			array(
				'dry_run'     => false,
				'future_only' => true,
			)
		);

		$this->assertSame( 1, $result['updated'], 'Only the future-dated event converts.' );
		$this->assertStringContainsString( 'evyy.net', (string) get_post( $past_id )->post_content, 'Past events must be untouched by --future-only.' );
		$this->assertStringNotContainsString( 'evyy.net', (string) get_post( $future_id )->post_content );
	}

	/**
	 * End-to-end tie-back to the resolve ability: after conversion, the 302
	 * destination (ability output) is byte-identical to the entity-normalized
	 * wrapper that was stored before the migration.
	 */
	public function test_resolved_destination_after_backfill_matches_original_wrapper(): void {
		$event_id = $this->makeEventWithRawTicketUrl( self::STORED_WRAPPER, '2099-08-15' );

		$this->ability->executeBackfill( array( 'dry_run' => false ) );

		$resolve = ( new ResolveTicketDestinationAbilities() )->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertIsArray( $resolve );
		$this->assertSame( self::STORED_WRAPPER, $resolve['url'], 'Resolve output must be byte-identical to the pre-migration stored wrapper.' );
		$this->assertTrue( $resolve['is_affiliate'] );
	}

	public function test_limit_bounds_processed_slice(): void {
		$this->makeEventWithRawTicketUrl( self::STORED_WRAPPER, '2099-08-15' );
		$this->makeEventWithRawTicketUrl( self::STORED_WRAPPER, '2099-09-20' );

		$result = $this->ability->executeBackfill( array( 'limit' => 1 ) );

		$this->assertSame( 1, $result['scanned'] );
	}

	/**
	 * Rotation end-to-end: config-only change flips the resolved wrapper
	 * with zero content writes.
	 */
	public function test_affiliate_id_rotation_is_config_only(): void {
		$event_id = $this->makeEventWithRawTicketUrl( self::STORED_WRAPPER, '2099-08-15' );
		$this->ability->executeBackfill( array( 'dry_run' => false ) );

		$content_before = (string) get_post( $event_id )->post_content;

		add_filter(
			'data_machine_events_ticket_wrapper_config',
			static function ( array $config ): array {
				$config['affiliate_id'] = '9999999';
				return $config;
			}
		);

		$resolve = ( new ResolveTicketDestinationAbilities() )->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertIsArray( $resolve );
		$this->assertStringContainsString( '/c/9999999/264167/4272?u=', $resolve['url'], 'Rotation must be a config edit, not a content edit.' );
		$this->assertStringContainsString( rawurlencode( self::CANONICAL ), $resolve['url'] );
		$this->assertSame( $content_before, (string) get_post( $event_id )->post_content, 'Zero content writes on rotation.' );
	}

	private function blockTicketUrl( int $post_id ): string {
		$blocks = parse_blocks( (string) get_post( $post_id )->post_content );
		foreach ( $blocks as $block ) {
			if ( Event_Post_Type::EVENT_DETAILS_BLOCK_NAME === ( $block['blockName'] ?? '' ) ) {
				return (string) ( $block['attrs']['ticketUrl'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Create a published event whose stored post_content bytes are exactly
	 * the given wrapper (bypasses wp_insert_post's kses entity rewriting —
	 * same approach as ResolveTicketDestinationAbilitiesTest), plus an
	 * event-dates table row so scope queries (upcoming/all) find it.
	 *
	 * @param string $ticket_url Exact ticketUrl value to store.
	 * @param string $start_date Event start date (YYYY-MM-DD).
	 * @return int Post ID.
	 */
	private function makeEventWithRawTicketUrl( string $ticket_url, string $start_date ): int {
		global $wpdb;

		$event_id = self::factory()->post->create(
			array(
				'post_title'   => 'Canonical Backfill Event ' . uniqid(),
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
			)
		);
		$this->assertIsInt( $event_id );

		$content = '<!-- wp:data-machine-events/event-details {"startDate":"' . $start_date . '","startTime":"20:00","ticketUrl":"' . $ticket_url . '"} --><div></div><!-- /wp:data-machine-events/event-details -->';

		$updated = $wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $event_id ) );
		$this->assertNotFalse( $updated, 'Direct post_content write failed.' );
		clean_post_cache( $event_id );

		$end_date = gmdate( 'Y-m-d', strtotime( $start_date . ' +1 day' ) );
		EventDatesTable::upsert( $event_id, $start_date . ' 20:00:00', $end_date . ' 23:00:00', 'publish' );

		return $event_id;
	}
}
