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

		public function __construct() {}

		public function upsert( array $item ): ?array {
			$key = (string) $item['namespace'] . ':' . (string) $item['item_id'];
			$this->items[ $key ] = array_merge(
				array(
					'id'         => count( $this->items ) + 1,
					'item_type'  => '',
					'state'      => TrackedItems::STATE_DISCOVERED,
					'metadata'   => array(),
					'updated_at' => '2026-05-26 00:00:00',
				),
				$item
			);
			return $this->items[ $key ];
		}

		public function get( string $namespace, string $item_id ): ?array {
			return $this->items[ $namespace . ':' . $item_id ] ?? null;
		}

		public function list( array $filters = array() ): array {
			return array_values( $this->items );
		}

		public function summary( array $filters = array() ): array {
			return array(
				'total'    => count( $this->items ),
				'by_type'  => array( 'function' => array( 'generated' => count( $this->items ) ) ),
				'by_state' => array( 'generated' => count( $this->items ) ),
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
			'namespace' => 'wp-docs:core',
			'item_id'   => 'function:wp_register_script_module',
			'item_type' => 'function',
			'state'     => TrackedItems::STATE_GENERATED,
		)
	);

	tracked_items_assert( 'upsert succeeds', true === ( $upsert['success'] ?? false ) );
	$get = $abilities->executeGetTrackedItem( array( 'namespace' => 'wp-docs:core', 'item_id' => 'function:wp_register_script_module' ) );
	tracked_items_assert( 'get returns tracked item', 'function:wp_register_script_module' === ( $get['item']['item_id'] ?? '' ) );
	$list = $abilities->executeListTrackedItems( array( 'namespace' => 'wp-docs:core' ) );
	tracked_items_assert( 'list returns tracked item', 1 === count( (array) ( $list['items'] ?? array() ) ) );
	$summary = $abilities->executeTrackedItemsSummary( array( 'namespace' => 'wp-docs:core' ) );
	tracked_items_assert( 'summary counts tracked item', 1 === (int) ( $summary['total'] ?? 0 ) );

	if ( $failed > 0 ) {
		echo "\ntracked-items-smoke failed: {$failed}/{$total} assertions failed.\n";
		exit( 1 );
	}

	echo "\ntracked-items-smoke passed: {$total} assertions.\n";
}
