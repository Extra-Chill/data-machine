<?php
/**
 * Pure-PHP smoke for the pageable source aggregation primitive (#1611).
 *
 * Run with: php tests/source-aggregate-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code, private string $message, private mixed $data = null ) {}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
			public function get_error_data(): mixed { return $this->data; }
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ): bool {
			return $value instanceof WP_Error;
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
			return json_encode( $data, $flags, $depth );
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			return $text;
		}
	}

	if ( ! function_exists( 'doing_action' ) ) {
		function doing_action( $hook ) {
			return 'wp_abilities_api_init' === $hook;
		}
	}

	if ( ! function_exists( 'did_action' ) ) {
		function did_action( $hook ) {
			return 'wp_abilities_api_init' === $hook ? 1 : 0;
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( $hook, $callback ) {}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook, $value ) {
			if ( 'datamachine_source_aggregate_page_callback' === $hook && isset( $GLOBALS['source_aggregate_page_callback'] ) ) {
				return $GLOBALS['source_aggregate_page_callback'];
			}
			return $value;
		}
	}

	$GLOBALS['source_aggregate_registered_abilities'] = array();
	if ( ! function_exists( 'wp_register_ability' ) ) {
		function wp_register_ability( $name, $args ) {
			$GLOBALS['source_aggregate_registered_abilities'][ $name ] = $args;
		}
	}
}

namespace DataMachine\Abilities {
	if ( ! class_exists( PermissionHelper::class, false ) ) {
		class PermissionHelper {
			public static function can_manage(): bool {
				return true;
			}
		}
	}
}

namespace {
	require_once __DIR__ . '/../inc/Core/SourceAggregation/PageableSourceAggregator.php';
	require_once __DIR__ . '/../inc/Abilities/AbilityRegistration.php';
	require_once __DIR__ . '/../inc/Abilities/SourceAggregateAbility.php';

	use DataMachine\Abilities\SourceAggregateAbility;
	use DataMachine\Core\SourceAggregation\PageableSourceAggregator;

	$failed = 0;
	$total  = 0;

	function assert_source_aggregate( string $name, bool $condition, string $detail = '' ): void {
		global $failed, $total;
		++$total;

		if ( $condition ) {
			echo "  [PASS] {$name}\n";
			return;
		}

		++$failed;
		echo "  [FAIL] {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	}

	function source_aggregate_failed_count(): int {
		global $failed;
		return $failed;
	}

	echo "=== source-aggregate-smoke ===\n";

	$pages = array(
		array(
			'total'   => 5,
			'tickets' => array(
				array( 'id' => 1, 'component' => 'Editor', 'status' => 'open', 'type' => 'defect', 'owner' => '', 'meta' => array( 'year' => 2021 ) ),
				array( 'id' => 2, 'component' => 'Editor', 'status' => 'closed', 'type' => 'task', 'owner' => 'alice', 'meta' => array( 'year' => 2022 ) ),
			),
		),
		array(
			'total'   => 5,
			'tickets' => array(
				array( 'id' => 3, 'component' => 'Media', 'status' => 'open', 'type' => 'defect', 'owner' => '', 'meta' => array( 'year' => 2021 ) ),
				array( 'id' => 4, 'component' => 'Editor', 'status' => 'open', 'type' => 'defect', 'owner' => 'bob', 'meta' => array( 'year' => 2023 ) ),
			),
		),
		array(
			'total'   => 5,
			'tickets' => array(
				array( 'id' => 5, 'component' => 'Media', 'status' => 'open', 'type' => 'enhancement', 'owner' => '', 'meta' => array( 'year' => 2024 ) ),
			),
		),
	);

	$seen_params = array();
	$aggregator  = new PageableSourceAggregator();
	$result      = $aggregator->aggregate(
		function ( array $params, array $state ) use ( $pages, &$seen_params ): array {
			$seen_params[] = $params;
			return $pages[ (int) $state['page_index'] ] ?? array();
		},
		array(
			'pagination' => array(
				'limit'        => 2,
				'item_path'    => 'tickets',
				'total_path'   => 'total',
				'offset_param' => 'start',
				'limit_param'  => 'rows',
			),
			'group_by'                => array( 'component', 'status', 'type', 'owner', 'meta.year' ),
			'sample_limit_per_bucket' => 1,
			'max_items'               => 10,
			'max_pages'               => 10,
		)
	);

	assert_source_aggregate( 'processes all fake-source items', 5 === $result['processed'] );
	assert_source_aggregate( 'reports total from dotted configured page path', 5 === $result['total'] );
	assert_source_aggregate( 'uses three pages including short final page', 3 === $result['pages'] );
	assert_source_aggregate( 'stops after short page once total reached', 'total_reached' === $result['diagnostics']['stop_reason'] );
	assert_source_aggregate( 'passes custom offset param to callback', array( 0, 2, 4 ) === array_column( $seen_params, 'start' ) );
	assert_source_aggregate( 'passes custom limit param to callback', array( 2, 2, 2 ) === array_column( $seen_params, 'rows' ) );
	assert_source_aggregate( 'counts component buckets', 3 === $result['groups']['component']['Editor'] && 2 === $result['groups']['component']['Media'] );
	assert_source_aggregate( 'counts dotted field buckets', 2 === $result['groups']['meta.year']['2021'] );
	assert_source_aggregate( 'normalizes missing owner bucket', 3 === $result['groups']['owner']['(missing)'] );
	assert_source_aggregate( 'keeps first sample per bucket', 1 === count( $result['samples']['component']['Editor'] ) && 1 === ( $result['samples']['component']['Editor'][0]['id'] ?? 0 ) );

	$limited = $aggregator->aggregate(
		fn( array $params, array $state ): array => $pages[ (int) $state['page_index'] ] ?? array(),
		array(
			'pagination' => array( 'limit' => 2, 'item_path' => 'tickets', 'total_path' => 'total' ),
			'group_by'   => array( 'component' ),
			'max_items'  => 3,
		)
	);

	assert_source_aggregate( 'max_items bounds processing', 3 === $limited['processed'] );
	assert_source_aggregate( 'max_items stop reason is explicit', 'max_items' === $limited['diagnostics']['stop_reason'] );

	$ability = new SourceAggregateAbility();
	assert_source_aggregate( 'ability registers datamachine/source-aggregate', isset( $GLOBALS['source_aggregate_registered_abilities']['datamachine/source-aggregate'] ) );
	assert_source_aggregate( 'ability category is fetch', 'datamachine-fetch' === ( $GLOBALS['source_aggregate_registered_abilities']['datamachine/source-aggregate']['category'] ?? null ) );

	$ability_result = $ability->execute(
		array(
			'source'     => array( 'kind' => 'static_pages', 'pages' => $pages ),
			'pagination' => array( 'limit' => 2, 'item_path' => 'tickets', 'total_path' => 'total' ),
			'group_by'   => array( 'component' ),
		)
	);

	assert_source_aggregate( 'ability executes static page source', true === ( $ability_result['success'] ?? false ) && 5 === ( $ability_result['processed'] ?? 0 ) );

	$page_error = new WP_Error(
		'upstream_page_failed',
		'Upstream page failed.',
		array(
			'status'      => 429,
			'diagnostics' => array( 'request_id' => 'aggregate-smoke' ),
		)
	);
	$GLOBALS['source_aggregate_page_callback'] = static fn(): WP_Error => $page_error;
	$failed_aggregate = $ability->execute(
		array(
			'source'     => array( 'kind' => 'remote' ),
			'pagination' => array( 'limit' => 2, 'item_path' => 'tickets' ),
		)
	);
	unset( $GLOBALS['source_aggregate_page_callback'] );

	assert_source_aggregate( 'ability returns page callback WP_Error unchanged', $page_error === $failed_aggregate );
	assert_source_aggregate( 'page callback error preserves code and status', 'upstream_page_failed' === $failed_aggregate->get_error_code() && 429 === ( $failed_aggregate->get_error_data()['status'] ?? null ) );
	assert_source_aggregate( 'page callback error does not become invalid_page success', is_wp_error( $failed_aggregate ) );

	$unsupported = $ability->execute(
		array(
			'source'     => array( 'kind' => 'mcp', 'provider' => 'trac' ),
			'pagination' => array( 'limit' => 2, 'item_path' => 'tickets' ),
		)
	);

	assert_source_aggregate( 'unsupported live source fails clearly', is_wp_error( $unsupported ) && 'source_aggregate_executor_missing' === $unsupported->get_error_code() );

	if ( source_aggregate_failed_count() > 0 ) {
		echo "\nsource-aggregate-smoke failed: {$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nsource-aggregate-smoke passed: {$total} assertions.\n";
}
