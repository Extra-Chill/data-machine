<?php
/**
 * Affiliate wrapper assembly tests (issue #818).
 *
 * The single most important guarantee in the canonical-URL migration: the
 * wrapper assembled at resolve time from config must be BYTE-IDENTICAL to
 * what Impact Radius has always carried for a given event. If assembly
 * changed even one byte — a lowercase percent-encoded hex pair, a missing
 * `&utm_medium=affiliate`, a differently-encoded `u=` — affiliate
 * attribution would silently shift and that costs real money. The fixtures
 * below are REAL stored production values (read from the live events
 * corpus), covering the dominant Ticketmaster class in all three observed
 * stored shapes plus the pathological classes the migration deliberately
 * refuses to touch.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;

use function DataMachineEvents\Core\data_machine_events_assemble_affiliate_wrapper;
use function DataMachineEvents\Core\data_machine_events_is_gated_ticket_url;
use function DataMachineEvents\Core\datamachine_decode_stored_url_artifacts;
use function DataMachineEvents\Core\data_machine_events_ticket_wrapper_config;
use function DataMachineEvents\Core\datamachine_unwrap_affiliate_url;

class AffiliateWrapperAssemblyTest extends WP_UnitTestCase {

	/**
	 * Real stored production wrapper, plain shape (raw `&`).
	 */
	private const STORED_WRAPPER_PLAIN = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fwww.ticketmaster.com%2Fevent%2FZ7r9jZ1A7Q4qb&utm_medium=affiliate';

	/**
	 * The canonical URL inside the `u=` parameter above.
	 */
	private const CANONICAL_TM = 'https://www.ticketmaster.com/event/Z7r9jZ1A7Q4qb';

	public function tearDown(): void {
		remove_all_filters( 'data_machine_events_ticket_wrapper_config' );
		parent::tearDown();
	}

	/**
	 * THE byte-identity proof: for real stored wrapper values, in every
	 * observed stored shape, normalize → unwrap → re-assemble must reproduce
	 * the normalized stored wrapper exactly, byte for byte.
	 *
	 * This is the exact pipeline resolve-time assembly runs for a
	 * canonical-stored row (minus the unwrap, which only the backfill and
	 * legacy wrapper-stored rows use), so byte-identity here means the 302
	 * endpoint serves the identical destination it has always served —
	 * same affiliate id path, same `u=` percent-encoding, same
	 * `&utm_medium=affiliate`.
	 *
	 * @dataProvider byte_identical_stored_wrapper_provider
	 */
	public function test_reassembly_from_real_stored_values_is_byte_identical( string $stored ): void {
		$normalized = datamachine_decode_stored_url_artifacts( $stored );
		$canonical  = datamachine_unwrap_affiliate_url( $normalized );

		$this->assertNotSame( $canonical, $normalized, 'Fixture must unwrap to a distinct canonical URL.' );

		$reassembled = data_machine_events_assemble_affiliate_wrapper( $canonical );

		$this->assertSame( $normalized, $reassembled, 'Re-assembled wrapper must be byte-identical to the stored wrapper.' );
	}

	/**
	 * Real stored production wrappers (verified by read-only query against
	 * the live events corpus). Each entry is the exact `ticketUrl` string as
	 * stored in `post_content`, in one of the three observed shapes:
	 * plain raw `&`, HTML-entity `&amp;` (~54% of rows), and the literal
	 * `\u0026` + `&amp;` combined JSON artifact. Multiple Ticketmaster event
	 * IDs are covered, including a trailing-dash ID.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function byte_identical_stored_wrapper_provider(): array {
		$ids = array( 'Z7r9jZ1A7Q4qb', 'Z7r9jZ1A7Q4qO', 'Z7r9jZ1A7Q4q-', 'Z7r9jZ1A7JFo-' );

		$cases = array();
		foreach ( $ids as $id ) {
			$canonical = 'https://www.ticketmaster.com/event/' . $id;

			$cases[ 'plain ' . $id ] = array(
				'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( $canonical ) . '&utm_medium=affiliate',
			);
			// ~54% production shape: entity-encoded ampersand.
			$cases[ 'entity ' . $id ] = array(
				'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( $canonical ) . '&amp;utm_medium=affiliate',
			);
			// Combined legacy artifact: literal six-character `\u0026`
			// escape + `&amp;`, exactly as double-JSON-encoded imports left
			// it in post_content.
			$cases[ 'u0026 artifact ' . $id ] = array(
				'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( $canonical ) . '\\u0026amp;utm_medium=affiliate',
			);
		}

		return $cases;
	}

	public function test_assembled_wrapper_matches_production_string_exactly(): void {
		$this->assertSame(
			self::STORED_WRAPPER_PLAIN,
			data_machine_events_assemble_affiliate_wrapper( self::CANONICAL_TM )
		);
	}

	/**
	 * Nested percent-encoding class: the Impact Radius-wrapped SeatGeek
	 * shape (real production example). The shared unwrapper decodes twice
	 * (parse_str + urldecode), so the extracted "canonical" has lost the
	 * inner URL's nested encoding and re-assembly can NOT reproduce the
	 * stored wrapper byte-for-byte. This is exactly the row class the
	 * backfill's round-trip guard refuses to convert — this test pins the
	 * mismatch so the guard's premise stays true.
	 */
	public function test_nested_encoding_wrapper_does_not_round_trip(): void {
		$stored     = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fseatgeek.com%2Fchris-brown-tickets%2Fsunrise-florida-amerant-bank-arena-2026-04-04-7-pm%2Fconcert%2F18082005%3Fdd_referrer%3Dhttps%253A%252F%252Famplify.seatgeek.com%252F&utm_medium=affiliate';
		$normalized = datamachine_decode_stored_url_artifacts( $stored );
		$canonical  = datamachine_unwrap_affiliate_url( $normalized );

		$this->assertNotSame( $canonical, $normalized, 'Nested-encoding wrapper must still unwrap (validation passes on the over-decoded URL).' );
		$this->assertNotSame(
			$normalized,
			data_machine_events_assemble_affiliate_wrapper( $canonical ),
			'Re-assembly must NOT be byte-identical for nested-encoding wrappers — the round-trip guard depends on this.'
		);
	}

	/**
	 * Mangled v0.8.39-era shape (`u=httpswww...`, punctuation stripped):
	 * the unwrapper cannot extract a valid inner URL and returns the
	 * wrapper unchanged. The backfill reports these as mangled and never
	 * writes them; issue #823 owns detection/repair.
	 */
	public function test_mangled_wrapper_does_not_unwrap(): void {
		$stored     = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=httpswww.ticketmaster.comeventZ7r9jZ1A7jv1d&utm_medium=affiliate';
		$normalized = datamachine_decode_stored_url_artifacts( $stored );

		$this->assertSame( $normalized, datamachine_unwrap_affiliate_url( $normalized ) );
		// Assembly passes it through unchanged too: the wrapper's own host
		// (evyy.net) is not a wrap-target host.
		$this->assertSame( $normalized, data_machine_events_assemble_affiliate_wrapper( $normalized ) );
	}

	public function test_rotating_affiliate_id_changes_output_without_touching_content(): void {
		add_filter(
			'data_machine_events_ticket_wrapper_config',
			static function ( array $config ): array {
				$config['affiliate_id'] = '9999999';
				return $config;
			}
		);

		$wrapped = data_machine_events_assemble_affiliate_wrapper( self::CANONICAL_TM );

		$this->assertSame(
			'https://ticketmaster.evyy.net/c/9999999/264167/4272?u=' . rawurlencode( self::CANONICAL_TM ) . '&utm_medium=affiliate',
			$wrapped
		);
	}

	public function test_rotating_campaign_and_ad_ids_changes_output(): void {
		add_filter(
			'data_machine_events_ticket_wrapper_config',
			static function ( array $config ): array {
				$config['campaign_id'] = '777';
				$config['ad_id']       = '888';
				return $config;
			}
		);

		$wrapped = data_machine_events_assemble_affiliate_wrapper( self::CANONICAL_TM );

		$this->assertStringStartsWith( 'https://ticketmaster.evyy.net/c/1191134/777/888?u=', $wrapped );
		$this->assertSame( rawurlencode( self::CANONICAL_TM ) . '&utm_medium=affiliate', substr( $wrapped, strpos( $wrapped, '?u=' ) + 3 ) );
	}

	public function test_disabled_config_returns_canonical_unchanged(): void {
		add_filter(
			'data_machine_events_ticket_wrapper_config',
			static function ( array $config ): array {
				$config['enabled'] = false;
				return $config;
			}
		);

		$this->assertSame( self::CANONICAL_TM, data_machine_events_assemble_affiliate_wrapper( self::CANONICAL_TM ) );
		$this->assertFalse( data_machine_events_is_gated_ticket_url( self::CANONICAL_TM ) );
	}

	public function test_template_without_destination_placeholder_is_ignored(): void {
		add_filter(
			'data_machine_events_ticket_wrapper_config',
			static function ( array $config ): array {
				$config['template'] = 'https://ticketmaster.evyy.net/c/{affiliate_id}?utm_medium=affiliate';
				return $config;
			}
		);

		$this->assertSame( self::CANONICAL_TM, data_machine_events_assemble_affiliate_wrapper( self::CANONICAL_TM ) );
	}

	/**
	 * @dataProvider not_wrapped_url_provider
	 */
	public function test_non_monetized_urls_pass_through_unwrapped( string $url ): void {
		$this->assertSame( $url, data_machine_events_assemble_affiliate_wrapper( $url ) );
		$this->assertFalse( data_machine_events_is_gated_ticket_url( $url ) );
	}

	public static function not_wrapped_url_provider(): array {
		return array(
			'dice.fm direct'          => array( 'https://link.dice.fm/x123' ),
			'ticketmaster.co.uk'      => array( 'https://www.ticketmaster.co.uk/event/ABC123' ),
			'ticketmaster.nl'         => array( 'https://www.ticketmaster.nl/event/ABC123' ),
			'lookalike host'          => array( 'https://notticketmaster.com/event/ABC123' ),
			'empty string'            => array( '' ),
			'etix direct'             => array( 'https://www.etix.com/ticket/p/123456/some-show' ),
		);
	}

	/**
	 * @dataProvider wrapped_or_canonical_monetized_provider
	 */
	public function test_gated_predicate_covers_both_stored_shapes( string $url ): void {
		$this->assertTrue( data_machine_events_is_gated_ticket_url( $url ) );
	}

	public static function wrapped_or_canonical_monetized_provider(): array {
		return array(
			'wrapper stored'        => array( self::STORED_WRAPPER_PLAIN ),
			'canonical stored'      => array( self::CANONICAL_TM ),
			'www-prefixed canonical' => array( 'https://www.ticketmaster.com/event/Z7r9jZ1A7Q4qb' ),
		);
	}

	public function test_default_config_shape(): void {
		$config = data_machine_events_ticket_wrapper_config();

		foreach ( array( 'enabled', 'template', 'affiliate_id', 'campaign_id', 'ad_id', 'apply_to_hosts' ) as $key ) {
			$this->assertArrayHasKey( $key, $config );
		}

		$this->assertTrue( $config['enabled'] );
		$this->assertStringContainsString( '{encoded_destination}', $config['template'] );
		$this->assertSame( array( 'ticketmaster.com', 'ticketweb.com' ), $config['apply_to_hosts'] );
	}

	/**
	 * TicketWeb is Ticketmaster's own sub-brand for smaller venues and is
	 * ~33% of the live wrapped corpus (24,620 of 74,643 published rows,
	 * verified by read-only production query) — NOT a "vendor we don't
	 * monetize." Confirmed byte-identical against a real stored production
	 * value, including the shape where the inner destination carries its own
	 * query string (`?REFERRAL_ID=tmfeed`).
	 *
	 * @dataProvider ticketweb_byte_identical_provider
	 */
	public function test_ticketweb_reassembly_is_byte_identical( string $stored ): void {
		$normalized = datamachine_decode_stored_url_artifacts( $stored );
		$canonical  = datamachine_unwrap_affiliate_url( $normalized );

		$this->assertNotSame( $canonical, $normalized );
		$this->assertSame( $normalized, data_machine_events_assemble_affiliate_wrapper( $canonical ) );
	}

	/**
	 * Real stored production wrappers for ticketweb.com destinations.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function ticketweb_byte_identical_provider(): array {
		return array(
			'no query string'  => array(
				'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fwww.ticketweb.com%2Fevent%2Fbailey-katsu-olivia-dolphin-band-middle-east-upstairs-tickets%2F14188854&utm_medium=affiliate',
			),
			'inner query string' => array(
				'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fwww.ticketweb.com%2Fevent%2Ftransmission-an-80s-dark-wave-thunderbird-lounge-tickets%2F13975154%3FREFERRAL_ID%3Dtmfeed&utm_medium=affiliate',
			),
			'u0026 artifact'    => array(
				'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fwww.ticketweb.com%2Fevent%2Fcountry-karaoke-showdown-saloon-tickets%2F14855173\\u0026amp;utm_medium=affiliate',
			),
		);
	}

	/**
	 * The long tail of one-off third-party box-office hosts (verified
	 * against real production examples) deliberately stays OUT of
	 * `apply_to_hosts` — each is a handful of rows, not a vendor Extra
	 * Chill has an affiliate relationship with beyond what Ticketmaster's
	 * own Discovery API happens to wrap. Assembly must not re-wrap these:
	 * the round-trip guard depends on assembly being a no-op for them.
	 *
	 * @dataProvider third_party_box_office_provider
	 */
	public function test_third_party_box_office_hosts_are_not_wrapped( string $canonical ): void {
		$this->assertSame( $canonical, data_machine_events_assemble_affiliate_wrapper( $canonical ) );
		$this->assertFalse( data_machine_events_is_gated_ticket_url( $canonical ) );
	}

	public static function third_party_box_office_provider(): array {
		return array(
			'axs.com'          => array( 'https://www.axs.com/events/1313694/reggae-on-the-rocks-2026-tickets' ),
			'evenue.net venue' => array( 'https://northernquest.evenue.net/events/071726' ),
			'etix.com'         => array( 'https://www.etix.com/ticket/p/65942571/death-angel-act-iiitour-asheville-the-orange-peel' ),
			'eventim.us'       => array( 'https://eventim.us/a5sicardhollow2026' ),
		);
	}
}
