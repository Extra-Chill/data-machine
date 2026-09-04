<?php
/**
 * PHPStan class stubs for the php-ai-client provider registry surface the
 * default provider-turn adapter uses to resolve a model-id string into a
 * concrete ModelInterface.
 *
 * agents-api has no hard dependency on wp-ai-client. These stubs exist only so
 * the static analyzer can type the registry-based model resolution the adapter
 * performs behind `class_exists()` guards. They mirror the real php-ai-client
 * classes (shipped in WordPress core under wp-includes/php-ai-client) but are
 * never loaded at runtime.
 *
 * @see https://github.com/WordPress/php-ai-client
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Grouped stub file.

namespace WordPress\AiClient {

	use WordPress\AiClient\Providers\ProviderRegistry;

	class AiClient {

		public static function defaultRegistry(): ProviderRegistry {
			return new ProviderRegistry();
		}
	}
}

namespace WordPress\AiClient\Providers {

	use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;

	class ProviderRegistry {

		/**
		 * @param string $id_or_class_name Provider id or class name.
		 * @param string $model_id         Model identifier.
		 * @param mixed  $model_config     Optional model configuration.
		 */
		public function getProviderModel( string $id_or_class_name, string $model_id, $model_config = null ): ModelInterface {
			unset( $id_or_class_name, $model_id, $model_config );
			throw new \RuntimeException( 'stub' );
		}
	}
}

namespace WordPress\AiClient\Providers\Models\Contracts {

	interface ModelInterface {
	}
}
