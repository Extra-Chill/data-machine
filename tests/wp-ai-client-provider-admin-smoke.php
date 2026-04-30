<?php
/**
 * Smoke test for wp-ai-client provider/admin settings migration.
 */

namespace {
	define( 'ABSPATH', __DIR__ );

	$GLOBALS['datamachine_provider_admin_options']      = array();
	$GLOBALS['datamachine_provider_admin_site_options'] = array();
	$GLOBALS['datamachine_provider_admin_updates']      = array();

	function wp_supports_ai(): bool {
		return true;
	}

	function sanitize_key( $key ): string {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
	}

	function sanitize_text_field( $value ): string {
		return trim( (string) $value );
	}

	function get_option( string $name, $default = false ) {
		return $GLOBALS['datamachine_provider_admin_options'][ $name ] ?? $default;
	}

	function update_option( string $name, $value, bool $autoload = true ): bool {
		$GLOBALS['datamachine_provider_admin_options'][ $name ] = $value;
		$GLOBALS['datamachine_provider_admin_updates'][]        = array( $name, $value, $autoload );
		return true;
	}

	function get_site_option( string $name, $default = false ) {
		return $GLOBALS['datamachine_provider_admin_site_options'][ $name ] ?? $default;
	}

	function maybe_unserialize( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$decoded = @unserialize( $value );
		return false === $decoded && 'b:0;' !== $value ? $value : $decoded;
	}

	function wp_get_connector( string $id ): ?array {
		$connectors = array(
			'openai' => array(
				'name'           => 'OpenAI',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method'        => 'api_key',
					'setting_name'  => 'connectors_ai_openai_api_key',
					'env_var_name'  => 'OPENAI_API_KEY',
					'constant_name' => 'OPENAI_API_KEY',
				),
			),
			'gemini' => array(
				'name'           => 'Gemini',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_gemini_api_key',
				),
			),
		);

		return $connectors[ $id ] ?? null;
	}
}

namespace WordPress\AiClient {
	class AiClient {
		public static function defaultRegistry(): \WordPress\AiClient\Providers\ProviderRegistry {
			return new \WordPress\AiClient\Providers\ProviderRegistry();
		}
	}
}

namespace WordPress\AiClient\Providers {
	class ProviderRegistry {
		public array $auth = array();

		public function getRegisteredProviderIds(): array {
			return array( 'openai', 'gemini' );
		}

		public function getProviderClassName( string $provider ): string {
			return 'openai' === $provider ? \DataMachine\Tests\Smoke\OpenAiProviderDouble::class : \DataMachine\Tests\Smoke\GeminiProviderDouble::class;
		}

		public function setProviderRequestAuthentication( string $provider, $auth ): void {
			$this->auth[ $provider ] = $auth;
		}
	}
}

namespace WordPress\AiClient\Providers\Http\DTO {
	class ApiKeyRequestAuthentication {
		public function __construct( public string $api_key ) {}
	}
}

namespace DataMachine\Tests\Smoke {
	class ProviderMetadataDouble {
		public function __construct( private string $name ) {}

		public function getName(): string {
			return $this->name;
		}
	}

	class ModelMetadataDouble {
		public function __construct( private string $id, private string $name ) {}

		public function getId(): string {
			return $this->id;
		}

		public function getName(): string {
			return $this->name;
		}
	}

	class ModelDirectoryDouble {
		public function __construct( private array $models ) {}

		public function listModelMetadata(): array {
			return $this->models;
		}
	}

	class OpenAiProviderDouble {
		public static function metadata(): ProviderMetadataDouble {
			return new ProviderMetadataDouble( 'OpenAI Provider' );
		}

		public static function modelMetadataDirectory(): ModelDirectoryDouble {
			return new ModelDirectoryDouble(
				array(
					new ModelMetadataDouble( 'gpt-5.4', 'GPT 5.4' ),
					new ModelMetadataDouble( 'gpt-5.4-mini', 'GPT 5.4 Mini' ),
				)
			);
		}
	}

	class GeminiProviderDouble {
		public static function metadata(): ProviderMetadataDouble {
			return new ProviderMetadataDouble( 'Gemini Provider' );
		}

		public static function modelMetadataDirectory(): ModelDirectoryDouble {
			return new ModelDirectoryDouble( array( new ModelMetadataDouble( 'gemini-2.5-pro', 'Gemini 2.5 Pro' ) ) );
		}
	}
}

namespace {
	require_once __DIR__ . '/../inc/Engine/AI/WpAiClientProviderAdmin.php';
	require_once __DIR__ . '/../inc/migrations/ai-provider-keys.php';

	use DataMachine\Engine\AI\WpAiClientProviderAdmin;

	$assertions = 0;
	$failures   = array();

	$assert = function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( ! $condition ) {
			$failures[] = $message;
		}
	};

	$GLOBALS['datamachine_provider_admin_options']['connectors_ai_openai_api_key'] = 'sk-openai-secret-1234';

	$providers = WpAiClientProviderAdmin::getProviders();
	$assert( isset( $providers['openai'] ), 'registered OpenAI provider is exposed' );
	$assert( 'OpenAI' === $providers['openai']['label'], 'connector label wins over provider metadata' );
	$assert( 'gpt-5.4' === ( $providers['openai']['models'][0]['id'] ?? null ), 'models are discovered from wp-ai-client metadata directory' );
	$assert( WpAiClientProviderAdmin::isProviderRegistered( 'openai' ), 'registered provider validates' );
	$assert( WpAiClientProviderAdmin::isProviderRegistered( 'google' ), 'historical google provider alias validates against gemini' );
	$assert( ! WpAiClientProviderAdmin::isProviderRegistered( 'unknown' ), 'unknown provider fails validation' );
	$assert( 'sk-openai-secret-1234' === WpAiClientProviderAdmin::resolveApiKey( 'openai' ), 'connector option key resolves for runtime auth' );

	$masked = WpAiClientProviderAdmin::getMaskedApiKeys();
	$assert( 'sk-o****************1234' === ( $masked['openai'] ?? null ), 'settings response masks connector key' );

	WpAiClientProviderAdmin::updateApiKeys(
		array(
			'openai' => 'sk-new-secret',
			'gemini' => '****************',
		)
	);
	$assert( 'sk-new-secret' === get_option( 'connectors_ai_openai_api_key' ), 'settings update writes unmasked key to connector option' );
	$assert( '' === get_option( 'connectors_ai_gemini_api_key', '' ), 'settings update ignores masked round-trip value' );

	$GLOBALS['datamachine_provider_admin_site_options']['chubes_ai_http_shared_api_keys'] = serialize(
		array(
			'openai' => 'legacy-openai',
			'gemini' => 'legacy-gemini',
		)
	);
	$GLOBALS['datamachine_provider_admin_options']['connectors_ai_openai_api_key']       = 'already-present';
	datamachine_migrate_ai_provider_keys_to_connectors();
	$assert( 'already-present' === get_option( 'connectors_ai_openai_api_key' ), 'migration preserves existing connector key' );
	$assert( 'legacy-gemini' === get_option( 'connectors_ai_gemini_api_key' ), 'migration copies missing serialized legacy key' );
	$assert( true === get_option( 'datamachine_ai_provider_keys_migrated' ), 'migration records completion flag' );

	$production_files = array(
		'inc/Api/Providers.php',
		'inc/Api/Chat/Chat.php',
		'inc/Abilities/SettingsAbilities.php',
		'inc/Engine/AI/WpAiClientAdapter.php',
		'inc/Engine/Filters/DataMachineFilters.php',
	);

	foreach ( $production_files as $file ) {
		$source = file_get_contents( __DIR__ . '/../' . $file ) ?: '';
		$assert( ! str_contains( $source, "apply_filters( 'chubes_ai_providers'" ), $file . ' no longer calls chubes_ai_providers' );
		$assert( ! str_contains( $source, "apply_filters( 'chubes_ai_models'" ), $file . ' no longer calls chubes_ai_models' );
		$assert( ! str_contains( $source, "apply_filters( 'chubes_ai_provider_api_keys'" ), $file . ' no longer calls chubes_ai_provider_api_keys' );
	}

	if ( $failures ) {
		foreach ( $failures as $failure ) {
			fwrite( STDERR, "FAIL: {$failure}\n" );
		}
		exit( 1 );
	}

	echo "wp-ai-client provider/admin smoke passed ({$assertions} assertions)\n";
}
