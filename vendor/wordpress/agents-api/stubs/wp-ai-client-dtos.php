<?php
/**
 * PHPStan class stubs for the php-ai-client DTO surface the default
 * provider-turn adapter consumes.
 *
 * agents-api has no hard dependency on wp-ai-client. These stubs exist only so
 * the static analyzer can type the generic message/tool mapping the adapter
 * performs behind `class_exists()` guards. They mirror the real php-ai-client
 * DTOs but are never loaded at runtime. The builder facade stub lives in its
 * own file (class-wp-ai-client-prompt-builder.php) because it is in the global
 * namespace.
 *
 * @see https://github.com/WordPress/php-ai-client
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Grouped stub file.

namespace WordPress\AiClient\Messages\DTO {

	class MessagePart {
		/**
		 * @param mixed $value Part value.
		 */
		public function __construct( $value ) {
			unset( $value );
		}
	}

	class UserMessage {
		/**
		 * @param array<int, object> $parts Message parts.
		 */
		public function __construct( array $parts ) {
			unset( $parts );
		}
	}

	class ModelMessage {
		/**
		 * @param array<int, object> $parts Message parts.
		 */
		public function __construct( array $parts ) {
			unset( $parts );
		}
	}
}

namespace WordPress\AiClient\Tools\DTO {

	class FunctionCall {
		/**
		 * @param string|null          $id   Call id.
		 * @param string|null          $name Tool name.
		 * @param array<mixed> $args Call arguments.
		 */
		public function __construct( ?string $id, ?string $name, array $args ) {
			unset( $id, $name, $args );
		}
	}

	class FunctionResponse {
		/**
		 * @param string|null          $id      Call id.
		 * @param string|null          $name    Tool name.
		 * @param array<mixed> $payload Response payload.
		 */
		public function __construct( ?string $id, ?string $name, array $payload ) {
			unset( $id, $name, $payload );
		}
	}

	class FunctionDeclaration {
		/**
		 * @param string               $name        Function name.
		 * @param string               $description Function description.
		 * @param array<mixed> $parameters  JSON-schema parameters.
		 */
		public function __construct( string $name, string $description, array $parameters ) {
			unset( $name, $description, $parameters );
		}
	}
}

namespace WordPress\AiClient\Providers\Http\DTO {

	class RequestOptions {

		public const KEY_TIMEOUT = 'timeout';

		/**
		 * @param array<string, mixed> $array Request option values keyed by RequestOptions constants.
		 */
		public static function fromArray( array $array ): self {
			unset( $array );
			return new self();
		}

		public function getTimeout(): ?float {
			return null;
		}
	}
}
