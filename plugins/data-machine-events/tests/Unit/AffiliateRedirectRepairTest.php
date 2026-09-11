<?php
/**
 * Affiliate redirect repair ability tests.
 *
 * Exercises the scan/repair loop end to end against real posts: corrupted
 * ticketUrl and organizerUrl attributes are detected, dry-run leaves content
 * untouched, execute rewrites only the corrupted attributes, and ambiguous
 * rows are skipped rather than guessed. See issue #823.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.63.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Abilities\AffiliateRedirectRepairAbilities;
use DataMachineEvents\Core\Event_Post_Type;

class AffiliateRedirectRepairTest extends WP_UnitTestCase {

	private const CORRUPTED_TICKET = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=httpswww.ticketmaster.comeventZ7r9jZ1A7jv1d&utm_medium=affiliate';

	private const CORRUPTED_ORGANIZER = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=httpswww.ticketweb.comeventold-school-rb-crescent-ballroom-tickets14280894&utm_medium=affiliate';

	private const AMBIGUOUS_ORGANIZER = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=httpswww.ticketweb.comeventvalgur-andy-loebs-zom6ii-nikki-lopez-philly-tickets14178484REFERRAL_IDtmfeed&utm_medium=affiliate';

	private function make_block_content( array $extra_attrs ): string {
		$attrs = array_merge(
			array(
				'startDate' => '2026-08-01',
				'startTime' => '20:00',
				'venue'     => 'Test Venue',
			),
			$extra_attrs
		);

		return '<!-- wp:data-machine-events/event-details ' . wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE ) . ' -->'
			. "\n" . '<div class="wp-block-data-machine-events-event-details"></div>' . "\n"
			. '<!-- /wp:data-machine-events/event-details -->';
	}

	private function create_published_event( string $content ): int {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Repair Test ' . uniqid(),
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		return (int) $post_id;
	}

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		// The repair scan resolves events through the event_dates table.
		\DataMachineEvents\Core\EventDatesTable::create_table();
	}

	public function test_dry_run_reports_changes_without_touching_content(): void {
		$content = $this->make_block_content( array( 'ticketUrl' => self::CORRUPTED_TICKET ) );
		$post_id = $this->create_published_event( $content );
		$before  = get_post( $post_id )->post_content;

		$result = ( new AffiliateRedirectRepairAbilities() )->executeRepair(
			array(
				'dry_run' => true,
				'scope'   => 'all',
			)
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['repaired'] );
		$this->assertSame( $post_id, $result['changes'][0]['post_id'] );
		$this->assertSame( 'ticketUrl', $result['changes'][0]['attribute'] );
		$this->assertSame(
			'https://www.ticketmaster.com/event/Z7r9jZ1A7jv1d',
			$result['changes'][0]['destination']
		);
		$this->assertSame( $before, get_post( $post_id )->post_content, 'Dry run must not modify post content.' );
	}

	public function test_execute_repairs_only_corrupted_attributes(): void {
		$content = $this->make_block_content(
			array(
				'ticketUrl'    => self::CORRUPTED_TICKET,
				'organizerUrl' => 'https://example.com/organizer',
			)
		);
		$post_id = $this->create_published_event( $content );

		$result = ( new AffiliateRedirectRepairAbilities() )->executeRepair(
			array(
				'dry_run' => false,
				'scope'   => 'all',
			)
		);

		$this->assertSame( 1, $result['repaired'] );

		$updated = get_post( $post_id )->post_content;
		$this->assertStringContainsString( 'u=https%3A%2F%2Fwww.ticketmaster.com%2Fevent%2FZ7r9jZ1A7jv1d', $updated );
		$this->assertStringNotContainsString( 'httpswww.', $updated );
		$this->assertStringContainsString( 'https://example.com/organizer', $updated, 'Healthy attrs must stay untouched.' );

		// Acceptance: the detector no longer flags the repaired event.
		$blocks = parse_blocks( $updated );
		foreach ( $blocks[0]['attrs'] as $attr => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$this->assertNull(
				\DataMachineEvents\Core\AffiliateRedirectShape::find_corrupted_redirect( $value ),
				"Attribute {$attr} still detected as corrupted after repair."
			);
		}
	}

	public function test_ambiguous_row_is_skipped_not_guessed(): void {
		$content = $this->make_block_content( array( 'organizerUrl' => self::AMBIGUOUS_ORGANIZER ) );
		$post_id = $this->create_published_event( $content );

		$result = ( new AffiliateRedirectRepairAbilities() )->executeRepair(
			array(
				'dry_run' => false,
				'scope'   => 'all',
			)
		);

		$this->assertSame( 0, $result['repaired'] );
		$this->assertSame( 1, $result['skipped_ambiguous'] );
		$this->assertSame( 'ambiguous_destination', $result['skipped'][0]['reason'] );
		$this->assertStringContainsString( 'REFERRAL_IDtmfeed', get_post( $post_id )->post_content, 'Ambiguous value must be left as-is, never guessed.' );
	}

	public function test_events_without_affiliate_urls_are_untouched(): void {
		$content = $this->make_block_content( array( 'ticketUrl' => 'https://www.ticketmaster.com/event/Z7r9jZ1A7jv1d' ) );
		$post_id = $this->create_published_event( $content );
		$before  = get_post( $post_id )->post_content;

		$result = ( new AffiliateRedirectRepairAbilities() )->executeRepair(
			array(
				'dry_run' => false,
				'scope'   => 'all',
			)
		);

		$this->assertSame( 0, $result['repaired'] );
		$this->assertSame( $before, get_post( $post_id )->post_content );
	}
}
