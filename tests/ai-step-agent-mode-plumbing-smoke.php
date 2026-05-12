<?php
/**
 * Smoke test for configurable AI step agent mode plumbing.
 *
 * Run with: php tests/ai-step-agent-mode-plumbing-smoke.php
 *
 * @package DataMachine\Tests
 */

$source = file_get_contents( __DIR__ . '/../inc/Core/Steps/AI/AIStep.php' ) ?: '';

$failures = array();
$passes   = 0;

$assert = static function ( string $message, bool $condition ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "FAIL: {$message}\n";
};

$assert(
	'AI step resolves execution mode from step configuration',
	false !== strpos( $source, 'resolveExecutionMode( $pipeline_step_config, $this->flow_step_config )' )
);

$assert(
	'model resolution uses resolved execution mode',
	false !== strpos( $source, 'PluginSettings::resolveModelForAgentMode( $agent_id, $execution_mode )' )
);

$assert(
	'tool policy receives resolved execution mode',
	false !== strpos( $source, "'mode'                 => $" . 'execution_mode' )
);

$assert(
	'conversation loop receives resolved execution mode',
	false !== strpos( $source, "\n\t\t\t\t\t$" . 'execution_mode,' )
);

$assert(
	'payload carries agent_mode for directives and runtime context',
	false !== strpos( $source, "'agent_mode'                  => $" . 'execution_mode' )
);

$assert(
	'AI step no longer hardcodes pipeline for model resolution',
	false === strpos( $source, "resolveModelForAgentMode( $" . "agent_id, 'pipeline'" )
);

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " AI step agent mode plumbing assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} AI step agent mode plumbing assertions passed.\n";
