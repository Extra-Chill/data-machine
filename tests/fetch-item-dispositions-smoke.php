<?php
/**
 * Pure-PHP smoke test for explicit fetch item dispositions (#1814).
 *
 * Run with: php tests/fetch-item-dispositions-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

	$failed = 0;
	$total  = 0;

	function assert_fetch_disposition_smoke( string $name, bool $cond, string $detail = '' ): void {
		global $failed, $total;
		++$total;
		if ( $cond ) {
			echo "  [PASS] $name\n";
			return;
		}

		echo "  [FAIL] $name" . ( $detail ? " - $detail" : '' ) . "\n";
		++$failed;
	}

	$GLOBALS['fetch_disposition_smoke_processed'] = array();
	$GLOBALS['fetch_disposition_smoke_released']  = array();
	$GLOBALS['fetch_disposition_smoke_engine']    = array();

	function do_action( string $hook, ...$args ): void {
		if ( 'datamachine_mark_item_processed' === $hook ) {
			$GLOBALS['fetch_disposition_smoke_processed'][] = $args;
		}
	}

	function datamachine_merge_engine_data( int $job_id, array $data ): void {
		$GLOBALS['fetch_disposition_smoke_engine'][ $job_id ] = array_merge(
			$GLOBALS['fetch_disposition_smoke_engine'][ $job_id ] ?? array(),
			$data
		);
	}
}

namespace DataMachine\Core\Database\ProcessedItems {
	class ProcessedItems {
		public function release_claim( string $flow_step_id, string $source_type, string $item_identifier ): int|false {
			$GLOBALS['fetch_disposition_smoke_released'][] = array( $flow_step_id, $source_type, $item_identifier );
			return 1;
		}
	}
}

namespace {
	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Core/Steps/Fetch/Tools/FetchItemDispositionTool.php';

	final class FetchDispositionSmokeEngine {
		/** @var array<string,mixed> */
		private array $data;

		/**
		 * @param array<string,mixed> $data Engine data.
		 */
		public function __construct( array $data ) {
			$this->data = $data;
		}

		public function get( string $key ): mixed {
			return $this->data[ $key ] ?? null;
		}
	}

	$engine = new FetchDispositionSmokeEngine(
		array(
			'item_identifier' => 'source-123',
			'source_type'     => 'rss',
			'flow_config'     => array(
				'fetch-step_7' => array( 'step_type' => 'fetch' ),
				'ai-step_7'    => array( 'step_type' => 'ai' ),
			),
		)
	);

	$tool = new DataMachine\Core\Steps\Fetch\Tools\FetchItemDispositionTool();
	$data_packets = array(
		array(
			'type'     => 'fetch',
			'data'     => array(
				'title' => 'Fixture source title',
				'body'  => str_repeat( 'Source body with access_token=secret-value. ', 40 ),
			),
			'metadata' => array(
				'item_identifier' => 'source-123',
				'source_type'     => 'rss',
				'source_url'      => 'https://example.test/post?access_token=secret-value',
				'provider_id'     => 'fixture-provider',
			),
		),
	);

	echo "Case 1: reject_source marks processed\n";
	$reject = $tool->handle_tool_call(
		array(
			'job_id'       => 1814,
			'flow_step_id' => 'ai-step_7',
			'engine'       => $engine,
			'data'         => $data_packets,
			'reason'       => 'duplicate-source',
		),
		array( 'disposition' => 'reject_source' )
	);
	assert_fetch_disposition_smoke( 'reject_source succeeds', true === ( $reject['success'] ?? false ) );
	assert_fetch_disposition_smoke( 'reject_source reports explicit tool name', 'reject_source' === ( $reject['tool_name'] ?? '' ) );
	assert_fetch_disposition_smoke( 'reject_source marks fetch step processed', array( 'fetch-step_7', 'rss', 'source-123', 1814 ) === ( $GLOBALS['fetch_disposition_smoke_processed'][0] ?? null ) );
	assert_fetch_disposition_smoke( 'reject_source sets source-rejected status', 'agent_skipped - source-rejected' === ( $GLOBALS['fetch_disposition_smoke_engine'][1814]['job_status'] ?? '' ) );
	$reject_diagnostic = $GLOBALS['fetch_disposition_smoke_engine'][1814]['disposition_diagnostic'] ?? array();
	assert_fetch_disposition_smoke( 'reject_source persists disposition diagnostic', 'reject_source' === ( $reject_diagnostic['disposition'] ?? '' ) && 'duplicate-source' === ( $reject_diagnostic['reason'] ?? '' ) );
	assert_fetch_disposition_smoke( 'reject_source diagnostic includes source identity', 'source-123' === ( $reject_diagnostic['item_identifier'] ?? '' ) && 'fixture-provider' === ( $reject_diagnostic['provider'] ?? '' ) && 'fetch-step_7' === ( $reject_diagnostic['flow_step_id'] ?? '' ) );
	assert_fetch_disposition_smoke( 'reject_source diagnostic includes packet count and bounded excerpt', 1 === ( $reject_diagnostic['packet_count'] ?? 0 ) && 1200 === ( $reject_diagnostic['excerpt_limit'] ?? 0 ) && 1200 >= strlen( $reject_diagnostic['excerpt'] ?? '' ) );
	assert_fetch_disposition_smoke( 'reject_source diagnostic redacts obvious secrets', ! str_contains( $reject_diagnostic['excerpt'] ?? '', 'secret-value' ) && ! str_contains( $reject_diagnostic['source_url'] ?? '', 'secret-value' ) );

	echo "Case 2: defer_item releases claim without marking processed\n";
	$GLOBALS['fetch_disposition_smoke_processed'] = array();
	$defer = $tool->handle_tool_call(
		array(
			'job_id'       => 1815,
			'flow_step_id' => 'ai-step_7',
			'engine'       => $engine,
			'data'         => $data_packets,
			'reason'       => 'tool-error',
		),
		array( 'disposition' => 'defer_item' )
	);
	assert_fetch_disposition_smoke( 'defer_item succeeds', true === ( $defer['success'] ?? false ) );
	assert_fetch_disposition_smoke( 'defer_item reports explicit tool name', 'defer_item' === ( $defer['tool_name'] ?? '' ) );
	assert_fetch_disposition_smoke( 'defer_item releases fetch step claim', array( 'fetch-step_7', 'rss', 'source-123' ) === ( $GLOBALS['fetch_disposition_smoke_released'][0] ?? null ) );
	assert_fetch_disposition_smoke( 'defer_item does not mark processed', array() === $GLOBALS['fetch_disposition_smoke_processed'] );
	assert_fetch_disposition_smoke( 'tool-error deferral remains retry eligible', 'failed - item-deferred' === ( $GLOBALS['fetch_disposition_smoke_engine'][1815]['job_status'] ?? '' ) );
	$defer_diagnostic = $GLOBALS['fetch_disposition_smoke_engine'][1815]['disposition_diagnostic'] ?? array();
	assert_fetch_disposition_smoke( 'defer_item persists disposition diagnostic', 'defer_item' === ( $defer_diagnostic['disposition'] ?? '' ) && 'tool-error' === ( $defer_diagnostic['reason'] ?? '' ) );

	echo "Case 3: production tool surface exposes positive affordances\n";
	$fetch_handler = file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/FetchHandler.php' );
	$execute_step  = file_get_contents( __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php' );
	assert_fetch_disposition_smoke( 'fetch surface exposes reject_source', str_contains( $fetch_handler, "'reject_source'" ) );
	assert_fetch_disposition_smoke( 'fetch surface exposes defer_item', str_contains( $fetch_handler, "'defer_item'" ) );
	assert_fetch_disposition_smoke( 'fetch surface avoids legacy skip tool registration', ! str_contains( $fetch_handler, "'skip_item'" ) );
	assert_fetch_disposition_smoke( 'fetch disposition schemas use canonical root object schemas', 2 === substr_count( $fetch_handler, "'type'       => 'object'" ) );
	assert_fetch_disposition_smoke( 'fetch disposition schemas use object-level required arrays', 2 === substr_count( $fetch_handler, "'required'   => array( 'reason' )" ) );
	assert_fetch_disposition_smoke( 'fetch disposition schemas avoid property-level required flags', ! str_contains( $fetch_handler, "'required'    => true" ) );
	assert_fetch_disposition_smoke( 'reject_source describes reasoned content/source rejection', str_contains( $fetch_handler, 'reasoned content/source evaluation' ) );
	assert_fetch_disposition_smoke( 'defer_item describes safe completion and retry eligibility', str_contains( $fetch_handler, 'cannot safely complete processing now' ) && str_contains( $fetch_handler, 'remain eligible' ) );
	assert_fetch_disposition_smoke( 'normal completion still marks processed', str_contains( $execute_step, '$this->markCompletedItemProcessed( $job_id );' ) && str_contains( $execute_step, 'JobStatus::COMPLETED' ) );

	echo "\nFetch item dispositions smoke complete: {$total} assertions, {$failed} failures.\n";
	if ( $failed > 0 ) {
		exit( 1 );
	}
}
