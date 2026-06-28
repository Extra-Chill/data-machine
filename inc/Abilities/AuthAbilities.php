<?php
/**
 * Auth Abilities
 *
 * WordPress 6.9 Abilities API primitives for authentication operations.
 * Centralizes OAuth status, disconnect, and configuration saving.
 * Self-contained auth provider discovery and lookup with request-level caching.
 *
 * @package DataMachine\Abilities
 */

namespace DataMachine\Abilities;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class AuthAbilities {

	private static bool $registered = false;

	/**
	 * Cached auth providers.
	 *
	 * @var array|null
	 */
	private static ?array $cache = null;

	private HandlerAbilities $handler_abilities;

	public function __construct() {
		$this->handler_abilities = new HandlerAbilities();

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	/**
	 * Clear cached auth providers.
	 * Call when handlers are dynamically registered.
	 */
	public static function clearCache(): void {
		self::$cache = null;
	}

	/**
	 * Get all registered auth providers (cached).
	 *
	 * @return array Auth providers array keyed by provider key
	 */
	public function getAllProviders(): array {
		if ( null === self::$cache ) {
			self::$cache = apply_filters( 'datamachine_auth_providers', array() );
		}

		return self::$cache;
	}

	/**
	 * Get auth provider instance by provider key.
	 *
	 * @param string $provider_key Provider key (e.g., 'facebook', 'reddit')
	 * @return object|null Auth provider instance or null
	 */
	public function getProvider( string $provider_key ): ?object {
		$providers = $this->getAllProviders();
		return $providers[ $provider_key ] ?? null;
	}

	/**
	 * Resolve the auth provider key for a handler slug.
	 *
	 * Handlers can share authentication by setting `auth_provider_key` during
	 * registration (see HandlerRegistrationTrait). This method centralizes the
	 * mapping so callers do not assume provider key === handler slug.
	 *
	 * @param string $handler_slug Handler slug.
	 * @return string Provider key to use for lookups.
	 */
	private function resolveProviderKey( string $handler_slug ): string {
		$handler = $this->handler_abilities->getHandler( $handler_slug );

		if ( ! is_array( $handler ) ) {
			return $handler_slug;
		}

		$auth_provider_key = $handler['auth_provider_key'] ?? null;

		if ( ! is_string( $auth_provider_key ) || '' === $auth_provider_key ) {
			return $handler_slug;
		}

		if ( $auth_provider_key !== $handler_slug ) {
			do_action(
				'datamachine_log',
				'debug',
				'Resolved auth provider key differs from handler slug',
				array(
					'handler_slug'      => $handler_slug,
					'auth_provider_key' => $auth_provider_key,
				)
			);
		}

		return $auth_provider_key;
	}

	/**
	 * Get auth provider instance from a handler slug.
	 *
	 * @param string $handler_slug Handler slug.
	 * @return object|null Auth provider instance or null.
	 */
	public function getProviderForHandler( string $handler_slug ): ?object {
		$provider_key = $this->resolveProviderKey( $handler_slug );
		return $this->getProvider( $provider_key );
	}

	/**
	 * Check if auth provider exists for handler.
	 *
	 * @param string $handler_slug Handler slug
	 * @return bool True if auth provider exists
	 */
	public function providerExists( string $handler_slug ): bool {
		return $this->getProviderForHandler( $handler_slug ) !== null;
	}

	/**
	 * Check if handler is authenticated (has valid tokens).
	 *
	 * @param string $handler_slug Handler slug
	 * @return bool True if authenticated
	 */
	public function isHandlerAuthenticated( string $handler_slug ): bool {
		$provider = $this->getProviderForHandler( $handler_slug );

		if ( ! $provider || ! method_exists( $provider, 'is_authenticated' ) ) {
			return false;
		}

		return $provider->is_authenticated();
	}

	/**
	 * Get authentication status details for a handler.
	 *
	 * @param string $handler_slug Handler slug
	 * @return array Status array with exists, authenticated, and provider keys
	 */
	public function getAuthStatus( string $handler_slug ): array {
		$provider = $this->getProviderForHandler( $handler_slug );

		if ( ! $provider ) {
			return array(
				'exists'        => false,
				'authenticated' => false,
				'provider'      => null,
			);
		}

		$authenticated = method_exists( $provider, 'is_authenticated' )
			? $provider->is_authenticated()
			: false;

		return array(
			'exists'        => true,
			'authenticated' => $authenticated,
			'provider'      => $provider,
		);
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerGetAuthStatus();
			$this->registerDisconnectAuth();
			$this->registerRevokeAuthForUser();
			$this->registerSaveAuthConfig();
			$this->registerSetAuthToken();
			$this->registerRefreshAuth();
			$this->registerListProviders();
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	private function registerGetAuthStatus(): void {
		wp_register_ability(
			'datamachine/get-auth-status',
			array(
				'label'               => __( 'Get Auth Status', 'data-machine' ),
				'description'         => __( 'Get OAuth/authentication status for a handler including authorization URL if applicable.', 'data-machine' ),
				'category'            => 'datamachine-auth',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'handler_slug' ),
					'properties' => array(
						'handler_slug' => array(
							'type'        => 'string',
							'description' => __( 'Handler identifier (e.g., twitter, facebook)', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'authenticated' => array( 'type' => 'boolean' ),
						'requires_auth' => array( 'type' => 'boolean' ),
						'handler_slug'  => array( 'type' => 'string' ),
						'oauth_url'     => array( 'type' => 'string' ),
						'message'       => array( 'type' => 'string' ),
						'instructions'  => array( 'type' => 'string' ),
						'error'         => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetAuthStatus' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerDisconnectAuth(): void {
		wp_register_ability(
			'datamachine/disconnect-auth',
			array(
				'label'               => __( 'Disconnect Auth', 'data-machine' ),
				'description'         => __( 'Disconnect/revoke authentication for a handler.', 'data-machine' ),
				'category'            => 'datamachine-auth',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'handler_slug' ),
					'properties' => array(
						'handler_slug' => array(
							'type'        => 'string',
							'description' => __( 'Handler identifier (e.g., twitter, facebook)', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeDisconnectAuth' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerRevokeAuthForUser(): void {
		wp_register_ability(
			'datamachine/revoke-auth-for-user',
			array(
				'label'               => __( 'Revoke Auth For User', 'data-machine' ),
				'description'         => __( 'Revoke a specific user\'s OAuth credentials for a handler. Used by platform UIs to power per-user disconnect buttons.', 'data-machine' ),
				'category'            => 'datamachine-auth',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'handler_slug', 'user_id' ),
					'properties' => array(
						'handler_slug' => array(
							'type'        => 'string',
							'description' => __( 'Handler identifier (e.g., registered handler slug).', 'data-machine' ),
						),
						'user_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Target user ID whose per-user credentials should be revoked.', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'          => array( 'type' => 'boolean' ),
						'message'          => array( 'type' => 'string' ),
						'error'            => array( 'type' => 'string' ),
						'upstream_revoked' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the provider\'s upstream revoke endpoint was called successfully. Local storage is still cleared on upstream failure.', 'data-machine' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'executeRevokeAuthForUser' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerSaveAuthConfig(): void {
		wp_register_ability(
			'datamachine/save-auth-config',
			array(
				'label'               => __( 'Save Auth Config', 'data-machine' ),
				'description'         => __( 'Save authentication configuration for a handler.', 'data-machine' ),
				'category'            => 'datamachine-auth',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'handler_slug' ),
					'properties' => array(
						'handler_slug' => array(
							'type'        => 'string',
							'description' => __( 'Handler identifier', 'data-machine' ),
						),
						'config'       => array(
							'type'        => 'object',
							'description' => __( 'Configuration key-value pairs to save', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeSaveAuthConfig' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerSetAuthToken(): void {
		wp_register_ability(
			'datamachine/set-auth-token',
			array(
				'label'               => __( 'Set Auth Token', 'data-machine' ),
				'description'         => __( 'Manually set authentication token and account data for a handler. Used for migration, CI, and headless auth setup.', 'data-machine' ),
				'category'            => 'datamachine-auth',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'handler_slug', 'account_data' ),
					'properties' => array(
						'handler_slug' => array(
							'type'        => 'string',
							'description' => __( 'Handler identifier (e.g., twitter, facebook, linkedin)', 'data-machine' ),
						),
						'account_data' => array(
							'type'        => 'object',
							'description' => __( 'Account data to store. Must include access_token. Can include any platform-specific fields (user_id, username, token_expires_at, refresh_token, etc.).', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeSetAuthToken' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerRefreshAuth(): void {
		wp_register_ability(
			'datamachine/refresh-auth',
			array(
				'label'               => __( 'Refresh Auth Token', 'data-machine' ),
				'description'         => __( 'Force a token refresh for an OAuth2 handler. Only works for providers that support token refresh.', 'data-machine' ),
				'category'            => 'datamachine-auth',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'handler_slug' ),
					'properties' => array(
						'handler_slug' => array(
							'type'        => 'string',
							'description' => __( 'Handler identifier (e.g., twitter, facebook, linkedin)', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'message'    => array( 'type' => 'string' ),
						'expires_at' => array( 'type' => array( 'string', 'null' ) ),
						'error'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeRefreshAuth' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerListProviders(): void {
		wp_register_ability(
			'datamachine/list-auth-providers',
			array(
				'label'               => __( 'List Auth Providers', 'data-machine' ),
				'description'         => __( 'List all registered authentication providers with status, config fields, and account details.', 'data-machine' ),
				'category'            => 'datamachine-auth',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'providers' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( $this, 'executeListProviders' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * List all registered auth providers with status and configuration.
	 *
	 * Returns each provider with its type (oauth2, oauth1, simple),
	 * authentication status, config fields, callback URL, and connected
	 * account details.
	 *
	 * @since 0.47.0
	 * @param array $input Ability input (unused).
	 * @return array Provider list.
	 */
	public function executeListProviders( array $input ): array {
		$input;
		$providers = $this->getAllProviders();

		$data = array();

		foreach ( $providers as $provider_key => $instance ) {
			$auth_type = 'simple';
			if ( $instance instanceof \DataMachine\Core\OAuth\BaseOAuth2Provider ) {
				$auth_type = 'oauth2';
			} elseif ( $instance instanceof \DataMachine\Core\OAuth\BaseOAuth1Provider ) {
				$auth_type = 'oauth1';
			}

			$is_authenticated = false;
			if ( method_exists( $instance, 'is_authenticated' ) ) {
				$is_authenticated = $instance->is_authenticated();
			}

			$entry = array(
				'provider_key'     => $provider_key,
				'label'            => ucfirst( str_replace( '_', ' ', $provider_key ) ),
				'auth_type'        => $auth_type,
				'is_configured'    => method_exists( $instance, 'is_configured' ) ? $instance->is_configured() : false,
				'is_authenticated' => $is_authenticated,
				'auth_fields'      => method_exists( $instance, 'get_config_fields' ) ? $instance->get_config_fields() : array(),
				'config_values'    => method_exists( $instance, 'get_config' ) ? $instance->get_config() : array(),
				'callback_url'     => null,
				'account_details'  => null,
			);

			if ( in_array( $auth_type, array( 'oauth1', 'oauth2' ), true ) && method_exists( $instance, 'get_callback_url' ) ) {
				$entry['callback_url'] = $instance->get_callback_url();
			}

			if ( $is_authenticated && method_exists( $instance, 'get_account_details' ) ) {
				$entry['account_details'] = $instance->get_account_details();
			}

			$data[] = $entry;
		}

		// Sort: authenticated first, then alphabetically by label.
		usort( $data, function ( $a, $b ) {
			if ( $a['is_authenticated'] !== $b['is_authenticated'] ) {
				return $a['is_authenticated'] ? -1 : 1;
			}
			return strcasecmp( $a['label'], $b['label'] );
		} );

		return array(
			'success'   => true,
			'providers' => $data,
		);
	}

	public function executeGetAuthStatus( array $input ): array {
		$handler_slug = sanitize_text_field( $input['handler_slug'] ?? '' );

		if ( empty( $handler_slug ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Handler slug is required', 'data-machine' ),
			);
		}

		$handler_info = $this->handler_abilities->getHandler( $handler_slug );
		if ( $handler_info && ( $handler_info['requires_auth'] ?? false ) === false ) {
			return array(
				'success'       => true,
				'authenticated' => true,
				'requires_auth' => false,
				'handler_slug'  => $handler_slug,
				'message'       => __( 'Authentication not required for this handler', 'data-machine' ),
			);
		}

		$auth_instance = $this->getProviderForHandler( $handler_slug );

		if ( ! $auth_instance ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication provider not found', 'data-machine' ),
			);
		}

		if ( ! method_exists( $auth_instance, 'get_authorization_url' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'This handler does not support OAuth authorization', 'data-machine' ),
			);
		}

		if ( method_exists( $auth_instance, 'is_configured' ) && ! $auth_instance->is_configured() ) {
			return array(
				'success' => false,
				'error'   => __( 'OAuth credentials not configured. Please provide client ID and secret first.', 'data-machine' ),
			);
		}

		try {
			$oauth_url = $auth_instance->get_authorization_url();

			return array(
				'success'       => true,
				'oauth_url'     => $oauth_url,
				'handler_slug'  => $handler_slug,
				'requires_auth' => true,
				'instructions'  => __( 'Visit this URL to authorize your account. You will be redirected back to Data Machine upon completion.', 'data-machine' ),
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	public function executeDisconnectAuth( array $input ): array {
		$handler_slug = sanitize_text_field( $input['handler_slug'] ?? '' );

		if ( empty( $handler_slug ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Handler slug is required', 'data-machine' ),
			);
		}

		$handler_info = $this->handler_abilities->getHandler( $handler_slug );
		if ( $handler_info && ( $handler_info['requires_auth'] ?? false ) === false ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication is not required for this handler', 'data-machine' ),
			);
		}

		$auth_instance = $this->getProviderForHandler( $handler_slug );

		if ( ! $auth_instance ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication provider not found', 'data-machine' ),
			);
		}

		if ( ! method_exists( $auth_instance, 'clear_account' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'This handler does not support account disconnection', 'data-machine' ),
			);
		}

		$cleared = $auth_instance->clear_account();

		if ( $cleared ) {
			return array(
				'success' => true,
				/* translators: %s: Service name (e.g., Twitter, Facebook) */
				'message' => sprintf( __( '%s account disconnected successfully', 'data-machine' ), ucfirst( $handler_slug ) ),
			);
		}

		return array(
			'success' => false,
			'error'   => __( 'Failed to disconnect account', 'data-machine' ),
		);
	}

	/**
	 * Revoke a specific user's OAuth credentials for a handler.
	 *
	 * Deletes the per-user account slot via BaseAuthProvider::delete_account_for_user.
	 * If the provider exposes an upstream revoke method (`revoke_token_for_user`
	 * or generic `revoke_token`), it is called BEFORE local deletion so the
	 * upstream credential is invalidated even if we lose our copy later. An
	 * upstream failure logs a warning but does NOT prevent local deletion —
	 * better to lose local access than leak credentials.
	 *
	 * @since 0.123.0
	 *
	 * @param array $input { handler_slug, user_id }.
	 * @return array Standard ability response.
	 */
	public function executeRevokeAuthForUser( array $input ): array {
		$handler_slug = sanitize_text_field( $input['handler_slug'] ?? '' );
		$user_id      = absint( $input['user_id'] ?? 0 );

		if ( '' === $handler_slug ) {
			return array(
				'success' => false,
				'error'   => __( 'Handler slug is required', 'data-machine' ),
			);
		}

		if ( $user_id <= 0 ) {
			return array(
				'success' => false,
				'error'   => __( 'A positive user_id is required', 'data-machine' ),
			);
		}

		$auth_instance = $this->getProviderForHandler( $handler_slug );
		if ( ! $auth_instance ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication provider not found', 'data-machine' ),
			);
		}

		if ( ! method_exists( $auth_instance, 'delete_account_for_user' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'This handler does not support per-user revocation', 'data-machine' ),
			);
		}

		$revoked_by_user_id = absint( PermissionHelper::acting_user_id() );
		if ( $revoked_by_user_id <= 0 && function_exists( 'get_current_user_id' ) ) {
			$revoked_by_user_id = absint( get_current_user_id() );
		}

		// Best-effort upstream revoke before local delete. If the provider doesn't
		// implement a revoke endpoint, that's fine — local-only revocation still
		// achieves the immediate goal of cutting off this install's access.
		$upstream_revoked = null;
		if ( method_exists( $auth_instance, 'revoke_token_for_user' ) ) {
			try {
				$upstream_revoked = (bool) $auth_instance->revoke_token_for_user( $user_id );
			} catch ( \Throwable $e ) {
				$upstream_revoked = false;
				do_action(
					'datamachine_log',
					'warning',
					'OAuth: Upstream per-user revoke threw',
					array(
						'provider' => $handler_slug,
						'user_id'  => $user_id,
						'error'    => $e->getMessage(),
					)
				);
			}
			if ( ! $upstream_revoked ) {
				do_action(
					'datamachine_log',
					'warning',
					'OAuth: Upstream per-user revoke failed; continuing with local delete',
					array(
						'provider' => $handler_slug,
						'user_id'  => $user_id,
					)
				);
			}
		}

		$deleted = $auth_instance->delete_account_for_user( $user_id );

		do_action(
			'datamachine_log',
			'info',
			'OAuth: Per-user credentials revoked',
			array(
				'provider'         => $handler_slug,
				'user_id'          => $user_id,
				'revoked_by'       => $revoked_by_user_id,
				'upstream_revoked' => $upstream_revoked,
				'local_deleted'    => $deleted,
			)
		);

		if ( ! $deleted ) {
			return array(
				'success'          => false,
				'error'            => __( 'Failed to delete per-user credentials', 'data-machine' ),
				'upstream_revoked' => (bool) $upstream_revoked,
			);
		}

		return array(
			'success'          => true,
			'message'          => sprintf(
				/* translators: 1: Service name, 2: user ID */
				__( '%1$s credentials revoked for user %2$d', 'data-machine' ),
				ucfirst( $handler_slug ),
				$user_id
			),
			'upstream_revoked' => (bool) $upstream_revoked,
		);
	}

	public function executeSaveAuthConfig( array $input ): array {
		$handler_slug      = sanitize_text_field( $input['handler_slug'] ?? '' );
		$config_input      = $input['config'] ?? array();
		$principal_context = $this->getPrincipalContext( $input );

		if ( empty( $handler_slug ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Handler slug is required', 'data-machine' ),
			);
		}

		$handler_info = $this->handler_abilities->getHandler( $handler_slug );
		if ( $handler_info && ( $handler_info['requires_auth'] ?? false ) === false ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication is not required for this handler', 'data-machine' ),
			);
		}

		$auth_instance = $this->getProviderForHandler( $handler_slug );

		if ( ! $auth_instance || ! method_exists( $auth_instance, 'get_config_fields' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Auth provider not found or invalid', 'data-machine' ),
			);
		}

		$config_fields = $auth_instance->get_config_fields();
		$config_data   = array();

		$uses_oauth = method_exists( $auth_instance, 'get_authorization_url' ) || method_exists( $auth_instance, 'handle_oauth_callback' );

		$existing_config = array();
		if ( method_exists( $auth_instance, 'get_config' ) ) {
			$existing_config = $auth_instance->get_config();
		} elseif ( method_exists( $auth_instance, 'get_account' ) ) {
			$existing_config = $auth_instance->get_account();
		} else {
			return array(
				'success' => false,
				'error'   => __( 'Could not retrieve existing configuration', 'data-machine' ),
			);
		}

		foreach ( $config_fields as $field_name => $field_config ) {
			$value = sanitize_text_field( $config_input[ $field_name ] ?? '' );

			if ( ( $field_config['required'] ?? false ) && empty( $value ) && empty( $existing_config[ $field_name ] ?? '' ) ) {
				return array(
					'success' => false,
					/* translators: %s: Field label (e.g., API Key, Client ID) */
					'error'   => sprintf( __( '%s is required', 'data-machine' ), $field_config['label'] ),
				);
			}

			if ( empty( $value ) && ! empty( $existing_config[ $field_name ] ?? '' ) ) {
				$value = $existing_config[ $field_name ];
			}

			$config_data[ $field_name ] = $value;
		}

		if ( ! empty( $existing_config ) ) {
			$data_changed = false;

			foreach ( $config_data as $field_name => $new_value ) {
				$existing_value = $existing_config[ $field_name ] ?? '';
				if ( $new_value !== $existing_value ) {
					$data_changed = true;
					break;
				}
			}

			if ( ! $data_changed ) {
				return array(
					'success' => true,
					'message' => __( 'Configuration is already up to date - no changes detected', 'data-machine' ),
				);
			}
		}

		if ( $uses_oauth ) {
			if ( method_exists( $auth_instance, 'save_config' ) ) {
				$saved = $auth_instance->save_config( $config_data );
			} else {
				return array(
					'success' => false,
					'error'   => __( 'Handler does not support saving config', 'data-machine' ),
				);
			}
		} elseif ( method_exists( $auth_instance, 'save_config' ) ) {
			$saved = $auth_instance->save_config( $config_data, $principal_context );
		} else {
			$saved = $this->saveAuthAccountForContext( $auth_instance, $config_data, $principal_context );
			if ( null === $saved ) {
				return array(
					'success' => false,
					'error'   => __( 'Handler does not support saving account', 'data-machine' ),
				);
			}
		}

		if ( $saved ) {
			return array(
				'success' => true,
				'message' => __( 'Configuration saved successfully', 'data-machine' ),
			);
		}

		return array(
			'success' => false,
			'error'   => __( 'Failed to save configuration', 'data-machine' ),
		);
	}

	/**
	 * Save account data through the explicit site/user/agent account APIs.
	 *
	 * @param object $auth_instance Auth provider instance.
	 * @param array  $account_data Account data to save.
	 * @param array  $principal_context Principal context with optional agent_id/user_id.
	 * @return bool|null Save result, or null when no supported account save API exists.
	 */
	private function saveAuthAccountForContext( object $auth_instance, array $account_data, array $principal_context ): ?bool {
		if ( ! empty( $principal_context['agent_id'] ) ) {
			return method_exists( $auth_instance, 'save_account_for_agent' )
				? (bool) $auth_instance->save_account_for_agent( absint( $principal_context['agent_id'] ), $account_data )
				: null;
		}

		if ( ! empty( $principal_context['user_id'] ) ) {
			return method_exists( $auth_instance, 'save_account_for_user' )
				? (bool) $auth_instance->save_account_for_user( absint( $principal_context['user_id'] ), $account_data )
				: null;
		}

		if ( method_exists( $auth_instance, 'save_site_account' ) ) {
			return (bool) $auth_instance->save_site_account( $account_data );
		}

		if ( method_exists( $auth_instance, 'save_account' ) ) {
			return (bool) $auth_instance->save_account( $account_data );
		}

		return null;
	}

	/**
	 * Check whether the provider can save account data for the requested context.
	 *
	 * @param object $auth_instance Auth provider instance.
	 * @param array  $principal_context Principal context with optional agent_id/user_id.
	 * @return bool True when a matching account save API exists.
	 */
	private function supportsAuthAccountSaveForContext( object $auth_instance, array $principal_context ): bool {
		if ( ! empty( $principal_context['agent_id'] ) ) {
			return method_exists( $auth_instance, 'save_account_for_agent' );
		}

		if ( ! empty( $principal_context['user_id'] ) ) {
			return method_exists( $auth_instance, 'save_account_for_user' );
		}

		return method_exists( $auth_instance, 'save_site_account' ) || method_exists( $auth_instance, 'save_account' );
	}

	/**
	 * Manually set authentication token and account data for a handler.
	 *
	 * Bypasses OAuth flow to directly inject credentials. Useful for:
	 * - Migrating tokens from another plugin
	 * - CI/headless environments where browser OAuth is impossible
	 * - Restoring credentials from backup
	 *
	 * @since 0.47.0
	 * @param array $input Input with handler_slug and account_data.
	 * @return array Result.
	 */
	public function executeSetAuthToken( array $input ): array {
		$handler_slug      = sanitize_text_field( $input['handler_slug'] ?? '' );
		$account_data      = $input['account_data'] ?? array();
		$principal_context = $this->getPrincipalContext( $input );

		if ( empty( $handler_slug ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Handler slug is required', 'data-machine' ),
			);
		}

		if ( empty( $account_data ) || ! is_array( $account_data ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Account data is required and must be an object', 'data-machine' ),
			);
		}

		if ( empty( $account_data['access_token'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'access_token is required in account_data', 'data-machine' ),
			);
		}

		$auth_instance = $this->getProviderForHandler( $handler_slug );

		if ( ! $auth_instance ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication provider not found', 'data-machine' ),
			);
		}

		if ( ! $this->supportsAuthAccountSaveForContext( $auth_instance, $principal_context ) ) {
			return array(
				'success' => false,
				'error'   => __( 'This handler does not support saving account data', 'data-machine' ),
			);
		}

		// Sanitize string values in account data.
		$sanitized = array();
		foreach ( $account_data as $key => $value ) {
			if ( is_string( $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( $value );
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || is_null( $value ) ) {
				$sanitized[ $key ] = $value;
			} elseif ( is_array( $value ) ) {
				$sanitized[ $key ] = $value;
			}
		}

		$saved = $this->saveAuthAccountForContext( $auth_instance, $sanitized, $principal_context );

		if ( $saved ) {
			// Schedule proactive refresh if the provider supports it.
			if ( method_exists( $auth_instance, 'schedule_proactive_refresh' ) ) {
				$auth_instance->schedule_proactive_refresh();
			}

			do_action(
				'datamachine_log',
				'info',
				'Auth: Token set manually via CLI/ability',
				array(
					'handler_slug' => $handler_slug,
					'has_expiry'   => ! empty( $sanitized['token_expires_at'] ),
				)
			);

			return array(
				'success' => true,
				/* translators: %s: Service name (e.g., Twitter, Facebook) */
				'message' => sprintf( __( '%s authentication token set successfully', 'data-machine' ), ucfirst( $handler_slug ) ),
			);
		}

		return array(
			'success' => false,
			'error'   => __( 'Failed to save account data', 'data-machine' ),
		);
	}

	/**
	 * Force a token refresh for an OAuth2 handler.
	 *
	 * Calls get_valid_access_token() which handles refresh logic automatically.
	 * Only works for providers extending BaseOAuth2Provider that implement
	 * do_refresh_token().
	 *
	 * @since 0.47.0
	 * @param array $input Input with handler_slug.
	 * @return array Result with new expiry if available.
	 */
	public function executeRefreshAuth( array $input ): array {
		$handler_slug = sanitize_text_field( $input['handler_slug'] ?? '' );

		if ( empty( $handler_slug ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Handler slug is required', 'data-machine' ),
			);
		}

		$auth_instance = $this->getProviderForHandler( $handler_slug );

		if ( ! $auth_instance ) {
			return array(
				'success' => false,
				'error'   => __( 'Authentication provider not found', 'data-machine' ),
			);
		}

		if ( ! method_exists( $auth_instance, 'is_authenticated' ) || ! $auth_instance->is_authenticated() ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: Service name (e.g., Twitter, Facebook) */
					__( '%s is not currently authenticated. Connect first before refreshing.', 'data-machine' ),
					ucfirst( $handler_slug )
				),
			);
		}

		if ( ! method_exists( $auth_instance, 'get_valid_access_token' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'This handler does not support token refresh', 'data-machine' ),
			);
		}

		// Force refresh by getting a valid token (handles expiry check + refresh).
		$new_token = $auth_instance->get_valid_access_token();

		if ( null === $new_token ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: Service name (e.g., Twitter, Facebook) */
					__( 'Token refresh failed for %s. Re-authorization may be required.', 'data-machine' ),
					ucfirst( $handler_slug )
				),
			);
		}

		// Get updated account to show new expiry.
		$expires_at = null;
		if ( method_exists( $auth_instance, 'get_account' ) ) {
			$account    = $auth_instance->get_account();
			$expires_at = ! empty( $account['token_expires_at'] )
				? wp_date( 'Y-m-d H:i:s', intval( $account['token_expires_at'] ) )
				: null;
		}

		return array(
			'success'    => true,
			/* translators: %s: Service name (e.g., Twitter, Facebook) */
			'message'    => sprintf( __( '%s token refreshed successfully', 'data-machine' ), ucfirst( $handler_slug ) ),
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Extract explicit principal context from ability input.
	 *
	 * @param array $input Ability input.
	 * @return array{user_id?:int,agent_id?:int}
	 */
	private function getPrincipalContext( array $input ): array {
		$context = array();
		if ( ! empty( $input['agent_id'] ) ) {
			$context['agent_id'] = absint( $input['agent_id'] );
		}
		if ( ! empty( $input['user_id'] ) ) {
			$context['user_id'] = absint( $input['user_id'] );
		}
		if ( empty( $context['agent_id'] ) ) {
			$agent_id = absint( PermissionHelper::get_acting_agent_id() );
			if ( $agent_id > 0 ) {
				$context['agent_id'] = $agent_id;
			}
		}

		if ( empty( $context['user_id'] ) ) {
			$user_id = absint( PermissionHelper::acting_user_id() );
			if ( $user_id <= 0 && function_exists( 'get_current_user_id' ) ) {
				$user_id = absint( get_current_user_id() );
			}
			if ( $user_id > 0 ) {
				$context['user_id'] = $user_id;
			}
		}

		return array_filter( $context );
	}
}
