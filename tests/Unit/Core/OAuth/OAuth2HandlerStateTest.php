<?php
/**
 * OAuth2Handler State Payload Tests
 *
 * Tests for the state payload propagation feature:
 * - Payload roundtrip (create → verify → recover payload)
 * - Empty payload
 * - Oversized payload rejection
 * - Malformed transient handling
 * - Expired transient handling
 * - Legacy plain-string state migration
 *
 * @package DataMachine\Tests\Unit\Core\OAuth
 */

namespace DataMachine\Tests\Unit\Core\OAuth;

use DataMachine\Core\OAuth\OAuth2Handler;
use WP_UnitTestCase;

class OAuth2HandlerStateTest extends WP_UnitTestCase {

	private OAuth2Handler $handler;

	public function set_up(): void {
		parent::set_up();
		$this->handler = new OAuth2Handler();
	}

	public function tear_down(): void {
		// Clean up any transients.
		delete_transient( 'datamachine_test_provider_oauth_state' );
		delete_transient( 'datamachine_test_roundtrip_oauth_state' );
		delete_transient( 'datamachine_test_empty_oauth_state' );
		delete_transient( 'datamachine_test_legacy_oauth_state' );
		delete_transient( 'datamachine_test_malformed_oauth_state' );
		delete_transient( 'datamachine_test_oversize_oauth_state' );
		delete_transient( 'datamachine_test_expired_oauth_state' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// create_state() basics
	// -------------------------------------------------------------------------

	public function test_create_state_returns_hex_string(): void {
		$state = $this->handler->create_state( 'test_provider' );

		$this->assertNotEmpty( $state );
		$this->assertSame( 64, strlen( $state ) ); // 32 bytes = 64 hex chars
		$this->assertMatchesRegularExpression( '/^[0-9a-f]+$/', $state );
	}

	public function test_create_state_stores_structured_record_in_transient(): void {
		$this->handler->create_state( 'test_provider', array( 'key' => 'value' ) );

		$record = get_transient( 'datamachine_test_provider_oauth_state' );

		$this->assertIsArray( $record );
		$this->assertArrayHasKey( 'nonce', $record );
		$this->assertArrayHasKey( 'payload', $record );
		$this->assertArrayHasKey( 'created_at', $record );
		$this->assertSame( array( 'key' => 'value' ), $record['payload'] );
	}

	public function test_create_state_without_payload_stores_empty_array(): void {
		$this->handler->create_state( 'test_provider' );

		$record = get_transient( 'datamachine_test_provider_oauth_state' );

		$this->assertIsArray( $record );
		$this->assertSame( array(), $record['payload'] );
	}

	// -------------------------------------------------------------------------
	// Payload roundtrip
	// -------------------------------------------------------------------------

	public function test_payload_roundtrip(): void {
		$payload = array(
			'artist_id' => 123,
			'return_to' => '/manage-artist/awesome-band',
			'request_id' => 'req_abc123',
		);

		$state = $this->handler->create_state( 'test_roundtrip', $payload );

		$result = $this->handler->verify_state( 'test_roundtrip', $state );

		$this->assertIsArray( $result );
		$this->assertSame( $payload, $result );
	}

	public function test_payload_with_nested_arrays(): void {
		$payload = array(
			'config'  => array(
				'mode' => 'advanced',
				'tags' => array( 'alpha', 'beta' ),
			),
			'user_id' => 42,
		);

		$state = $this->handler->create_state( 'test_roundtrip', $payload );

		$result = $this->handler->verify_state( 'test_roundtrip', $state );

		$this->assertSame( $payload, $result );
	}

	// -------------------------------------------------------------------------
	// Empty payload
	// -------------------------------------------------------------------------

	public function test_empty_payload_returns_empty_array_on_verify(): void {
		$state = $this->handler->create_state( 'test_empty' );

		$result = $this->handler->verify_state( 'test_empty', $state );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result );
		// Empty array is falsy in PHP — callers must use `false !== $result`.
		$this->assertTrue( false !== $result );
	}

	public function test_empty_payload_is_not_identical_to_false(): void {
		$state = $this->handler->create_state( 'test_empty' );

		$result = $this->handler->verify_state( 'test_empty', $state );

		// This is the critical assertion: empty array !== false.
		$this->assertNotFalse( $result );
	}

	// -------------------------------------------------------------------------
	// Oversized payload rejection
	// -------------------------------------------------------------------------

	public function test_oversized_payload_throws_exception(): void {
		// Create a payload that exceeds 4 KB when serialized.
		$payload = array(
			'data' => str_repeat( 'x', 5000 ),
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/exceeds maximum size/' );

		$this->handler->create_state( 'test_oversize', $payload );
	}

	public function test_payload_at_limit_is_accepted(): void {
		// Create a payload that's just under 4 KB.
		// Serialized array overhead is ~30 bytes, so use ~4050 bytes of data.
		$payload = array(
			'data' => str_repeat( 'x', 4000 ),
		);

		$serialized_size = strlen( maybe_serialize( $payload ) );

		// Only test if actually under the limit.
		if ( $serialized_size <= OAuth2Handler::MAX_PAYLOAD_SIZE ) {
			$state = $this->handler->create_state( 'test_provider', $payload );
			$this->assertNotEmpty( $state );
		} else {
			// If our test payload happens to exceed, adjust and skip.
			$this->markTestSkipped( 'Test payload exceeded size limit.' );
		}
	}

	// -------------------------------------------------------------------------
	// Malformed transient
	// -------------------------------------------------------------------------

	public function test_malformed_transient_returns_false(): void {
		// Simulate a corrupted/malformed transient (array without nonce key).
		set_transient( 'datamachine_test_malformed_oauth_state', array( 'garbage' => 'data' ), 900 );

		$result = $this->handler->verify_state( 'test_malformed', 'any_state_value' );

		$this->assertFalse( $result );
	}

	public function test_null_record_returns_false(): void {
		// No transient set at all.
		$result = $this->handler->verify_state( 'nonexistent_provider', 'any_state_value' );

		$this->assertFalse( $result );
	}

	public function test_empty_state_string_returns_false(): void {
		$this->handler->create_state( 'test_provider' );

		$result = $this->handler->verify_state( 'test_provider', '' );

		$this->assertFalse( $result );
	}

	public function test_wrong_nonce_returns_false(): void {
		$this->handler->create_state( 'test_provider', array( 'secret' => 'data' ) );

		$result = $this->handler->verify_state( 'test_provider', 'wrong_nonce_value' );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// Expired transient
	// -------------------------------------------------------------------------

	public function test_expired_transient_returns_false(): void {
		// WordPress transients with 0 TTL or manually deleted simulate expiry.
		$state = $this->handler->create_state( 'test_expired' );

		// Simulate expiration by deleting the transient.
		delete_transient( 'datamachine_test_expired_oauth_state' );

		$result = $this->handler->verify_state( 'test_expired', $state );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// Legacy plain-string state migration
	// -------------------------------------------------------------------------

	public function test_legacy_plain_string_state_verifies_correctly(): void {
		// Simulate the old format: plain hex string stored directly in transient.
		$legacy_state = bin2hex( random_bytes( 32 ) );
		set_transient( 'datamachine_test_legacy_oauth_state', $legacy_state, 900 );

		$result = $this->handler->verify_state( 'test_legacy', $legacy_state );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result ); // Legacy states have no payload.
	}

	public function test_legacy_plain_string_state_deletes_transient_on_success(): void {
		$legacy_state = bin2hex( random_bytes( 32 ) );
		set_transient( 'datamachine_test_legacy_oauth_state', $legacy_state, 900 );

		$this->handler->verify_state( 'test_legacy', $legacy_state );

		// Transient should be consumed.
		$this->assertFalse( get_transient( 'datamachine_test_legacy_oauth_state' ) );
	}

	public function test_legacy_plain_string_state_rejects_wrong_nonce(): void {
		$legacy_state = bin2hex( random_bytes( 32 ) );
		set_transient( 'datamachine_test_legacy_oauth_state', $legacy_state, 900 );

		$result = $this->handler->verify_state( 'test_legacy', 'wrong_value' );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// State consumption (one-time use)
	// -------------------------------------------------------------------------

	public function test_state_is_consumed_after_verify(): void {
		$state = $this->handler->create_state( 'test_provider', array( 'once' => true ) );

		// First verification succeeds.
		$result = $this->handler->verify_state( 'test_provider', $state );
		$this->assertNotFalse( $result );

		// Second verification fails (consumed).
		$result2 = $this->handler->verify_state( 'test_provider', $state );
		$this->assertFalse( $result2 );
	}

	// -------------------------------------------------------------------------
	// get_state_payload() alias
	// -------------------------------------------------------------------------

	public function test_get_state_payload_is_alias_for_verify_state(): void {
		$payload = array( 'artist_id' => 456 );
		$state   = $this->handler->create_state( 'test_provider', $payload );

		$result = $this->handler->get_state_payload( 'test_provider', $state );

		$this->assertSame( $payload, $result );
	}

	public function test_get_state_payload_returns_false_on_failure(): void {
		$result = $this->handler->get_state_payload( 'test_provider', 'bogus' );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// MAX_PAYLOAD_SIZE constant
	// -------------------------------------------------------------------------

	public function test_max_payload_size_constant_is_4096(): void {
		$this->assertSame( 4096, OAuth2Handler::MAX_PAYLOAD_SIZE );
	}
}
