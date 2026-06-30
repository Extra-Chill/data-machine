<?php
/**
 * Smoke test for explicit lightweight pipeline list response shapes.
 */

$root = dirname( __DIR__ );

$get_pipelines    = file_get_contents( $root . '/inc/Abilities/Pipeline/GetPipelinesAbility.php' );
$pipeline_helpers = file_get_contents( $root . '/inc/Abilities/Pipeline/PipelineHelpers.php' );
$pipeline_db      = file_get_contents( $root . '/inc/Core/Database/Pipelines/Pipelines.php' );
$rest_api         = file_get_contents( $root . '/inc/Api/Pipelines/Pipelines.php' );
$pipelines_app    = file_get_contents( $root . '/inc/Core/Admin/Pages/Pipelines/assets/react/PipelinesApp.jsx' );
$pipeline_queries = file_get_contents( $root . '/inc/Core/Admin/Pages/Pipelines/assets/react/queries/pipelines.js' );
$pipelines_client = file_get_contents( $root . '/inc/Core/Admin/Pages/Pipelines/assets/react/utils/api.js' );
$jobs_client      = file_get_contents( $root . '/inc/Core/Admin/Pages/Jobs/assets/react/api/jobs.js' );

$failures = array();

$assert = static function ( string $label, bool $condition ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

$assert(
	'pipeline ability exposes list output mode',
	false !== strpos( $get_pipelines, "array( 'full', 'list', 'summary', 'ids' )" )
);

$assert(
	'pipeline list mode disables embedded flows',
	false !== strpos( $get_pipelines, "if ( 'list' === \$output_mode )" ) && false !== strpos( $get_pipelines, '$include_flows = false;' )
);

$assert(
	'pipeline helper has explicit list branch',
	false !== strpos( $pipeline_helpers, "if ( 'list' === \$output_mode )" )
);

$assert(
	'pipeline database exposes summary query without pipeline_config',
	false !== strpos( $pipeline_db, 'function get_all_pipelines_summary' )
		&& false !== strpos( $pipeline_db, 'SELECT pipeline_id, pipeline_name, user_id, agent_id, portable_slug, created_at, updated_at' )
		&& false === strpos( $pipeline_db, 'SELECT pipeline_id, pipeline_name, pipeline_config' )
);

$assert(
	'pipeline ability uses summary query for list-like modes',
	false !== strpos( $get_pipelines, "array( 'list', 'summary', 'ids' )" )
		&& false !== strpos( $get_pipelines, 'get_all_pipelines_summary' )
);

$assert(
	'pipeline list branch unsets embedded flows',
	false !== strpos( $pipeline_helpers, "unset( \$pipeline['flows'] );" )
);

$assert(
	'REST pipelines endpoint accepts output_mode',
	false !== strpos( $rest_api, "'output_mode'   => array(" ) && false !== strpos( $rest_api, "'output_mode'   => \$output_mode" )
);

$assert(
	'REST single pipeline ids mode returns before array shape handling',
	false !== strpos( $rest_api, "if ( 'ids' === ( \$result['output_mode'] ?? \$output_mode ) )" )
);

$assert(
	'REST collection ids mode skips fields filtering',
	false !== strpos( $rest_api, "! empty( \$requested_fields ) && 'ids' !== ( \$result['output_mode'] ?? \$output_mode )" )
);

$assert(
	'pipelines admin client requests list output mode',
	false !== strpos( $pipelines_client, "outputMode = 'list'" ) && false !== strpos( $pipelines_client, 'output_mode: outputMode' )
);

$assert(
	'selected pipeline query requests full output mode',
	false !== strpos( $pipeline_queries, "outputMode: 'full'" )
);

$assert(
	'pipelines app hydrates selected pipeline separately from list cache',
	false !== strpos( $pipelines_app, 'usePipeline( selectedPipelineId || null )' )
		&& false !== strpos( $pipelines_app, 'fetchedSelectedPipeline || selectedFromList' )
);

$assert(
	'jobs admin dropdown requests list output mode',
	false !== strpos( $jobs_client, "output_mode: 'list'" )
);

if ( $failures ) {
	fwrite( fopen( 'php://stderr', 'w' ), "Pipeline list output mode smoke failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Pipeline list output mode smoke passed.\n";
