<?php
/**
 * Pure-PHP smoke test for the flow_config pre-save filter.
 *
 * Run with: php tests/flow-config-pre-save-filter-smoke.php
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['datamachine_flow_config_pre_save_filters'] = array();
$GLOBALS['datamachine_flow_config_pre_save_logs']    = array();

class WP_Error {
	private string $message;

	public function __construct( string $code, string $message ) {
		unset( $code );
		$this->message = $message;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
    	unset( $priority, $accepted_args );
    	$GLOBALS['datamachine_flow_config_pre_save_filters'][ $hook_name ][] = $callback;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $hook_name, $value, ...$args ) {
    	foreach ( $GLOBALS['datamachine_flow_config_pre_save_filters'][ $hook_name ] ?? array() as $callback ) {
    		$value = $callback( $value, ...$args );
    	}

    	return $value;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( string $hook_name, ...$args ): void {
    	$GLOBALS['datamachine_flow_config_pre_save_logs'][] = array( $hook_name, $args );
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $value ): bool {
    	return $value instanceof WP_Error;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value ): string {
    	return json_encode( $value );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $value ): string {
    	return trim( (string) $value );
    }
}

if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $value ): string {
    	return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', trim( (string) $value ) ) );
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $value ): int {
    	return max( 0, (int) $value );
    }
}

final class DataMachineFlowConfigPreSaveFakeWpdb {
	public string $prefix     = 'wp_';
	public int $insert_id     = 123;
	public string $last_error = '';
	public array $inserted    = array();
	public array $updated     = array();

	public function insert( string $table, array $data, array $formats ) {
		$this->inserted[] = compact( 'table', 'data', 'formats' );
		return 1;
	}

	public function update( string $table, array $data, array $where, array $formats, array $where_formats ) {
		$this->updated[] = compact( 'table', 'data', 'where', 'formats', 'where_formats' );
		return 1;
	}
}

$GLOBALS['wpdb'] = new DataMachineFlowConfigPreSaveFakeWpdb();

require_once dirname( __DIR__ ) . '/inc/Core/Database/BaseRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/Database/Flows/Flows.php';

use DataMachine\Core\Database\Flows\Flows;

function datamachine_flow_config_pre_save_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL {$message}\n" );
		exit( 1 );
	}

	echo "PASS {$message}\n";
}

echo "flow-config-pre-save-filter-smoke\n";

$flows = new Flows();

add_filter(
	'datamachine_flow_config_pre_save',
	static function ( array $flow_config, int $flow_id, array $flow_data ) {
		$flow_config['step_one']['handler_configs']['upsert_event']['taxonomy_location_selection'] = 'derived';
		$flow_config['step_one']['seen_flow_id'] = $flow_id;
		$flow_config['step_one']['seen_flow_name'] = $flow_data['flow_name'] ?? '';
		return $flow_config;
	},
	10,
	3
);

$created = $flows->create_flow(
	array(
		'pipeline_id'       => 10,
		'flow_name'         => ' Event Flow ',
		'flow_config'       => array(
			'step_one' => array(
				'handler_configs' => array(
					'upsert_event' => array( 'taxonomy_location_selection' => 'ai_decides' ),
				),
			),
		),
		'scheduling_config' => array(),
	)
);

datamachine_flow_config_pre_save_assert( 123 === $created, 'create_flow returns the inserted flow ID' );
$created_config = json_decode( $GLOBALS['wpdb']->inserted[0]['data']['flow_config'], true );
datamachine_flow_config_pre_save_assert( 'derived' === $created_config['step_one']['handler_configs']['upsert_event']['taxonomy_location_selection'], 'create_flow persists filtered flow_config' );
datamachine_flow_config_pre_save_assert( 0 === $created_config['step_one']['seen_flow_id'], 'create_flow passes flow_id 0 to the pre-save filter' );

$updated = $flows->update_flow(
	456,
	array(
		'flow_name'   => 'Updated Flow',
		'flow_config' => array( 'step_one' => array() ),
	)
);

datamachine_flow_config_pre_save_assert( true === $updated, 'update_flow succeeds when the filter returns an array' );
$updated_config = json_decode( $GLOBALS['wpdb']->updated[0]['data']['flow_config'], true );
datamachine_flow_config_pre_save_assert( 456 === $updated_config['step_one']['seen_flow_id'], 'update_flow passes the persisted flow ID to the pre-save filter' );
datamachine_flow_config_pre_save_assert( 'Updated Flow' === $updated_config['step_one']['seen_flow_name'], 'update_flow passes the full update payload to the pre-save filter' );

$GLOBALS['datamachine_flow_config_pre_save_filters']['datamachine_flow_config_pre_save'] = array(
	static fn() => new WP_Error( 'invalid_flow_config', 'location must be derived' ),
);

$before_update_count = count( $GLOBALS['wpdb']->updated );
$rejected            = $flows->update_flow( 789, array( 'flow_config' => array( 'step_one' => array() ) ) );

datamachine_flow_config_pre_save_assert( false === $rejected, 'update_flow rejects WP_Error filter results' );
datamachine_flow_config_pre_save_assert( $before_update_count === count( $GLOBALS['wpdb']->updated ), 'rejected flow_config is not written' );
$last_log = end( $GLOBALS['datamachine_flow_config_pre_save_logs'] );
datamachine_flow_config_pre_save_assert( 'location must be derived' === ( $last_log[1][2]['reason'] ?? '' ), 'rejection reason is logged' );

echo "All flow config pre-save filter assertions passed.\n";
