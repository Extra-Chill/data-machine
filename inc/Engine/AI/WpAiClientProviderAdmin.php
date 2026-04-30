<?php
/**
 * wp-ai-client provider/admin settings facade.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Reads provider metadata and API-key settings through wp-ai-client / Connectors.
 */
class WpAiClientProviderAdmin {

	/**
	 * Return provider metadata in Data Machine's existing REST/admin shape.
	 *
	 * @since next
	 *
	 * @return array<string, array{label:string,models:array<int,array{id:string,name:string}>}>
	 */
	public static function getProviders(): array {
		$registry = self::registry();
		if ( null === $registry ) {
			return array();
		}

		$providers = array();
		foreach ( self::registeredProviderIds() as $provider_id ) {
			$providers[ $provider_id ] = array(
				'label'  => self::providerLabel( $provider_id ),
				'models' => self::providerModels( $provider_id ),
			);
		}

		return $providers;
	}

	/**
	 * Determine whether a provider is registered in wp-ai-client.
	 *
	 * @since next
	 *
	 * @param string $provider Provider identifier.
	 * @return bool
	 */
	public static function isProviderRegistered( string $provider ): bool {
		$provider_ids = self::registeredProviderIds();

		foreach ( self::providerAliases( $provider ) as $candidate ) {
			if ( in_array( $candidate, $provider_ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve an API key for a provider from Connectors-shaped settings.
	 *
	 * @since next
	 *
	 * @param string $provider Provider identifier.
	 * @return string API key, or empty string when none is configured.
	 */
	public static function resolveApiKey( string $provider ): string {
		foreach ( self::providerAliases( $provider ) as $candidate ) {
			$connector = self::connector( $candidate );
			$auth      = is_array( $connector['authentication'] ?? null ) ? $connector['authentication'] : array();

			if ( 'api_key' !== ( $auth['method'] ?? 'api_key' ) ) {
				continue;
			}

			$key = self::resolveConnectorApiKey(
				(string) ( $auth['setting_name'] ?? self::defaultConnectorSettingName( $candidate ) ),
				(string) ( $auth['env_var_name'] ?? self::defaultApiKeyConstantName( $candidate ) ),
				(string) ( $auth['constant_name'] ?? self::defaultApiKeyConstantName( $candidate ) )
			);

			if ( '' !== $key ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Return masked provider API keys keyed by provider id.
	 *
	 * @since next
	 *
	 * @return array<string, string>
	 */
	public static function getMaskedApiKeys(): array {
		$masked = array();

		foreach ( self::registeredProviderIds() as $provider_id ) {
			$key = self::resolveApiKey( $provider_id );
			if ( '' === $key ) {
				$masked[ $provider_id ] = '';
				continue;
			}

			$masked[ $provider_id ] = self::maskApiKey( $key );
		}

		return $masked;
	}

	/**
	 * Persist provider API keys into Connectors-shaped settings.
	 *
	 * Masked values are ignored so the settings UI can round-trip without erasing
	 * existing credentials.
	 *
	 * @since next
	 *
	 * @param array<string, mixed> $provider_keys Provider-key map from settings input.
	 * @return void
	 */
	public static function updateApiKeys( array $provider_keys ): void {
		foreach ( $provider_keys as $provider => $key ) {
			$provider_key = sanitize_key( (string) $provider );
			$new_key      = sanitize_text_field( (string) $key );

			if ( '' === $provider_key || str_contains( $new_key, '****' ) ) {
				continue;
			}

			$setting_name = self::settingNameForProvider( $provider_key );
			if ( '' === $setting_name ) {
				continue;
			}

			update_option( $setting_name, $new_key, true );
		}
	}

	/**
	 * Return registered wp-ai-client provider ids.
	 *
	 * @return list<string>
	 */
	private static function registeredProviderIds(): array {
		$registry = self::registry();
		if ( null === $registry ) {
			return array();
		}

		try {
			if ( ! method_exists( $registry, 'getRegisteredProviderIds' ) ) {
				return array();
			}

			$provider_ids = $registry->getRegisteredProviderIds();
		} catch ( \Throwable $e ) {
			return array();
		}

		return is_array( $provider_ids ) ? array_values( array_filter( $provider_ids, 'is_string' ) ) : array();
	}

	/**
	 * Return provider models in the existing admin selector shape.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return array<int, array{id:string,name:string}>
	 */
	private static function providerModels( string $provider_id ): array {
		$registry = self::registry();
		if ( null === $registry ) {
			return array();
		}

		try {
			self::bindApiKeyToRegistry( $registry, $provider_id );
			if ( ! method_exists( $registry, 'getProviderClassName' ) ) {
				return array();
			}

			$provider_class = $registry->getProviderClassName( $provider_id );
			$directory      = $provider_class::modelMetadataDirectory();
			$model_metadata = $directory->listModelMetadata();
		} catch ( \Throwable $e ) {
			return array();
		}

		$models = array();
		foreach ( $model_metadata as $metadata ) {
			if ( is_object( $metadata ) && method_exists( $metadata, 'getId' ) ) {
				$model_id = (string) $metadata->getId();
				$name     = method_exists( $metadata, 'getName' ) ? (string) $metadata->getName() : $model_id;
			} elseif ( is_array( $metadata ) ) {
				$model_id = (string) ( $metadata['id'] ?? '' );
				$name     = (string) ( $metadata['name'] ?? $model_id );
			} else {
				continue;
			}

			if ( '' === $model_id ) {
				continue;
			}

			$models[] = array(
				'id'   => $model_id,
				'name' => '' === $name ? $model_id : $name,
			);
		}

		return $models;
	}

	/**
	 * Return the label for a provider id.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string
	 */
	private static function providerLabel( string $provider_id ): string {
		$connector = self::connector( $provider_id );
		if ( ! empty( $connector['name'] ) && is_string( $connector['name'] ) ) {
			return $connector['name'];
		}

		$registry = self::registry();
		if ( null !== $registry ) {
			try {
				if ( ! method_exists( $registry, 'getProviderClassName' ) ) {
					return ucwords( str_replace( array( '_', '-' ), ' ', $provider_id ) );
				}

				$provider_class = $registry->getProviderClassName( $provider_id );
				$metadata       = $provider_class::metadata();
				if ( is_object( $metadata ) && method_exists( $metadata, 'getName' ) ) {
					$name = (string) $metadata->getName();
					if ( '' !== $name ) {
						return $name;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $provider_id ) );
	}

	/**
	 * Bind a stored connector key to the registry before model discovery.
	 *
	 * @param object $registry    wp-ai-client provider registry.
	 * @param string $provider_id Provider identifier.
	 * @return void
	 */
	private static function bindApiKeyToRegistry( object $registry, string $provider_id ): void {
		if ( ! method_exists( $registry, 'setProviderRequestAuthentication' ) || ! class_exists( '\WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication' ) ) {
			return;
		}

		$api_key = self::resolveApiKey( $provider_id );
		if ( '' === $api_key ) {
			return;
		}

		try {
			$registry->setProviderRequestAuthentication(
				$provider_id,
				new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( $api_key )
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Return a connector definition for a provider id when Connectors is available.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return array<string, mixed>
	 */
	private static function connector( string $provider_id ): array {
		if ( function_exists( 'wp_get_connector' ) ) {
			$connector = wp_get_connector( $provider_id );
			if ( is_array( $connector ) ) {
				return $connector;
			}
		}

		return array(
			'name'           => ucwords( str_replace( array( '_', '-' ), ' ', $provider_id ) ),
			'type'           => 'ai_provider',
			'authentication' => array(
				'method'        => 'api_key',
				'setting_name'  => self::defaultConnectorSettingName( $provider_id ),
				'constant_name' => self::defaultApiKeyConstantName( $provider_id ),
				'env_var_name'  => self::defaultApiKeyConstantName( $provider_id ),
			),
		);
	}

	/**
	 * Resolve the connector setting name for a provider id.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string
	 */
	private static function settingNameForProvider( string $provider_id ): string {
		foreach ( self::providerAliases( $provider_id ) as $candidate ) {
			$connector = self::connector( $candidate );
			$auth      = is_array( $connector['authentication'] ?? null ) ? $connector['authentication'] : array();

			if ( 'api_key' !== ( $auth['method'] ?? 'api_key' ) ) {
				continue;
			}

			return (string) ( $auth['setting_name'] ?? self::defaultConnectorSettingName( $candidate ) );
		}

		return '';
	}

	/**
	 * Resolve a connector API key from env, constant, or database option.
	 *
	 * @param string $setting_name  Connector option name.
	 * @param string $env_var_name  Environment variable name.
	 * @param string $constant_name Constant name.
	 * @return string
	 */
	private static function resolveConnectorApiKey( string $setting_name, string $env_var_name = '', string $constant_name = '' ): string {
		if ( '' !== $env_var_name ) {
			$env_value = getenv( $env_var_name );
			if ( false !== $env_value && '' !== $env_value ) {
				return (string) $env_value;
			}
		}

		if ( '' !== $constant_name && defined( $constant_name ) ) {
			$constant_value = constant( $constant_name );
			if ( is_scalar( $constant_value ) && '' !== (string) $constant_value ) {
				return (string) $constant_value;
			}
		}

		if ( '' === $setting_name ) {
			return '';
		}

		$key = get_option( $setting_name, '' );
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Return the wp-ai-client provider registry when available.
	 *
	 * @return object|null
	 */
	private static function registry(): ?object {
		if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			return null;
		}

		$ai_client_class = '\WordPress\AiClient\AiClient';
		if ( ! class_exists( $ai_client_class ) ) {
			return null;
		}

		try {
			$registry = $ai_client_class::defaultRegistry();
		} catch ( \Throwable $e ) {
			return null;
		}

		return $registry;
	}

	/**
	 * Return provider id aliases shared with Data Machine's historical ids.
	 *
	 * @param string $provider Provider identifier.
	 * @return list<string>
	 */
	private static function providerAliases( string $provider ): array {
		$provider = sanitize_key( $provider );
		$aliases  = array( $provider );

		if ( 'google' === $provider ) {
			$aliases[] = 'gemini';
		}

		if ( 'gemini' === $provider ) {
			$aliases[] = 'google';
		}

		return array_values( array_unique( array_filter( $aliases ) ) );
	}

	/**
	 * Return the Connectors default option name for a provider id.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string
	 */
	private static function defaultConnectorSettingName( string $provider_id ): string {
		return 'connectors_ai_' . str_replace( '-', '_', sanitize_key( $provider_id ) ) . '_api_key';
	}

	/**
	 * Return the Connectors default env/constant name for a provider id.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return string
	 */
	private static function defaultApiKeyConstantName( string $provider_id ): string {
		return strtoupper( str_replace( array( '-', ' ' ), '_', sanitize_key( $provider_id ) ) ) . '_API_KEY';
	}

	/**
	 * Mask an API key for settings responses.
	 *
	 * @param string $key API key.
	 * @return string
	 */
	private static function maskApiKey( string $key ): string {
		if ( strlen( $key ) > 12 ) {
			return substr( $key, 0, 4 ) . '****************' . substr( $key, -4 );
		}

		return '****************';
	}
}
