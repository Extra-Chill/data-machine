<?php
/**
 * Smoke test for Data Machine's wp-ai-client request timeout boundary.
 *
 * Run with: php tests/wp-ai-client-request-timeout-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$GLOBALS['datamachine_timeout_test_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_timeout_test_filters'][ $tag ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_filter( $tag, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $tag, callable $callback, int $priority = 10 ): void {
		foreach ( $GLOBALS['datamachine_timeout_test_filters'][ $tag ][ $priority ] ?? array() as $index => $entry ) {
			if ( $entry[0] === $callback ) {
				unset( $GLOBALS['datamachine_timeout_test_filters'][ $tag ][ $priority ][ $index ] );
			}
		}
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value, ...$args ) {
		$callbacks = $GLOBALS['datamachine_timeout_test_filters'][ $tag ] ?? array();
		ksort( $callbacks );

		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $entry ) {
				$callback      = $entry[0];
				$accepted_args = $entry[1];
				$value         = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $tag, ...$args ): void {
		$GLOBALS['datamachine_timeout_test_last_action'] = array( $tag, $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ?? '' );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		unset( $option );
		return $default;
	}
}

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	function wp_ai_client_prompt( $prompt = null ): TimeoutPromptBuilderDouble {
		return new TimeoutPromptBuilderDouble( is_string( $prompt ) ? $prompt : '' );
	}
}

class TimeoutPromptBuilderDouble {
	/** @var array<string, mixed> */
	public static array $captured_request = array();

	private string $provider = '';
	private mixed $model = null;
	private array $history = array();
	private float $request_timeout = 30.0;

	public function __construct( private string $prompt = '' ) {
		$filtered_timeout = apply_filters( 'wp_ai_client_default_request_timeout', $this->request_timeout );
		if ( is_numeric( $filtered_timeout ) ) {
			$this->request_timeout = (float) $filtered_timeout;
		}
	}

	public function using_provider( string $provider ): self {
		$this->provider = $provider;
		return $this;
	}

	public function using_model( $model ): self {
		$this->model = $model;
		return $this;
	}

	public function using_model_config( $model_config ): self {
		unset( $model_config );
		return $this;
	}

	public function using_system_instruction( string $system_instruction ): self {
		$this->history[] = array( 'role' => 'system', 'content' => $system_instruction );
		return $this;
	}

	public function with_history( ...$messages ): self {
		foreach ( $messages as $message ) {
			$this->history[] = $message;
		}
		return $this;
	}

	public function using_function_declarations( ...$declarations ): self {
		unset( $declarations );
		return $this;
	}

	public function generate_text_result() {
		self::$captured_request = array(
			'provider'          => $this->provider,
			'model'             => $this->model,
			'prompt'            => $this->prompt,
			'timeout'           => $this->request_timeout,
			'history'           => $this->history,
			'curl_filter_count' => timeout_smoke_filter_count( 'http_api_curl' ),
		);

		return \WordPress\AiClient\Results\DTO\GenerativeAiResult::fromData(
			array(
				'content' => 'ok',
				'usage'   => array( 'prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2 ),
			)
		);
	}
}

require_once __DIR__ . '/bootstrap-unit.php';
require_once __DIR__ . '/Unit/Support/WpAiClientTestDoubles.php';

use DataMachine\Engine\AI\RequestBuilder;

$failures   = array();
$assertions = 0;

function assert_timeout_smoke( bool $condition, string $label ): void {
	global $failures, $assertions;

	++$assertions;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	echo "FAIL: {$label}\n";
	$failures[] = $label;
}

function timeout_smoke_failure_count(): int {
	global $failures;
	return count( $failures );
}

function timeout_smoke_filter_count( string $tag ): int {
	$filters = $GLOBALS['datamachine_timeout_test_filters'];
	if ( ! is_array( $filters ) || ! isset( $filters[ $tag ] ) || ! is_array( $filters[ $tag ] ) ) {
		return 0;
	}

	$count = 0;
	foreach ( $filters[ $tag ] as $callbacks ) {
		$count += is_array( $callbacks ) ? count( $callbacks ) : 0;
	}

	return $count;
}

$timeout_context = null;

add_filter(
	'datamachine_wp_ai_client_request_timeout',
	function ( float $timeout, string $mode, string $provider, string $model, array $payload ) use ( &$timeout_context ): float {
		$timeout_context = compact( 'timeout', 'mode', 'provider', 'model', 'payload' );
		return 240.0;
	},
	10,
	5
);

$result = RequestBuilder::build(
	array(
		array(
			'role'    => 'user',
			'content' => 'hello',
		),
		array(
			'role'    => 'assistant',
			'content' => 'hi there',
		),
	),
	'openai',
	'gpt-smoke',
	array(),
	'pipeline',
	array( 'job_id' => 1695 )
);

assert_timeout_smoke( ! is_wp_error( $result ), 'RequestBuilder dispatch succeeds with wp-ai-client test double' );
assert_timeout_smoke( 240.0 === ( TimeoutPromptBuilderDouble::$captured_request['timeout'] ?? null ), 'Data Machine applies scoped wp-ai-client request timeout' );
assert_timeout_smoke( 300.0 === ( $timeout_context['timeout'] ?? null ), 'Data Machine timeout filter receives product default' );
assert_timeout_smoke( 'pipeline' === ( $timeout_context['mode'] ?? null ), 'Data Machine timeout filter receives execution mode' );
assert_timeout_smoke( 'openai' === ( $timeout_context['provider'] ?? null ), 'Data Machine timeout filter receives provider' );
assert_timeout_smoke( 'gpt-smoke' === ( $timeout_context['model'] ?? null ), 'Data Machine timeout filter receives model' );

$captured_history = TimeoutPromptBuilderDouble::$captured_request['history'] ?? array();
assert_timeout_smoke( isset( $captured_history[0] ) && $captured_history[0] instanceof \WordPress\AiClient\Messages\DTO\UserMessage, 'Data Machine converts user history arrays to wp-ai-client UserMessage DTOs' );
assert_timeout_smoke( isset( $captured_history[1] ) && $captured_history[1] instanceof \WordPress\AiClient\Messages\DTO\ModelMessage, 'Data Machine converts assistant history arrays to wp-ai-client ModelMessage DTOs' );
assert_timeout_smoke( 1 === ( TimeoutPromptBuilderDouble::$captured_request['curl_filter_count'] ?? null ), 'Data Machine scopes cURL low-speed settings during wp-ai-client dispatch' );

assert_timeout_smoke( 0 === timeout_smoke_filter_count( 'wp_ai_client_default_request_timeout' ), 'Data Machine removes temporary wp-ai-client timeout filter after dispatch' );
assert_timeout_smoke( 0 === timeout_smoke_filter_count( 'http_api_curl' ), 'Data Machine removes temporary cURL low-speed filter after dispatch' );

if ( timeout_smoke_failure_count() > 0 ) {
	exit( 1 );
}

echo "\nwp-ai-client request timeout smoke passed ({$assertions} assertions).\n";
