<?php
/** Execute the production child-engine builder against sibling claim injection. */

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public function create_job( array $args ): int { unset( $args ); return 501; }
		public function create_or_get_job( array $args ): array { unset( $args ); return array( 'job_id' => 501 ); }
		public function get_job( int $job_id ): array { return array( 'job_id' => $job_id, 'status' => 'processing' ); }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['pipeline_child_engines'] = array();
	function current_time( string $type, bool $gmt = false ): string { unset( $type, $gmt ); return gmdate( 'Y-m-d H:i:s' ); }
	function do_action( string $hook, mixed ...$args ): void { unset( $hook, $args ); }
	function datamachine_get_engine_data( int $job_id ): array { return $GLOBALS['pipeline_child_engines'][ $job_id ] ?? array(); }
	function datamachine_set_engine_data( int $job_id, array $engine ): bool { $GLOBALS['pipeline_child_engines'][ $job_id ] = $engine; return true; }

	require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
	require_once __DIR__ . '/../inc/Core/Database/ProcessedItems/ProcessedItems.php';
	require_once __DIR__ . '/../inc/Core/EngineData.php';
	require_once __DIR__ . '/../inc/Core/PacketEngineData.php';
	require_once __DIR__ . '/../inc/Abilities/Engine/PipelineBatchScheduler.php';

	use DataMachine\Abilities\Engine\PipelineBatchScheduler;
	use DataMachine\Core\Database\ProcessedItems\ProcessedItems;

	$claim = array(
		'identity_scope'  => 'scope',
		'source_type'     => 'source',
		'item_identifier' => 'selected',
		'ownership_token' => 'selected-token',
	);
	$claim['disposition_id'] = ProcessedItems::disposition_identity( 'scope', 'source', 'selected' );
	$sibling = array(
		'identity_scope'  => 'scope',
		'source_type'     => 'source',
		'item_identifier' => 'sibling',
		'ownership_token' => 'sibling-token',
	);
	$packet = array(
		'data' => array( 'title' => 'Selected packet' ),
		'metadata' => array(
			ProcessedItems::CLAIM_METADATA_KEY => $claim,
			'_engine_data' => array( ProcessedItems::CLAIMS_METADATA_KEY => array( $sibling ) ),
		),
	);
	$parent = array(
		'job' => array( 'pipeline_id' => 2, 'flow_id' => 3, 'agent_id' => 4, 'user_id' => 5 ),
		'flow_config' => array(),
		ProcessedItems::CLAIMS_METADATA_KEY => array( $claim, $sibling ),
		'packet_fanout_transfer' => array( 'state' => 'prepared', 'claims' => array( $sibling ) ),
	);

	$method = new ReflectionMethod( PipelineBatchScheduler::class, 'createChildJob' );
	$child_id = $method->invoke( new PipelineBatchScheduler(), 99, 'next-step', $packet, $parent );
	$engine   = $GLOBALS['pipeline_child_engines'][501] ?? array();
	$valid    = 501 === $child_id
		&& $claim === ( $engine[ ProcessedItems::CLAIM_METADATA_KEY ] ?? null )
		&& ! array_key_exists( ProcessedItems::CLAIMS_METADATA_KEY, $engine )
		&& ! array_key_exists( 'packet_fanout_transfer', $engine );

	echo $valid ? "  [PASS] child receives exactly one validated claim after packet engine merge\n" : "  [FAIL] child claim isolation failed\n";
	echo 'pipeline-child-claim-smoke: ' . ( $valid ? '1 passed, 0 failed' : '0 passed, 1 failed' ) . "\n";
	exit( $valid ? 0 : 1 );
}
