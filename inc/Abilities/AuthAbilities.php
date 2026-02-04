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

		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
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
					'agent_type'        => 'system',
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
			$this->registerSaveAuthConfig();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerGetAuthStatus(): void {
		wp_register_ability(
			'datamachine/get-auth-status',
			array(
				'label'               => __( 'Get Auth Status', 'data-machine' ),
				'description'         => __( 'Get OAuth/authentication status for a handler including authorization URL if applicable.', 'data-machine' ),
				'category'            => 'datamachine',
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
				'category'            => 'datamachine',
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

	private function registerSaveAuthConfig(): void {
		wp_register_ability(
			'datamachine/save-auth-config',
			array(
				'label'               => __( 'Save Auth Config', 'data-machine' ),
				'description'         => __( 'Save authentication configuration for a handler.', 'data-machine' ),
				'category'            => 'datamachine',
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

	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
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

	public function executeSaveAuthConfig( array $input ): array {
		$handler_slug = sanitize_text_field( $input['handler_slug'] ?? '' );
		$config_input = $input['config'] ?? array();

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
		} elseif ( method_exists( $auth_instance, 'save_account' ) ) {
			$saved = $auth_instance->save_account( $config_data );
		} elseif ( method_exists( $auth_instance, 'save_config' ) ) {
			$saved = $auth_instance->save_config( $config_data );
		} else {
			return array(
				'success' => false,
				'error'   => __( 'Handler does not support saving account', 'data-machine' ),
			);
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
}
