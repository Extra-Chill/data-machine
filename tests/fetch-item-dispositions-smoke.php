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

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {
			if ( 'datamachine_mark_item_processed' === $hook ) {
				$GLOBALS['fetch_disposition_smoke_processed'][] = $args;
			}
		}
	}

	// Under a real WordPress runtime the
	// do_action stub above never installs, so bridge the observed hook into the
	// same capture buffer via real add_action.
	if ( defined( 'WPINC' ) ) {
		add_action(
			'datamachine_mark_item_processed',
			static function ( ...$args ): void {
				$GLOBALS['fetch_disposition_smoke_processed'][] = $args;
			},
			10,
			10
		);
	}

	if ( ! function_exists( 'datamachine_merge_engine_data' ) ) {
		function datamachine_merge_engine_data( int $job_id, array $data ): bool {
			$GLOBALS['fetch_disposition_smoke_engine'][ $job_id ] = array_merge(
				$GLOBALS['fetch_disposition_smoke_engine'][ $job_id ] ?? array(),
				$data
			);

			// Mirror into the persisted snapshot so EngineData::retrieve() sees
			// merged data, matching production EngineData::merge() behavior.
			$GLOBALS['fetch_disposition_smoke_persisted_engine'][ $job_id ] = array_merge(
				$GLOBALS['fetch_disposition_smoke_persisted_engine'][ $job_id ] ?? array(),
				$data
			);

			// Keep the (possibly real) object cache coherent with the snapshot
			// so EngineData::retrieve() never serves a stale pre-merge value.
			wp_cache_set( $job_id, $GLOBALS['fetch_disposition_smoke_persisted_engine'][ $job_id ], 'datamachine_engine_data' );
			return true;
		}
	}

	if ( ! function_exists( 'wp_cache_get' ) ) {
		function wp_cache_get( $key, string $group = '' ) {
			unset( $key, $group );
			return false;
		}
	}

	if ( ! function_exists( 'wp_cache_set' ) ) {
		function wp_cache_set( $key, $value, string $group = '' ): bool {
			unset( $key, $value, $group );
			return true;
		}
	}

	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( string $text ): string {
			return strip_tags( $text );
		}
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( string $value ): string {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
		}
	}
}

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public function retrieve_engine_data( int $job_id ): array {
			return $GLOBALS['fetch_disposition_smoke_persisted_engine'][ $job_id ] ?? array();
		}
		public function compare_and_swap_engine_data( int $job_id, array $expected, array $next ): array {
			$current = $GLOBALS['fetch_disposition_smoke_persisted_engine'][ $job_id ] ?? array();
			if ( is_array( $GLOBALS['fetch_disposition_smoke_cas_injection'][ $job_id ] ?? null ) ) {
				$current['packet_dispositions'] = array_replace(
					is_array( $current['packet_dispositions'] ?? null ) ? $current['packet_dispositions'] : array(),
					$GLOBALS['fetch_disposition_smoke_cas_injection'][ $job_id ]
				);
				$GLOBALS['fetch_disposition_smoke_persisted_engine'][ $job_id ] = $current;
				unset( $GLOBALS['fetch_disposition_smoke_cas_injection'][ $job_id ] );
				return array( 'updated' => false, 'conflict' => true, 'error' => null );
			}
			if ( $current !== $expected ) {
				return array( 'updated' => false, 'conflict' => true, 'error' => null );
			}
			$GLOBALS['fetch_disposition_smoke_persisted_engine'][ $job_id ] = $next;
			$GLOBALS['fetch_disposition_smoke_engine'][ $job_id ] = $next;
			return array( 'updated' => true, 'conflict' => false, 'error' => null );
		}
	}
}

namespace DataMachine\Core\Database\ProcessedItems {
	class ProcessedItems {
		public const CLAIM_METADATA_KEY          = '_datamachine_item_claim';
		public const CLAIMS_METADATA_KEY         = '_datamachine_item_claims';
		public const DISPOSITION_ID_METADATA_KEY = '_datamachine_packet_disposition_id';
		public const MAX_DEFERRAL_ATTEMPTS       = 3;

		public static function disposition_identity( string $scope, string $source, string $item ): string {
			return hash( 'sha256', implode( "\0", array( $scope, $source, $item ) ) );
		}

		public static function disposition_claims( array $container ): array {
			$candidates = isset( $container[ self::CLAIM_METADATA_KEY ] ) ? array( $container[ self::CLAIM_METADATA_KEY ] ) : array();
			$candidates = array_merge( $candidates, is_array( $container[ self::CLAIMS_METADATA_KEY ] ?? null ) ? $container[ self::CLAIMS_METADATA_KEY ] : array() );
			$claims     = array();
			foreach ( $candidates as $claim ) {
				if ( ! is_array( $claim ) ) {
					continue;
				}
				$id                    = $claim['disposition_id'] ?? self::disposition_identity( $claim['identity_scope'], $claim['source_type'], $claim['item_identifier'] );
				$claim['disposition_id'] = $id;
				$claims[ $id ]         = $claim;
			}
			return $claims;
		}

		public static function resolve_disposition_claim( array $container, string $id = '', bool $infer_single = true ): ?array {
			$claims = self::disposition_claims( $container );
			if ( '' !== $id ) {
				return $claims[ $id ] ?? null;
			}
			return $infer_single && 1 === count( $claims ) ? reset( $claims ) : null;
		}

		public function release_claim( string $flow_step_id, string $source_type, string $item_identifier ): int|false {
			$GLOBALS['fetch_disposition_smoke_released'][] = array( $flow_step_id, $source_type, $item_identifier );
			return 1;
		}

		public function record_owned_deferral_attempt( array $claim, int $job_id ): array|false {
			$key = $claim['identity_scope'] . "\0" . $claim['item_identifier'];
			$current = $GLOBALS['fetch_disposition_smoke_deferrals'][ $key ] ?? array( 'attempts' => 0, 'job_id' => 0 );
			if ( $job_id !== $current['job_id'] ) {
				++$current['attempts'];
				$current['job_id'] = $job_id;
			}
			$GLOBALS['fetch_disposition_smoke_deferrals'][ $key ] = $current;
			return array( 'attempts' => $current['attempts'], 'exhausted' => $current['attempts'] >= self::MAX_DEFERRAL_ATTEMPTS );
		}
	}
}

namespace {
	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Core/EngineData.php';
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

	$claim = array(
		'identity_scope'  => 'fetch-step_7',
		'source_type'     => 'rss',
		'item_identifier' => 'source-123',
		'ownership_token' => 'opaque-owner-token',
	);
	$claim['disposition_id'] = DataMachine\Core\Database\ProcessedItems\ProcessedItems::disposition_identity( $claim['identity_scope'], $claim['source_type'], $claim['item_identifier'] );
	$engine = new FetchDispositionSmokeEngine(
		array(
			'item_identifier' => 'source-123',
			'source_type'     => 'rss',
			'flow_config'     => array(
				'fetch-step_7' => array( 'step_type' => 'fetch' ),
				'ai-step_7'    => array( 'step_type' => 'ai' ),
			),
			DataMachine\Core\Database\ProcessedItems\ProcessedItems::CLAIM_METADATA_KEY => $claim,
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

	echo "Case 1: reject_source defers claim completion to terminal lifecycle\n";
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
	assert_fetch_disposition_smoke( 'reject_source does not eagerly mark the claim processed', array() === $GLOBALS['fetch_disposition_smoke_processed'] );
	assert_fetch_disposition_smoke( 'reject_source preserves exact safe identity', $claim['disposition_id'] === ( $reject['disposition_id'] ?? '' ) );
	$reject_diagnostic = $GLOBALS['fetch_disposition_smoke_engine'][1814]['packet_dispositions'][ $claim['disposition_id'] ]['diagnostic'] ?? array();
	assert_fetch_disposition_smoke( 'reject_source persists disposition diagnostic', 'reject_source' === ( $reject_diagnostic['disposition'] ?? '' ) && 'duplicate-source' === ( $reject_diagnostic['reason'] ?? '' ) );
	assert_fetch_disposition_smoke( 'reject_source diagnostic includes source identity', 'source-123' === ( $reject_diagnostic['item_identifier'] ?? '' ) && 'fixture-provider' === ( $reject_diagnostic['provider'] ?? '' ) && 'fetch-step_7' === ( $reject_diagnostic['flow_step_id'] ?? '' ) );
	assert_fetch_disposition_smoke( 'reject_source diagnostic includes packet count and bounded excerpt', 1 === ( $reject_diagnostic['packet_count'] ?? 0 ) && 1200 === ( $reject_diagnostic['excerpt_limit'] ?? 0 ) && 1200 >= strlen( $reject_diagnostic['excerpt'] ?? '' ) );
	assert_fetch_disposition_smoke( 'reject_source diagnostic redacts obvious secrets', ! str_contains( $reject_diagnostic['excerpt'] ?? '', 'secret-value' ) && ! str_contains( $reject_diagnostic['source_url'] ?? '', 'secret-value' ) );

	echo "Case 2: defer_item defers claim release to terminal lifecycle\n";
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
	assert_fetch_disposition_smoke( 'defer_item does not eagerly release the claim', array() === $GLOBALS['fetch_disposition_smoke_released'] );
	assert_fetch_disposition_smoke( 'defer_item does not mark processed', array() === $GLOBALS['fetch_disposition_smoke_processed'] );
	assert_fetch_disposition_smoke( 'tool-error deferral remains packet scoped', ! isset( $GLOBALS['fetch_disposition_smoke_engine'][1815]['job_status'] ) );
	$defer_diagnostic = $GLOBALS['fetch_disposition_smoke_engine'][1815]['packet_dispositions'][ $claim['disposition_id'] ]['diagnostic'] ?? array();
	assert_fetch_disposition_smoke( 'defer_item persists disposition diagnostic', 'defer_item' === ( $defer_diagnostic['disposition'] ?? '' ) && 'tool-error' === ( $defer_diagnostic['reason'] ?? '' ) );
	assert_fetch_disposition_smoke( 'first defer records bounded attempt state', 1 === ( $defer['deferral_attempts'] ?? 0 ) && false === ( $defer['exhausted'] ?? true ) );

	echo "Case 2a: third exact-identity deferral exhausts and remains idempotent\n";
	$tool->handle_tool_call( array( 'job_id' => 1818, 'engine' => $engine, 'reason' => 'still unavailable' ), array( 'disposition' => 'defer_item' ) );
	$exhausted = $tool->handle_tool_call( array( 'job_id' => 1819, 'engine' => $engine, 'reason' => 'still unavailable' ), array( 'disposition' => 'defer_item' ) );
	$repeat    = $tool->handle_tool_call( array( 'job_id' => 1819, 'engine' => $engine, 'reason' => 'repeat call' ), array( 'disposition' => 'defer_item' ) );
	assert_fetch_disposition_smoke( 'third defer returns terminal disposition', true === ( $exhausted['success'] ?? false ) && 'defer_exhausted' === ( $exhausted['disposition'] ?? '' ) && 3 === ( $exhausted['deferral_attempts'] ?? 0 ) );
	$exhausted_record = $GLOBALS['fetch_disposition_smoke_engine'][1819]['packet_dispositions'][ $claim['disposition_id'] ] ?? array();
	assert_fetch_disposition_smoke( 'cap persists exact identity exhaustion without a job override', 'defer_exhausted' === ( $exhausted_record['disposition'] ?? '' ) && ! isset( $GLOBALS['fetch_disposition_smoke_engine'][1819]['job_status'], $GLOBALS['fetch_disposition_smoke_engine'][1819]['job_status_reason'] ) );
	assert_fetch_disposition_smoke( 'same-job repeat does not increase count', true === ( $repeat['already_dispositioned'] ?? false ) && 3 === ( $GLOBALS['fetch_disposition_smoke_deferrals']["fetch-step_7\0source-123"]['attempts'] ?? 0 ) );

	echo "Case 2b: reject_source hydrates engine data from job_id when runtime engine is absent\n";
	$GLOBALS['fetch_disposition_smoke_processed']               = array();
	$GLOBALS['fetch_disposition_smoke_persisted_engine'][1816] = array(
		'item_identifier' => 'source-456',
		'source_type'     => 'mcp',
		'flow_config'     => array(
			'fetch-step_8' => array( 'step_type' => 'fetch' ),
			'ai-step_8'    => array( 'step_type' => 'ai' ),
		),
		DataMachine\Core\Database\ProcessedItems\ProcessedItems::CLAIM_METADATA_KEY => array(
			'identity_scope'  => 'fetch-step_8',
			'source_type'     => 'mcp',
			'item_identifier' => 'source-456',
			'ownership_token' => 'opaque-owner-token-456',
		),
	);
	$reject_hydrated = $tool->handle_tool_call(
		array(
			'job_id'       => 1816,
			'flow_step_id' => 'ai-step_8',
			'data'         => $data_packets,
			'reason'       => 'unrelated-subject',
		),
		array( 'disposition' => 'reject_source' )
	);
	assert_fetch_disposition_smoke( 'reject_source succeeds with persisted engine data', true === ( $reject_hydrated['success'] ?? false ) );
	assert_fetch_disposition_smoke( 'persisted engine data still defers claim completion', array() === $GLOBALS['fetch_disposition_smoke_processed'] );

	echo "Case 2c: dispositions are first-write-wins (#2609)\n";
	$GLOBALS['fetch_disposition_smoke_processed'] = array();
	$GLOBALS['fetch_disposition_smoke_released']  = array();
	$defer_after_reject = $tool->handle_tool_call(
		array(
			'job_id'       => 1814,
			'flow_step_id' => 'ai-step_7',
			'engine'       => $engine,
			'data'         => $data_packets,
			'reason'       => 'already rejected; nothing left to do',
		),
		array( 'disposition' => 'defer_item' )
	);
	assert_fetch_disposition_smoke( 'second disposition returns success (no error-retry loop)', true === ( $defer_after_reject['success'] ?? false ) );
	assert_fetch_disposition_smoke( 'second disposition reports already_dispositioned', true === ( $defer_after_reject['already_dispositioned'] ?? false ) );
	assert_fetch_disposition_smoke( 'second disposition message references existing disposition', str_contains( $defer_after_reject['message'] ?? '', 'already dispositioned (source-rejected)' ) );
	assert_fetch_disposition_smoke( 'defer_item does not downgrade source rejection', 'reject_source' === ( $defer_after_reject['disposition'] ?? '' ) );
	assert_fetch_disposition_smoke( 'second disposition preserves original disposition record', 'reject_source' === ( $GLOBALS['fetch_disposition_smoke_engine'][1814]['packet_dispositions'][ $claim['disposition_id'] ]['disposition'] ?? '' ) );
	assert_fetch_disposition_smoke( 'second disposition does not write a second packet record', 1 === count( $GLOBALS['fetch_disposition_smoke_engine'][1814]['packet_dispositions'] ?? array() ) );
	assert_fetch_disposition_smoke( 'second disposition does not release the processed claim', array() === $GLOBALS['fetch_disposition_smoke_released'] );
	assert_fetch_disposition_smoke( 'second disposition does not re-mark processed', array() === $GLOBALS['fetch_disposition_smoke_processed'] );

	$reject_after_defer = $tool->handle_tool_call(
		array(
			'job_id'       => 1815,
			'flow_step_id' => 'ai-step_7',
			'engine'       => $engine,
			'data'         => $data_packets,
			'reason'       => 'second thoughts',
		),
		array( 'disposition' => 'reject_source' )
	);
	assert_fetch_disposition_smoke( 'reject_source after defer_item returns success', true === ( $reject_after_defer['success'] ?? false ) );
	assert_fetch_disposition_smoke( 'reject_source after defer_item reports already_dispositioned', true === ( $reject_after_defer['already_dispositioned'] ?? false ) );
	assert_fetch_disposition_smoke( 'reject_source does not overwrite packet deferral', 'defer_item' === ( $reject_after_defer['disposition'] ?? '' ) );
	assert_fetch_disposition_smoke( 'reject_source after defer does not mark processed', array() === $GLOBALS['fetch_disposition_smoke_processed'] );

	echo "Case 2d: concurrent dispositions preserve the first CAS winner\n";
	$GLOBALS['fetch_disposition_smoke_persisted_engine'][1816] = array(
		DataMachine\Core\Database\ProcessedItems\ProcessedItems::CLAIM_METADATA_KEY => $claim,
		'flow_config' => array( 'fetch-step_7' => array( 'step_type' => 'fetch' ) ),
	);
	$GLOBALS['fetch_disposition_smoke_cas_injection'][1816] = array(
		$claim['disposition_id'] => array( 'disposition' => 'defer_item', 'reason' => 'competing-winner' ),
	);
	$contention = $tool->handle_tool_call(
		array( 'job_id' => 1816, 'reason' => 'losing-rejection', 'engine' => $engine ),
		array( 'disposition' => 'reject_source' )
	);
	assert_fetch_disposition_smoke( 'CAS loser returns idempotent success', true === ( $contention['success'] ?? false ) && true === ( $contention['already_dispositioned'] ?? false ) );
	assert_fetch_disposition_smoke( 'CAS loser cannot overwrite first disposition', 'defer_item' === ( $contention['disposition'] ?? '' ) && 'competing-winner' === ( $contention['reason'] ?? '' ) );

	echo "Case 2e: explicit identities resolve distinct claims in a collection\n";
	$second_claim                    = $claim;
	$second_claim['item_identifier'] = 'source-789';
	$second_claim['disposition_id']  = DataMachine\Core\Database\ProcessedItems\ProcessedItems::disposition_identity( $second_claim['identity_scope'], $second_claim['source_type'], $second_claim['item_identifier'] );
	$collection_engine = new FetchDispositionSmokeEngine(
		array(
			'flow_config' => array( 'fetch-step_7' => array( 'step_type' => 'fetch' ) ),
			DataMachine\Core\Database\ProcessedItems\ProcessedItems::CLAIMS_METADATA_KEY => array( $claim, $second_claim ),
		)
	);
	$collection_reject = $tool->handle_tool_call(
		array( 'job_id' => 1817, 'engine' => $collection_engine, 'reason' => 'first claim', 'disposition_id' => $claim['disposition_id'] ),
		array( 'disposition' => 'reject_source' )
	);
	$collection_defer = $tool->handle_tool_call(
		array( 'job_id' => 1817, 'engine' => $collection_engine, 'reason' => 'second claim', 'disposition_id' => $second_claim['disposition_id'] ),
		array( 'disposition' => 'defer_item' )
	);
	assert_fetch_disposition_smoke( 'reject_source resolves the explicitly selected collection claim', true === ( $collection_reject['success'] ?? false ) && $claim['disposition_id'] === ( $collection_reject['disposition_id'] ?? '' ) );
	assert_fetch_disposition_smoke( 'defer_item resolves the explicitly selected collection claim', true === ( $collection_defer['success'] ?? false ) && $second_claim['disposition_id'] === ( $collection_defer['disposition_id'] ?? '' ) );
	assert_fetch_disposition_smoke( 'collection dispositions remain separate first-write records', 2 === count( $GLOBALS['fetch_disposition_smoke_engine'][1817]['packet_dispositions'] ?? array() ) );

	echo "Case 2f: disposition tools declare terminal completion signal (#2609)\n";
	$fetch_handler_src = file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/FetchHandler.php' );
	assert_fetch_disposition_smoke( 'both disposition tool definitions declare runtime completion_signal terminal', 2 === substr_count( $fetch_handler_src, "'completion_signal' => 'terminal'" ) );

	echo "Case 3: production tool surface exposes positive affordances\n";
	$fetch_handler = file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/Handlers/FetchHandler.php' );
	$disposition   = file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/Tools/FetchItemDispositionTool.php' );
	$lifecycle     = file_get_contents( __DIR__ . '/../inc/Engine/Actions/Handlers/StepLifecycleHandler.php' );
	assert_fetch_disposition_smoke( 'fetch surface exposes reject_source', str_contains( $fetch_handler, "'reject_source'" ) );
	assert_fetch_disposition_smoke( 'fetch surface exposes defer_item', str_contains( $fetch_handler, "'defer_item'" ) );
	assert_fetch_disposition_smoke( 'fetch surface avoids legacy skip tool registration', ! str_contains( $fetch_handler, "'skip_item'" ) );
	assert_fetch_disposition_smoke( 'fetch disposition schemas use canonical root object schemas', 2 === substr_count( $fetch_handler, "'type'       => 'object'" ) );
	assert_fetch_disposition_smoke( 'fetch disposition schemas use object-level required arrays', 2 === substr_count( $fetch_handler, "'required'   => array( 'reason' )" ) );
	assert_fetch_disposition_smoke( 'fetch disposition schemas avoid property-level required flags', ! str_contains( $fetch_handler, "'required'    => true" ) );
	assert_fetch_disposition_smoke( 'reject_source describes reasoned content/source rejection', str_contains( $fetch_handler, 'reasoned content/source evaluation' ) );
	assert_fetch_disposition_smoke( 'defer_item describes safe completion and retry eligibility', str_contains( $fetch_handler, 'cannot safely complete processing now' ) && str_contains( $fetch_handler, 'remain eligible' ) );
	assert_fetch_disposition_smoke( 'terminal lifecycle owns claim completion and release', ! str_contains( $disposition, 'datamachine_mark_item_processed' ) && ! str_contains( $disposition, 'release_claim(' ) && str_contains( $lifecycle, 'complete_owned_claim_in_transaction' ) && str_contains( $lifecycle, 'release_owned_claim(' ) );

	echo "\nFetch item dispositions smoke complete: {$total} assertions, {$failed} failures.\n";
	if ( $failed > 0 ) {
		exit( 1 );
	}
}
