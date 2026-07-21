<?php
/**
 * Flow and pipeline create CLI ownership wiring regression test.
 *
 * Run with: php tests/flow-pipeline-create-cli-agent-smoke.php
 *
 * @package DataMachine\Tests
 */

$root           = dirname( __DIR__ );
$flows_source   = file_get_contents( $root . '/inc/Cli/Commands/Flows/FlowsCommand.php' );
$pipeline_source = file_get_contents( $root . '/inc/Cli/Commands/PipelinesCommand.php' );
$failures       = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( str_contains( $flows_source, "if ( isset( \$assoc_args['agent'] ) )" ), 'flow create checks --agent' );
$assert( str_contains( $flows_source, 'AgentResolver::resolve( $assoc_args )' ), 'flow create resolves --agent' );
$assert( str_contains( $flows_source, '$input[\'agent_id\'] = $agent_id;' ), 'flow create passes agent_id to the ability' );
$assert( str_contains( $pipeline_source, "if ( isset( \$assoc_args['agent'] ) )" ), 'pipeline create checks --agent' );
$assert( str_contains( $pipeline_source, 'AgentResolver::resolve( $assoc_args )' ), 'pipeline create resolves --agent' );
$assert( str_contains( $pipeline_source, '$input[\'agent_id\'] = $agent_id;' ), 'pipeline create passes agent_id to the ability' );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "Flow and pipeline create CLI ownership smoke passed.\n";
