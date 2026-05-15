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
	'AI step resolves execution modes from step configuration',
	false !== strpos( $source, 'resolveExecutionModes( $pipeline_step_config, $this->flow_step_config )' )
);

$assert(
	'model resolution uses resolved execution modes',
	false !== strpos( $source, 'resolveModelForExecutionModes( $agent_id, $execution_modes )' )
);

$assert(
	'tool policy receives resolved execution modes',
	false !== strpos( $source, "'modes'                => $" . 'execution_modes' )
);

$assert(
	'conversation loop receives resolved execution modes',
	false !== strpos( $source, "\n\t\t\t\t\t$" . 'execution_modes,' )
);

$assert(
	'payload carries agent_modes for directives and runtime context',
	false !== strpos( $source, "'agent_modes'                 => $" . 'execution_modes' )
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
