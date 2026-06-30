<?php
/**
 * Smoke coverage for content-addressed DataPacket refs.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/datamachine-packet-smoke/' );
}

if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
	class WP_Filesystem_Base {
		public function put_contents( $file, $contents ) {
			return false !== file_put_contents( $file, $contents );
		}

		public function get_contents( $file ) {
			return file_get_contents( $file );
		}

		public function copy( $source, $destination, $overwrite = false ) {
			if ( ! $overwrite && file_exists( $destination ) ) {
				return false;
			}
			return copy( $source, $destination );
		}
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	function WP_Filesystem(): bool {
		global $wp_filesystem;
		$wp_filesystem = new WP_Filesystem_Base();
		return true;
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		$base = sys_get_temp_dir() . '/datamachine-packet-smoke/uploads';
		return array(
			'basedir' => $base,
			'baseurl' => 'http://example.test/uploads',
		);
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $path ): bool {
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ): void {}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook = '' ): int {
		return 0;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = '' ): bool {
		return false;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ): void {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ): void {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, $gmt = 0 ): string {
		return '2026-01-01 00:00:00';
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( ...$args ): int {
		return 1;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		if ( 'datamachine_settings' === $name ) {
			return array( 'queue_tuning' => array( 'chunk_size' => 10, 'chunk_delay' => 0 ) );
		}
		return $default;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! class_exists( 'DataMachine\\Core\\RunMetrics' ) ) {
	eval( 'namespace DataMachine\\Core { class RunMetrics { public static function start( int $job_id, array $context = array() ): bool { return true; } } }' );
}

$engine_snapshots = array();

if ( ! function_exists( 'datamachine_merge_engine_data' ) ) {
	function datamachine_merge_engine_data( int $job_id, array $data ): bool {
		global $engine_snapshots;
		$engine_snapshots[ $job_id ] = array_replace_recursive( $engine_snapshots[ $job_id ] ?? array(), $data );
		return true;
	}
}

require_once __DIR__ . '/../vendor/autoload.php';

use DataMachine\Core\ActionScheduler\BatchScheduler;
use DataMachine\Core\DataPacketStore;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\FilesRepository\FileRetrieval;
use DataMachine\Core\FilesRepository\FileStorage;

function datamachine_packet_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function datamachine_packet_smoke_rm_tree( string $path ): void {
	if ( ! file_exists( $path ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $path );
}

$upload_dir = wp_upload_dir();
datamachine_packet_smoke_rm_tree( $upload_dir['basedir'] );

$packet_a = array(
	'type'      => 'fetch',
	'timestamp' => 111,
	'data'      => array(
		'title' => 'Packet title',
		'body'  => 'Packet body',
	),
	'metadata'  => array(
		'source_type' => 'smoke',
	),
);
$packet_b               = $packet_a;
$packet_b['timestamp'] = 999;

$ref_a = DataPacketStore::store( $packet_a );
$ref_b = DataPacketStore::store( $packet_b );

datamachine_packet_smoke_assert( is_array( $ref_a ) && DataPacketStore::is_ref( $ref_a ), 'store returns packet ref' );
datamachine_packet_smoke_assert( $ref_a['content_hash'] === $ref_b['content_hash'], 'runtime timestamp excluded from content hash' );

$hydrated = DataPacketStore::hydrate( $ref_a );
datamachine_packet_smoke_assert( is_array( $hydrated ), 'ref hydrates' );
datamachine_packet_smoke_assert( ! isset( $hydrated['timestamp'] ), 'hydrated packet is canonical without runtime timestamp' );
datamachine_packet_smoke_assert( $hydrated['data']['title'] === 'Packet title', 'hydrated packet preserves content' );

$context = array(
	'pipeline_id' => 7,
	'flow_id'     => 8,
);
$stored = ( new FileStorage() )->store_data_packet( array( $packet_a ), 42, $context );
datamachine_packet_smoke_assert( is_array( $stored ) && isset( $stored['packet_refs'][0] ), 'job data storage returns packet refs' );

$retrieved = ( new FileRetrieval() )->retrieve_data_by_job_id( 42, $context );
datamachine_packet_smoke_assert( count( $retrieved ) === 1, 'job data retrieval hydrates one packet' );
datamachine_packet_smoke_assert( $retrieved[0]['data']['body'] === 'Packet body', 'legacy data.json read hydrates refs transparently' );

$legacy_job_dir = ( new DirectoryManager() )->get_job_directory( $context['pipeline_id'], $context['flow_id'], 43 );
wp_mkdir_p( $legacy_job_dir );
file_put_contents( $legacy_job_dir . '/data.json', wp_json_encode( array( $packet_a ), JSON_UNESCAPED_SLASHES ) );
$legacy_retrieved = ( new FileRetrieval() )->retrieve_data_by_job_id( 43, $context );
datamachine_packet_smoke_assert( isset( $legacy_retrieved[0]['timestamp'] ), 'legacy raw data.json packet remains readable' );

$batch_result = BatchScheduler::start(
	101,
	'datamachine_packet_smoke_batch',
	array(
		array( 'data_packets' => array( $packet_a ) ),
	),
	array(),
	'packet-smoke',
	BatchScheduler::COMPLETION_STRATEGY_CHUNKS_SCHEDULED
);

global $engine_snapshots;
$batch_item = $engine_snapshots[101]['batch_state']['items'][0] ?? array();
datamachine_packet_smoke_assert( ! empty( $batch_result['parent_job_id'] ), 'batch scheduler starts' );
datamachine_packet_smoke_assert( DataPacketStore::is_ref( $batch_item['data_packets'][0] ?? array() ), 'child batch item stores packet by ref' );

$child_params = DataPacketStore::hydrate_packet_collections_in_value( $batch_item );
datamachine_packet_smoke_assert( $child_params['data_packets'][0]['data']['title'] === 'Packet title', 'child packet ref hydrates deterministically' );

datamachine_packet_smoke_rm_tree( $upload_dir['basedir'] );
fwrite( fopen( 'php://stdout', 'w' ), "content-addressed-data-packets-smoke: ok\n" );
