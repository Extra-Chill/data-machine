<?php
/**
 * Smoke test for settings CLI secret redaction.
 *
 * Run: php tests/settings-command-redaction-smoke.php
 */

declare(strict_types=1);

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/../');
	}

	if ( ! class_exists('WP_CLI') ) {
		class WP_CLI {}
	}
}

namespace DataMachine\Cli {
	if ( ! class_exists(BaseCommand::class) ) {
		class BaseCommand {}
	}
}

namespace DataMachine\Core {
	require_once __DIR__ . '/../inc/Core/PluginSettings.php';
}

namespace {
	require_once __DIR__ . '/../inc/Cli/Commands/SettingsCommand.php';

	use DataMachine\Cli\Commands\SettingsCommand;

	$failures = 0;
	$passes   = 0;

	$assert = function ( bool $condition, string $message ) use ( &$failures, &$passes ): void {
		if ( $condition ) {
			$passes++;
			echo "PASS: {$message}\n";
			return;
		}

		$failures++;
		echo "FAIL: {$message}\n";
	};

	$redacted = SettingsCommand::redactSecretsForDisplay(
		'github_credential_profiles',
		array(
			'profiles' => array(
				array(
					'pat'             => 'SENTINEL_PROFILE_PAT',
					'id'              => 'default',
					'app_private_key' => '-----BEGIN PRIVATE KEY-----secret',
					'token'           => 'ghs_nested_secret',
					'password'        => 'nested-password',
					'default_repo'    => 'chubes4/wp-docs',
					'provider'        => array(
						'api_key'       => 'sk-provider-secret',
						'client_secret' => 'client-secret-value',
						'profile_name'  => 'wp-docs',
					),
				),
			),
		)
	);

	$encoded_default = json_encode( $redacted );
	$assert('[redacted]' === ( $redacted['profiles'][0]['pat'] ?? null ), 'nested PAT is redacted');
	$assert('[redacted]' === ( $redacted['profiles'][0]['app_private_key'] ?? null ), 'nested app_private_key is redacted');
	$assert('[redacted]' === ( $redacted['profiles'][0]['token'] ?? null ), 'nested token is redacted');
	$assert('[redacted]' === ( $redacted['profiles'][0]['password'] ?? null ), 'nested password is redacted');
	$assert('[redacted]' === ( $redacted['profiles'][0]['provider']['api_key'] ?? null ), 'nested api_key is redacted');
	$assert('[redacted]' === ( $redacted['profiles'][0]['provider']['client_secret'] ?? null ), 'nested client_secret is redacted');
	$assert('chubes4/wp-docs' === ( $redacted['profiles'][0]['default_repo'] ?? null ), 'non-secret nested values remain visible');
	$assert(false === strpos( $encoded_default, '-----BEGIN PRIVATE KEY-----secret' ), 'default output omits private key value');
	$assert(false === strpos( $encoded_default, 'SENTINEL_PROFILE_PAT' ), 'default output omits nested PAT value');
	$assert(false === strpos( $encoded_default, 'ghs_nested_secret' ), 'default output omits token value');
	$assert(false === strpos( $encoded_default, 'nested-password' ), 'default output omits password value');
	$assert(false === strpos( $encoded_default, 'sk-provider-secret' ), 'default output omits api key value');
	$assert(false === strpos( $encoded_default, 'client-secret-value' ), 'default output omits client secret value');

	$assert('[redacted]' === SettingsCommand::redactSecretsForDisplay('github_app_private_key', 'secret-key'), 'top-level private key is redacted');
	$assert('[redacted]' === SettingsCommand::redactSecretsForDisplay('github_pat', 'ghp_secret'), 'GitHub PAT is redacted');
	$assert('[redacted]' === SettingsCommand::redactSecretsForDisplay('openai_key', 'sk-obvious'), 'top-level *_key is redacted');
	$assert('ghp_secret' === SettingsCommand::redactSecretsForDisplay('github_pat', 'ghp_secret', true), 'reveal flag returns raw value');
	$assert('openai/gpt-5.5' === SettingsCommand::redactSecretsForDisplay('default_model', 'openai/gpt-5.5'), 'non-secret top-level value remains visible');

	printf("Result: %d passed, %d failed\n", $passes, $failures);
	exit($failures > 0 ? 1 : 0);
}
