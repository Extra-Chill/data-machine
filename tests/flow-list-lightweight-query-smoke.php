<?php
/**
 * Smoke test for lightweight pipeline flow list queries.
 *
 * Verifies that list/summary pipeline requests can avoid selecting and
 * decoding the large flow_config longtext blob.
 */

$root = dirname( __DIR__ );

$get_flows = file_get_contents( $root . '/inc/Abilities/Flow/GetFlowsAbility.php' );
$flows_db  = file_get_contents( $root . '/inc/Core/Database/Flows/Flows.php' );
$rest_api  = file_get_contents( $root . '/inc/Api/Flows/Flows.php' );

$failures = array();

$assert = static function ( string $label, bool $condition ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

$assert(
	'pipeline list modes include list/summary/ids in lightweight query gate',
	false !== strpos( $get_flows, "array( 'list', 'summary', 'ids' )" )
);

$assert(
	'handler_slug disables lightweight query so handler filtering can inspect flow_config',
	false !== strpos( $get_flows, '&& ! $handler_slug' )
);

$assert(
	'pipeline-scoped list path calls get_flows_for_pipeline_summary',
	false !== strpos( $get_flows, 'get_flows_for_pipeline_summary' )
);

$summary_method_pos = strpos( $flows_db, 'function get_flows_for_pipeline_summary' );
$assert( 'pipeline summary query method exists', false !== $summary_method_pos );

if ( false !== $summary_method_pos ) {
	$summary_method = substr( $flows_db, $summary_method_pos, 1600 );
	$assert(
		'pipeline summary query selects explicit lightweight columns',
		false !== strpos( $summary_method, 'SELECT flow_id, flow_name, pipeline_id, scheduling_config, user_id, agent_id' )
	);
	$assert(
		'pipeline summary query does not select all columns',
		false === strpos( $summary_method, 'SELECT *' )
	);
	$assert(
		'pipeline summary query does not decode flow_config',
		false === strpos( $summary_method, 'json_decode( ' . '$flow' . "['flow_config']" )
	);
}

$assert(
	'REST flows endpoint accepts output_mode',
	false !== strpos( $rest_api, "'output_mode' => array(" ) && false !== strpos( $rest_api, "'output_mode' => " . '$output_mode' )
);

if ( $failures ) {
	fwrite( fopen( 'php://stderr', 'w' ), "Lightweight flow list query smoke failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Lightweight flow list query smoke passed.\n";
