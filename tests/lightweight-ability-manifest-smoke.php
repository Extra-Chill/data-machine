<?php
/**
 * Smoke test: lightweight ability declarations register cheap schemas and lazy-load runtime on execute.
 *
 * Run with: php tests/lightweight-ability-manifest-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/tmp/' );
	}

	$runtime_activations = 0;
	$wp_actions          = array();
	$wp_actions_done     = array();
	$wp_actions_running  = array();
	$registered_abilities = array();

	class WP_Ability {}

	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}

	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		global $wp_actions;

		$wp_actions[ $hook_name ][ $priority ][] = $callback;
		unset( $accepted_args );
	}

	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_action( $hook_name, $callback, $priority, $accepted_args );
	}

	function doing_action( string $hook_name ): bool {
		global $wp_actions_running;

		return ! empty( $wp_actions_running[ $hook_name ] );
	}

	function did_action( string $hook_name ): int {
		global $wp_actions_done;

		return $wp_actions_done[ $hook_name ] ?? 0;
	}

	function do_action( string $hook_name, ...$args ): void {
		global $wp_actions, $wp_actions_done, $wp_actions_running;

		$wp_actions_running[ $hook_name ] = true;
		foreach ( $wp_actions[ $hook_name ] ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$callback( ...$args );
			}
		}
		unset( $wp_actions_running[ $hook_name ] );
		$wp_actions_done[ $hook_name ] = ( $wp_actions_done[ $hook_name ] ?? 0 ) + 1;
	}

	function wp_register_ability( string $name, array $args ): WP_Ability {
		global $registered_abilities;

		$registered_abilities[ $name ] = $args;
		return new WP_Ability();
	}

	function datamachine_activate_full_runtime( string $reason = '' ): void {
		global $runtime_activations;

		if ( 'ability:datamachine/heavy-test' === $reason ) {
			++$runtime_activations;
		}
	}

	require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/AgentBundleArtifactRebase.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/AbilityRegistration.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/AbilityManifest.php';

	$failed = 0;
	$total  = 0;

	$assert = static function ( string $name, bool $condition ) use ( &$failed, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  PASS: {$name}\n";
			return;
		}

		echo "  FAIL: {$name}\n";
		++$failed;
	};

	$plugin_root = dirname( __DIR__ );
	$plugin_file = file_get_contents( $plugin_root . '/data-machine.php' );
	$helper_file = file_get_contents( $plugin_root . '/inc/Abilities/AbilityRegistration.php' );

	if ( false === $plugin_file || false === $helper_file ) {
		fwrite( STDERR, "FAIL: source files are not readable\n" );
		exit( 1 );
	}

	$assert( 'entrypoint declares lightweight ability manifest', str_contains( $plugin_file, 'function datamachine_lightweight_ability_manifest(): array' ) );
	$assert( 'manifest registrar is used by entrypoint', str_contains( $plugin_file, 'AbilityManifest::register( datamachine_lightweight_ability_manifest() )' ) );
	$assert( 'agent ability declared lightweight', str_contains( $plugin_file, '\\DataMachine\\Abilities\\AgentAbilities::class' ) );
	$assert( 'image-template ability declared lightweight', str_contains( $plugin_file, '\\DataMachine\\Abilities\\Media\\ImageTemplateAbilities::class' ) );
	$assert( 'send-email ability declared lightweight', str_contains( $plugin_file, '\\DataMachine\\Abilities\\Publish\\SendEmailAbility::class' ) );
	$assert( 'send-email-queued ability declared lightweight', str_contains( $plugin_file, '\\DataMachine\\Abilities\\Publish\\SendEmailQueuedAbility::class' ) );
	$lightweight_section = substr( $plugin_file, strpos( $plugin_file, 'AbilityManifest::register' ) ?: 0 );
	$assert( 'old agent ad-hoc registration removed from lightweight section', ! str_contains( $lightweight_section, 'new \\DataMachine\\Abilities\\AgentAbilities();' ) );
	$assert( 'old image-template ad-hoc registration removed from lightweight section', ! str_contains( $lightweight_section, 'ImageTemplateAbilities::ensure_registered();' ) );
	$assert( 'old send-email ad-hoc registration removed from lightweight section', ! str_contains( $lightweight_section, 'SendEmailAbility::ensure_registered();' ) );
	$assert( 'ability registration helper wraps execute callbacks', str_contains( $helper_file, 'function runtime_callback( callable $callback, string $ability_name ): callable' ) );

	\DataMachine\Abilities\AbilityManifest::register(
		array(
			array(
				'file'  => $plugin_root . '/inc/Abilities/AgentAbilities.php',
				'class' => \DataMachine\Abilities\AgentAbilities::class,
			),
			array(
				'file'   => $plugin_root . '/inc/Abilities/Media/ImageTemplateAbilities.php',
				'class'  => \DataMachine\Abilities\Media\ImageTemplateAbilities::class,
				'method' => 'ensure_registered',
			),
			array(
				'file'   => $plugin_root . '/inc/Abilities/Publish/SendEmailAbility.php',
				'class'  => \DataMachine\Abilities\Publish\SendEmailAbility::class,
				'method' => 'ensure_registered',
			),
			array(
				'file'   => $plugin_root . '/inc/Abilities/Publish/SendEmailQueuedAbility.php',
				'class'  => \DataMachine\Abilities\Publish\SendEmailQueuedAbility::class,
				'method' => 'ensure_registered',
			),
		)
	);
	do_action( 'wp_abilities_api_init' );

	foreach ( array( 'datamachine/list-agents', 'datamachine/run-agent-bundle', 'datamachine/render-image-template', 'datamachine/send-email', 'datamachine/send-email-queued' ) as $ability_name ) {
		$assert( "lite manifest resolves {$ability_name}", isset( $registered_abilities[ $ability_name ] ) );
	}
	$assert( 'lite manifest resolution does not activate full runtime', 0 === $runtime_activations );

	$callback_runs = 0;
	$callback      = \DataMachine\Abilities\AbilityRegistration::runtime_callback(
		static function ( array $input ) use ( &$callback_runs ): array {
			++$callback_runs;

			return $input;
		},
		'datamachine/heavy-test'
	);

	$result_one = $callback( array( 'run' => 1 ) );
	$result_two = $callback( array( 'run' => 2 ) );

	$assert( 'heavy ability callback returns original result', array( 'run' => 1 ) === $result_one && array( 'run' => 2 ) === $result_two );
	$assert( 'heavy ability callback executes every invocation', 2 === $callback_runs );
	$assert( 'heavy ability activates full runtime once', 1 === $runtime_activations );

	if ( $failed > 0 ) {
		fwrite( STDERR, "lightweight ability manifest smoke failed: {$failed}/{$total}\n" );
		exit( 1 );
	}

	echo "Lightweight ability manifest smoke passed: {$total} assertions.\n";
}
