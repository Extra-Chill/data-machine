<?php
/**
 * Base Service Account Provider
 *
 * Abstract base for RFC 7523 JWT-bearer authentication — the standard
 * server-to-server grant, used by Google service accounts among others.
 *
 * Core previously shipped OAuth2, OAuth1, and HTTP Basic providers but had no
 * service-account primitive, so every consumer hand-rolled JWT assembly, RS256
 * signing, and token caching inside its own ability class. Three independent
 * copies drifted apart, and the drift produced a real bug: one cached its token
 * with get_transient() while an otherwise identical sibling used
 * get_site_transient(), so on a multisite network the same credential minted
 * one token per site.
 *
 * This class owns the mechanism. Vendor specifics — the token endpoint and the
 * scopes a consumer needs — stay in the subclass.
 *
 * Two properties are structural rather than incidental:
 *
 * - **Tokens are cached network-wide.** A service-account credential is stored
 *   with get_site_option(), so the token it mints is valid for every site on
 *   the network. Caching per-site re-mints the same token once per site. This
 *   is not left to the subclass to get right.
 * - **The private key is encrypted at rest.** Credentials flow through
 *   BaseAuthProvider, so the RSA key gets the same AES-256-GCM treatment as
 *   every other secret. Previously these sat in plaintext in wp_sitemeta while
 *   short-lived access tokens were encrypted — protection inverted relative to
 *   risk, since a private key is long-lived and unscoped.
 *
 * @package DataMachine
 * @subpackage Core\OAuth
 */

namespace DataMachine\Core\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseServiceAccountProvider extends BaseAuthProvider {

	/**
	 * JWT-bearer grant type.
	 */
	public const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

	/**
	 * Assertion lifetime in seconds.
	 *
	 * One hour is the maximum most providers accept.
	 */
	public const ASSERTION_TTL = 3600;

	/**
	 * Token cache lifetime in seconds.
	 *
	 * Slightly under the assertion TTL so a cached token is never served in the
	 * moments before it expires.
	 */
	public const TOKEN_CACHE_TTL = 3500;

	/**
	 * The token endpoint this provider exchanges assertions at.
	 *
	 * @return string
	 */
	abstract public function get_token_endpoint(): string;

	/**
	 * Fields the service account credential is expected to carry.
	 *
	 * @return array<string>
	 */
	public function get_required_credential_fields(): array {
		return array( 'client_email', 'private_key' );
	}

	/**
	 * Whether a usable service account credential is stored.
	 */
	public function is_authenticated(): bool {
		return ! is_wp_error( $this->get_service_account() );
	}

	/**
	 * Obtain an access token for the given scope.
	 *
	 * Cached network-wide, keyed by scope so consumers requesting different
	 * scopes from one credential do not evict each other.
	 *
	 * @param string $scope Space-separated OAuth scope string.
	 * @return string|\WP_Error Access token, or a failure.
	 */
	public function get_access_token( string $scope ) {
		$scope = trim( $scope );

		if ( '' === $scope ) {
			return new \WP_Error(
				'datamachine_service_account_scope_missing',
				__( 'A scope is required to request a service account token.', 'data-machine' )
			);
		}

		$cache_key = $this->get_token_cache_key( $scope );
		$cached    = get_site_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$service_account = $this->get_service_account();

		if ( is_wp_error( $service_account ) ) {
			return $service_account;
		}

		$assertion = $this->build_assertion( $service_account, $scope );

		if ( is_wp_error( $assertion ) ) {
			return $assertion;
		}

		$token = $this->exchange_assertion( $assertion );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		set_site_transient( $cache_key, $token, self::TOKEN_CACHE_TTL );

		return $token;
	}

	/**
	 * Forget any cached token for one scope.
	 *
	 * @param string $scope Scope string.
	 */
	public function clear_cached_token( string $scope ): void {
		delete_site_transient( $this->get_token_cache_key( trim( $scope ) ) );
	}

	/**
	 * Read and validate the stored service account credential.
	 *
	 * @return array|\WP_Error Credential array, or a specific failure.
	 */
	protected function get_service_account() {
		$config = $this->get_config();

		$credential = $this->extract_credential( $config );

		if ( is_wp_error( $credential ) ) {
			return $credential;
		}

		foreach ( $this->get_required_credential_fields() as $field ) {
			if ( empty( $credential[ $field ] ) || ! is_string( $credential[ $field ] ) ) {
				return new \WP_Error(
					'datamachine_service_account_incomplete',
					sprintf(
						/* translators: %s: credential field name. */
						__( 'The service account credential is missing "%s".', 'data-machine' ),
						$field
					)
				);
			}
		}

		return $credential;
	}

	/**
	 * Resolve the credential array from stored config.
	 *
	 * Accepts either discrete fields or a raw service account JSON blob, since
	 * providers hand out the latter and operators paste it verbatim.
	 *
	 * @param array $config Stored provider config.
	 * @return array|\WP_Error
	 */
	protected function extract_credential( array $config ) {
		if ( ! empty( $config['client_email'] ) && ! empty( $config['private_key'] ) ) {
			return $config;
		}

		$json = '';

		foreach ( array( 'service_account_json', 'credentials_json', 'json' ) as $key ) {
			if ( ! empty( $config[ $key ] ) && is_string( $config[ $key ] ) ) {
				$json = $config[ $key ];
				break;
			}
		}

		if ( '' === $json ) {
			return new \WP_Error(
				'datamachine_service_account_missing',
				__( 'No service account credential is configured.', 'data-machine' )
			);
		}

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'datamachine_service_account_malformed',
				__( 'The service account credential is not valid JSON.', 'data-machine' )
			);
		}

		return $decoded;
	}

	/**
	 * Assemble and sign a JWT assertion.
	 *
	 * @param array  $service_account Validated credential.
	 * @param string $scope           Requested scope.
	 * @return string|\WP_Error Signed assertion, or a signing failure.
	 */
	protected function build_assertion( array $service_account, string $scope ) {
		$now = time();

		$claims = array(
			'iss'   => $service_account['client_email'],
			'scope' => $scope,
			'aud'   => $this->get_token_endpoint(),
			'iat'   => $now,
			'exp'   => $now + self::ASSERTION_TTL,
		);

		// Domain-wide delegation impersonates a user; only sent when configured.
		if ( ! empty( $service_account['subject'] ) && is_string( $service_account['subject'] ) ) {
			$claims['sub'] = $service_account['subject'];
		}

		$header = self::base64url_encode(
			(string) wp_json_encode(
				array(
					'alg' => 'RS256',
					'typ' => 'JWT',
				)
			)
		);

		$payload  = self::base64url_encode( (string) wp_json_encode( $claims ) );
		$unsigned = $header . '.' . $payload;

		$signature = '';

		if ( ! openssl_sign( $unsigned, $signature, $service_account['private_key'], 'SHA256' ) ) {
			return new \WP_Error(
				'datamachine_service_account_sign_failed',
				__( 'Could not sign the assertion. Check the private key in the service account credential.', 'data-machine' )
			);
		}

		return $unsigned . '.' . self::base64url_encode( $signature );
	}

	/**
	 * Exchange a signed assertion for an access token.
	 *
	 * @param string $assertion Signed JWT.
	 * @return string|\WP_Error Access token, or a failure.
	 */
	protected function exchange_assertion( string $assertion ) {
		$response = wp_remote_post(
			$this->get_token_endpoint(),
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => self::GRANT_TYPE,
					'assertion'  => $assertion,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['access_token'] ) || ! is_string( $body['access_token'] ) ) {
			$detail = '';

			if ( is_array( $body ) ) {
				$detail = (string) ( $body['error_description'] ?? $body['error'] ?? '' );
			}

			return new \WP_Error(
				'datamachine_service_account_token_failed',
				'' !== $detail
					? sprintf(
						/* translators: %s: provider error description. */
						__( 'Could not obtain an access token: %s', 'data-machine' ),
						$detail
					)
					: __( 'Could not obtain an access token.', 'data-machine' )
			);
		}

		return $body['access_token'];
	}

	/**
	 * Build the network-wide cache key for one scope.
	 *
	 * Scope is hashed rather than embedded so the key stays within the
	 * transient name length limit regardless of how many scopes are requested.
	 *
	 * @param string $scope Scope string.
	 * @return string
	 */
	protected function get_token_cache_key( string $scope ): string {
		return 'dm_sa_token_' . $this->provider_slug . '_' . substr( hash( 'sha256', $scope ), 0, 32 );
	}

	/**
	 * Base64url encode per RFC 7515.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	protected static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
