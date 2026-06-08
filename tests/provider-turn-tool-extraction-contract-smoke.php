<?php
/**
 * Smoke test for provider-turn tool-call extraction delegation.
 *
 * Run with: php tests/provider-turn-tool-extraction-contract-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$root        = dirname( __DIR__ );
$loop_source = file_get_contents( $root . '/inc/Engine/AI/conversation-loop.php' );
$failures    = array();
$passes      = 0;

$assert = static function ( bool $condition, string $message ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "FAIL: {$message}\n";
};

$assert( false !== $loop_source, 'conversation loop source is readable' );
$assert(
	false !== strpos( (string) $loop_source, 'WP_Agent_Provider_Turn_Result::extract_tool_calls( $result )' ),
	'Data Machine delegates provider-turn tool extraction to Agents API'
);
$assert(
	false === strpos( (string) $loop_source, 'class_exists( \'\\AgentsAPI\\AI\\WP_Agent_Provider_Turn_Result\'' ),
	'Data Machine does not fall back when Agents API extraction is unavailable'
);

foreach (
	array(
		'datamachine_extract_xml_tool_calls',
		'datamachine_extract_json_tool_calls',
		'datamachine_extract_tag_tool_calls',
		'datamachine_extract_named_text_tool_calls',
		'datamachine_dedupe_tool_calls',
		'datamachine_parse_text_tool_attributes',
		'datamachine_text_tool_parameters_complete',
		'datamachine_normalize_function_args',
	) as $removed_helper
) {
	$assert(
		false === strpos( (string) $loop_source, 'function ' . $removed_helper . '(' ),
		$removed_helper . ' is not defined in Data Machine'
	);
}

if ( ! empty( $failures ) ) {
	fprintf( STDERR, "\n%d provider-turn extraction contract assertion(s) failed.\n", count( $failures ) );
	exit( 1 );
}

printf( "\nOK: %d provider-turn extraction contract assertions passed.\n", $passes );
