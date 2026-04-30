<?php
/**
 * Test doubles for the wp-ai-client runtime path.
 *
 * These doubles let unit tests exercise Data Machine's wp-ai-client dispatch
 * contract without restoring the legacy `chubes_ai_request` fallback.
 *
 * @package DataMachine\Tests\Unit\Support
 */

namespace {
	if ( ! function_exists( 'wp_supports_ai' ) ) {
		function wp_supports_ai(): bool {
			return true;
		}
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		function wp_ai_client_prompt( $prompt = null ): \DataMachine\Tests\Unit\Support\WpAiClientPromptBuilderDouble {
			return new \DataMachine\Tests\Unit\Support\WpAiClientPromptBuilderDouble( is_string( $prompt ) ? $prompt : '' );
		}
	}
}

namespace DataMachine\Tests\Unit\Support {
	class WpAiClientTestDouble {
		/** @var callable|null */
		private static $callback = null;

		public static function reset(): void {
			self::$callback = null;
			$GLOBALS['datamachine_test_wp_ai_client_provider_ids'] = array( 'openai', 'fake_provider', 'gemini' );
		}

		public static function set_response_callback( callable $callback ): void {
			self::$callback = $callback;
		}

		public static function dispatch( array $request, string $provider ): array {
			if ( is_callable( self::$callback ) ) {
				$response = call_user_func( self::$callback, $request, $provider );
				if ( is_array( $response ) ) {
					return $response;
				}
			}

			return array(
				'success' => true,
				'data'    => array(
					'content'    => '',
					'tool_calls' => array(),
				),
			);
		}
	}

	class WpAiClientPromptBuilderDouble {
		private string $prompt = '';
		private string $provider = '';
		private mixed $model = null;
		private string $system_instruction = '';
		private array $history = array();
		private array $function_declarations = array();

		public function __construct( string $prompt = '' ) {
			$this->prompt = $prompt;
		}

		public function using_provider( string $provider ): self {
			$this->provider = $provider;
			return $this;
		}

		public function using_model( $model ): self {
			$this->model = $model;
			return $this;
		}

		public function as_output_file_type( $file_type ): self {
			return $this;
		}

		public function as_output_media_orientation( $orientation ): self {
			return $this;
		}

		public function is_supported_for_image_generation(): bool {
			$request  = $this->to_request_array();
			$response = WpAiClientTestDouble::dispatch( $request + array( 'capability_check' => 'image_generation' ), $this->provider );

			return $response['supported'] ?? true;
		}

		public function generate_image(): \WordPress\AiClient\Files\DTO\File|\WP_Error {
			$request  = $this->to_request_array();
			$response = WpAiClientTestDouble::dispatch( $request + array( 'capability' => 'image_generation' ), $this->provider );

			if ( empty( $response['success'] ) ) {
				return new \WP_Error( 'test_image_generation_failed', $response['error'] ?? 'Image generation failed' );
			}

			$file = (string) ( $response['data']['image_url'] ?? $response['data']['image_data_uri'] ?? '' );
			if ( '' === $file ) {
				return new \WP_Error( 'test_image_generation_empty', 'No image returned' );
			}

			return new \WordPress\AiClient\Files\DTO\File( $file, $response['data']['mime_type'] ?? 'image/png' );
		}

		public function using_system_instruction( string $system_instruction ): self {
			$this->system_instruction = $system_instruction;
			return $this;
		}

		public function with_history( ...$messages ): self {
			$this->history = $messages;
			return $this;
		}

		public function using_function_declarations( ...$declarations ): self {
			$this->function_declarations = $declarations;
			return $this;
		}

		public function generate_text_result(): \WordPress\AiClient\Results\DTO\GenerativeAiResult {
			$request  = $this->to_request_array();
			$response = WpAiClientTestDouble::dispatch( $request, $this->provider );

			return \WordPress\AiClient\Results\DTO\GenerativeAiResult::fromData( $response['data'] ?? array() );
		}

		private function to_request_array(): array {
			$messages = array();
			if ( '' !== $this->system_instruction ) {
				$messages[] = array(
					'role'    => 'system',
					'content' => $this->system_instruction,
				);
			}

			foreach ( $this->history as $message ) {
				$role = $message instanceof \WordPress\AiClient\Messages\DTO\ModelMessage ? 'assistant' : 'user';
				foreach ( $message->getParts() as $part ) {
					$messages[] = array(
						'role'    => $role,
						'content' => (string) $part->getText(),
					);
				}
			}

			$tools = array();
			foreach ( $this->function_declarations as $declaration ) {
				$tools[ $declaration->name ] = array(
					'name'        => $declaration->name,
					'description' => $declaration->description,
					'parameters'  => $declaration->parameters,
				);
			}

			return array(
				'provider' => $this->provider,
				'model'    => $this->model,
				'prompt'   => $this->prompt,
				'messages' => $messages,
				'tools'    => $tools,
			);
		}
	}
}

namespace WordPress\AiClient\Files\Enums {
	if ( ! class_exists( FileTypeEnum::class ) ) {
		class FileTypeEnum {
			public static function remote(): self {
				return new self();
			}
		}
	}

	if ( ! class_exists( MediaOrientationEnum::class ) ) {
		class MediaOrientationEnum {
			public static function square(): self {
				return new self();
			}

			public static function portrait(): self {
				return new self();
			}

			public static function landscape(): self {
				return new self();
			}
		}
	}
}

namespace WordPress\AiClient\Files\DTO {
	if ( ! class_exists( File::class ) ) {
		class File {
			private ?string $url = null;
			private ?string $data_uri = null;

			public function __construct( string $file, ?string $mime_type = null ) {
				unset( $mime_type );

				if ( str_starts_with( $file, 'data:' ) ) {
					$this->data_uri = $file;
				} else {
					$this->url = $file;
				}
			}

			public function getUrl(): ?string {
				return $this->url;
			}

			public function getDataUri(): ?string {
				return $this->data_uri;
			}
		}
	}
}

namespace WordPress\AiClient {
	if ( ! class_exists( AiClient::class ) ) {
		class AiClient {
			public static function defaultRegistry(): \WordPress\AiClient\Providers\ProviderRegistry {
				return new \WordPress\AiClient\Providers\ProviderRegistry();
			}
		}
	}
}

namespace WordPress\AiClient\Providers {
	if ( ! class_exists( ProviderRegistry::class ) ) {
		class ProviderRegistry {
			public function getRegisteredProviderIds(): array {
				$ids = $GLOBALS['datamachine_test_wp_ai_client_provider_ids'] ?? array( 'openai', 'fake_provider', 'gemini' );
				return is_array( $ids ) ? $ids : array();
			}

			public function setProviderRequestAuthentication( string $provider, $auth ): void {
			}

			public function getProviderModel( string $provider, string $model, $config = null ): string {
				return $model;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Http\DTO {
	if ( ! class_exists( ApiKeyRequestAuthentication::class ) ) {
		class ApiKeyRequestAuthentication {
			private string $api_key;

			public function __construct( string $api_key ) {
				$this->api_key = $api_key;
			}

			public function getApiKey(): string {
				return $this->api_key;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Models\DTO {
	if ( ! class_exists( ModelConfig::class ) ) {
		class ModelConfig {
			public const KEY_TEMPERATURE = 'temperature';
			public const KEY_MAX_TOKENS  = 'max_tokens';

			public static function fromArray( array $config ): self {
				return new self();
			}
		}
	}
}

namespace WordPress\AiClient\Messages\DTO {
	if ( ! class_exists( MessagePartChannelDouble::class ) ) {
		class MessagePartChannelDouble {
			public function isContent(): bool {
				return true;
			}
		}
	}

	if ( ! class_exists( MessagePart::class ) ) {
		class MessagePart {
			private ?string $text;
			private ?\WordPress\AiClient\Tools\DTO\FunctionCall $function_call;

			public function __construct( ?string $text = null, ?\WordPress\AiClient\Tools\DTO\FunctionCall $function_call = null ) {
				$this->text          = $text;
				$this->function_call = $function_call;
			}

			public function getText(): ?string {
				return $this->text;
			}

			public function getChannel(): MessagePartChannelDouble {
				return new MessagePartChannelDouble();
			}

			public function getFunctionCall(): ?\WordPress\AiClient\Tools\DTO\FunctionCall {
				return $this->function_call;
			}
		}
	}

	if ( ! class_exists( Message::class ) ) {
		class Message {
			protected array $parts;

			public function __construct( array $parts ) {
				$this->parts = $parts;
			}

			public function getParts(): array {
				return $this->parts;
			}
		}
	}

	if ( ! class_exists( UserMessage::class ) ) {
		class UserMessage extends Message {}
	}

	if ( ! class_exists( ModelMessage::class ) ) {
		class ModelMessage extends Message {}
	}
}

namespace WordPress\AiClient\Tools\DTO {
	if ( ! class_exists( FunctionDeclaration::class ) ) {
		class FunctionDeclaration {
			public string $name;
			public string $description;
			public ?array $parameters;

			public function __construct( string $name, string $description = '', ?array $parameters = null ) {
				$this->name        = $name;
				$this->description = $description;
				$this->parameters  = $parameters;
			}
		}
	}

	if ( ! class_exists( FunctionCall::class ) ) {
		class FunctionCall {
			private string $name;
			private array $args;
			private ?string $id;

			public function __construct( string $name, array $args = array(), ?string $id = null ) {
				$this->name = $name;
				$this->args = $args;
				$this->id   = $id;
			}

			public function getName(): string {
				return $this->name;
			}

			public function getArgs(): array {
				return $this->args;
			}

			public function getId(): ?string {
				return $this->id;
			}
		}
	}
}

namespace WordPress\AiClient\Results\DTO {
	if ( ! class_exists( TokenUsageDouble::class ) ) {
		class TokenUsageDouble {
			private array $usage;

			public function __construct( array $usage ) {
				$this->usage = $usage;
			}

			public function getPromptTokens(): int {
				return (int) ( $this->usage['prompt_tokens'] ?? 0 );
			}

			public function getCompletionTokens(): int {
				return (int) ( $this->usage['completion_tokens'] ?? 0 );
			}

			public function getTotalTokens(): int {
				return (int) ( $this->usage['total_tokens'] ?? 0 );
			}
		}
	}

	if ( ! class_exists( CandidateDouble::class ) ) {
		class CandidateDouble {
			private \WordPress\AiClient\Messages\DTO\ModelMessage $message;

			public function __construct( \WordPress\AiClient\Messages\DTO\ModelMessage $message ) {
				$this->message = $message;
			}

			public function getMessage(): \WordPress\AiClient\Messages\DTO\ModelMessage {
				return $this->message;
			}
		}
	}

	if ( ! class_exists( GenerativeAiResult::class ) ) {
		class GenerativeAiResult {
			private array $candidates;
			private TokenUsageDouble $token_usage;

			public function __construct( array $candidates, array $usage = array() ) {
				$this->candidates  = $candidates;
				$this->token_usage = new TokenUsageDouble( $usage );
			}

			public static function fromData( array $data ): self {
				$parts   = array();
				$content = (string) ( $data['content'] ?? '' );
				if ( '' !== $content ) {
					$parts[] = new \WordPress\AiClient\Messages\DTO\MessagePart( $content );
				}

				foreach ( $data['tool_calls'] ?? array() as $tool_call ) {
					$function_call = new \WordPress\AiClient\Tools\DTO\FunctionCall(
						(string) ( $tool_call['name'] ?? '' ),
						$tool_call['parameters'] ?? array(),
						$tool_call['id'] ?? null
					);
					$parts[]       = new \WordPress\AiClient\Messages\DTO\MessagePart( null, $function_call );
				}

				$message = new \WordPress\AiClient\Messages\DTO\ModelMessage( $parts );

				return new self( array( new CandidateDouble( $message ) ), $data['usage'] ?? array() );
			}

			public function getCandidates(): array {
				return $this->candidates;
			}

			public function getTokenUsage(): TokenUsageDouble {
				return $this->token_usage;
			}
		}
	}
}
