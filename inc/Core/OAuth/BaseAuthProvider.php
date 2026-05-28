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

use DataMachine\Abilities\PermissionHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseAuthProvider {

	public const AUTH_SCOPE_SITE      = 'site';
	public const AUTH_SCOPE_USER      = 'user';
	public const AUTH_SCOPE_AGENT     = 'agent';
	public const AUTH_SCOPE_PRINCIPAL = 'principal';

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

	// -------------------------------------------------------------------------
	// Site-wide account API
	//
	// These methods provide explicit top-level account storage access for shared
	// bot credentials, scheduled flows, and other cases where the credential is
	// intentionally not owned by a specific user or agent.
	// -------------------------------------------------------------------------

	/**
	 * Get the site-wide OAuth account for this provider.
	 *
	 * Unlike `get_account( array $context )`, this method never consults auth
	 * scope policy and never resolves to a user or agent principal. It only reads
	 * the provider's top-level account slot.
	 *
	 * @since 0.128.0
	 *
	 * @return array|null Decrypted site-wide account data, or null if no account exists.
	 */
	public function get_site_account(): ?array {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		$provider_data = $all_auth_data[ $this->provider_slug ] ?? array();
		$account       = $provider_data['account'] ?? null;

		if ( ! is_array( $account ) || empty( $account ) ) {
			return null;
		}

		return $this->decrypt_fields( $account );
	}

	/**
	 * Save the site-wide OAuth account for this provider.
	 *
	 * Sensitive fields are encrypted before storage. The account is stored in the
	 * top-level provider account slot and does not affect principal-scoped user or
	 * agent accounts.
	 *
	 * @since 0.128.0
	 *
	 * @param array $account Account data to store.
	 * @return bool True on successful write, false on storage failure.
	 */
	public function save_site_account( array $account ): bool {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		if ( ! isset( $all_auth_data[ $this->provider_slug ] ) || ! is_array( $all_auth_data[ $this->provider_slug ] ) ) {
			$all_auth_data[ $this->provider_slug ] = array();
		}

		$all_auth_data[ $this->provider_slug ]['account'] = $this->encrypt_fields( $account );

		return update_site_option( 'datamachine_auth_data', $all_auth_data );
	}

	/**
	 * Merge partial account data into the site-wide OAuth account.
	 *
	 * Use this for incremental token/profile refreshes where omitted fields should
	 * be preserved. Use `save_site_account()` for initial authorization or
	 * re-consent flows that intentionally replace the complete account payload.
	 *
	 * @since 0.132.0
	 *
	 * @param array $patch Partial account data to merge into the existing account.
	 * @return bool True on successful write, false on storage failure.
	 */
	public function update_site_account( array $patch ): bool {
		$existing = $this->get_stored_account_for_scope( null );
		return $this->store_account_for_scope( array_merge( $existing, $patch ), null );
	}

	/**
	 * Delete the site-wide OAuth account for this provider.
	 *
	 * Principal-scoped user and agent accounts are preserved. The delete is
	 * idempotent: removing a missing site account is successful.
	 *
	 * @since 0.128.0
	 *
	 * @return bool True on success, false on storage failure.
	 */
	public function delete_site_account(): bool {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );

		if ( isset( $all_auth_data[ $this->provider_slug ]['account'] ) ) {
			unset( $all_auth_data[ $this->provider_slug ]['account'] );
			return update_site_option( 'datamachine_auth_data', $all_auth_data );
		}

		return true;
	}

	/**
	 * Get OAuth account data directly from options.
	 *
	 * Automatically decrypts any encrypted fields. Principal-scoped credentials
	 * are preferred when a user or agent context is available; legacy site-level
	 * credentials remain readable as a fallback for existing installs.
	 *
	 * @param array $context Optional principal context, e.g. user_id or agent_id.
	 * @return array Account data or empty array
	 */
	public function get_account( array $context = array() ): array {
		if ( ! empty( $context ) ) {
			if ( function_exists( '_deprecated_function' ) ) {
				_deprecated_function(
					__METHOD__ . ' with a context argument',
					'0.131.0',
					'BaseAuthProvider::get_account_for_policy_context()'
				);
			}

			return $this->get_account_for_policy_context( $context ) ?? array();
		}

		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		$provider_data = $all_auth_data[ $this->provider_slug ] ?? array();
		$scope         = $this->get_principal_scope( $context, 'account' );

		if ( null !== $scope ) {
			$scoped_account = $provider_data['principals'][ $scope ]['account'] ?? array();
			if ( ! empty( $scoped_account ) && is_array( $scoped_account ) ) {
				return $this->decrypt_fields( $scoped_account );
			}
		}

		$account = $provider_data['account'] ?? array();
		return $this->decrypt_fields( $account );
	}

	/**
	 * Get the OAuth account that applies to an execution context.
	 *
	 * Deprecated compatibility wrapper for the explicit policy-fallback lookup.
	 * New callers must choose a named scope API (`get_site_account()`,
	 * `get_account_for_user()`, `get_account_for_agent()`) or intentionally opt
	 * into policy resolution plus site fallback via
	 * `get_account_for_policy_context()`.
	 *
	 * @since 0.130.0
	 * @deprecated 0.136.0 Use get_account_for_policy_context() when fallback is intended.
	 *
	 * @param array $context Optional principal context, e.g. user_id or agent_id.
	 * @return array|null Decrypted account data, or null if no account resolves.
	 */
	public function get_account_for_context( array $context = array() ): ?array {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function(
				__METHOD__,
				'0.136.0',
				'BaseAuthProvider::get_account_for_policy_context()'
			);
		}

		return $this->get_account_for_policy_context( $context );
	}

	/**
	 * Get the policy-resolved OAuth account for an execution context.
	 *
	 * This method is the explicit opt-in surface for scope-policy resolution with
	 * legacy site-account fallback. Use named scope methods when a missing scoped
	 * account must remain missing instead of falling back to site credentials.
	 *
	 * @since 0.136.0
	 *
	 * @param array $context Optional principal context, e.g. user_id or agent_id.
	 * @return array|null Decrypted account data, or null if no account resolves.
	 */
	public function get_account_for_policy_context( array $context = array() ): ?array {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		$provider_data = $all_auth_data[ $this->provider_slug ] ?? array();
		$scope         = $this->get_principal_scope( $context, 'account' );

		if ( null !== $scope ) {
			$scoped_account = $provider_data['principals'][ $scope ]['account'] ?? array();
			if ( ! empty( $scoped_account ) && is_array( $scoped_account ) ) {
				return $this->decrypt_fields( $scoped_account );
			}
		}

		$account = $provider_data['account'] ?? array();
		if ( ! is_array( $account ) || empty( $account ) ) {
			return null;
		}

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
	public function get_config( array $context = array() ): array {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		$provider_data = $all_auth_data[ $this->provider_slug ] ?? array();
		$scope         = $this->get_principal_scope( $context, 'config' );

		if ( null !== $scope ) {
			$scoped_config = $provider_data['principals'][ $scope ]['config'] ?? array();
			if ( ! empty( $scoped_config ) && is_array( $scoped_config ) ) {
				return $this->decrypt_fields( $scoped_config );
			}
		}

		$config = $provider_data['config'] ?? array();
		return $this->decrypt_fields( $config );
	}

	/**
	 * Store OAuth account data directly in options.
	 *
	 * Sensitive fields are automatically encrypted before storage. Writes with a
	 * resolvable user or agent context are stored under that principal; writes
	 * without context preserve legacy site-level storage.
	 *
	 * @param array $data Account data to store
	 * @param array $context Optional principal context, e.g. user_id or agent_id.
	 * @return bool True on success
	 */
	public function save_account( array $data, array $context = array() ): bool {
		if ( ! empty( $context ) && function_exists( '_deprecated_function' ) ) {
			_deprecated_function(
				__METHOD__ . ' with a context argument',
				'0.132.0',
				'BaseAuthProvider::save_site_account(), BaseAuthProvider::save_account_for_user(), or BaseAuthProvider::save_account_for_agent()'
			);
		}

		return $this->store_account_for_scope( $data, $this->get_principal_scope( $context, 'account' ) );
	}

	/**
	 * Merge partial account data into the policy-resolved OAuth account slot.
	 *
	 * This method preserves omitted fields. It writes to the same site/user/agent
	 * slot that `save_account()` would target for the supplied context, but reads
	 * only that exact slot before merging so a missing scoped account does not pull
	 * values from the legacy site fallback.
	 *
	 * Use `save_account()` for full replacement, especially initial authorization
	 * or re-consent flows. Use this method for token refreshes and other partial
	 * account updates.
	 *
	 * @since 0.132.0
	 *
	 * @param array $patch Partial account data to merge.
	 * @param array $context Optional principal context, e.g. user_id or agent_id.
	 * @return bool True on successful write, false on storage failure.
	 */
	public function update_account( array $patch, array $context = array() ): bool {
		$scope    = $this->get_principal_scope( $context, 'account' );
		$existing = $this->get_stored_account_for_scope( $scope );

		return $this->store_account_for_scope( array_merge( $existing, $patch ), $scope );
	}

	/**
	 * Store OAuth configuration keys directly in options.
	 *
	 * Sensitive fields are automatically encrypted before storage.
	 *
	 * @param array $data Configuration data to store
	 * @param array $context Optional principal context. Config writes only scope when context is explicit.
	 * @return bool True on success
	 */
	public function save_config( array $data, array $context = array() ): bool {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		if ( ! isset( $all_auth_data[ $this->provider_slug ] ) ) {
			$all_auth_data[ $this->provider_slug ] = array();
		}

		$scope = $this->get_principal_scope( $context, 'config' );
		if ( null !== $scope ) {
			if ( ! isset( $all_auth_data[ $this->provider_slug ]['principals'] ) || ! is_array( $all_auth_data[ $this->provider_slug ]['principals'] ) ) {
				$all_auth_data[ $this->provider_slug ]['principals'] = array();
			}
			$all_auth_data[ $this->provider_slug ]['principals'][ $scope ]['config'] = $this->encrypt_fields( $data );
		} else {
			$all_auth_data[ $this->provider_slug ]['config'] = $this->encrypt_fields( $data );
		}

		return update_site_option( 'datamachine_auth_data', $all_auth_data );
	}

	/**
	 * Clear OAuth account data from options.
	 *
	 * @param array $context Optional principal context, e.g. user_id or agent_id.
	 * @return bool True on success
	 */
	public function clear_account( array $context = array() ): bool {
		if ( ! empty( $context ) && function_exists( '_deprecated_function' ) ) {
			_deprecated_function(
				__METHOD__ . ' with a context argument',
				'0.132.0',
				'BaseAuthProvider::delete_site_account(), BaseAuthProvider::delete_account_for_user(), or BaseAuthProvider::delete_account_for_agent()'
			);
		}

		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		$scope         = $this->get_principal_scope( $context, 'account' );

		if ( null !== $scope ) {
			if ( isset( $all_auth_data[ $this->provider_slug ]['principals'][ $scope ] ) ) {
				unset( $all_auth_data[ $this->provider_slug ]['principals'][ $scope ] );
				return update_site_option( 'datamachine_auth_data', $all_auth_data );
			}
			return true;
		}

		if ( isset( $all_auth_data[ $this->provider_slug ]['account'] ) ) {
			unset( $all_auth_data[ $this->provider_slug ]['account'] );
			return update_site_option( 'datamachine_auth_data', $all_auth_data );
		}
		return true;
	}

	/**
	 * Read the exact stored account slot for merge updates.
	 *
	 * Unlike `get_account_for_context()`, this does not fall back from a scoped
	 * principal slot to the legacy site account. Partial updates must not copy site
	 * credentials into a user or agent slot just because the scoped slot is empty.
	 *
	 * @since 0.132.0
	 *
	 * @param string|null $scope Principal scope key (`user:<id>` or `agent:<id>`), or null for site account.
	 * @return array<string,mixed> Decrypted account data, or an empty array when none exists.
	 */
	private function get_stored_account_for_scope( ?string $scope ): array {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		$provider_data = $all_auth_data[ $this->provider_slug ] ?? array();
		$account       = null === $scope
			? ( $provider_data['account'] ?? array() )
			: ( $provider_data['principals'][ $scope ]['account'] ?? array() );

		return is_array( $account ) ? $this->decrypt_fields( $account ) : array();
	}

	/**
	 * Store account data in the exact site or principal scope slot.
	 *
	 * @since 0.132.0
	 *
	 * @param array<string,mixed> $account Account data to store.
	 * @param string|null         $scope   Principal scope key, or null for the site account.
	 * @return bool True on successful write, false on storage failure.
	 */
	private function store_account_for_scope( array $account, ?string $scope ): bool {
		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		if ( ! isset( $all_auth_data[ $this->provider_slug ] ) || ! is_array( $all_auth_data[ $this->provider_slug ] ) ) {
			$all_auth_data[ $this->provider_slug ] = array();
		}

		if ( null !== $scope ) {
			if ( ! isset( $all_auth_data[ $this->provider_slug ]['principals'] ) || ! is_array( $all_auth_data[ $this->provider_slug ]['principals'] ) ) {
				$all_auth_data[ $this->provider_slug ]['principals'] = array();
			}
			$all_auth_data[ $this->provider_slug ]['principals'][ $scope ]['account'] = $this->encrypt_fields( $account );
		} else {
			$all_auth_data[ $this->provider_slug ]['account'] = $this->encrypt_fields( $account );
		}

		return update_site_option( 'datamachine_auth_data', $all_auth_data );
	}

	// -------------------------------------------------------------------------
	// Per-user account API
	//
	// These methods provide a deliberate, no-fallback per-user storage surface
	// for callers that operate on a specific human user's credentials. They
	// share the same underlying storage layout as the principal-scoped API
	// (`principals[user:<id>][account]`) so an account written via either
	// surface is readable through the other.
	//
	// Unlike `get_account( array $context )`, these methods never consult the
	// provider's scope policy and never fall back to site-wide storage when
	// no per-user account exists. A missing per-user account always returns
	// `null`.
	// -------------------------------------------------------------------------

	/**
	 * Get the OAuth account for a specific user.
	 *
	 * Sensitive fields are decrypted on read. Returns `null` when no per-user
	 * account exists for this provider+user — there is intentionally no
	 * fallback to site-wide storage so callers cannot accidentally cross
	 * user boundaries.
	 *
	 * Resolution order:
	 *   1. `datamachine_resolve_oauth_account_for_user` filter — platform
	 *      plugins return a non-null array to short-circuit with their own
	 *      per-user storage layer (custom table, encrypted blob, etc.).
	 *   2. Default user-meta-equivalent storage at
	 *      `principals[user:<id>][account]`.
	 *   3. Return null if neither yielded an account.
	 *
	 * @since 0.123.0
	 *
	 * @param int $user_id Target user ID. Must be a positive integer.
	 * @return array|null Decrypted account data, or null if no account exists.
	 */
	public function get_account_for_user( int $user_id ): ?array {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return null;
		}

		/**
		 * Filter the resolution of an OAuth account for a given user.
		 *
		 * Platform plugins can return a non-null array to plug in their own
		 * per-user credential storage (custom table, external KMS, encrypted
		 * blob, etc.) without touching core. Returning null lets the default
		 * storage path run.
		 *
		 * The filter fires BEFORE the default lookup. The filter result is
		 * NOT passed through decrypt_fields() — platform plugins are
		 * responsible for returning already-decrypted account data in the
		 * canonical shape (access_token, refresh_token, scope, expires_at,
		 * etc. as plaintext).
		 *
		 * @since 0.123.0
		 *
		 * @param array|null $account     Resolved account, or null to fall through.
		 * @param string     $provider    Provider slug (e.g. registered handler slug).
		 * @param int        $user_id     Target user ID.
		 */
		$resolved = apply_filters(
			'datamachine_resolve_oauth_account_for_user',
			null,
			$this->provider_slug,
			$user_id
		);

		if ( is_array( $resolved ) && ! empty( $resolved ) ) {
			$this->log_per_user_account_read( $user_id, 'filter' );
			return $resolved;
		}

		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		$provider_data = $all_auth_data[ $this->provider_slug ] ?? array();
		$scope_key     = 'user:' . $user_id;
		$account       = $provider_data['principals'][ $scope_key ]['account'] ?? null;

		if ( ! is_array( $account ) || empty( $account ) ) {
			return null;
		}

		$this->log_per_user_account_read( $user_id, 'default' );
		return $this->decrypt_fields( $account );
	}

	/**
	 * Emit an audit log entry for a successful per-user account resolution.
	 *
	 * Only successful resolves are logged — failed lookups would dwarf real
	 * signal during normal "no account exists yet" probes. The token itself
	 * is NEVER logged, at any level.
	 *
	 * @since 0.123.0
	 *
	 * @param int    $user_id Resolved user ID.
	 * @param string $source  Resolution source — 'filter' or 'default'.
	 */
	private function log_per_user_account_read( int $user_id, string $source ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action(
			'datamachine_log',
			'debug',
			'OAuth: Per-user account resolved',
			array(
				'provider' => $this->provider_slug,
				'user_id'  => $user_id,
				'source'   => $source,
			)
		);
	}

	/**
	 * Save the OAuth account for a specific user.
	 *
	 * Sensitive fields are encrypted before storage. Stores under the same
	 * `principals[user:<id>][account]` slot used by the scope-aware
	 * `save_account()` path so the two surfaces stay in sync.
	 *
	 * @since 0.123.0
	 *
	 * @param int   $user_id Target user ID. Must be a positive integer.
	 * @param array $account Account data to store. Must include `access_token`
	 *                       for the credential to be useful.
	 * @return bool True on successful write, false on invalid input or storage failure.
	 */
	public function save_account_for_user( int $user_id, array $account ): bool {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}

		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		if ( ! isset( $all_auth_data[ $this->provider_slug ] ) || ! is_array( $all_auth_data[ $this->provider_slug ] ) ) {
			$all_auth_data[ $this->provider_slug ] = array();
		}
		if ( ! isset( $all_auth_data[ $this->provider_slug ]['principals'] ) || ! is_array( $all_auth_data[ $this->provider_slug ]['principals'] ) ) {
			$all_auth_data[ $this->provider_slug ]['principals'] = array();
		}

		$scope_key = 'user:' . $user_id;
		$all_auth_data[ $this->provider_slug ]['principals'][ $scope_key ]['account'] = $this->encrypt_fields( $account );

		return update_site_option( 'datamachine_auth_data', $all_auth_data );
	}

	/**
	 * Merge partial account data into a specific user's OAuth account.
	 *
	 * @since 0.132.0
	 *
	 * @param int   $user_id Target user ID. Must be a positive integer.
	 * @param array $patch   Partial account data to merge.
	 * @return bool True on successful write, false on invalid input or storage failure.
	 */
	public function update_account_for_user( int $user_id, array $patch ): bool {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}

		$scope_key = 'user:' . $user_id;
		$existing  = $this->get_stored_account_for_scope( $scope_key );
		return $this->store_account_for_scope( array_merge( $existing, $patch ), $scope_key );
	}

	/**
	 * Delete the OAuth account for a specific user.
	 *
	 * Returns true when the per-user slot existed and was removed, or when
	 * there was nothing to remove (idempotent). Returns false only on
	 * invalid input or a storage failure.
	 *
	 * @since 0.123.0
	 *
	 * @param int $user_id Target user ID. Must be a positive integer.
	 * @return bool True on success (including no-op deletes), false on invalid input or storage failure.
	 */
	public function delete_account_for_user( int $user_id ): bool {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}

		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		$scope_key     = 'user:' . $user_id;

		if ( ! isset( $all_auth_data[ $this->provider_slug ]['principals'][ $scope_key ] ) ) {
			return true; // Idempotent: nothing to delete.
		}

		unset( $all_auth_data[ $this->provider_slug ]['principals'][ $scope_key ] );

		return update_site_option( 'datamachine_auth_data', $all_auth_data );
	}

	// -------------------------------------------------------------------------
	// Per-agent account API
	//
	// These methods provide a deliberate, no-fallback per-agent storage surface
	// for callers that operate on credentials delegated to a specific agent.
	// They share the same underlying storage layout as the principal-scoped API
	// (`principals[agent:<id>][account]`) so an account written via either
	// surface is readable through the other.
	// -------------------------------------------------------------------------

	/**
	 * Get the OAuth account for a specific agent.
	 *
	 * Sensitive fields are decrypted on read. Returns `null` when no per-agent
	 * account exists for this provider+agent — there is intentionally no fallback
	 * to site-wide storage.
	 *
	 * @since 0.129.0
	 *
	 * @param int $agent_id Target agent ID. Must be a positive integer.
	 * @return array|null Decrypted account data, or null if no account exists.
	 */
	public function get_account_for_agent( int $agent_id ): ?array {
		$agent_id = absint( $agent_id );
		if ( $agent_id <= 0 ) {
			return null;
		}

		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		$provider_data = $all_auth_data[ $this->provider_slug ] ?? array();
		$scope_key     = 'agent:' . $agent_id;
		$account       = $provider_data['principals'][ $scope_key ]['account'] ?? null;

		if ( ! is_array( $account ) || empty( $account ) ) {
			return null;
		}

		return $this->decrypt_fields( $account );
	}

	/**
	 * Save the OAuth account for a specific agent.
	 *
	 * Sensitive fields are encrypted before storage. Stores under the same
	 * `principals[agent:<id>][account]` slot used by the scope-aware
	 * `save_account()` path so the two surfaces stay in sync.
	 *
	 * @since 0.129.0
	 *
	 * @param int   $agent_id Target agent ID. Must be a positive integer.
	 * @param array $account Account data to store.
	 * @return bool True on successful write, false on invalid input or storage failure.
	 */
	public function save_account_for_agent( int $agent_id, array $account ): bool {
		$agent_id = absint( $agent_id );
		if ( $agent_id <= 0 ) {
			return false;
		}

		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		if ( ! isset( $all_auth_data[ $this->provider_slug ] ) || ! is_array( $all_auth_data[ $this->provider_slug ] ) ) {
			$all_auth_data[ $this->provider_slug ] = array();
		}
		if ( ! isset( $all_auth_data[ $this->provider_slug ]['principals'] ) || ! is_array( $all_auth_data[ $this->provider_slug ]['principals'] ) ) {
			$all_auth_data[ $this->provider_slug ]['principals'] = array();
		}

		$scope_key = 'agent:' . $agent_id;
		$all_auth_data[ $this->provider_slug ]['principals'][ $scope_key ]['account'] = $this->encrypt_fields( $account );

		return update_site_option( 'datamachine_auth_data', $all_auth_data );
	}

	/**
	 * Merge partial account data into a specific agent's OAuth account.
	 *
	 * @since 0.132.0
	 *
	 * @param int   $agent_id Target agent ID. Must be a positive integer.
	 * @param array $patch    Partial account data to merge.
	 * @return bool True on successful write, false on invalid input or storage failure.
	 */
	public function update_account_for_agent( int $agent_id, array $patch ): bool {
		$agent_id = absint( $agent_id );
		if ( $agent_id <= 0 ) {
			return false;
		}

		$scope_key = 'agent:' . $agent_id;
		$existing  = $this->get_stored_account_for_scope( $scope_key );
		return $this->store_account_for_scope( array_merge( $existing, $patch ), $scope_key );
	}

	/**
	 * Delete the OAuth account for a specific agent.
	 *
	 * Returns true when the per-agent slot existed and was removed, or when there
	 * was nothing to remove (idempotent). Returns false only on invalid input or a
	 * storage failure.
	 *
	 * @since 0.129.0
	 *
	 * @param int $agent_id Target agent ID. Must be a positive integer.
	 * @return bool True on success (including no-op deletes), false on invalid input or storage failure.
	 */
	public function delete_account_for_agent( int $agent_id ): bool {
		$agent_id = absint( $agent_id );
		if ( $agent_id <= 0 ) {
			return false;
		}

		$all_auth_data = get_site_option( 'datamachine_auth_data', array() );
		$scope_key     = 'agent:' . $agent_id;

		if ( ! isset( $all_auth_data[ $this->provider_slug ]['principals'][ $scope_key ] ) ) {
			return true; // Idempotent: nothing to delete.
		}

		unset( $all_auth_data[ $this->provider_slug ]['principals'][ $scope_key ] );

		return update_site_option( 'datamachine_auth_data', $all_auth_data );
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

	/**
	 * Resolve the current credential scope.
	 *
	 * Agent context wins over user context so delegated agent execution cannot
	 * accidentally read a broader owner credential when an agent credential exists.
	 * Returning null intentionally preserves site-level behavior.
	 *
	 * @param array $context Optional explicit principal context.
	 * @param string $credential_type Credential type: account or config.
	 * @return string|null Scope key such as agent:12 or user:34, or null.
	 */
	protected function get_principal_scope( array $context = array(), string $credential_type = 'account' ): ?string {
		$policy = $this->get_auth_scope_policy( $credential_type, $context );
		if ( self::AUTH_SCOPE_SITE === $policy ) {
			return null;
		}

		$agent_id = isset( $context['agent_id'] ) ? absint( $context['agent_id'] ) : 0;
		if ( $agent_id > 0 && in_array( $policy, array( self::AUTH_SCOPE_AGENT, self::AUTH_SCOPE_PRINCIPAL ), true ) ) {
			return 'agent:' . $agent_id;
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		if ( $user_id > 0 && in_array( $policy, array( self::AUTH_SCOPE_USER, self::AUTH_SCOPE_PRINCIPAL ), true ) ) {
			return 'user:' . $user_id;
		}

		if ( class_exists( PermissionHelper::class ) ) {
			$agent_id = absint( PermissionHelper::get_acting_agent_id() );
			if ( $agent_id > 0 && in_array( $policy, array( self::AUTH_SCOPE_AGENT, self::AUTH_SCOPE_PRINCIPAL ), true ) ) {
				return 'agent:' . $agent_id;
			}

			$user_id = absint( PermissionHelper::acting_user_id() );
			if ( $user_id > 0 && in_array( $policy, array( self::AUTH_SCOPE_USER, self::AUTH_SCOPE_PRINCIPAL ), true ) ) {
				return 'user:' . $user_id;
			}
		}

		if ( function_exists( 'get_current_user_id' ) ) {
			$user_id = absint( get_current_user_id() );
			if ( $user_id > 0 && in_array( $policy, array( self::AUTH_SCOPE_USER, self::AUTH_SCOPE_PRINCIPAL ), true ) ) {
				return 'user:' . $user_id;
			}
		}

		return null;
	}

	/**
	 * Return the credential scope policy for this provider.
	 *
	 * The default preserves existing site-wide behavior for all providers. Providers
	 * that represent a human browser/session identity can opt into user, agent, or
	 * principal scoping by overriding this method or using the filter.
	 *
	 * @param string $credential_type Credential type: account or config.
	 * @param array  $context Optional explicit principal context.
	 * @return string One of site, user, agent, principal.
	 */
	protected function get_auth_scope_policy( string $credential_type = 'account', array $context = array() ): string {
		$policy = self::AUTH_SCOPE_SITE;

		if ( function_exists( 'apply_filters' ) ) {
			$policy = (string) apply_filters(
				'datamachine_auth_scope_policy',
				$policy,
				$this->provider_slug,
				$credential_type,
				$context,
				$this
			);
		}

		$allowed = array(
			self::AUTH_SCOPE_SITE,
			self::AUTH_SCOPE_USER,
			self::AUTH_SCOPE_AGENT,
			self::AUTH_SCOPE_PRINCIPAL,
		);

		return in_array( $policy, $allowed, true ) ? $policy : self::AUTH_SCOPE_SITE;
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
