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
	if ( ! class_exists(PluginSettings::class) ) {
		class PluginSettings {}
	}
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
			array(
				'id'              => 'default',
				'app_private_key' => '-----BEGIN PRIVATE KEY-----secret',
				'default_repo'    => 'chubes4/wp-docs',
			),
		)
	);

	$assert('[redacted]' === ( $redacted[0]['app_private_key'] ?? null ), 'nested app_private_key is redacted');
	$assert('chubes4/wp-docs' === ( $redacted[0]['default_repo'] ?? null ), 'non-secret nested values remain visible');

	$assert('[redacted]' === SettingsCommand::redactSecretsForDisplay('github_app_private_key', 'secret-key'), 'top-level private key is redacted');
	$assert('[redacted]' === SettingsCommand::redactSecretsForDisplay('github_pat', 'ghp_secret'), 'GitHub PAT is redacted');
	$assert('ghp_secret' === SettingsCommand::redactSecretsForDisplay('github_pat', 'ghp_secret', true), 'reveal flag returns raw value');
	$assert('openai/gpt-5.5' === SettingsCommand::redactSecretsForDisplay('default_model', 'openai/gpt-5.5'), 'non-secret top-level value remains visible');

	printf("Result: %d passed, %d failed\n", $passes, $failures);
	exit($failures > 0 ? 1 : 0);
}
