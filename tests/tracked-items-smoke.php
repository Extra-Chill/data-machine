<?php
/**
 * Pure-PHP smoke coverage for durable tracked item surfaces.
 *
 * Run with: php tests/tracked-items-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	$GLOBALS['tracked_items_registered_abilities'] = array();

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

	if ( ! function_exists( 'wp_register_ability' ) ) {
		function wp_register_ability( $name, $args ) {
			$GLOBALS['tracked_items_registered_abilities'][ $name ] = $args;
		}
	}

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
}

namespace DataMachine\Abilities {
	if ( ! class_exists( PermissionHelper::class, false ) ) {
		class PermissionHelper {
			public static function can( string $capability ): bool {
				return true;
			}
		}
	}
}

namespace {
	require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
	require_once __DIR__ . '/../inc/Core/Database/TrackedItems/TrackedItems.php';
	require_once __DIR__ . '/../inc/Abilities/TrackedItemsAbilities.php';

	use DataMachine\Abilities\TrackedItemsAbilities;
	use DataMachine\Core\Database\TrackedItems\TrackedItems;

	class TrackedItemsSmokeRepository extends TrackedItems {
		private array $items = array();
		private int $next_id = 1;

		public function __construct() {}

		public function upsert( array $item ): ?array {
			$key = (string) $item['namespace'] . ':' . (string) $item['item_id'];
			$existing = $this->items[ $key ] ?? array();
			$this->items[ $key ] = array_merge(
				array(
					'id'         => $existing['id'] ?? $this->next_id++,
					'item_type'  => '',
					'state'      => TrackedItems::STATE_DISCOVERED,
					'metadata'   => array(),
					'updated_at' => '2026-05-26 00:00:00',
				),
				$existing,
				$item
			);
			return $this->items[ $key ];
		}

		public function get( string $namespace, string $item_id ): ?array {
			return $this->items[ $namespace . ':' . $item_id ] ?? null;
		}

		public function list( array $filters = array() ): array {
			$items = array_values( $this->items );
			foreach ( array( 'namespace', 'item_type', 'state', 'source_ref', 'output_ref' ) as $filter_key ) {
				$filter_value = (string) ( $filters[ $filter_key ] ?? '' );
				if ( '' === $filter_value ) {
					continue;
				}

				$items = array_values(
					array_filter(
						$items,
						static fn ( array $item ): bool => $filter_value === (string) ( $item[ $filter_key ] ?? '' )
					)
				);
			}

			return $items;
		}

		public function summary( array $filters = array() ): array {
			$items    = $this->list( $filters );
			$by_type  = array();
			$by_state = array();
			foreach ( $items as $item ) {
				$item_type = (string) ( $item['item_type'] ?? '' );
				$state     = (string) ( $item['state'] ?? '' );
				$by_type[ $item_type ][ $state ] = ( $by_type[ $item_type ][ $state ] ?? 0 ) + 1;
				$by_state[ $state ]              = ( $by_state[ $state ] ?? 0 ) + 1;
			}

			return array(
				'total'    => count( $items ),
				'by_type'  => $by_type,
				'by_state' => $by_state,
			);
		}
	}

	$failed = 0;
	$total  = 0;

	function tracked_items_assert( string $name, bool $condition ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  [PASS] {$name}\n";
			return;
		}
		++$failed;
		echo "  [FAIL] {$name}\n";
	}

	echo "=== tracked-items-smoke ===\n";

	$abilities = new TrackedItemsAbilities( new TrackedItemsSmokeRepository() );

	tracked_items_assert( 'upsert ability registered', isset( $GLOBALS['tracked_items_registered_abilities']['datamachine/upsert-tracked-item'] ) );
	tracked_items_assert( 'get ability registered', isset( $GLOBALS['tracked_items_registered_abilities']['datamachine/get-tracked-item'] ) );
	tracked_items_assert( 'list ability registered', isset( $GLOBALS['tracked_items_registered_abilities']['datamachine/list-tracked-items'] ) );
	tracked_items_assert( 'summary ability registered', isset( $GLOBALS['tracked_items_registered_abilities']['datamachine/tracked-items-summary'] ) );
	tracked_items_assert( 'states include excluded', in_array( TrackedItems::STATE_EXCLUDED, TrackedItems::states(), true ) );

	$upsert = $abilities->executeUpsertTrackedItem(
		array(
			'namespace'  => 'wp-docs:core',
			'item_id'    => 'function:wp_register_script_module',
			'item_type'  => 'function',
			'state'      => TrackedItems::STATE_DISCOVERED,
			'source_ref' => 'wordpress-develop',
		)
	);

	tracked_items_assert( 'upsert succeeds', true === ( $upsert['success'] ?? false ) );
	$get = $abilities->executeGetTrackedItem( array( 'namespace' => 'wp-docs:core', 'item_id' => 'function:wp_register_script_module' ) );
	tracked_items_assert( 'get returns tracked item', 'function:wp_register_script_module' === ( $get['item']['item_id'] ?? '' ) );
	tracked_items_assert( 'initial state is discovered', TrackedItems::STATE_DISCOVERED === ( $get['item']['state'] ?? '' ) );

	$transition = $abilities->executeUpsertTrackedItem(
		array(
			'namespace'  => 'wp-docs:core',
			'item_id'    => 'function:wp_register_script_module',
			'item_type'  => 'function',
			'source_ref' => 'wordpress-develop',
			'output_ref' => 'docs/reference/functions/wp-register-script-module.md',
			'state'      => TrackedItems::STATE_GENERATED,
			'metadata'   => array( 'signature' => 'wp_register_script_module()' ),
		)
	);

	tracked_items_assert( 'state transition succeeds', true === ( $transition['success'] ?? false ) );
	tracked_items_assert( 'state transition preserves row identity', ( $upsert['item']['id'] ?? null ) === ( $transition['item']['id'] ?? null ) );
	tracked_items_assert( 'state transition stores generated state', TrackedItems::STATE_GENERATED === ( $transition['item']['state'] ?? '' ) );
	tracked_items_assert( 'state transition stores output ref', 'docs/reference/functions/wp-register-script-module.md' === ( $transition['item']['output_ref'] ?? '' ) );
	$list = $abilities->executeListTrackedItems( array( 'namespace' => 'wp-docs:core' ) );
	tracked_items_assert( 'list returns tracked item', 1 === count( (array) ( $list['items'] ?? array() ) ) );
	$filtered_list = $abilities->executeListTrackedItems( array( 'namespace' => 'wp-docs:core', 'state' => TrackedItems::STATE_EXCLUDED ) );
	tracked_items_assert( 'list filters by state', 0 === count( (array) ( $filtered_list['items'] ?? array() ) ) );
	$summary = $abilities->executeTrackedItemsSummary( array( 'namespace' => 'wp-docs:core' ) );
	tracked_items_assert( 'summary counts tracked item', 1 === (int) ( $summary['total'] ?? 0 ) );
	tracked_items_assert( 'summary groups generated state', 1 === (int) ( $summary['by_state'][ TrackedItems::STATE_GENERATED ] ?? 0 ) );

	if ( $failed > 0 ) {
		echo "\ntracked-items-smoke failed: {$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\ntracked-items-smoke passed: {$total} assertions.\n";
}
