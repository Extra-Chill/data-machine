<?php
/**
 * Runtime registration and settings continuity smoke for IndexNow.
 */

namespace DataMachine\Abilities {
	class AbilityRegistration {
		public static function on_abilities_api_init( callable $callback ): void {
			$callback();
		}
	}

	class PermissionHelper {
		public static function can_manage(): bool {
			return true;
		}
	}
}

namespace DataMachine\Core {
	class HttpClient {
	}

	class PluginSettings {
		public static function get( string $key, $default = null ) {
			return $GLOBALS['indexnow_options']['datamachine_settings'][ $key ] ?? $default;
		}

		public static function clearCache(): void {
		}
	}
}

namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );

	class WP_Post {
	}

	class WP {
	}

	$indexnow_actions = array();
	$indexnow_abilities = array();
	$indexnow_options = array(
		'datamachine_settings' => array(
			'unrelated_setting' => 'preserved',
			'indexnow_enabled'  => true,
			'indexnow_api_key'  => 'existing-indexnow-key',
		),
	);

	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		global $indexnow_actions;
		$indexnow_actions[ $hook ] = compact( 'callback', 'priority', 'accepted_args' );
	}

	function wp_register_ability( string $name, array $definition ): void {
		global $indexnow_abilities;
		$indexnow_abilities[ $name ] = $definition;
	}

	function __( string $text, string $domain = '' ): string {
		return $text;
	}

	function get_option( string $name, $default = false ) {
		global $indexnow_options;
		return $indexnow_options[ $name ] ?? $default;
	}

	function update_option( string $name, $value ): bool {
		global $indexnow_options;
		$indexnow_options[ $name ] = $value;
		return true;
	}

	function wp_generate_uuid4(): string {
		return '12345678-1234-1234-1234-123456789abc';
	}

	function do_action( string $hook, ...$args ): void {
	}

	function home_url( string $path = '' ): string {
		return 'https://example.com' . $path;
	}

	require_once dirname( __DIR__ ) . '/inc/Abilities/SEO/IndexNowAbilities.php';
	require_once dirname( __DIR__ ) . '/inc/Cli/CommandRegistry.php';

	new \DataMachineBusiness\Abilities\SEO\IndexNowAbilities();

	$failures = array();
	$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
		if ( ! $condition ) {
			$failures[] = $message;
		}
	};

	$assert(
		array(
			'datamachine/indexnow-submit',
			'datamachine/indexnow-status',
			'datamachine/indexnow-generate-key',
			'datamachine/indexnow-verify-key',
		) === array_keys( $indexnow_abilities ),
		'all four stable abilities register'
	);
	$assert( isset( $indexnow_actions['wp_after_insert_post'] ), 'auto-submit hook registers' );
	$assert( 4 === $indexnow_actions['wp_after_insert_post']['accepted_args'], 'auto-submit hook preserves four arguments' );
	$assert( isset( $indexnow_actions['parse_request'] ), 'public key-file hook registers' );

	$status = \DataMachineBusiness\Abilities\SEO\IndexNowAbilities::get_status();
	$assert( true === $status['enabled'], 'existing indexnow_enabled setting is reused' );
	$assert( 'existing-indexnow-key' === \DataMachineBusiness\Abilities\SEO\IndexNowAbilities::get_api_key(), 'existing indexnow_api_key setting is reused' );

	$new_key = \DataMachineBusiness\Abilities\SEO\IndexNowAbilities::generate_key();
	$assert( '12345678123412341234123456789abc' === $new_key, 'new key uses the established format' );
	$assert( 'preserved' === $indexnow_options['datamachine_settings']['unrelated_setting'], 'key generation preserves other Data Machine settings' );
	$assert( $new_key === $indexnow_options['datamachine_settings']['indexnow_api_key'], 'key generation writes the existing settings key' );

	$commands = \DataMachineBusiness\Cli\CommandRegistry::map();
	$assert( \DataMachineBusiness\Cli\IndexNowCommand::class === $commands['datamachine indexnow'], 'stable CLI command registers through CommandRegistry' );

	if ( ! empty( $failures ) ) {
		fwrite( STDERR, "IndexNow registration smoke failed:\n - " . implode( "\n - ", $failures ) . "\n" );
		exit( 1 );
	}

	echo "IndexNow registration smoke checks passed.\n";
}
