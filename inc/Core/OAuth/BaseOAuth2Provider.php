<?php
/**
 * Base OAuth 2.0 Provider Class
 *
 * Abstract base class for OAuth 2.0 providers to reduce code duplication.
 * Standardizes configuration, authentication checks, callback handling,
 * and token lifecycle management (expiry, refresh, proactive cron).
 *
 * Token lifecycle:
 * - On-demand refresh: get_valid_access_token() checks expiry with configurable
 *   buffer and auto-refreshes when close to expiration.
 * - Proactive refresh: schedule_proactive_refresh() registers a WP-Cron single
 *   event that fires before the token expires, ensuring tokens stay fresh even
 *   if no publishing happens for extended periods.
 * - Subclasses override do_refresh_token() with their platform-specific refresh
 *   API call (e.g. ig_refresh_token, th_refresh_token, refresh_token grant).
 *
 * @package DataMachine
 * @subpackage Core\OAuth
 * @since 0.2.0
 */

namespace DataMachine\Core\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseOAuth2Provider extends BaseAuthProvider {

	/**
	 * @var OAuth2Handler OAuth2 handler instance
	 */
	protected $oauth2;

	/**
	 * Constructor
	 *
	 * @param string $provider_slug Provider identifier
	 */
	public function __construct( string $provider_slug ) {
		parent::__construct( $provider_slug );
		$this->oauth2 = new OAuth2Handler();

		// Register cron hook for proactive token refresh.
		add_action( $this->get_cron_hook_name(), array( $this, 'handle_cron_refresh' ) );
	}

	/**
	 * Whether this provider requires a client_secret.
	 *
	 * Public OAuth2 clients (e.g. WordPress.com implicit flow) don't use a
	 * client_secret. Subclasses override this to return false when the provider
	 * is a public client.
	 *
	 * @since 0.66.0
	 * @return bool True if a client_secret is required (default). False for public clients.
	 */
	protected function requires_client_secret(): bool {
		return true;
	}

	/**
	 * Get the OAuth2 response type for this provider.
	 *
	 * Returns 'code' for standard authorization code flow (default).
	 * Returns 'token' for implicit flow (legacy public clients).
	 *
	 * When 'token' is returned, the callback page renders a JS snippet that
	 * extracts the access_token from the URL fragment and POSTs it to the
	 * server via OAuth2Handler::handle_implicit_callback().
	 *
	 * @since 0.66.0
	 * @return string 'code' or 'token'.
	 */
	public function get_oauth_response_type(): string {
		return 'code';
	}

	/**
	 * Whether this provider uses PKCE (Proof Key for Code Exchange).
	 *
	 * PKCE is the modern replacement for the implicit flow. It allows
	 * public clients (no client_secret) to use the authorization code
	 * flow securely by generating a one-time code_verifier/code_challenge
	 * pair for each authorization request.
	 *
	 * When true, OAuth2Handler automatically:
	 * - Generates code_verifier and code_challenge
	 * - Stores code_verifier in a transient
	 * - Adds code_challenge + code_challenge_method to the auth URL
	 * - Includes code_verifier in the token exchange request
	 *
	 * @since 0.66.0
	 * @return bool True to use PKCE. Default false.
	 */
	public function uses_pkce(): bool {
		return false;
	}

	/**
	 * Check if provider is properly configured.
	 *
	 * Requires client_id for all providers. Requires client_secret only when
	 * requires_client_secret() returns true (the default). Public clients
	 * override requires_client_secret() to skip the secret check.
	 *
	 * @return bool True if configured
	 */
	public function is_configured(): bool {
		$config = $this->get_config();

		if ( empty( $config['client_id'] ) ) {
			return false;
		}

		return ! $this->requires_client_secret() || ! empty( $config['client_secret'] );
	}

	/**
	 * Check if authenticated.
	 *
	 * Validates that an access token exists and has not expired.
	 * Subclasses may override for additional checks (e.g. Facebook dual tokens)
	 * but should call parent::is_authenticated() or replicate the expiry check.
	 *
	 * @return bool True if authenticated
	 */
	public function is_authenticated(): bool {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['access_token'] ) ) {
			return false;
		}

		// Check token expiry if stored.
		if ( isset( $account['token_expires_at'] ) && time() > intval( $account['token_expires_at'] ) ) {
			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Token Lifecycle Management
	// -------------------------------------------------------------------------

	/**
	 * Get a valid access token, refreshing if close to expiry.
	 *
	 * This is the recommended way to obtain an access token before making API
	 * calls. It checks whether the token is expired or within the refresh buffer,
	 * and automatically refreshes when needed.
	 *
	 * Providers that don't support refresh (do_refresh_token returns null) will
	 * simply return the current token or null if expired.
	 *
	 * @since 0.31.1
	 * @return string|null Valid access token, or null if unavailable/expired.
	 */
	public function get_valid_access_token(): ?string {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['access_token'] ) ) {
			return null;
		}

		$current_token = $account['access_token'];
		$needs_refresh = false;

		if ( isset( $account['token_expires_at'] ) ) {
			$expiry_timestamp = intval( $account['token_expires_at'] );
			$buffer           = $this->get_refresh_buffer_seconds();

			if ( time() > $expiry_timestamp ) {
				// Token is expired.
				$needs_refresh = true;
			} elseif ( ( $expiry_timestamp - time() ) < $buffer ) {
				// Token expires within the buffer window.
				$needs_refresh = true;
			}
		}

		if ( $needs_refresh ) {
			$refreshed = $this->do_refresh_token( $current_token );

			if ( null === $refreshed ) {
				// Provider does not support refresh.
				if ( isset( $account['token_expires_at'] ) && time() > intval( $account['token_expires_at'] ) ) {
					return null;
				}
				return $current_token;
			}

			if ( ! is_wp_error( $refreshed ) && ! empty( $refreshed['access_token'] ) ) {
				$account['access_token']      = $refreshed['access_token'];
				$account['token_expires_at']  = $refreshed['expires_at'] ?? $account['token_expires_at'];
				$account['last_refreshed_at'] = time();
				$this->save_account( $account );

				// Re-schedule proactive refresh for the new token.
				$this->schedule_proactive_refresh();

				do_action(
					'datamachine_log',
					'info',
					'OAuth2: Token refreshed successfully',
					array(
						'provider'   => $this->provider_slug,
						'expires_at' => $account['token_expires_at'],
					)
				);

				return $refreshed['access_token'];
			}

			// Refresh failed.
			do_action(
				'datamachine_log',
				'error',
				'OAuth2: Token refresh failed',
				array(
					'provider' => $this->provider_slug,
					'error'    => is_wp_error( $refreshed ) ? $refreshed->get_error_message() : 'Unknown error',
				)
			);

			// Return current token if not yet hard-expired.
			if ( isset( $account['token_expires_at'] ) && time() > intval( $account['token_expires_at'] ) ) {
				return null;
			}
			return $current_token;
		}

		return $current_token;
	}

	/**
	 * Perform the platform-specific token refresh API call.
	 *
	 * Override in subclasses to implement the actual refresh logic.
	 * Return null if the provider does not support token refresh.
	 *
	 * Expected return format on success:
	 *   ['access_token' => '...', 'expires_at' => <unix_timestamp>]
	 *
	 * @since 0.31.1
	 * @param string $current_token The current access token to refresh.
	 * @return array|\WP_Error|null Token data on success, WP_Error on failure,
	 *                              or null if refresh is not supported.
	 */
	protected function do_refresh_token( string $current_token ): array|\WP_Error|null {
		return null;
	}

	/**
	 * Get the number of seconds before token expiry to trigger a refresh.
	 *
	 * Override in subclasses to change the buffer. Default is 7 days.
	 * For providers with short-lived tokens (e.g. Google, 1 hour), override
	 * to return a smaller buffer like 300 (5 minutes).
	 *
	 * @since 0.31.1
	 * @return int Buffer in seconds before expiry to trigger refresh.
	 */
	protected function get_refresh_buffer_seconds(): int {
		return 7 * DAY_IN_SECONDS;
	}

	// -------------------------------------------------------------------------
	// Proactive Cron Refresh
	// -------------------------------------------------------------------------

	/**
	 * Get the WP-Cron hook name for this provider's proactive refresh.
	 *
	 * @since 0.31.1
	 * @return string Cron hook name.
	 */
	public function get_cron_hook_name(): string {
		return 'datamachine_refresh_token_' . $this->provider_slug;
	}

	/**
	 * Schedule a proactive WP-Cron event to refresh the token before it expires.
	 *
	 * Call this after initial authentication and after each successful refresh.
	 * The event fires at (token_expires_at - refresh_buffer), ensuring the token
	 * is refreshed even if no one calls get_valid_access_token() for weeks.
	 *
	 * When the refresh window is already past (token expired or within buffer),
	 * attempts an immediate on-demand refresh to self-heal rather than leaving
	 * the proactive refresh chain permanently broken.
	 *
	 * @since 0.31.1
	 * @return bool True if event was scheduled, false otherwise.
	 */
	public function schedule_proactive_refresh(): bool {
		$hook = $this->get_cron_hook_name();

		// Clear any existing scheduled refresh for this provider.
		wp_clear_scheduled_hook( $hook );

		$account = $this->get_account();
		if ( empty( $account['token_expires_at'] ) ) {
			return false;
		}

		$expiry     = intval( $account['token_expires_at'] );
		$buffer     = $this->get_refresh_buffer_seconds();
		$refresh_at = $expiry - $buffer;

		// Refresh window is in the future — schedule normally.
		if ( $refresh_at > time() ) {
			$scheduled = wp_schedule_single_event( $refresh_at, $hook );

			if ( false !== $scheduled ) {
				do_action(
					'datamachine_log',
					'debug',
					'OAuth2: Scheduled proactive token refresh',
					array(
						'provider'   => $this->provider_slug,
						'refresh_at' => wp_date( 'Y-m-d H:i:s', $refresh_at ),
						'expires_at' => wp_date( 'Y-m-d H:i:s', $expiry ),
					)
				);
			}

			return false !== $scheduled;
		}

		// Refresh window is in the past — attempt immediate recovery.
		// This prevents a permanent dead-end where no future cron is scheduled.
		do_action(
			'datamachine_log',
			'info',
			'OAuth2: Refresh window passed, attempting immediate recovery',
			array(
				'provider'   => $this->provider_slug,
				'expires_at' => wp_date( 'Y-m-d H:i:s', $expiry ),
			)
		);

		$token = $this->attempt_recovery_refresh();

		if ( null !== $token ) {
			// Recovery succeeded — attempt_recovery_refresh() saved the new token
			// and called schedule_proactive_refresh() which re-entered this method.
			// The new token's refresh_at is in the future, so the cron was scheduled
			// via the normal path above.
			return true;
		}

		return false;
	}

	/**
	 * Handle the proactive cron refresh event.
	 *
	 * Called automatically by WP-Cron when the scheduled event fires.
	 *
	 * If the token is still valid (or within buffer), delegates to
	 * get_valid_access_token() which handles refresh and re-scheduling.
	 *
	 * If the token is already expired (cron window was missed), attempts
	 * a recovery refresh instead of giving up. This prevents the refresh
	 * chain from being permanently broken when WP-Cron misses its window.
	 *
	 * @since 0.31.1
	 */
	public function handle_cron_refresh(): void {
		$account = $this->get_account();

		// No account data at all — nothing to refresh.
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['access_token'] ) ) {
			do_action(
				'datamachine_log',
				'debug',
				'OAuth2: Cron refresh skipped — no account data',
				array( 'provider' => $this->provider_slug )
			);
			return;
		}

		// Try the normal path first (handles both valid and expired tokens).
		$token = $this->get_valid_access_token();

		if ( null !== $token ) {
			do_action(
				'datamachine_log',
				'info',
				'OAuth2: Cron refresh completed',
				array( 'provider' => $this->provider_slug )
			);
			return;
		}

		// get_valid_access_token() returned null — token is expired and refresh failed.
		// Fire the failure hook so external systems can react.
		do_action(
			'datamachine_log',
			'error',
			'OAuth2: Cron refresh failed — token expired and refresh failed. Re-authorize in WP Admin > Data Machine > Settings.',
			array( 'provider' => $this->provider_slug )
		);

		/**
		 * Fires when an OAuth2 token refresh fails and the token is expired.
		 *
		 * External systems (agent pings, notifications, admin notices) can
		 * hook into this to alert the user that re-authorization is needed.
		 *
		 * @since 0.32.0
		 * @param string $provider_slug The provider that failed (e.g. 'reddit', 'pinterest').
		 * @param array  $account       The account data at the time of failure.
		 */
		do_action( 'datamachine_oauth_refresh_failed', $this->provider_slug, $account );
	}

	/**
	 * Attempt to recover from an expired token by performing an immediate refresh.
	 *
	 * Used by schedule_proactive_refresh() when the refresh window has passed.
	 * Delegates to do_refresh_token() and saves the new token on success.
	 *
	 * On success, calls schedule_proactive_refresh() to re-establish the cron
	 * chain. This re-enters schedule_proactive_refresh(), but the new token's
	 * refresh window is in the future, so it takes the normal scheduling path
	 * (no recursion beyond one level).
	 *
	 * @since 0.32.0
	 * @return string|null New access token on success, null on failure.
	 */
	protected function attempt_recovery_refresh(): ?string {
		$account = $this->get_account();
		if ( empty( $account ) || ! is_array( $account ) || empty( $account['access_token'] ) ) {
			return null;
		}

		$refreshed = $this->do_refresh_token( $account['access_token'] );

		if ( null === $refreshed ) {
			// Provider does not support refresh — cannot recover.
			return null;
		}

		if ( ! is_wp_error( $refreshed ) && ! empty( $refreshed['access_token'] ) ) {
			$account['access_token']      = $refreshed['access_token'];
			$account['token_expires_at']  = $refreshed['expires_at'] ?? $account['token_expires_at'];
			$account['last_refreshed_at'] = time();
			$this->save_account( $account );

			do_action(
				'datamachine_log',
				'info',
				'OAuth2: Recovery refresh succeeded',
				array(
					'provider'   => $this->provider_slug,
					'expires_at' => $account['token_expires_at'],
				)
			);

			// Schedule proactive refresh for the new token's expiry.
			// This is safe because the new token's refresh_at should be in the future.
			$this->schedule_proactive_refresh();

			return $refreshed['access_token'];
		}

		// Refresh failed — fire the failure hook.
		$error_message = is_wp_error( $refreshed ) ? $refreshed->get_error_message() : 'Unknown error';

		do_action(
			'datamachine_log',
			'error',
			'OAuth2: Recovery refresh failed. Re-authorize in WP Admin > Data Machine > Settings.',
			array(
				'provider' => $this->provider_slug,
				'error'    => $error_message,
			)
		);

		/** This action is documented in handle_cron_refresh(). */
		do_action( 'datamachine_oauth_refresh_failed', $this->provider_slug, $account );

		return null;
	}

	/**
	 * Clear proactive refresh cron for this provider.
	 *
	 * Called automatically when account is cleared/disconnected.
	 *
	 * @since 0.31.1
	 */
	public function clear_proactive_refresh(): void {
		wp_clear_scheduled_hook( $this->get_cron_hook_name() );
	}

	/**
	 * Clear OAuth account data and clean up cron.
	 *
	 * Overrides BaseAuthProvider::clear_account() to also remove
	 * the proactive refresh cron event.
	 *
	 * @since 0.31.1
	 * @return bool True on success
	 */
	public function clear_account(): bool {
		$this->clear_proactive_refresh();
		return parent::clear_account();
	}

	// -------------------------------------------------------------------------
	// Legacy / Display
	// -------------------------------------------------------------------------

	/**
	 * Get account details for display
	 *
	 * @return array|null Account details or null
	 */
	public function get_account_details(): ?array {
		$account = $this->get_account();
		if ( empty( $account ) || empty( $account['access_token'] ) ) {
			return null;
		}

		$details = array();
		if ( ! empty( $account['username'] ) ) {
			$details['username'] = $account['username'];
		}
		if ( ! empty( $account['name'] ) ) {
			$details['name'] = $account['name'];
		}
		if ( ! empty( $account['scope'] ) ) {
			$details['scope'] = $account['scope'];
		}
		if ( ! empty( $account['last_refreshed_at'] ) ) {
			$details['last_refreshed'] = wp_date( 'Y-m-d H:i:s', $account['last_refreshed_at'] );
		}
		if ( ! empty( $account['token_expires_at'] ) ) {
			$details['token_expires_at'] = wp_date( 'Y-m-d H:i:s', intval( $account['token_expires_at'] ) );
		}

		return $details;
	}

	/**
	 * Get configuration fields (Abstract)
	 *
	 * @return array Configuration field definitions
	 */
	abstract public function get_config_fields(): array;

	/**
	 * Get authorization URL (Abstract or default implementation)
	 *
	 * @return string Authorization URL
	 */
	abstract public function get_authorization_url(): string;

	/**
	 * Handle OAuth callback (Abstract or default implementation)
	 */
	abstract public function handle_oauth_callback();

	/**
	 * Refresh token (Legacy — use get_valid_access_token() instead)
	 *
	 * @deprecated 0.31.1 Use get_valid_access_token() for on-demand refresh.
	 * @return bool True on success
	 */
	public function refresh_token(): bool {
		$token = $this->get_valid_access_token();
		return null !== $token;
	}
}
