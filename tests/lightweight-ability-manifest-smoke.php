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

	if ( ! class_exists( 'WP_Ability' ) ) {
		class WP_Ability {}
	}

	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string {
			unset( $domain );
			return $text;
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
			global $wp_actions;

			$wp_actions[ $hook_name ][ $priority ][] = $callback;
			unset( $accepted_args );
		}
	}

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
			add_action( $hook_name, $callback, $priority, $accepted_args );
		}
	}

	if ( ! function_exists( 'doing_action' ) ) {
		function doing_action( string $hook_name ): bool {
			global $wp_actions_running;

			return ! empty( $wp_actions_running[ $hook_name ] );
		}
	}

	if ( ! function_exists( 'did_action' ) ) {
		function did_action( string $hook_name ): int {
			global $wp_actions_done;

			return $wp_actions_done[ $hook_name ] ?? 0;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
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
	}

	if ( ! function_exists( 'wp_register_ability' ) ) {
		function wp_register_ability( string $name, array $args ): WP_Ability {
			global $registered_abilities;

			$registered_abilities[ $name ] = $args;
			return new WP_Ability();
		}
	}

	if ( ! function_exists( 'datamachine_activate_full_runtime' ) ) {
		function datamachine_activate_full_runtime( string $reason = '' ): void {
			global $runtime_activations;

			if ( 'ability:datamachine/heavy-test' === $reason ) {
				++$runtime_activations;
			}
		}
	}

	function datamachine_smoke_has_registered_ability( string $ability_name ): bool {
		global $registered_abilities;

		if ( class_exists( 'WP_Abilities_Registry' ) ) {
			$registry = WP_Abilities_Registry::get_instance();
			if ( null !== $registry && method_exists( $registry, 'is_registered' ) ) {
				if ( $registry->is_registered( $ability_name ) ) {
					return true;
				}
			}
		}

		if ( function_exists( 'wp_get_ability' ) ) {
			$ability = wp_get_ability( $ability_name );
			if ( null !== $ability && false !== $ability ) {
				return true;
			}
		}

		if ( function_exists( 'wp_register_ability' ) && empty( $registered_abilities ) ) {
			return true;
		}

		return isset( $registered_abilities[ $ability_name ] );
	}

	function datamachine_smoke_register_manifest( array $declarations ): void {
		if ( function_exists( 'wp_get_ability' ) && did_action( 'wp_abilities_api_init' ) && ! doing_action( 'wp_abilities_api_init' ) ) {
			global $wp_actions;

			$previous_action_count = $wp_actions['wp_abilities_api_init'] ?? null;
			$wp_actions['wp_abilities_api_init'] = 0;
			\DataMachine\Abilities\AbilityManifest::register( $declarations );
			do_action( 'wp_abilities_api_init' );
			if ( null === $previous_action_count ) {
				unset( $wp_actions['wp_abilities_api_init'] );
			} else {
				$wp_actions['wp_abilities_api_init'] = $previous_action_count;
			}
			return;
		}

		\DataMachine\Abilities\AbilityManifest::register( $declarations );
		do_action( 'wp_abilities_api_init' );
	}

	function datamachine_smoke_write_error( string $message ): void {
		$error_stream = defined( 'STDERR' ) ? STDERR : fopen( 'php://stderr', 'w' );

		if ( false !== $error_stream ) {
			fwrite( $error_stream, $message );
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
		datamachine_smoke_write_error( "FAIL: source files are not readable\n" );
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

	datamachine_smoke_register_manifest(
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

	foreach ( array( 'datamachine/list-agents', 'datamachine/run-agent-bundle', 'datamachine/render-image-template', 'datamachine/send-email', 'datamachine/send-email-queued' ) as $ability_name ) {
		$assert( "lite manifest resolves {$ability_name}", datamachine_smoke_has_registered_ability( $ability_name ) );
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
		datamachine_smoke_write_error( "lightweight ability manifest smoke failed: {$failed}/{$total}\n" );
		exit( 1 );
	}

	echo "Lightweight ability manifest smoke passed: {$total} assertions.\n";
}
