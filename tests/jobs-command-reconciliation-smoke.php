<?php
/** Behavioral smoke test for JobsCommand reconciliation planning. */

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	require_once __DIR__ . '/../inc/Core/Database/Jobs/LegacyAIConcurrencyReconciler.php';
	require_once __DIR__ . '/../inc/Cli/Commands/JobsCommand.php';

	use DataMachine\Cli\Commands\JobsCommand;
	use DataMachine\Core\Database\Jobs\LegacyAIConcurrencyReconciler;

	$failed = 0;
	$total  = 0;
	$assert = static function ( string $label, bool $condition ) use ( &$failed, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  [PASS] {$label}\n";
			return;
		}
		++$failed;
		echo "  [FAIL] {$label}\n";
	};

	$reflection = new \ReflectionClass( JobsCommand::class );
	$command    = $reflection->newInstanceWithoutConstructor();
	$resolver   = $reflection->getMethod( 'resolve_reconciled_job_status' );
	$planner    = $reflection->getMethod( 'resolve_reconciled_job_plan' );

	$source_rejected = array(
		'status'      => LegacyAIConcurrencyReconciler::SOURCE_STATUS,
		'engine_data' => json_encode( array( 'job_status' => 'agent_skipped - source-rejected' ) ),
	);
	$runtime_success = array(
		'status'      => LegacyAIConcurrencyReconciler::SOURCE_STATUS,
		'engine_data' => json_encode(
			array(
				'runtime_provenance'   => array( 'status' => array( 'completed' => true ) ),
				'tool_execution_summary' => array( array( 'tool_name' => 'wiki_upsert', 'success' => true ) ),
			)
		),
	);

	echo "=== jobs-command-reconciliation-smoke ===\n";
	foreach ( array( 'source-rejected evidence' => $source_rejected, 'runtime-success evidence' => $runtime_success ) as $label => $row ) {
		$target = $resolver->invoke( $command, $row );
		$plan   = $planner->invoke( $command, $row );
		$assert( "exact legacy status wins over {$label}", LegacyAIConcurrencyReconciler::TARGET_STATUS === $target );
		$assert( "{$label} cannot select generic complete_job path", 'legacy_ai_concurrency' === $plan['strategy'] );
	}

	$generic = $planner->invoke(
		$command,
		array(
			'status'      => 'failed - handler_failure',
			'engine_data' => json_encode( array( 'job_status' => 'agent_skipped - source-rejected' ) ),
		)
	);
	$assert( 'non-legacy evidence retains generic transition strategy', 'terminal_transition' === $generic['strategy'] );
	$assert( 'non-legacy evidence retains derived target status', 'agent_skipped - source-rejected' === $generic['target_status'] );

	echo "\nJobs command reconciliation smoke complete: {$total} assertions, {$failed} failures.\n";
	exit( $failed > 0 ? 1 : 0 );
}
