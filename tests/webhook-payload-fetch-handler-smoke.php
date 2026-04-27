<?php
/**
 * Pure-PHP smoke test for webhook payload fetch handler mappings (#1411).
 *
 * Run with: php tests/webhook-payload-fetch-handler-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/smoke-wp-stubs.php';

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( (string) $value );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

require_once __DIR__ . '/../inc/Core/Steps/Handlers/HttpRequestHelpers.php';
require_once __DIR__ . '/../inc/Core/Steps/HandlerRegistrationTrait.php';
require_once __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/FetchHandler.php';
require_once __DIR__ . '/../inc/Core/Steps/Settings/SettingsHandler.php';
require_once __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/FetchHandlerSettings.php';
require_once __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/WebhookPayload/WebhookPayload.php';
require_once __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/WebhookPayload/WebhookPayloadSettings.php';

use DataMachine\Core\Steps\Fetch\Handlers\WebhookPayload\WebhookPayload;
use DataMachine\Core\Steps\Fetch\Handlers\WebhookPayload\WebhookPayloadSettings;

$failed = 0;
$total  = 0;

function assert_webhook_payload_handler( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	++$failed;
	echo "  [FAIL] {$name}" . ( $detail ? " - {$detail}" : '' ) . "\n";
}

echo "=== webhook-payload-fetch-handler-smoke ===\n";

$payload = array(
	'action'       => 'synchronize',
	'repository'   => array(
		'full_name' => 'Extra-Chill/data-machine',
		'private'   => false,
	),
	'pull_request' => array(
		'number' => 1411,
		'title'  => 'Add webhook payload packet primitive',
		'body'   => "Cook the webhook payload mapping primitive.\n\nNo headers should leak.",
		'head'   => array(
			'sha' => 'abc123def456',
		),
	),
	'headers'      => array(
		'x-hub-signature-256' => 'sha256=secret',
	),
	'secret_field' => 'do-not-leak',
);

$config = array(
	'source_type'              => 'github_webhook',
	'title_path'               => 'pull_request.title',
	'content_path'             => 'pull_request.body',
	'metadata'                 => array(
		'repo'        => 'repository.full_name',
		'pull_number' => 'pull_request.number',
		'head_sha'    => 'pull_request.head.sha',
		'action'      => 'action',
	),
	'item_identifier_template' => '{repository.full_name}#{pull_request.number}@{pull_request.head.sha}',
);

$item = WebhookPayload::mapPayloadToItem( $payload, $config );

assert_webhook_payload_handler(
	'GitHub-like payload title is mapped from nested title_path',
	'Add webhook payload packet primitive' === $item['title']
);

assert_webhook_payload_handler(
	'GitHub-like payload body is mapped from nested content_path',
	str_contains( $item['content'], 'webhook payload mapping primitive' )
);

assert_webhook_payload_handler(
	'nested metadata path extraction maps repository full_name',
	'Extra-Chill/data-machine' === $item['metadata']['repo']
);

assert_webhook_payload_handler(
	'nested metadata path extraction preserves scalar pull number',
	1411 === $item['metadata']['pull_number']
);

assert_webhook_payload_handler(
	'nested metadata path extraction maps head sha',
	'abc123def456' === $item['metadata']['head_sha']
);

assert_webhook_payload_handler(
	'item_identifier_template interpolates nested placeholders',
	'Extra-Chill/data-machine#1411@abc123def456' === $item['metadata']['item_identifier']
);

assert_webhook_payload_handler(
	'source_type is explicit and generic',
	'github_webhook' === $item['metadata']['source_type']
);

assert_webhook_payload_handler(
	'full payload is not copied into metadata',
	! array_key_exists( 'payload', $item['metadata'] ) && ! array_key_exists( 'pull_request', $item['metadata'] )
);

assert_webhook_payload_handler(
	'headers are not exposed unless explicitly mapped',
	! array_key_exists( 'headers', $item['metadata'] ) && ! str_contains( wp_json_encode( $item ), 'x-hub-signature' )
);

assert_webhook_payload_handler(
	'unmapped payload secrets are not exposed',
	! str_contains( wp_json_encode( $item ), 'do-not-leak' )
);

assert_webhook_payload_handler(
	'dot path helper extracts nested values',
	'abc123def456' === WebhookPayload::getPath( $payload, 'pull_request.head.sha' )
);

assert_webhook_payload_handler(
	'dot path helper returns null for missing paths',
	null === WebhookPayload::getPath( $payload, 'pull_request.base.sha' )
);

$missing_path_failed = false;
try {
	WebhookPayload::mapPayloadToItem(
		$payload,
		array_merge( $config, array( 'content_path' => 'pull_request.missing_body' ) )
	);
} catch ( UnexpectedValueException $e ) {
	$missing_path_failed = str_contains( $e->getMessage(), 'pull_request.missing_body' );
}

assert_webhook_payload_handler(
	'missing required content path fails with clear path message',
	$missing_path_failed
);

$bad_config_failed = false;
try {
	WebhookPayload::mapPayloadToItem( $payload, array( 'source_type' => 'github_webhook' ) );
} catch ( InvalidArgumentException $e ) {
	$bad_config_failed = str_contains( $e->getMessage(), 'requires title_path' );
}

assert_webhook_payload_handler(
	'bad config without title_path or content_path fails clearly',
	$bad_config_failed
);

$handler_source = file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/WebhookPayload/WebhookPayload.php' );

assert_webhook_payload_handler(
	'handler reads only webhook_trigger.payload from engine data',
	str_contains( $handler_source, "get( 'webhook_trigger'" )
		&& str_contains( $handler_source, "['payload']" )
		&& ! str_contains( $handler_source, "['headers']" )
);

assert_webhook_payload_handler(
	'handler supports ignore_missing_paths skip mode for intentionally ignored events',
	str_contains( $handler_source, "config['ignore_missing_paths']" )
		&& str_contains( $handler_source, 'mapped payload field is missing' )
);

assert_webhook_payload_handler(
	'settings sanitize JSON metadata mapping into an array',
	array(
		'repo' => 'repository.full_name',
	) === WebhookPayloadSettings::sanitize(
		array(
			'source_type'              => 'github_webhook',
			'title_path'               => 'pull_request.title',
			'content_path'             => 'pull_request.body',
			'metadata'                 => '{"repo":"repository.full_name"}',
			'item_identifier_template' => '{repository.full_name}',
		)
	)['metadata']
);

assert_webhook_payload_handler(
	'core handler loader instantiates webhook_payload fetch handler',
	str_contains(
		file_get_contents( __DIR__ . '/../data-machine.php' ),
		'new \\DataMachine\\Core\\Steps\\Fetch\\Handlers\\WebhookPayload\\WebhookPayload()'
	)
);

if ( $failed > 0 ) {
	echo "\nwebhook-payload-fetch-handler-smoke failed: {$failed}/{$total} assertions failed.\n";
	exit( 1 );
}

echo "\nwebhook-payload-fetch-handler-smoke passed: {$total} assertions.\n";
