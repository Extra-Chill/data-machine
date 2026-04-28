<?php
/**
 * Base Authentication Provider
 *
 * Abstract base class for all authentication providers.
 * Centralizes option storage, retrieval, and common configuration logic.
 *
 * Auth data is stored via get_site_option()/update_site_option() so credentials
 * are shared across all subsites on a multisite network. On single-site installs,
 * these behave identically to get_option()/update_option().
 *
 * Sensitive fields (tokens, secrets) are encrypted at rest using AES-256-GCM with
 * a key derived from wp_salt('auth'). Encrypted values use an envelope format:
 *
 *     dm:enc:v1:{base64(iv)}:{base64(tag)}:{base64(ciphertext)}
 *
 * Plaintext values without the envelope prefix are read as-is for backward
 * compatibility. Values get encrypted opportunistically on next save.
 *
 * @package DataMachine
 * @subpackage Core\OAuth
 * @since 0.2.6
 */

namespace DataMachine\Core\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseAuthProvider {

	/**
	 * Encryption envelope prefix.
	 *
	 * @since 0.88.0
	 */
	const ENCRYPTION_PREFIX = 'dm:enc:v1:';

	/**
	 * Cipher algorithm for at-rest encryption.
	 *
	 * @since 0.88.0
	 */
	const CIPHER_ALGO = 'aes-256-gcm';

	/**
	 * Authentication tag length for AES-GCM.
	 *
	 * @since 0.88.0
	 */
	const AUTH_TAG_LENGTH = 16;

	/**
	 * Fields that should be encrypted when stored.
	 *
	 * Providers can extend this list via the `datamachine_auth_encrypted_fields` filter.
	 *
	 * @since 0.88.0
	 * @var array<string>
	 */
	const ENCRYPTED_FIELDS = array(
		'access_token',
		'refresh_token',
		'oauth_token',
		'oauth_token_secret',
		'app_secret',
		'client_secret',
		'consumer_secret',
		'api_secret',
		'webhook_secret',
	);

	/**
	 * @var string Provider slug (e.g., 'twitter', 'facebook')
	 */
	protected $provider_slug;

	/**
	 * Constructor
	 *
	 * @param string $provider_slug Provider identifier
	 */
	public function __construct( string $provider_slug ) {
		$this->provider_slug = $provider_slug;
	}

	/**
	 * Get configuration fields (Abstract)
	 *
	 * @return array Configuration field definitions
	 */
	abstract public function get_config_fields(): array;

	/**
	 * Check if authenticated (Abstract)
	 *
	 * @return bool True if authenticated
	 */
	abstract public function is_authenticated(): bool;

	/**
	 * Get this provider's registered slug.
	 *
	 * @return string Provider slug.
	 */
	public function get_provider_slug(): string {
		return $this->provider_slug;
	}

	/**
	 * Convert an inline handler config to a portable auth_ref.
	 *
	 * Providers that recognize their own credential shape can override this and
	 * return a provider:account handle. The default is intentionally no-op so
	 * unknown handler configs pass through unchanged on export.
	 *
	 * @param array  $handler_config Handler config being exported.
	 * @param string $handler_slug Handler slug that owns the config.
	 * @param array  $context Export/import context.
	 * @return string|null Portable auth_ref or null when not recognized.
	 */
	public function get_auth_ref_for_config( array $handler_config, string $handler_slug = '', array $context = array() ): ?string {
		unset( $handler_config, $handler_slug, $context );
		return null;
	}

	/**
	 * Resolve an auth_ref account id to local handler config values.
	 *
	 * Providers that support portable refs should override this. The default
	 * returns a clear unresolved error without exposing stored credentials.
	 *
	 * @param string $account Auth ref account/id segment.
	 * @param string $handler_slug Handler slug requesting credentials.
	 * @param array  $context Import/runtime context.
	 * @return array|\WP_Error Local handler config fragment or failure.
	 */
	public function resolve_auth_ref( string $account, string $handler_slug = '', array $context = array() ): array|\WP_Error {
		unset( $handler_slug, $context );
		return new \WP_Error(
			'auth_ref_unresolved',
			sprintf(
				/* translators: 1: provider slug, 2: auth ref account id. */
				__( 'No local %1$s auth connection is configured for ref "%2$s".', 'data-machine' ),
				$this->provider_slug,
				$account
			)
		);
	}

	/**
	 * Strip credential-shaped keys from a handler config before export.
	 *
	 * @param array $handler_config Handler config.
	 * @return array Config without token/secret/password/key material.
	 */
	public function strip_auth_config_secrets( array $handler_config ): array {
		$secret_fields = array_fill_keys( $this->get_encrypted_fields(), true );
		$clean         = array();

		foreach ( $handler_config as $key => $value ) {
			$key = (string) $key;
			if ( isset( $secret_fields[ $key ] ) || preg_match( '/(secret|token|password|credential|key)/i', $key ) ) {
				continue;
			}

			$clean[ $key ] = is_array( $value ) ? $this->strip_auth_config_secrets( $value ) : $value;
		}

		return $clean;
	}

	/**
	 * Check if provider is properly configured
	 *
	 * @return bool True if configured
	 */
	public function is_configured(): bool {
		$config = $this->get_config();
		return ! empty( $config );
	}

	/**
	 * Get the callback URL for this provider.
	 *
	 * @since 0.67.0
	 *
	 * @return string Callback URL
	 */
	public function get_callback_url(): string {
		$url = site_url( "/datamachine-auth/{$this->provider_slug}/" );

		/**
		 * Filters the OAuth callback URL for an auth provider.
		 *
		 * Allows plugins to customize the callback URL to match their
		 * OAuth client's registered redirect URI.
		 *
		 * @since 0.67.0
		 *
		 * @param string $url           The default callback URL.
		 * @param string $provider_slug The provider slug (e.g. 'wpcom', 'twitter').
		 */
		return apply_filters( 'datamachine_oauth_callback_url', $url, $this->provider_slug );
	}

	/**
	 * Get OAuth account data directly from options.
	 *
	 * Automatically decrypts any encrypted fields. Plaintext legacy values
	 * pass through unchanged.
	 *
	 * @return array Account data or empty array
	 */
	public function get_account(): array {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		$account       = $all_auth_data[ $this->provider_slug ]['account'] ?? array();
		return $this->decrypt_fields( $account );
	}

	/**
	 * Get OAuth configuration keys directly from options.
	 *
	 * Automatically decrypts any encrypted fields. Plaintext legacy values
	 * pass through unchanged.
	 *
	 * @return array Configuration data or empty array
	 */
	public function get_config(): array {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		$config        = $all_auth_data[ $this->provider_slug ]['config'] ?? array();
		return $this->decrypt_fields( $config );
	}

	/**
	 * Store OAuth account data directly in options.
	 *
	 * Sensitive fields are automatically encrypted before storage.
	 *
	 * @param array $data Account data to store
	 * @return bool True on success
	 */
	public function save_account( array $data ): bool {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		if ( ! isset( $all_auth_data[ $this->provider_slug ] ) ) {
			$all_auth_data[ $this->provider_slug ] = array();
		}
		$all_auth_data[ $this->provider_slug ]['account'] = $this->encrypt_fields( $data );
		return update_site_option( 'datamachine_auth_data', $all_auth_data );
	}

	/**
	 * Store OAuth configuration keys directly in options.
	 *
	 * Sensitive fields are automatically encrypted before storage.
	 *
	 * @param array $data Configuration data to store
	 * @return bool True on success
	 */
	public function save_config( array $data ): bool {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		if ( ! isset( $all_auth_data[ $this->provider_slug ] ) ) {
			$all_auth_data[ $this->provider_slug ] = array();
		}
		$all_auth_data[ $this->provider_slug ]['config'] = $this->encrypt_fields( $data );
		return update_site_option( 'datamachine_auth_data', $all_auth_data );
	}

	/**
	 * Clear OAuth account data from options.
	 *
	 * @return bool True on success
	 */
	public function clear_account(): bool {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		if ( isset( $all_auth_data[ $this->provider_slug ]['account'] ) ) {
			unset( $all_auth_data[ $this->provider_slug ]['account'] );
			return update_site_option( 'datamachine_auth_data', $all_auth_data );
		}
		return true;
	}

	/**
	 * Get the authenticated username for this provider.
	 *
	 * All providers should store username under the canonical 'username'
	 * key in account data. Override only if the provider stores it
	 * elsewhere (e.g. in config rather than account).
	 *
	 * @since 0.2.7
	 * @return string|null Username or null if not available
	 */
	public function get_username(): ?string {
		$account = $this->get_account();
		return ! empty( $account['username'] ) ? $account['username'] : null;
	}

	/**
	 * Get account details for display (Optional)
	 *
	 * @return array|null Account details or null
	 */
	public function get_account_details(): ?array {
		return $this->get_account();
	}

	// -------------------------------------------------------------------------
	// Encryption
	// -------------------------------------------------------------------------

	/**
	 * Get the list of field names that should be encrypted at rest.
	 *
	 * Combines the built-in ENCRYPTED_FIELDS constant with any additions
	 * from the `datamachine_auth_encrypted_fields` filter.
	 *
	 * @since 0.88.0
	 * @return array<string> Field names to encrypt.
	 */
	protected function get_encrypted_fields(): array {
		$fields = self::ENCRYPTED_FIELDS;

		/**
		 * Filters the list of auth data fields that are encrypted at rest.
		 *
		 * Providers can add custom field names that contain sensitive data
		 * (e.g. non-standard token field names used by specific OAuth1 flows).
		 *
		 * @since 0.88.0
		 *
		 * @param array  $fields        Default list of field names.
		 * @param string $provider_slug The provider this applies to.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$fields = apply_filters( 'datamachine_auth_encrypted_fields', $fields, $this->provider_slug );
		}

		return $fields;
	}

	/**
	 * Encrypt sensitive fields in a data array before storage.
	 *
	 * Only encrypts fields listed in get_encrypted_fields() that have
	 * non-empty string values and are not already encrypted.
	 *
	 * @since 0.88.0
	 * @param array $data Raw data array.
	 * @return array Data with sensitive fields encrypted.
	 */
	protected function encrypt_fields( array $data ): array {
		foreach ( $this->get_encrypted_fields() as $field ) {
			if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) && '' !== $data[ $field ] ) {
				// Don't double-encrypt values that already carry the envelope.
				if ( str_starts_with( $data[ $field ], self::ENCRYPTION_PREFIX ) ) {
					continue;
				}
				$encrypted = $this->encrypt_value( $data[ $field ] );
				if ( null !== $encrypted ) {
					$data[ $field ] = $encrypted;
				}
			}
		}
		return $data;
	}

	/**
	 * Decrypt sensitive fields in a data array after retrieval.
	 *
	 * Only attempts decryption on fields that carry the encryption envelope
	 * prefix. Plaintext values are returned unchanged for backward compatibility.
	 *
	 * @since 0.88.0
	 * @param array $data Stored data array (may contain encrypted or plaintext values).
	 * @return array Data with sensitive fields decrypted.
	 */
	protected function decrypt_fields( array $data ): array {
		foreach ( $this->get_encrypted_fields() as $field ) {
			if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
				if ( str_starts_with( $data[ $field ], self::ENCRYPTION_PREFIX ) ) {
					$decrypted = $this->decrypt_value( $data[ $field ] );
					if ( null !== $decrypted ) {
						$data[ $field ] = $decrypted;
					}
					// On decryption failure, leave the encrypted blob as-is
					// so it doesn't silently become an empty string.
				}
				// else: plaintext legacy value — pass through unchanged.
			}
		}
		return $data;
	}

	/**
	 * Encrypt a single value using AES-256-GCM.
	 *
	 * Returns the encrypted value in envelope format:
	 *     dm:enc:v1:{base64(iv)}:{base64(tag)}:{base64(ciphertext)}
	 *
	 * @since 0.88.0
	 * @param string $plaintext The value to encrypt.
	 * @return string|null Encrypted envelope string, or null on failure.
	 */
	private function encrypt_value( string $plaintext ): ?string {
		$key = $this->derive_encryption_key();
		if ( null === $key ) {
			return null;
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER_ALGO );
		$iv        = openssl_random_pseudo_bytes( $iv_length );

		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER_ALGO, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::AUTH_TAG_LENGTH );

		if ( false === $ciphertext || '' === $tag ) {
			$this->log_encryption_error( 'Encryption failed' );
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary crypto envelope fields, not obfuscation.
		return self::ENCRYPTION_PREFIX . base64_encode( $iv ) . ':' . base64_encode( $tag ) . ':' . base64_encode( $ciphertext );
	}

	/**
	 * Decrypt a single value from the encryption envelope format.
	 *
	 * Expects input in format: dm:enc:v1:{base64(iv)}:{base64(tag)}:{base64(ciphertext)}
	 *
	 * @since 0.88.0
	 * @param string $envelope The encrypted envelope string.
	 * @return string|null Decrypted plaintext, or null on failure.
	 */
	private function decrypt_value( string $envelope ): ?string {
		$key = $this->derive_encryption_key();
		if ( null === $key ) {
			return null;
		}

		// Strip prefix and split into iv:tag:ciphertext.
		$payload = substr( $envelope, strlen( self::ENCRYPTION_PREFIX ) );
		$parts   = explode( ':', $payload, 3 );

		if ( 3 !== count( $parts ) ) {
			$this->log_encryption_error( 'Malformed encryption envelope: missing separator' );
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding binary crypto envelope fields, not obfuscation.
		$iv = base64_decode( $parts[0], true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding binary crypto envelope fields, not obfuscation.
		$tag = base64_decode( $parts[1], true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding binary crypto envelope fields, not obfuscation.
		$ciphertext = base64_decode( $parts[2], true );

		if ( false === $iv || false === $tag || false === $ciphertext ) {
			$this->log_encryption_error( 'Malformed encryption envelope: invalid base64' );
			return null;
		}

		$expected_iv_length = openssl_cipher_iv_length( self::CIPHER_ALGO );
		if ( strlen( $iv ) !== $expected_iv_length ) {
			$this->log_encryption_error( 'Malformed encryption envelope: invalid IV length' );
			return null;
		}

		if ( strlen( $tag ) !== self::AUTH_TAG_LENGTH ) {
			$this->log_encryption_error( 'Malformed encryption envelope: invalid authentication tag length' );
			return null;
		}

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER_ALGO, $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $plaintext ) {
			$this->log_encryption_error( 'Decryption failed (wrong key or corrupted data)' );
			return null;
		}

		return $plaintext;
	}

	/**
	 * Derive the encryption key from WordPress auth salts.
	 *
	 * Uses hash('sha256', wp_salt('auth') . 'datamachine-oauth') to produce
	 * a 32-byte key suitable for AES-256-GCM. The 'datamachine-oauth' suffix
	 * ensures key isolation from other WordPress subsystems.
	 *
	 * @since 0.88.0
	 * @return string|null 32-byte binary key, or null if derivation fails.
	 */
	private function derive_encryption_key(): ?string {
		if ( ! function_exists( 'wp_salt' ) ) {
			return null;
		}

		$salt = wp_salt( 'auth' );

		// Warn if WordPress is using default salts (insecure but functional).
		if ( 'put your unique phrase here' === $salt ) {
			$this->log_encryption_error(
				'wp_salt(\'auth\') returns the WordPress default. '
				. 'Set unique AUTH_KEY and AUTH_SALT in wp-config.php for proper security.'
			);
		}

		// Return raw binary (32 bytes) for use as AES-256 key.
		return hash( 'sha256', $salt . 'datamachine-oauth', true );
	}

	/**
	 * Log an encryption-related error via the datamachine_log action.
	 *
	 * @since 0.88.0
	 * @param string $message Error message.
	 */
	private function log_encryption_error( string $message ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action(
				'datamachine_log',
				'error',
				'OAuth Encryption: ' . $message,
				array( 'provider' => $this->provider_slug )
			);
		}
	}
}
