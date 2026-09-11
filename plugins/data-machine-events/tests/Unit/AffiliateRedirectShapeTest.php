<?php
/**
 * Affiliate redirect shape detection and repair tests.
 *
 * Covers the shape-based corrupted-redirect detector and the
 * confidence-gated reconstruction introduced for issue #823. Detection must
 * key on the scheme/host shape of the redirect parameter — never on a
 * literal corruption signature — and repair must skip rather than guess
 * whenever the canonical destination cannot be reconstructed uniquely.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.63.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\AffiliateRedirectShape;

use function DataMachineEvents\Core\datamachine_decode_stored_url_artifacts;
use function DataMachineEvents\Core\datamachine_find_affiliate_redirect_param;
use function DataMachineEvents\Core\datamachine_unwrap_affiliate_url;

class AffiliateRedirectShapeTest extends WP_UnitTestCase {

	/**
	 * Real production corrupted wrapper (post 46): no-slug Ticketmaster
	 * destination, punctuation stripped.
	 */
	private const CORRUPTED_TM_NOSLUG = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=httpswww.ticketmaster.comeventZ7r9jZ1A7jv1d&utm_medium=affiliate';

	/**
	 * Real production corrupted wrapper (post 45): slugged Ticketmaster
	 * destination.
	 */
	private const CORRUPTED_TM_SLUG = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=httpswww.ticketmaster.comcharlton-singletons-holiday-spectacular-charleston-south-carolina-11-29-2025event2D006315C1AC7E79&utm_medium=affiliate';

	/**
	 * Real production corrupted wrapper (post 359740): Ticketweb destination
	 * in an organizerUrl.
	 */
	private const CORRUPTED_TW = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=httpswww.ticketweb.comeventold-school-rb-crescent-ballroom-tickets14280894&utm_medium=affiliate';

	/**
	 * Real production corrupted wrapper (post 18641): query punctuation
	 * stripped too — a MORE corrupted variant.
	 */
	private const CORRUPTED_TW_QUERY = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=httpswww.ticketweb.comeventvalgur-andy-loebs-zom6ii-nikki-lopez-philly-tickets14178484REFERRAL_IDtmfeed&utm_medium=affiliate';

	/**
	 * Healthy wrapper shape, percent-encoded inner URL — the dominant
	 * stored form.
	 */
	private const HEALTHY_WRAPPED = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fwww.ticketmaster.com%2Fevent%2FZ7r9jZ1A7jv1d&utm_medium=affiliate';

	// ---------------------------------------------------------------
	// Detection
	// ---------------------------------------------------------------

	public function test_corrupted_ticketmaster_redirect_is_detected(): void {
		$corrupted = AffiliateRedirectShape::find_corrupted_redirect( self::CORRUPTED_TM_NOSLUG );

		$this->assertNotNull( $corrupted );
		$this->assertSame( 'u', $corrupted['param'] );
		$this->assertSame( 'httpswww.ticketmaster.comeventZ7r9jZ1A7jv1d', $corrupted['value'] );
	}

	public function test_corrupted_slugged_redirect_is_detected(): void {
		$this->assertNotNull( AffiliateRedirectShape::find_corrupted_redirect( self::CORRUPTED_TM_SLUG ) );
	}

	public function test_corrupted_ticketweb_redirect_is_detected(): void {
		$corrupted = AffiliateRedirectShape::find_corrupted_redirect( self::CORRUPTED_TW );

		$this->assertNotNull( $corrupted );
		$this->assertSame( 'httpswww.ticketweb.comeventold-school-rb-crescent-ballroom-tickets14280894', $corrupted['value'] );
	}

	public function test_detection_is_shape_based_not_string_based(): void {
		// A DIFFERENT corruption variant — scheme colon kept but slashes
		// stripped — must still be detected. Matching the literal "httpswww"
		// would miss this; the scheme/host assertion catches it.
		$wrapped = 'https://evyy.net/c/1?u=https%3Awww.ticketmaster.comeventZ7r9jZ1A7jv1d';

		$corrupted = AffiliateRedirectShape::find_corrupted_redirect( $wrapped );

		$this->assertNotNull( $corrupted );
		$this->assertSame( 'https:www.ticketmaster.comeventZ7r9jZ1A7jv1d', $corrupted['value'] );
		// Detected, but no known destination shape → repair skips.
		$this->assertNull( AffiliateRedirectShape::reconstruct_destination( $corrupted['value'] ) );
	}

	public function test_healthy_encoded_wrapper_is_not_flagged(): void {
		$this->assertNull( AffiliateRedirectShape::find_corrupted_redirect( self::HEALTHY_WRAPPED ) );
	}

	public function test_healthy_plain_wrapper_is_not_flagged(): void {
		$wrapped = 'https://ticketmaster.evyy.net/c/1?u=https://www.ticketmaster.com/event/Z7r9jZ1A7jv1d&utm_medium=affiliate';

		$this->assertNull( AffiliateRedirectShape::find_corrupted_redirect( $wrapped ) );
	}

	public function test_non_affiliate_url_with_garbage_param_is_ignored(): void {
		$direct = 'https://www.ticketmaster.com/event/12345?u=httpswww.garbage-value';

		$this->assertNull( AffiliateRedirectShape::find_corrupted_redirect( $direct ) );
	}

	public function test_wrapper_without_redirect_param_is_not_flagged(): void {
		$wrapped = 'https://ticketmaster.evyy.net/c/1191134/264167/4272';

		$this->assertNull( AffiliateRedirectShape::find_corrupted_redirect( $wrapped ) );
	}

	public function test_detection_survives_json_escape_and_entity_artifacts(): void {
		// The `\\u0026` bytes in post_content JSON-decode to a literal
		// `\u0026` sequence inside the attr value; `&amp;` also appears on
		// some rows (real production post 24366).
		$wrapped = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=httpswww.ticketweb.comeventlive-again-tribute-fest-the-premier-tickets14123154\u0026amp;utm_medium=affiliate';

		$corrupted = AffiliateRedirectShape::find_corrupted_redirect( $wrapped );

		$this->assertNotNull( $corrupted );
		$this->assertSame(
			'httpswww.ticketweb.comeventlive-again-tribute-fest-the-premier-tickets14123154',
			$corrupted['value']
		);
	}

	// ---------------------------------------------------------------
	// Reconstruction (confidence-gated)
	// ---------------------------------------------------------------

	public function test_reconstruct_ticketmaster_without_slug(): void {
		$this->assertSame(
			'https://www.ticketmaster.com/event/Z7r9jZ1A7jv1d',
			AffiliateRedirectShape::reconstruct_destination( 'httpswww.ticketmaster.comeventZ7r9jZ1A7jv1d' )
		);
	}

	public function test_reconstruct_ticketmaster_with_slug(): void {
		$this->assertSame(
			'https://www.ticketmaster.com/event/2D006315C1AC7E79',
			AffiliateRedirectShape::reconstruct_destination(
				'httpswww.ticketmaster.comcharlton-singletons-holiday-spectacular-charleston-south-carolina-11-29-2025event2D006315C1AC7E79'
			)
		);
	}

	public function test_reconstruct_ticketmaster_with_underscore_id(): void {
		// Real production value (post 2208).
		$this->assertSame(
			'https://www.ticketmaster.com/event/171982_FriFriday',
			AffiliateRedirectShape::reconstruct_destination( 'httpswww.ticketmaster.comevent171982_FriFriday' )
		);
	}

	public function test_ticketweb_is_detected_but_repair_is_withheld(): void {
		// Ticketweb corruption is detected by the shape check, but
		// reconstruction is deliberately withheld until the canonical
		// Ticketweb path shape is confirmed — these rows are reported and
		// skipped, never guessed.
		$corrupted = AffiliateRedirectShape::find_corrupted_redirect( self::CORRUPTED_TW );

		$this->assertNotNull( $corrupted );
		$this->assertSame( 'httpswww.ticketweb.comeventold-school-rb-crescent-ballroom-tickets14280894', $corrupted['value'] );
		$this->assertNull( AffiliateRedirectShape::reconstruct_destination( $corrupted['value'] ) );
		$this->assertNull( AffiliateRedirectShape::repair_stored_url( self::CORRUPTED_TW ) );
	}

	public function test_unknown_destination_shape_is_skipped(): void {
		$this->assertNull(
			AffiliateRedirectShape::reconstruct_destination( 'httpswww.some-other-platform.comevent12345' )
		);
	}

	public function test_query_stripped_variant_is_ambiguous_and_skipped(): void {
		// The REFERRAL_ID variant stripped `?` and `=` too; the trailing
		// garbage breaks every known path shape — skip, never guess.
		$corrupted = AffiliateRedirectShape::find_corrupted_redirect( self::CORRUPTED_TW_QUERY );

		$this->assertNotNull( $corrupted );
		$this->assertNull( AffiliateRedirectShape::reconstruct_destination( $corrupted['value'] ) );
		$this->assertNull( AffiliateRedirectShape::repair_stored_url( self::CORRUPTED_TW_QUERY ) );
	}

	public function test_ambiguous_marker_position_is_skipped(): void {
		// Two valid split points: slug "my" + id "eventABC123" OR slug
		// "myevent" + id "ABC123". Unknowable — must return null.
		$this->assertNull(
			AffiliateRedirectShape::reconstruct_destination( 'httpswww.ticketmaster.commyeventeventABC123' )
		);
	}

	// ---------------------------------------------------------------
	// Repair (wrapper rebuild)
	// ---------------------------------------------------------------

	public function test_repair_rebuilds_wrapper_preserving_base_and_other_params(): void {
		$repair = AffiliateRedirectShape::repair_stored_url( self::CORRUPTED_TM_NOSLUG );

		$this->assertNotNull( $repair );
		$this->assertSame( 'u', $repair['param'] );
		$this->assertSame( self::CORRUPTED_TM_NOSLUG, $repair['before'] );
		$this->assertSame( 'https://www.ticketmaster.com/event/Z7r9jZ1A7jv1d', $repair['destination'] );
		// Rebuilt wrapper matches the healthy stored shape byte-for-byte.
		$this->assertSame( self::HEALTHY_WRAPPED, $repair['after'] );
	}

	public function test_repair_ticketweb_returns_null_pending_shape_confirmation(): void {
		$this->assertNull( AffiliateRedirectShape::repair_stored_url( self::CORRUPTED_TW ) );
	}

	public function test_repair_healthy_url_returns_null(): void {
		$this->assertNull( AffiliateRedirectShape::repair_stored_url( self::HEALTHY_WRAPPED ) );
	}

	public function test_repair_non_affiliate_url_returns_null(): void {
		$this->assertNull( AffiliateRedirectShape::repair_stored_url( 'https://www.ticketmaster.com/event/12345' ) );
	}

	// ---------------------------------------------------------------
	// Absolute URL shape assertion
	// ---------------------------------------------------------------

	public function test_absolute_url_assertion(): void {
		$this->assertTrue( AffiliateRedirectShape::is_absolute_url( 'https://www.example.com/x' ) );
		$this->assertTrue( AffiliateRedirectShape::is_absolute_url( 'http://example.com' ) );
		// Punctuation-stripped concatenation: no scheme, no host.
		$this->assertFalse( AffiliateRedirectShape::is_absolute_url( 'httpswww.ticketmaster.comeventZ7r9jZ1A7jv1d' ) );
		// Scheme-only corruption variant (colon kept, slashes stripped).
		$this->assertFalse( AffiliateRedirectShape::is_absolute_url( 'https:www.ticketmaster.com/event/x' ) );
		$this->assertFalse( AffiliateRedirectShape::is_absolute_url( '' ) );
	}

	// ---------------------------------------------------------------
	// Shared extractor + artifact decode helpers
	// ---------------------------------------------------------------

	public function test_find_affiliate_redirect_param_returns_first_present_param(): void {
		foreach ( array( 'u', 'url', 'murl', 'destination' ) as $param ) {
			$found = datamachine_find_affiliate_redirect_param( 'https://evyy.net/c/1?' . $param . '=https%3A%2F%2Fexample.com' );

			$this->assertNotNull( $found, "Failed for redirect param \"{$param}\"" );
			$this->assertSame( $param, $found['param'] );
			$this->assertSame( 'https://example.com', $found['value'] );
		}

		$this->assertNull( datamachine_find_affiliate_redirect_param( 'https://evyy.net/c/1' ) );
		$this->assertNull( datamachine_find_affiliate_redirect_param( 'https://evyy.net/c/1?other=x' ) );
	}

	public function test_unwrap_fails_closed_on_corrupted_first_redirect_param(): void {
		// A wrapper whose 'u' param is corrupted must not silently unwrap to
		// a later valid param — masking the corruption was the wrong default.
		$wrapped = 'https://evyy.net/c/1?u=httpswww.ticketmaster.comeventX&url=https%3A%2F%2Fexample.com';

		$this->assertSame( $wrapped, datamachine_unwrap_affiliate_url( $wrapped ) );
	}

	public function test_unwrap_still_unwraps_healthy_wrappers(): void {
		$this->assertSame(
			'https://www.ticketmaster.com/event/Z7r9jZ1A7jv1d',
			datamachine_unwrap_affiliate_url( self::HEALTHY_WRAPPED )
		);
	}

	public function test_decode_stored_url_artifacts_reverses_both_layers(): void {
		$this->assertSame(
			'https://evyy.net/c/1?u=x&utm_medium=affiliate',
			datamachine_decode_stored_url_artifacts( 'https://evyy.net/c/1?u=x\u0026amp;utm_medium=affiliate' )
		);
		$this->assertSame(
			'https://evyy.net/c/1?u=x&utm_medium=affiliate',
			datamachine_decode_stored_url_artifacts( 'https://evyy.net/c/1?u=x\u0026utm_medium=affiliate' )
		);
		$this->assertSame(
			'https://evyy.net/c/1?u=x&utm_medium=affiliate',
			datamachine_decode_stored_url_artifacts( 'https://evyy.net/c/1?u=x&amp;utm_medium=affiliate' )
		);
		// Healthy URL round-trips unchanged.
		$this->assertSame( self::HEALTHY_WRAPPED, datamachine_decode_stored_url_artifacts( self::HEALTHY_WRAPPED ) );
		$this->assertSame( '', datamachine_decode_stored_url_artifacts( '' ) );
	}
}
