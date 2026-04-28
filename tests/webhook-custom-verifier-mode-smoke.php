<?php
/**
 * Pure-PHP smoke test for preserving registered webhook verifier modes (#1437).
 *
 * Run with: php tests/webhook-custom-verifier-mode-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Flows {
	class Flows {
		/** @var array<int,array<string,mixed>> */
		public static array $flows = array();

		public function get_flow( int $flow_id ): ?array {
			return self::$flows[ $flow_id ] ?? null;
		}

		public function update_flow( int $flow_id, array $flow_data ): bool {
			if ( ! isset( self::$flows[ $flow_id ] ) ) {
				return false;
			}

			self::$flows[ $flow_id ] = array_merge( self::$flows[ $flow_id ], $flow_data );
			return true;
		}
	}
}

namespace DataMachine\Core\Database\Pipelines {
	class Pipelines {}
}

namespace DataMachine\Core\Database\Jobs {
	class Jobs {}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	require_once __DIR__ . '/smoke-wp-stubs.php';

	$GLOBALS['__webhook_custom_mode_filters'] = array();

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
			$GLOBALS['__webhook_custom_mode_filters'][ $hook_name ][] = $callback;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook_name, $value, ...$args ) {
			foreach ( $GLOBALS['__webhook_custom_mode_filters'][ $hook_name ] ?? array() as $callback ) {
				$value = $callback( $value, ...$args );
			}
			return $value;
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = null ) {
			return $text;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook_name, ...$args ): void {}
	}

	if ( ! function_exists( 'rest_url' ) ) {
		function rest_url( string $path = '' ): string {
			return 'https://example.test/wp-json/' . ltrim( $path, '/' );
		}
	}

	class TestRegisteredWebhookVerifier {
		public static function verify( string $raw_body, array $headers, array $query_params, array $post_params, string $url, array $config, ?int $now = null ): \DataMachine\Api\WebhookVerificationResult {
			if ( 'github_pull_request' !== ( $config['mode'] ?? '' ) ) {
				return \DataMachine\Api\WebhookVerificationResult::fail( 'wrong_mode' );
			}

			if ( 'github-secret' !== ( $config['secrets'][0]['value'] ?? '' ) ) {
				return \DataMachine\Api\WebhookVerificationResult::fail( 'missing_secret' );
			}

			return \DataMachine\Api\WebhookVerificationResult::ok( (string) ( $config['secrets'][0]['id'] ?? '' ) );
		}
	}

	require_once __DIR__ . '/../inc/Api/WebhookVerificationResult.php';
	require_once __DIR__ . '/../inc/Api/WebhookVerifier.php';
	require_once __DIR__ . '/../inc/Api/WebhookAuthResolver.php';
	require_once __DIR__ . '/../inc/Abilities/Flow/FlowHelpers.php';
	require_once __DIR__ . '/../inc/Abilities/Flow/WebhookTriggerAbility.php';

	use DataMachine\Abilities\Flow\WebhookTriggerAbility;
	use DataMachine\Api\WebhookAuthResolver;
	use DataMachine\Api\WebhookVerifier;
	use DataMachine\Core\Database\Flows\Flows;

	$failures = array();
	$passes   = 0;

	function assert_webhook_custom_mode( string $name, bool $condition, string $detail = '' ): void {
		global $failures, $passes;
		if ( $condition ) {
			++$passes;
			echo "  [PASS] {$name}\n";
			return;
		}

		$failures[] = $name;
		echo "  [FAIL] {$name}" . ( $detail ? " - {$detail}" : '' ) . "\n";
	}

	echo "=== webhook-custom-verifier-mode-smoke ===\n";

	add_filter( 'datamachine_webhook_verifier_modes', function ( array $modes ): array {
		$modes['github_pull_request'] = TestRegisteredWebhookVerifier::class;
		return $modes;
	} );

	Flows::$flows[101] = array(
		'flow_id'           => 101,
		'scheduling_config' => array(),
	);

	$ability = new WebhookTriggerAbility();
	$result  = $ability->executeEnable(
		array(
			'flow_id'   => 101,
			'auth_mode' => 'hmac',
			'template'  => array(
				'mode'            => 'github_pull_request',
				'allowed_actions' => array( 'opened', 'synchronize' ),
			),
			'secret'    => 'github-secret',
			'secret_id' => 'github_webhook',
		)
	);

	$config   = Flows::$flows[101]['scheduling_config'];
	$resolved = WebhookAuthResolver::resolve( $config );
	$verified = WebhookVerifier::verify( '{}', array(), array(), array(), 'https://example.test', $resolved['verifier'] ?? array() );

	assert_webhook_custom_mode( 'enable succeeds for registered custom verifier mode', true === ( $result['success'] ?? false ) );
	assert_webhook_custom_mode( 'public auth primitive remains hmac', 'hmac' === ( $config['webhook_auth_mode'] ?? null ) );
	assert_webhook_custom_mode( 'registered verifier mode survives storage normalization', 'github_pull_request' === ( $config['webhook_auth']['mode'] ?? null ) );
	assert_webhook_custom_mode( 'flow secret roster stores explicit secret id', 'github_webhook' === ( $config['webhook_secrets'][0]['id'] ?? null ) );
	assert_webhook_custom_mode( 'resolver returns registered verifier mode from template', 'github_pull_request' === ( $resolved['mode'] ?? null ) );
	assert_webhook_custom_mode( 'resolver attaches flow secret roster to verifier config', 'github-secret' === ( $resolved['verifier']['secrets'][0]['value'] ?? null ) );
	assert_webhook_custom_mode( 'WebhookVerifier dispatches registered mode with resolved flow secrets', $verified->ok );

	Flows::$flows[102] = array(
		'flow_id'           => 102,
		'scheduling_config' => array(),
	);

	$body = '{"x":1}';
	$result = $ability->executeEnable(
		array(
			'flow_id'   => 102,
			'auth_mode' => 'hmac',
			'template'  => array(
				'mode'             => 'typo_pull_request',
				'algo'             => 'sha256',
				'signed_template'  => '{body}',
				'signature_source' => array(
					'header'   => 'X-Sig',
					'extract'  => array( 'kind' => 'raw' ),
					'encoding' => 'hex',
				),
			),
			'secret'    => 'hmac-secret',
			'secret_id' => 'current',
		)
	);

	$config   = Flows::$flows[102]['scheduling_config'];
	$resolved = WebhookAuthResolver::resolve( $config );
	$signature = hash_hmac( 'sha256', $body, 'hmac-secret' );
	$verified  = WebhookVerifier::verify( $body, array( 'x-sig' => $signature ), array(), array(), 'https://example.test', $resolved['verifier'] ?? array() );

	assert_webhook_custom_mode( 'enable still succeeds for generic HMAC template with unknown mode typo', true === ( $result['success'] ?? false ) );
	assert_webhook_custom_mode( 'unknown non-hmac mode normalizes back to hmac', 'hmac' === ( $config['webhook_auth']['mode'] ?? null ) );
	assert_webhook_custom_mode( 'resolver keeps generic hmac behavior for unknown modes', 'hmac' === ( $resolved['mode'] ?? null ) );
	assert_webhook_custom_mode( 'generic hmac verification still works after normalization', $verified->ok );

	echo "\n--------------------------------\n";
	$total = $passes + count( $failures );
	echo "{$passes} / {$total} passed\n";

	if ( ! empty( $failures ) ) {
		echo "\nFailures:\n";
		foreach ( $failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\nAll assertions passed.\n";
}
