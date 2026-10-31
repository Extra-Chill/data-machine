<?php
/**
 * Tests for the RFC 7523 JWT-bearer service account primitive.
 *
 * The signature is verified cryptographically against a real RSA keypair
 * rather than asserted by shape — a JWT that parses but does not verify is
 * exactly the bug this primitive exists to prevent recurring.
 *
 * @package DataMachine\Tests\Unit\Auth
 */

namespace DataMachine\Tests\Unit\Auth;

use DataMachine\Core\OAuth\BaseServiceAccountProvider;
use WP_UnitTestCase;

/**
 * Concrete stub used to exercise the primitive.
 *
 * Config is injected directly so the test exercises the token mechanism
 * without depending on stored auth data.
 */
class StubServiceAccountProvider extends BaseServiceAccountProvider {

	/** @var array */
	public $injected_config = array();

	/** @var array Requests captured instead of sent. */
	public $requests = array();

	/** @var array|null Response to return, or null for a default success. */
	public $response = null;

	public function __construct() {
		parent::__construct( 'test_service_account' );
	}

	public function get_token_endpoint(): string {
		return 'https://oauth2.googleapis.com/token';
	}

	public function get_config( array $context = array() ): array {
		return $this->injected_config;
	}

	protected function exchange_assertion( string $assertion ) {
		$this->requests[] = $assertion;

		if ( null !== $this->response ) {
			return $this->response;
		}

		return 'test-access-token';
	}
}

class BaseServiceAccountProviderTest extends WP_UnitTestCase {

	/** @var string */
	private static $private_key = '';

	/** @var string */
	private static $public_key = '';

	/**
	 * Load a committed test keypair.
	 *
	 * Generated once and committed rather than created per run: the sandbox
	 * PHP build cannot generate keys (openssl_pkey_new() returns false), and a
	 * fixed key also keeps the signature assertion deterministic. This key is
	 * a test fixture and protects nothing.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$dir = dirname( __DIR__, 2 ) . '/fixtures/service-account';

		self::$private_key = (string) file_get_contents( $dir . '/test-key.pem' );
		self::$public_key  = (string) file_get_contents( $dir . '/test-key.pub' );
	}

	private function provider(): StubServiceAccountProvider {
		$provider                  = new StubServiceAccountProvider();
		$provider->injected_config = array(
			'client_email' => 'svc@project.iam.gserviceaccount.com',
			'private_key'  => self::$private_key,
		);

		return $provider;
	}

	private function decode_segment( string $segment ): array {
		$padded = strtr( $segment, '-_', '+/' );
		$padded .= str_repeat( '=', ( 4 - strlen( $padded ) % 4 ) % 4 );

		return (array) json_decode( base64_decode( $padded ), true );
	}

	public function test_mints_a_token(): void {
		$provider = $this->provider();

		$this->assertSame(
			'test-access-token',
			$provider->get_access_token( 'https://www.googleapis.com/auth/analytics.readonly' )
		);
	}

	public function test_assertion_header_declares_rs256(): void {
		$provider = $this->provider();
		$provider->get_access_token( 'scope-a' );

		$header = $this->decode_segment( explode( '.', $provider->requests[0] )[0] );

		$this->assertSame( 'RS256', $header['alg'] );
		$this->assertSame( 'JWT', $header['typ'] );
	}

	public function test_assertion_claims_carry_issuer_scope_and_audience(): void {
		$provider = $this->provider();
		$provider->get_access_token( 'https://www.googleapis.com/auth/webmasters' );

		$claims = $this->decode_segment( explode( '.', $provider->requests[0] )[1] );

		$this->assertSame( 'svc@project.iam.gserviceaccount.com', $claims['iss'] );
		$this->assertSame( 'https://www.googleapis.com/auth/webmasters', $claims['scope'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $claims['aud'] );
		$this->assertSame( $claims['iat'] + BaseServiceAccountProvider::ASSERTION_TTL, $claims['exp'] );
	}

	/**
	 * The whole point of the primitive. A malformed signature fails at the
	 * provider, not here, so it must be verified against the real public key.
	 */
	public function test_assertion_signature_verifies_against_the_public_key(): void {
		$provider = $this->provider();
		$provider->get_access_token( 'scope-a' );

		list( $header, $payload, $signature ) = explode( '.', $provider->requests[0] );

		$raw  = strtr( $signature, '-_', '+/' );
		$raw .= str_repeat( '=', ( 4 - strlen( $raw ) % 4 ) % 4 );

		$this->assertSame(
			1,
			openssl_verify( $header . '.' . $payload, base64_decode( $raw ), self::$public_key, OPENSSL_ALGO_SHA256 )
		);
	}

	/**
	 * Domain-wide delegation must be opt-in. Sending an empty sub would make
	 * every request look like an impersonation attempt.
	 */
	public function test_subject_claim_is_omitted_unless_configured(): void {
		$provider = $this->provider();
		$provider->get_access_token( 'scope-a' );

		$claims = $this->decode_segment( explode( '.', $provider->requests[0] )[1] );

		$this->assertArrayNotHasKey( 'sub', $claims );
	}

	public function test_subject_claim_is_sent_when_configured(): void {
		$provider                             = $this->provider();
		$provider->injected_config['subject'] = 'user@example.com';
		$provider->get_access_token( 'scope-a' );

		$claims = $this->decode_segment( explode( '.', $provider->requests[0] )[1] );

		$this->assertSame( 'user@example.com', $claims['sub'] );
	}

	/**
	 * The bug this primitive exists to prevent. A service account credential is
	 * network-scoped, so its token must be cached network-wide - caching
	 * per-site re-mints the same token once per site.
	 */
	public function test_token_is_cached_network_wide_and_reused(): void {
		$provider = $this->provider();

		$provider->get_access_token( 'scope-a' );
		$provider->get_access_token( 'scope-a' );

		$this->assertCount( 1, $provider->requests, 'Second call must be served from cache' );
	}

	/**
	 * One credential can serve several consumers with different scopes, so
	 * cache entries must not evict each other.
	 */
	public function test_distinct_scopes_do_not_share_a_cache_entry(): void {
		$provider = $this->provider();

		$provider->get_access_token( 'scope-a' );
		$provider->get_access_token( 'scope-b' );

		$this->assertCount( 2, $provider->requests );
	}

	public function test_clearing_a_cached_token_forces_a_new_mint(): void {
		$provider = $this->provider();

		$provider->get_access_token( 'scope-a' );
		$provider->clear_cached_token( 'scope-a' );
		$provider->get_access_token( 'scope-a' );

		$this->assertCount( 2, $provider->requests );
	}

	public function test_missing_credential_is_a_specific_error(): void {
		$provider                  = new StubServiceAccountProvider();
		$provider->injected_config = array();

		$result = $provider->get_access_token( 'scope-a' );

		$this->assertWPError( $result );
		$this->assertSame( 'datamachine_service_account_missing', $result->get_error_code() );
	}

	public function test_incomplete_credential_is_a_specific_error(): void {
		$provider                  = new StubServiceAccountProvider();
		$provider->injected_config = array( 'client_email' => 'svc@project.iam.gserviceaccount.com' );

		$result = $provider->get_access_token( 'scope-a' );

		$this->assertWPError( $result );
		$this->assertSame( 'datamachine_service_account_incomplete', $result->get_error_code() );
	}

	public function test_empty_scope_is_rejected(): void {
		$result = $this->provider()->get_access_token( '   ' );

		$this->assertWPError( $result );
		$this->assertSame( 'datamachine_service_account_scope_missing', $result->get_error_code() );
	}

	/**
	 * Providers hand out service account credentials as a JSON blob and
	 * operators paste it verbatim, so that shape has to work.
	 */
	public function test_raw_service_account_json_is_accepted(): void {
		$provider                  = new StubServiceAccountProvider();
		$provider->injected_config = array(
			'service_account_json' => (string) wp_json_encode(
				array(
					'client_email' => 'svc@project.iam.gserviceaccount.com',
					'private_key'  => self::$private_key,
				)
			),
		);

		$this->assertSame( 'test-access-token', $provider->get_access_token( 'scope-a' ) );
	}

	public function test_malformed_json_credential_is_a_specific_error(): void {
		$provider                  = new StubServiceAccountProvider();
		$provider->injected_config = array( 'service_account_json' => 'not json at all' );

		$result = $provider->get_access_token( 'scope-a' );

		$this->assertWPError( $result );
		$this->assertSame( 'datamachine_service_account_malformed', $result->get_error_code() );
	}

	public function test_is_authenticated_reflects_credential_validity(): void {
		$this->assertTrue( $this->provider()->is_authenticated() );

		$empty                  = new StubServiceAccountProvider();
		$empty->injected_config = array();

		$this->assertFalse( $empty->is_authenticated() );
	}

	/**
	 * A private key is long-lived and unscoped. Leaving it plaintext while
	 * encrypting short-lived access tokens inverts protection relative to risk.
	 */
	public function test_service_account_material_is_marked_for_encryption(): void {
		$encrypted = \DataMachine\Core\OAuth\BaseAuthProvider::ENCRYPTED_FIELDS;

		$this->assertContains( 'private_key', $encrypted );
		$this->assertContains( 'service_account_json', $encrypted );
		$this->assertContains( 'credentials_json', $encrypted );
	}
}
