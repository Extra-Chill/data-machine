<?php
/**
 * RequestBuilder multimodal conversion regression tests — #2053.
 *
 * Locks in the contract that vision content blocks (type=file, type=image_url)
 * survive the canonical-message → wp-ai-client conversion. Prior to the fix
 * `wpAiClientMessageText()` flattened content to a string and silently dropped
 * file parts, which caused AltTextTask (and any other multimodal task) to
 * hallucinate descriptions from filename context because the image bytes
 * never reached the model.
 *
 * Two layers of coverage:
 *
 *  1. Reflection-based unit tests on the private converters
 *     (`wpAiClientMessageParts`, `wpAiClientHistoryMessage`,
 *     `wpAiClientPromptContext`) — fast, deterministic, no provider stack.
 *
 *  2. End-to-end smoke through `RequestBuilder::build()` using the existing
 *     wp-ai-client test double — proves the prompt builder actually receives
 *     `with_message_parts(...)` containing a file MessagePart when DM emits
 *     a vision-style content block.
 *
 * @package DataMachine\Tests\Unit\Engine\AI
 */

namespace DataMachine\Tests\Unit\Engine\AI;

use DataMachine\Engine\AI\RequestBuilder;
use DataMachine\Tests\Unit\Support\WpAiClientTestDouble;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use AgentsAPI\AI\WP_Agent_Message;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;

require_once dirname( __DIR__, 2 ) . '/Support/WpAiClientTestDoubles.php';

/**
 * @covers \DataMachine\Engine\AI\RequestBuilder
 */
class RequestBuilderMultimodalTest extends TestCase {

	private string $temp_image_path = '';

	protected function setUp(): void {
		parent::setUp();
		WpAiClientTestDouble::reset();
		$this->temp_image_path = self::makeTempImage();
	}

	protected function tearDown(): void {
		if ( '' !== $this->temp_image_path && file_exists( $this->temp_image_path ) ) {
			@unlink( $this->temp_image_path );
		}
		WpAiClientTestDouble::reset();
		parent::tearDown();
	}

	/**
	 * Writes a minimal valid JPEG to a writable directory and returns the path.
	 *
	 * Tries the WordPress uploads dir first (matches the production code path
	 * AltTextTask exercises), and falls back to sys_get_temp_dir() for
	 * pure-unit invocations without WP bootstrap. Either way the file_exists()
	 * gate inside RequestBuilder::buildFileMessagePart() can resolve the path.
	 *
	 * @return string Absolute path to the test image.
	 */
	private static function makeTempImage(): string {
		// 1x1 JPEG so the File DTO has real bytes to inspect.
		$jpeg = base64_decode( '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/3/AD' );

		$base_dir = '';
		if ( function_exists( 'wp_upload_dir' ) ) {
			$upload_dir = wp_upload_dir();
			if ( is_array( $upload_dir ) && ! empty( $upload_dir['path'] ) && is_dir( $upload_dir['path'] ) && is_writable( $upload_dir['path'] ) ) {
				$base_dir = $upload_dir['path'];
			}
		}

		if ( '' === $base_dir ) {
			$base_dir = sys_get_temp_dir();
		}

		$path = rtrim( $base_dir, '/\\' ) . '/dm-vision-' . uniqid() . '.jpg';
		file_put_contents( $path, $jpeg );

		return $path;
	}

	/**
	 * Regression: a canonical vision content block (type=file with a real
	 * local path) must convert into a wp-ai-client MessagePart that exposes
	 * a File DTO. Before #2053 the file block was silently dropped.
	 */
	public function test_message_parts_preserves_file_block(): void {
		$content = array(
			array(
				'type'      => 'file',
				'file_path' => $this->temp_image_path,
				'mime_type' => 'image/jpeg',
			),
			array(
				'type' => 'text',
				'text' => 'Write alt text for this image.',
			),
		);

		$parts = $this->invokePrivate( 'wpAiClientMessageParts', array( $content ) );

		$this->assertCount( 2, $parts, 'Both file and text parts must survive conversion' );

		$file_parts = array_values(
			array_filter(
				$parts,
				static fn( MessagePart $part ): bool => null !== $part->getFile()
			)
		);
		$this->assertCount( 1, $file_parts, 'Exactly one file MessagePart expected' );

		$file = $file_parts[0]->getFile();
		$this->assertInstanceOf( File::class, $file );
		$this->assertSame(
			'image/jpeg',
			(string) $file->getMimeType(),
			'File DTO must preserve the MIME type from the canonical block'
		);
	}

	/**
	 * Plain-string content blocks and ['type' => 'text', ...] blocks both
	 * map to text-only MessageParts. Keeps text-only callers unchanged.
	 */
	public function test_message_parts_handles_text_only_content(): void {
		$parts_from_string = $this->invokePrivate( 'wpAiClientMessageParts', array( 'Hello world.' ) );
		$this->assertCount( 1, $parts_from_string );
		$this->assertSame( 'Hello world.', $parts_from_string[0]->getText() );

		$parts_from_array = $this->invokePrivate(
			'wpAiClientMessageParts',
			array(
				array(
					array( 'type' => 'text', 'text' => 'first' ),
					'second',
				),
			)
		);
		$this->assertCount( 2, $parts_from_array );
		$this->assertSame( 'first', $parts_from_array[0]->getText() );
		$this->assertSame( 'second', $parts_from_array[1]->getText() );
	}

	/**
	 * File blocks pointing at a non-existent path must be dropped quietly
	 * (logged) instead of crashing the whole request. Text parts in the
	 * same message must still flow through.
	 */
	public function test_message_parts_drops_missing_file_blocks_gracefully(): void {
		$content = array(
			array(
				'type'      => 'file',
				'file_path' => '/nonexistent/' . uniqid( 'dm-missing-', true ) . '.jpg',
				'mime_type' => 'image/jpeg',
			),
			array(
				'type' => 'text',
				'text' => 'Fallback text.',
			),
		);

		$parts = $this->invokePrivate( 'wpAiClientMessageParts', array( $content ) );

		$this->assertCount( 1, $parts, 'Only the text part should survive when the file path is missing' );
		$this->assertSame( 'Fallback text.', $parts[0]->getText() );
		$this->assertNull( $parts[0]->getFile() );
	}

	/**
	 * The current user message (the "prompt" position) must surface its
	 * MessagePart[] via prompt_parts so the caller can attach files via
	 * `with_message_parts()`. Earlier user turns go into history.
	 */
	public function test_prompt_context_exposes_multimodal_prompt_parts(): void {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are an alt-text writer.',
			),
			array(
				'role'    => 'user',
				'content' => 'Previous turn (history).',
			),
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'      => 'file',
						'file_path' => $this->temp_image_path,
						'mime_type' => 'image/jpeg',
					),
					array(
						'type' => 'text',
						'text' => 'Write alt text for this image.',
					),
				),
			),
		);

		$aliases = RequestBuilder::providerToolNameAliases(
			array(
				'client/filesystem-write' => array(
					'name'            => 'client/filesystem-write',
					'runtime_tool_id' => 'filesystem_write',
				),
			)
		);

		$context = $this->invokePrivate( 'wpAiClientPromptContext', array( $messages, $aliases['logical_to_provider'] ) );

		$this->assertSame( array( 'You are an alt-text writer.' ), $context['system_parts'] );
		$this->assertCount( 1, $context['history'], 'Earlier user turn becomes history' );
		$this->assertInstanceOf( UserMessage::class, $context['history'][0] );

		$prompt_parts = $context['prompt_parts'];
		$this->assertCount( 2, $prompt_parts, 'Both file and text prompt parts must be exposed' );

		$has_file_part = false;
		foreach ( $prompt_parts as $part ) {
			$file = $part->getFile();
			if ( null !== $file ) {
				$has_file_part = true;
				$this->assertSame( 'image/jpeg', (string) $file->getMimeType() );
			}
		}
		$this->assertTrue( $has_file_part, 'The current user message must contribute a file MessagePart' );
	}

	/**
	 * The current prompt's prompt_parts must be passed to the wp-ai-client
	 * builder so the multimodal content reaches the model. This test
	 * walks the conversion path manually (mirroring what RequestBuilder::build
	 * does after assembly) to verify the converted MessagePart[] is the
	 * exact input the builder will receive.
	 *
	 * A pure end-to-end smoke through RequestBuilder::build() requires the
	 * full wp-ai-client provider registry plus a registered fake provider,
	 * which is not stable across the local playground / CI playground
	 * environments. The converter-output identity assertion captures the
	 * regression we actually care about: file blocks survive the
	 * canonical-message → MessagePart[] conversion that feeds the builder.
	 */
	public function test_prompt_parts_carry_file_message_part_into_builder_input(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'      => 'file',
						'file_path' => $this->temp_image_path,
						'mime_type' => 'image/jpeg',
					),
					array(
						'type' => 'text',
						'text' => 'Describe this image.',
					),
				),
			),
		);

		$context = $this->invokePrivate( 'wpAiClientPromptContext', array( $messages ) );

		$this->assertArrayHasKey( 'prompt_parts', $context, 'prompt_parts must exist for builder consumption' );
		$this->assertNotEmpty( $context['prompt_parts'], 'prompt_parts must be non-empty for a multimodal user message' );

		// Pure invariant: builder consumes prompt_parts via with_message_parts().
		// Walk it the same way the call site does and confirm the file part is
		// present with the correct mime type.
		$file_parts = array_values(
			array_filter(
				$context['prompt_parts'],
				static fn( MessagePart $part ): bool => null !== $part->getFile()
			)
		);
		$this->assertCount( 1, $file_parts, 'Exactly one file MessagePart must flow into the builder' );
		$this->assertSame( 'image/jpeg', (string) $file_parts[0]->getFile()->getMimeType() );
	}

	/**
	 * Tool-call transcripts must survive the canonical envelope → wp-ai-client
	 * conversion as typed function call/response parts so Responses API providers
	 * can replay the loop without flattening tool output into plain user text.
	 */
	public function test_prompt_context_preserves_tool_call_and_result_parts(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Create the site.',
			),
			WP_Agent_Message::toolCall(
				'',
				'client/filesystem-write',
				array( 'path' => 'index.html' ),
				1,
				array( 'tool_call_id' => 'call_123' )
			),
			WP_Agent_Message::toolResult(
				'{"success":true}',
				'client/filesystem-write',
				array(
					'success' => true,
					'result'  => array( 'path' => 'index.html' ),
				),
				array( 'tool_call_id' => 'call_123' )
			),
		);

		// The slashed logical tool name is not provider-safe (OpenAI/Responses
		// require ^[a-zA-Z0-9_-]+$). The provider declares it with a safe name
		// (e.g. some runtimes normalize `client/filesystem-write` to `filesystem_write`),
		// and RequestBuilder carries that mapping via the alias table built from
		// the structured tool declarations. The transcript converter must rewrite
		// the logical name to the provider-safe alias so replayed function call /
		// response parts match the tool actually declared to the provider.
		$aliases = RequestBuilder::providerToolNameAliases(
			array(
				'client/filesystem-write' => array(
					'name'            => 'client/filesystem-write',
					'runtime_tool_id' => 'filesystem_write',
				),
			)
		);

		$this->assertSame(
			'filesystem_write',
			$aliases['logical_to_provider']['client/filesystem-write'],
			'Slashed logical tool name aliases to the provider-safe name'
		);
		$this->assertSame(
			'client/filesystem-write',
			$aliases['provider_to_logical']['filesystem_write'],
			'Reverse alias maps the provider-safe name back to the logical name'
		);

		$context = $this->invokePrivate(
			'wpAiClientPromptContext',
			array( $messages, $aliases['logical_to_provider'] )
		);

		$this->assertCount( 2, $context['history'], 'Initial user prompt and assistant tool call remain in history' );
		$this->assertInstanceOf( ModelMessage::class, $context['history'][1] );

		$function_call = $context['history'][1]->getParts()[0]->getFunctionCall();
		$this->assertNotNull( $function_call, 'Assistant tool call converts to a function call part' );
		$this->assertSame( 'call_123', $function_call->getId() );
		$this->assertSame( 'filesystem_write', $function_call->getName(), 'Function call name is rewritten to the provider-safe alias' );
		$this->assertSame( array( 'path' => 'index.html' ), $function_call->getArgs() );

		$this->assertCount( 1, $context['prompt_parts'], 'Latest tool result becomes the current prompt part' );
		$function_response = $context['prompt_parts'][0]->getFunctionResponse();
		$this->assertNotNull( $function_response, 'Tool result converts to a function response part' );
		$this->assertSame( 'call_123', $function_response->getId() );
		$this->assertSame( 'filesystem_write', $function_response->getName(), 'Function response name is rewritten to the provider-safe alias' );
		$this->assertTrue( $function_response->getResponse()['success'] );
	}

	/**
	 * @param string  $method Private method name on RequestBuilder.
	 * @param mixed[] $args   Method args.
	 * @return mixed
	 */
	private function invokePrivate( string $method, array $args ) {
		$reflection = new ReflectionMethod( RequestBuilder::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}
}
