<?php
/**
 * Smoke tests for ordered multi-mode model resolution.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Agents {
	class Agents {
		public static array $agents = array();

		public function get_agent( int $agent_id ): ?array {
			return self::$agents[ $agent_id ] ?? null;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $key, mixed $default_value = false ): mixed {
			return $GLOBALS['datamachine_mode_model_options'][ $key ] ?? $default_value;
		}
	}

	if ( ! function_exists( 'get_site_option' ) ) {
		function get_site_option( string $key, mixed $default_value = false ): mixed {
			return $GLOBALS['datamachine_mode_model_site_options'][ $key ] ?? $default_value;
		}
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( mixed $value ): string {
			return trim( (string) $value );
		}
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( mixed $value ): string {
			$value = strtolower( (string) $value );
			return preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '';
		}
	}

	require_once __DIR__ . '/../inc/Core/NetworkSettings.php';
	require_once __DIR__ . '/../inc/Core/PluginSettings.php';

	use DataMachine\Core\Database\Agents\Agents;
	use DataMachine\Core\PluginSettings;

	$passes = 0;
	$fails  = 0;

	$assert_same = static function ( mixed $expected, mixed $actual, string $label ) use ( &$passes, &$fails ): void {
		if ( $expected === $actual ) {
			$passes++;
			echo "PASS: {$label}\n";
			return;
		}

		$fails++;
		echo "FAIL: {$label}\n";
		echo '  expected: ' . json_encode( $expected ) . "\n";
		echo '  actual:   ' . json_encode( $actual ) . "\n";
	};

	$GLOBALS['datamachine_mode_model_options']      = array(
		'datamachine_settings' => array(
			'mode_models' => array(
				'chat'     => array(
					'provider' => 'openai',
					'model'    => 'gpt-5.5',
				),
				'pipeline' => array(
					'provider' => 'openai',
					'model'    => 'gpt-5.4-mini',
				),
			),
		),
	);
	$GLOBALS['datamachine_mode_model_site_options'] = array();

	Agents::$agents = array(
		7 => array(
			'agent_config' => array(
				'mode_models' => array(
					'intelligence' => array(
						'provider' => 'openai',
						'model'    => '',
					),
				),
			),
		),
	);

	PluginSettings::clearCache();

	$assert_same(
		array( 'provider' => 'openai', 'model' => 'gpt-5.4-mini' ),
		PluginSettings::resolveModelForAgentModes( 7, array( 'intelligence', 'pipeline' ), 'pipeline' ),
		'incomplete behavior mode falls through to pipeline model'
	);

	$assert_same(
		array( 'provider' => 'openai', 'model' => 'gpt-5.5' ),
		PluginSettings::resolveModelForAgentModes( 7, array( 'intelligence', 'chat' ), 'chat' ),
		'incomplete behavior mode falls through to chat model'
	);

	$assert_same(
		array( 'provider' => 'openai', 'model' => 'gpt-5.5' ),
		PluginSettings::resolveModelForAgentModes( 7, array( 'intelligence' ), 'chat' ),
		'chat execution surface fallback applies when behavior mode is alone'
	);

	$assert_same(
		array( 'provider' => 'openai', 'model' => 'gpt-5.4-mini' ),
		PluginSettings::resolveModelForAgentModes( 7, array( 'intelligence' ), 'pipeline' ),
		'pipeline execution surface fallback applies when behavior mode is alone'
	);

	Agents::$agents[7]['agent_config']['mode_models']['intelligence']['model'] = 'gpt-5.5-intelligence';
	PluginSettings::clearCache();

	$assert_same(
		array( 'provider' => 'openai', 'model' => 'gpt-5.5-intelligence' ),
		PluginSettings::resolveModelForAgentModes( 7, array( 'intelligence', 'pipeline' ), 'pipeline' ),
		'complete behavior mode still wins when intentionally configured'
	);

	$ai_step_source = file_get_contents( __DIR__ . '/../inc/Core/Steps/AI/AIStep.php' ) ?: '';
	$chat_source    = file_get_contents( __DIR__ . '/../inc/Abilities/Chat/AgentsChatHandler.php' ) ?: '';

	$assert_same( true, str_contains( $ai_step_source, "resolveModelForAgentModes( $" . "agent_id, $" . "modes, ToolPolicyResolver::MODE_PIPELINE )" ), 'AIStep uses shared mode-model resolver with pipeline fallback' );
	$assert_same( true, str_contains( $chat_source, "resolveModelForAgentModes( 0 === $" . "agent_id ? null : $" . "agent_id, $" . "modes, 'chat' )" ), 'canonical agents/chat uses shared mode-model resolver with chat fallback' );
	$assert_same( false, file_exists( __DIR__ . '/../inc/Abilities/Chat/SendMessageAbility.php' ), 'datamachine/send-message facade is removed' );

	echo "\n{$passes} passed, {$fails} failed\n";
	if ( $fails > 0 ) {
		exit( 1 );
	}
}
