<?php
/**
 * BaseAuthProvider Encryption Tests
 *
 * Pure unit tests for the at-rest encryption layer.
 * No WordPress dependency — tests run with the bootstrap-unit.php stub.
 *
 * @package DataMachine\Tests\Unit\Core\OAuth
 */

namespace DataMachine\Tests\Unit\Core\OAuth;

use PHPUnit\Framework\TestCase;
use DataMachine\Core\OAuth\BaseAuthProvider;

/**
 * Concrete test double that exposes protected encryption methods.
 *
 * Also stubs wp_salt() and do_action() for the pure-unit context.
 */
class EncryptionTestProvider extends BaseAuthProvider {

	/**
	 * Fixed salt for deterministic key derivation in tests.
	 */
	private string $test_salt = 'test-auth-salt-value-for-unit-tests';

	public function get_config_fields(): array {
		return array();
	}

	public function is_authenticated(): bool {
		return false;
	}

	/**
	 * Expose encrypt_fields for testing.
	 */
	public function test_encrypt_fields( array $data ): array {
		return $this->encrypt_fields( $data );
	}

	/**
	 * Expose decrypt_fields for testing.
	 */
	public function test_decrypt_fields( array $data ): array {
		return $this->decrypt_fields( $data );
	}

	/**
	 * Expose get_encrypted_fields for testing.
	 */
	public function test_get_encrypted_fields(): array {
		return $this->get_encrypted_fields();
	}

	/**
	 * Set a custom salt for testing key derivation.
	 */
	public function set_test_salt( string $salt ): void {
		$this->test_salt = $salt;
	}

	/**
	 * Get the test salt (used by the wp_salt stub).
	 */
	public function get_test_salt(): string {
		return $this->test_salt;
	}
}

/**
 * Tests for BaseAuthProvider encryption layer.
 */
class BaseAuthProviderEncryptionTest extends TestCase {

	private EncryptionTestProvider $provider;

	/**
	 * Track the salt that wp_salt() should return.
	 */
	private static string $current_salt = 'test-auth-salt-value-for-unit-tests';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// Load global-namespace stubs for wp_salt(), do_action(), apply_filters().
		require_once __DIR__ . '/encryption-test-stubs.php';
	}

	public static function getSalt(): string {
		return self::$current_salt;
	}

	protected function setUp(): void {
		parent::setUp();
		self::$current_salt = 'test-auth-salt-value-for-unit-tests';
		$this->provider     = new EncryptionTestProvider( 'test_provider' );
	}

	// -------------------------------------------------------------------------
	// Encrypted fields list
	// -------------------------------------------------------------------------

	public function test_encrypted_fields_contains_expected_defaults(): void {
		$fields = $this->provider->test_get_encrypted_fields();

		$this->assertContains( 'access_token', $fields );
		$this->assertContains( 'refresh_token', $fields );
		$this->assertContains( 'client_secret', $fields );
		$this->assertContains( 'oauth_token', $fields );
		$this->assertContains( 'oauth_token_secret', $fields );
		$this->assertContains( 'app_secret', $fields );
		$this->assertContains( 'consumer_secret', $fields );
		$this->assertContains( 'api_secret', $fields );
		$this->assertContains( 'webhook_secret', $fields );
	}

	// -------------------------------------------------------------------------
	// Encrypt → Decrypt roundtrip
	// -------------------------------------------------------------------------

	public function test_encrypt_decrypt_roundtrip_access_token(): void {
		$data = array(
			'access_token' => 'my-secret-token-12345',
			'username'     => 'testuser',
		);

		$encrypted = $this->provider->test_encrypt_fields( $data );

		// access_token should be encrypted (starts with envelope prefix).
		$this->assertStringStartsWith( 'dm:enc:v1:', $encrypted['access_token'] );
		// username is NOT an encrypted field — should remain plaintext.
		$this->assertSame( 'testuser', $encrypted['username'] );

		// Decrypt should restore the original value.
		$decrypted = $this->provider->test_decrypt_fields( $encrypted );
		$this->assertSame( 'my-secret-token-12345', $decrypted['access_token'] );
		$this->assertSame( 'testuser', $decrypted['username'] );
	}

	public function test_encrypt_decrypt_roundtrip_multiple_fields(): void {
		$data = array(
			'access_token'  => 'access-tok-abc',
			'refresh_token' => 'refresh-tok-xyz',
			'client_secret' => 'client-secret-123',
			'user_id'       => '12345',
			'username'      => 'myuser',
		);

		$encrypted = $this->provider->test_encrypt_fields( $data );

		// All sensitive fields encrypted.
		$this->assertStringStartsWith( 'dm:enc:v1:', $encrypted['access_token'] );
		$this->assertStringStartsWith( 'dm:enc:v1:', $encrypted['refresh_token'] );
		$this->assertStringStartsWith( 'dm:enc:v1:', $encrypted['client_secret'] );

		// Non-sensitive fields untouched.
		$this->assertSame( '12345', $encrypted['user_id'] );
		$this->assertSame( 'myuser', $encrypted['username'] );

		// Full roundtrip.
		$decrypted = $this->provider->test_decrypt_fields( $encrypted );
		$this->assertSame( $data, $decrypted );
	}

	public function test_encrypt_decrypt_roundtrip_with_special_characters(): void {
		$token = 'tok/abc+def==123&foo=bar<script>"\'\\';

		$data      = array( 'access_token' => $token );
		$encrypted = $this->provider->test_encrypt_fields( $data );
		$decrypted = $this->provider->test_decrypt_fields( $encrypted );

		$this->assertSame( $token, $decrypted['access_token'] );
	}

	public function test_encrypt_decrypt_roundtrip_with_long_token(): void {
		// Simulate a JWT-like token (2000+ chars).
		$token = str_repeat( 'abcdefghijklmnop', 200 );

		$data      = array( 'access_token' => $token );
		$encrypted = $this->provider->test_encrypt_fields( $data );
		$decrypted = $this->provider->test_decrypt_fields( $encrypted );

		$this->assertSame( $token, $decrypted['access_token'] );
	}

	public function test_encrypt_decrypt_roundtrip_with_unicode(): void {
		$token = 'tok_' . "\xF0\x9F\x94\x91" . '_key_emoji';

		$data      = array( 'access_token' => $token );
		$encrypted = $this->provider->test_encrypt_fields( $data );
		$decrypted = $this->provider->test_decrypt_fields( $encrypted );

		$this->assertSame( $token, $decrypted['access_token'] );
	}

	// -------------------------------------------------------------------------
	// Plaintext passthrough (backward compatibility)
	// -------------------------------------------------------------------------

	public function test_plaintext_values_pass_through_on_decrypt(): void {
		// Simulate legacy data stored before encryption was added.
		$data = array(
			'access_token' => 'plaintext-legacy-token',
			'username'     => 'olduser',
		);

		$decrypted = $this->provider->test_decrypt_fields( $data );

		// Should return data unchanged.
		$this->assertSame( 'plaintext-legacy-token', $decrypted['access_token'] );
		$this->assertSame( 'olduser', $decrypted['username'] );
	}

	public function test_empty_string_not_encrypted(): void {
		$data = array(
			'access_token' => '',
			'username'     => 'user',
		);

		$encrypted = $this->provider->test_encrypt_fields( $data );

		// Empty strings should not be encrypted.
		$this->assertSame( '', $encrypted['access_token'] );
	}

	public function test_null_value_not_encrypted(): void {
		$data = array(
			'access_token' => null,
			'username'     => 'user',
		);

		$encrypted = $this->provider->test_encrypt_fields( $data );

		// Null values should pass through (not a string).
		$this->assertNull( $encrypted['access_token'] );
	}

	public function test_non_string_values_not_encrypted(): void {
		$data = array(
			'access_token' => 12345,
			'refresh_token' => true,
		);

		$encrypted = $this->provider->test_encrypt_fields( $data );

		// Non-string values should not be touched.
		$this->assertSame( 12345, $encrypted['access_token'] );
		$this->assertSame( true, $encrypted['refresh_token'] );
	}

	// -------------------------------------------------------------------------
	// Double-encryption prevention
	// -------------------------------------------------------------------------

	public function test_already_encrypted_values_not_double_encrypted(): void {
		$data = array(
			'access_token' => 'original-token',
		);

		// Encrypt once.
		$encrypted_once = $this->provider->test_encrypt_fields( $data );
		$this->assertStringStartsWith( 'dm:enc:v1:', $encrypted_once['access_token'] );

		// Encrypt again — should not double-encrypt.
		$encrypted_twice = $this->provider->test_encrypt_fields( $encrypted_once );
		$this->assertSame( $encrypted_once['access_token'], $encrypted_twice['access_token'] );

		// Should still decrypt to original.
		$decrypted = $this->provider->test_decrypt_fields( $encrypted_twice );
		$this->assertSame( 'original-token', $decrypted['access_token'] );
	}

	// -------------------------------------------------------------------------
	// Malformed envelope handling
	// -------------------------------------------------------------------------

	public function test_malformed_envelope_missing_separator_returns_null(): void {
		$data = array(
			'access_token' => 'dm:enc:v1:missingsecondsegment',
		);

		$decrypted = $this->provider->test_decrypt_fields( $data );

		// On failure, the encrypted blob should be returned as-is.
		$this->assertSame( 'dm:enc:v1:missingsecondsegment', $decrypted['access_token'] );
	}

	public function test_malformed_envelope_invalid_base64_returns_original(): void {
		$data = array(
			'access_token' => 'dm:enc:v1:!!!invalid!!!:!!!invalid!!!:!!!invalid!!!',
		);

		$decrypted = $this->provider->test_decrypt_fields( $data );

		// Should return the encrypted blob as-is on failure.
		$this->assertSame( 'dm:enc:v1:!!!invalid!!!:!!!invalid!!!:!!!invalid!!!', $decrypted['access_token'] );
	}

	public function test_malformed_envelope_wrong_iv_length_returns_original(): void {
		// Valid base64 but IV is too short (should be 12 bytes for AES-256-GCM).
		$short_iv   = base64_encode( 'short' );
		$tag        = base64_encode( str_repeat( 't', BaseAuthProvider::AUTH_TAG_LENGTH ) );
		$ciphertext = base64_encode( 'fakeciphertext' );
		$envelope   = "dm:enc:v1:{$short_iv}:{$tag}:{$ciphertext}";

		$data      = array( 'access_token' => $envelope );
		$decrypted = $this->provider->test_decrypt_fields( $data );

		$this->assertSame( $envelope, $decrypted['access_token'] );
	}

	public function test_corrupted_ciphertext_returns_original(): void {
		$data = array( 'access_token' => 'valid-token' );

		$encrypted = $this->provider->test_encrypt_fields( $data );

		// Corrupt the ciphertext portion.
		$parts    = explode( ':', $encrypted['access_token'] );
		$parts[5] = base64_encode( 'corrupted-garbage-data' );
		$corrupted_envelope = implode( ':', $parts );

		$corrupted_data = array( 'access_token' => $corrupted_envelope );
		$decrypted      = $this->provider->test_decrypt_fields( $corrupted_data );

		// Should return corrupted blob as-is (not silently empty).
		$this->assertSame( $corrupted_envelope, $decrypted['access_token'] );
	}

	// -------------------------------------------------------------------------
	// Key derivation determinism
	// -------------------------------------------------------------------------

	public function test_same_salt_produces_same_encrypted_result_after_decrypt(): void {
		// Encrypt with one provider instance.
		$provider_a = new EncryptionTestProvider( 'provider_a' );
		$data       = array( 'access_token' => 'shared-token' );

		$encrypted = $provider_a->test_encrypt_fields( $data );

		// Decrypt with another provider instance using the same salt.
		$provider_b = new EncryptionTestProvider( 'provider_b' );
		$decrypted  = $provider_b->test_decrypt_fields( $encrypted );

		$this->assertSame( 'shared-token', $decrypted['access_token'] );
	}

	public function test_modified_auth_tag_cannot_decrypt_data(): void {
		$data      = array( 'access_token' => 'secret-value' );
		$encrypted = $this->provider->test_encrypt_fields( $data );

		$parts    = explode( ':', $encrypted['access_token'] );
		$parts[4] = base64_encode( str_repeat( 'x', BaseAuthProvider::AUTH_TAG_LENGTH ) );
		$modified = implode( ':', $parts );

		$decrypted = $this->provider->test_decrypt_fields( array( 'access_token' => $modified ) );

		// Authenticated encryption must reject tampered envelopes.
		$this->assertSame( $modified, $decrypted['access_token'] );
	}

	public function test_key_derivation_is_deterministic(): void {
		// Same salt should produce consistent encrypt/decrypt across calls.
		self::$current_salt = 'deterministic-salt-xyz';

		$data = array( 'access_token' => 'test-token-123' );

		// First encrypt/decrypt cycle.
		$encrypted_1 = $this->provider->test_encrypt_fields( $data );
		$decrypted_1 = $this->provider->test_decrypt_fields( $encrypted_1 );

		// Second encrypt/decrypt cycle.
		$encrypted_2 = $this->provider->test_encrypt_fields( $data );
		$decrypted_2 = $this->provider->test_decrypt_fields( $encrypted_2 );

		// Both should decrypt to the same value.
		$this->assertSame( 'test-token-123', $decrypted_1['access_token'] );
		$this->assertSame( 'test-token-123', $decrypted_2['access_token'] );

		// But the encrypted values should differ (unique IV each time).
		$this->assertNotSame( $encrypted_1['access_token'], $encrypted_2['access_token'] );
	}

	// -------------------------------------------------------------------------
	// Envelope format verification
	// -------------------------------------------------------------------------

	public function test_encrypted_envelope_format(): void {
		$data      = array( 'access_token' => 'format-test-token' );
		$encrypted = $this->provider->test_encrypt_fields( $data );

		$envelope = $encrypted['access_token'];

		// Should match: dm:enc:v1:{base64}:{base64}:{base64}
		$this->assertMatchesRegularExpression(
			'/^dm:enc:v1:[A-Za-z0-9+\/=]+:[A-Za-z0-9+\/=]+:[A-Za-z0-9+\/=]+$/',
			$envelope
		);

		// Split and validate parts.
		$parts = explode( ':', $envelope );
		$this->assertCount( 6, $parts );
		$this->assertSame( 'dm', $parts[0] );
		$this->assertSame( 'enc', $parts[1] );
		$this->assertSame( 'v1', $parts[2] );

		// IV should decode to the AES-256-GCM IV length.
		$iv = base64_decode( $parts[3], true );
		$this->assertNotFalse( $iv );
		$this->assertSame( openssl_cipher_iv_length( BaseAuthProvider::CIPHER_ALGO ), strlen( $iv ) );

		// Authentication tag should decode to the configured length.
		$tag = base64_decode( $parts[4], true );
		$this->assertNotFalse( $tag );
		$this->assertSame( BaseAuthProvider::AUTH_TAG_LENGTH, strlen( $tag ) );

		// Ciphertext should be valid base64.
		$this->assertNotFalse( base64_decode( $parts[5], true ) );
	}

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	public function test_encryption_prefix_constant(): void {
		$this->assertSame( 'dm:enc:v1:', BaseAuthProvider::ENCRYPTION_PREFIX );
	}

	public function test_cipher_algo_constant(): void {
		$this->assertSame( 'aes-256-gcm', BaseAuthProvider::CIPHER_ALGO );
		// Verify the algorithm is available on this system.
		$this->assertContains( 'aes-256-gcm', openssl_get_cipher_methods() );
	}

	public function test_auth_tag_length_constant(): void {
		$this->assertSame( 16, BaseAuthProvider::AUTH_TAG_LENGTH );
	}

	// -------------------------------------------------------------------------
	// Edge cases
	// -------------------------------------------------------------------------

	public function test_data_with_no_encrypted_fields_passes_through(): void {
		$data = array(
			'username' => 'testuser',
			'user_id'  => '123',
			'scope'    => 'read write',
		);

		$encrypted = $this->provider->test_encrypt_fields( $data );
		$this->assertSame( $data, $encrypted );

		$decrypted = $this->provider->test_decrypt_fields( $data );
		$this->assertSame( $data, $decrypted );
	}

	public function test_empty_array_passes_through(): void {
		$this->assertSame( array(), $this->provider->test_encrypt_fields( array() ) );
		$this->assertSame( array(), $this->provider->test_decrypt_fields( array() ) );
	}

	public function test_field_with_prefix_like_text_not_treated_as_encrypted(): void {
		// A field named 'description' that happens to start with "dm:enc:" but
		// is not in the encrypted fields list should NOT be decrypted.
		$data = array(
			'description' => 'dm:enc:v1:some:thing',
		);

		$decrypted = $this->provider->test_decrypt_fields( $data );
		$this->assertSame( 'dm:enc:v1:some:thing', $decrypted['description'] );
	}
}
