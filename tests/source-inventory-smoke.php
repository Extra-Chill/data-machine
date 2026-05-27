<?php
/**
 * Pure-PHP smoke for source inventory capability profiling.
 *
 * Run with: php tests/source-inventory-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	$GLOBALS['source_inventory_filters']              = array();
	$GLOBALS['source_inventory_registered_abilities'] = array();

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
			return json_encode( $data, $flags, $depth );
		}
	}

	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $text ) {
			return strip_tags( (string) $text );
		}
	}

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type, $gmt = false ) {
			return gmdate( 'Y-m-d H:i:s' );
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

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
			$GLOBALS['source_inventory_filters'][ $hook ][ $priority ][] = compact( 'callback', 'accepted_args' );
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook, $value, ...$args ) {
			if ( empty( $GLOBALS['source_inventory_filters'][ $hook ] ) ) {
				return $value;
			}

			ksort( $GLOBALS['source_inventory_filters'][ $hook ] );
			foreach ( $GLOBALS['source_inventory_filters'][ $hook ] as $callbacks ) {
				foreach ( $callbacks as $entry ) {
					$accepted = (int) $entry['accepted_args'];
					$value    = call_user_func_array( $entry['callback'], array_slice( array_merge( array( $value ), $args ), 0, $accepted ) );
				}
			}

			return $value;
		}
	}

	if ( ! function_exists( 'wp_register_ability' ) ) {
		function wp_register_ability( $name, $args ) {
			$GLOBALS['source_inventory_registered_abilities'][ $name ] = $args;
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
	require_once __DIR__ . '/../inc/Core/SourceAggregation/SourceInventoryProfiler.php';
	require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
	require_once __DIR__ . '/../inc/Core/Database/TrackedItems/TrackedItems.php';
	require_once __DIR__ . '/../inc/Abilities/SourceInventoryAbility.php';

	use DataMachine\Abilities\SourceInventoryAbility;
	use DataMachine\Core\Database\TrackedItems\TrackedItems;
	use DataMachine\Core\SourceAggregation\SourceInventoryProfiler;

	class SourceInventoryTrackedItemsSmokeRepository extends TrackedItems {
		public array $items = array();

		public function __construct() {}

		public function upsert( array $item ): ?array {
			$key = (string) $item['namespace'] . ':' . (string) $item['item_id'];
			$this->items[ $key ] = array_merge(
				array(
					'id'       => count( $this->items ) + 1,
					'metadata' => array(),
				),
				$this->items[ $key ] ?? array(),
				$item
			);

			return $this->items[ $key ];
		}
	}

	$failed = 0;
	$total  = 0;

	function assert_source_inventory( string $name, bool $condition, string $detail = '' ): void {
		global $failed, $total;
		++$total;

		if ( $condition ) {
			echo "  [PASS] {$name}\n";
			return;
		}

		++$failed;
		echo "  [FAIL] {$name}" . ( $detail ? " - {$detail}" : '' ) . "\n";
	}

	function source_inventory_failed_count(): int {
		global $failed;
		return $failed;
	}

	echo "=== source-inventory-smoke ===\n";

	$profiler = new SourceInventoryProfiler();
	$profile  = $profiler->profile(
		array(
			'kind'         => 'search',
			'provider'     => 'github',
			'capabilities' => array(
				'has-total-count'       => true,
				'supports-time-windows' => true,
				'stable-ids'            => true,
			),
		)
	);

	assert_source_inventory( 'counted source gets counted_search mode', 'counted_search' === $profile['coverage_mode'] );
	assert_source_inventory( 'counted source uses reported total metric', 'reported_total_count' === $profile['metric'] );
	assert_source_inventory( 'counted source has denominator', true === $profile['has_denominator'] );
	assert_source_inventory( 'capability keys normalize to underscores', true === $profile['capabilities']['has_total_count'] );

	$inventory = $profiler->profile(
		array(
			'kind'         => 'calendar',
			'provider'     => 'events',
			'capabilities' => array(
				'can_enumerate'     => true,
				'stable_ids'        => true,
				'has_stable_cursor' => true,
			),
		)
	);
	assert_source_inventory( 'enumerable source gets inventory mode', 'inventory' === $inventory['coverage_mode'] );
	assert_source_inventory( 'inventory source has high inferred confidence', 'high' === $inventory['confidence'] );
	assert_source_inventory( 'inventory source denominator is reliable', true === $inventory['denominator_reliable'] );

	$discovery = $profiler->profile(
		array(
			'kind'         => 'search',
			'provider'     => 'mgs',
			'capabilities' => array(
				'supports_query_shards' => true,
			),
		)
	);
	assert_source_inventory( 'sharded search gets sampled discovery mode', 'sampled_discovery' === $discovery['coverage_mode'] );
	assert_source_inventory( 'sampled discovery uses yield metric', 'marginal_yield_saturation' === $discovery['metric'] );

	add_filter(
		'datamachine_source_inventory_capabilities',
		static function ( array $capabilities, array $source ): array {
			if ( 'filtered' === ( $source['provider'] ?? '' ) ) {
				$capabilities['coverage_mode'] = 'bounded_window';
				$capabilities['confidence']    = 'medium';
			}

			return $capabilities;
		},
		10,
		2
	);

	$filtered = $profiler->profile( array( 'provider' => 'filtered' ) );
	assert_source_inventory( 'capability filter can provide provider facts', 'bounded_window' === $filtered['coverage_mode'] );
	assert_source_inventory( 'capability filter confidence is preserved', 'medium' === $filtered['confidence'] );

	$pages = array(
		array(
			'total' => 3,
			'items' => array(
				array( 'id' => 1, 'venue' => 'A' ),
				array( 'id' => 2, 'venue' => 'A' ),
			),
		),
		array(
			'total' => 3,
			'items' => array(
				array( 'id' => 3, 'venue' => 'B' ),
			),
		),
	);

	$tracked_items = new SourceInventoryTrackedItemsSmokeRepository();
	$ability       = new SourceInventoryAbility( $tracked_items );
	assert_source_inventory( 'ability registers datamachine/source-inventory', isset( $GLOBALS['source_inventory_registered_abilities']['datamachine/source-inventory'] ) );

	$result = $ability->execute(
		array(
			'source'     => array(
				'kind'         => 'static_pages',
				'provider'     => 'fixture',
				'pages'        => $pages,
				'capabilities' => array(
					'can_enumerate' => true,
					'stable_ids'    => true,
				),
			),
			'scan'       => true,
			'pagination' => array( 'limit' => 2, 'item_path' => 'items', 'total_path' => 'total' ),
			'group_by'   => array( 'venue' ),
		)
	);

	assert_source_inventory( 'ability execute succeeds', true === ( $result['success'] ?? false ) );
	assert_source_inventory( 'ability includes inventory profile', 'inventory' === ( $result['profile']['coverage_mode'] ?? '' ) );
	assert_source_inventory( 'ability scan processes static pages', 3 === ( $result['scan']['processed'] ?? 0 ) );
	assert_source_inventory( 'ability scan reports total denominator', 3 === ( $result['scan']['total'] ?? 0 ) );
	assert_source_inventory( 'ability scan groups item fields', 2 === ( $result['scan']['groups']['venue']['A'] ?? 0 ) );

	$tracked = $ability->execute(
		array(
			'source'      => array(
				'kind'  => 'static_pages',
				'pages' => array(
					array(
						'items' => array(
							array( 'id' => 'wp_register_script_module', 'kind' => 'function', 'path' => 'src/wp-includes/script-modules.php', 'line' => 42 ),
							array( 'id' => 'WP_Script_Modules', 'kind' => 'class', 'path' => 'src/wp-includes/class-wp-script-modules.php', 'line' => 14 ),
						),
					),
				),
			),
			'scan'        => true,
			'pagination'  => array( 'limit' => 10, 'item_path' => 'items' ),
			'track_items' => array(
				'namespace'        => 'wp-docs:core-script-modules',
				'item_id_path'     => 'id',
				'item_type_path'   => 'kind',
				'state'            => TrackedItems::STATE_DISCOVERED,
				'source_ref'       => 'wordpress-develop',
				'source_path_path' => 'path',
				'source_line_path' => 'line',
			),
		)
	);
	assert_source_inventory( 'tracked scan succeeds', true === ( $tracked['success'] ?? false ) );
	assert_source_inventory( 'tracked scan reports upserts', 2 === ( $tracked['scan']['tracked_items']['succeeded'] ?? 0 ) );
	assert_source_inventory( 'tracked scan writes first item', isset( $tracked_items->items['wp-docs:core-script-modules:wp_register_script_module'] ) );
	assert_source_inventory( 'tracked scan maps source path', 'src/wp-includes/script-modules.php' === ( $tracked_items->items['wp-docs:core-script-modules:wp_register_script_module']['source_path'] ?? '' ) );

	$unsupported = $ability->execute(
		array(
			'source' => array( 'kind' => 'live' ),
			'scan'   => true,
		)
	);
	assert_source_inventory( 'unsupported scan fails inside scan result only', true === ( $unsupported['success'] ?? false ) && false === ( $unsupported['scan']['success'] ?? true ) );

	if ( source_inventory_failed_count() > 0 ) {
		echo "\nsource-inventory-smoke failed: {$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\nsource-inventory-smoke passed: {$total} assertions.\n";
}
