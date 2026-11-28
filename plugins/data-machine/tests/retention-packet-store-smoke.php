<?php
/**
 * Smoke: retention for the content-addressed DataPacket store
 * (Extra-Chill/data-machine#3938).
 *
 * Covers:
 *  - the bounded, resumable PacketStoreCleanup sweep (age floor, protected
 *    hashes, batch bound + cursor resume, empty prefix-dir removal, dry run),
 *  - RetentionCleanup's live-reference scan against an in-memory SQLite jobs
 *    + batch-items schema (live engine_data, live data.json, batch payloads,
 *    a deduped packet shared by a live and a terminal job, fail-closed),
 *  - cleanupPacketStore() end-to-end (cursor + stats persistence),
 *  - the age-floor clamp above job retention,
 *  - DataPacketStore::store() refreshing mtime on a dedupe hit.
 *
 * Run with: php tests/retention-packet-store-smoke.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	$failures = 0;
	$passes   = 0;

	function assert_packet_retention( string $name, bool $condition ): void {
		global $failures, $passes;
		if ( $condition ) {
			++$passes;
			echo "  PASS {$name}\n";
		} else {
			++$failures;
			echo "  FAIL {$name}\n";
		}
	}

	$GLOBALS['packet_smoke_root']    = sys_get_temp_dir() . '/dm-packet-retention-smoke-' . getmypid();
	$GLOBALS['packet_smoke_filters'] = array();
	$GLOBALS['packet_smoke_options'] = array();
	$GLOBALS['packet_smoke_logs']    = array();

	function apply_filters( string $hook, $value ) {
		return array_key_exists( $hook, $GLOBALS['packet_smoke_filters'] ) ? $GLOBALS['packet_smoke_filters'][ $hook ] : $value;
	}

	function do_action( string $hook, ...$args ): void {
		if ( 'datamachine_log' === $hook ) {
			$GLOBALS['packet_smoke_logs'][] = $args;
		}
	}

	function get_option( string $name, $fallback = false ) {
		return $GLOBALS['packet_smoke_options'][ $name ] ?? $fallback;
	}

	function update_option( string $name, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['packet_smoke_options'][ $name ] = $value;
		return true;
	}

	function wp_upload_dir(): array {
		return array(
			'basedir' => $GLOBALS['packet_smoke_root'] . '/uploads',
			'baseurl' => 'http://example.test/uploads',
		);
	}

	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}

	function wp_delete_file( string $file ): bool {
		return is_file( $file ) && unlink( $file );
	}

	function wp_mkdir_p( string $path ): bool {
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}

	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	class WP_Filesystem_Base {
		public function put_contents( $file, $contents ) {
			return false !== file_put_contents( $file, $contents );
		}

		public function get_contents( $file ) {
			return file_get_contents( $file );
		}
	}

	function WP_Filesystem(): bool {
		$GLOBALS['wp_filesystem'] = new WP_Filesystem_Base();
		return true;
	}

	/** Minimal wpdb over SQLite supporting the prepare()/%i/%d/%s subset retention uses. */
	final class Packet_Smoke_Wpdb {
		public string $prefix     = 'wp_';
		public string $last_error = '';
		public bool $fail_next    = false;
		public \PDO $pdo;

		public function __construct() {
			$this->pdo = new \PDO( 'sqlite::memory:' );
			$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		}

		public function prepare( string $sql, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i = 0;
			return (string) preg_replace_callback(
				'/%[ids]/',
				function ( $m ) use ( &$i, $args ) {
					$value = $args[ $i++ ];
					if ( '%i' === $m[0] ) {
						return '"' . str_replace( '"', '', (string) $value ) . '"';
					}
					if ( '%d' === $m[0] ) {
						return (string) (int) $value;
					}
					return $this->pdo->quote( (string) $value );
				},
				$sql
			);
		}

		public function query( string $sql ) {
			return $this->pdo->exec( $sql );
		}

		public function get_results( string $sql, $output = null ) {
			unset( $output );
			$this->last_error = '';
			if ( $this->fail_next ) {
				$this->fail_next  = false;
				$this->last_error = 'simulated failure';
				return null;
			}
			return $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_ASSOC );
		}
	}

	$GLOBALS['wpdb'] = new Packet_Smoke_Wpdb();
}

namespace DataMachine\Core\Database\BatchItems {
	class BatchItems {
		public const TABLE_NAME = 'datamachine_batch_items';
	}
}

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public const TABLE_NAME = 'datamachine' . '_jobs';
	}
}

namespace {
	$plugin = dirname( __DIR__ );
	require_once $plugin . '/inc/Core/JobStatus.php';
	require_once $plugin . '/inc/Core/FilesRepository/DirectoryManager.php';
	require_once $plugin . '/inc/Core/FilesRepository/FilesystemHelper.php';
	require_once $plugin . '/inc/Core/FilesRepository/PacketStoreCleanup.php';
	require_once $plugin . '/inc/Core/DataPacketStore.php';
	require_once $plugin . '/inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php';

	use DataMachine\Core\DataPacketStore;
	use DataMachine\Core\FilesRepository\PacketStoreCleanup;
	use DataMachine\Engine\AI\System\Tasks\Retention\RetentionCleanup;

	$rrmdir = static function ( string $dir ) use ( &$rrmdir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $entry ) {
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	};

	$store_root = PacketStoreCleanup::store_root();
	$write      = static function ( string $hash, int $age_days ) use ( $store_root ): string {
		$dir = $store_root . '/v1/' . substr( $hash, 0, 2 );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		$path = $dir . '/' . $hash . '.json';
		file_put_contents( $path, '{"packet":{}}' );
		$time = time() - ( $age_days * DAY_IN_SECONDS );
		touch( $path, $time );
		return $path;
	};
	$hash = static fn( string $seed ): string => hash( 'sha256', $seed );

	$rrmdir( $GLOBALS['packet_smoke_root'] );

	// ------------------------------------------------------------------
	echo "[1] sweep: age floor, protected hashes, dry run, prefix dirs\n";
	$old_unref  = $hash( 'old-unreferenced' );
	$old_ref    = $hash( 'old-referenced' );
	$fresh      = $hash( 'fresh' );
	$p_old      = $write( $old_unref, 60 );
	$p_ref      = $write( $old_ref, 60 );
	$p_fresh    = $write( $fresh, 2 );
	$stray_path = $store_root . '/v1/' . substr( $fresh, 0, 2 ) . '/not-a-packet.tmp';
	file_put_contents( $stray_path, 'x' );
	touch( $stray_path, time() - 90 * DAY_IN_SECONDS );

	$sweeper = new PacketStoreCleanup();
	$dry     = $sweeper->sweep( 30, array( $old_ref => true ), 1000, '', true );
	assert_packet_retention( 'dry run reports one eligible file', 1 === $dry['eligible'] );
	assert_packet_retention( 'dry run deletes nothing', 0 === $dry['deleted'] && is_file( $p_old ) );

	$run = $sweeper->sweep( 30, array( $old_ref => true ), 1000 );
	assert_packet_retention( 'old unreferenced packet is deleted', ! is_file( $p_old ) && 1 === $run['deleted'] );
	assert_packet_retention( 'old packet referenced by a live job is kept', is_file( $p_ref ) && 1 === $run['kept_referenced'] );
	assert_packet_retention( 'fresh packet is kept', is_file( $p_fresh ) && 1 === $run['kept_fresh'] );
	assert_packet_retention( 'non-packet files are never touched', is_file( $stray_path ) );
	assert_packet_retention( 'empty prefix dir is removed', ! is_dir( dirname( $p_old ) ) || dirname( $p_old ) === dirname( $p_ref ) || dirname( $p_old ) === dirname( $p_fresh ) );
	assert_packet_retention( 'full walk completes and resets cursor', true === $run['complete'] && '' === $run['cursor'] );
	unlink( $stray_path );

	// ------------------------------------------------------------------
	echo "[2] sweep: batch bound and resumable cursor\n";
	$rrmdir( $store_root );
	$bulk = array();
	for ( $i = 0; $i < 25; $i++ ) {
		$h          = $hash( 'bulk-' . $i );
		$bulk[ $h ] = $write( $h, 60 );
	}
	$cursor  = '';
	$runs    = 0;
	$max_one = 0;
	$deleted = 0;
	do {
		$step    = $sweeper->sweep( 30, array(), 10, $cursor );
		$cursor  = $step['cursor'];
		$max_one = max( $max_one, $step['examined'] );
		$deleted += $step['deleted'];
		++$runs;
	} while ( ! $step['complete'] && $runs < 10 );
	assert_packet_retention( 'no run examines more than the batch limit', $max_one <= 10 );
	assert_packet_retention( 'first run stops at the bound with a cursor', 3 === $runs );
	assert_packet_retention( 'resumed runs delete every eligible file exactly once', 25 === $deleted );
	$remaining = is_dir( $store_root . '/v1' ) ? array_diff( scandir( $store_root . '/v1' ), array( '.', '..' ) ) : array();
	assert_packet_retention( 'all emptied prefix dirs are removed', array() === array_values( $remaining ) );

	$step = $sweeper->sweep( 30, array(), 10, 'v1/zz/../../etc' );
	assert_packet_retention( 'malformed cursor restarts safely', true === $step['complete'] && 0 === $step['examined'] );

	// ------------------------------------------------------------------
	echo "[3] reference scan + cleanupPacketStore end-to-end\n";
	global $wpdb;
	$wpdb->query( 'CREATE TABLE wp_datamachine_jobs (job_id INTEGER PRIMARY KEY, pipeline_id TEXT, flow_id TEXT, status TEXT NOT NULL, engine_data TEXT)' );
	$wpdb->query( 'CREATE TABLE wp_datamachine_batch_items (batch_job_id INTEGER NOT NULL, item_index INTEGER NOT NULL, payload TEXT NOT NULL, PRIMARY KEY (batch_job_id, item_index))' );

	$shared     = $hash( 'shared-deduped' );
	$live_only  = $hash( 'live-engine-data' );
	$data_json  = $hash( 'live-data-json' );
	$batch_ref  = $hash( 'batch-payload' );
	$terminal   = $hash( 'terminal-only' );
	$retryable  = $hash( 'failed-retryable' );
	$orphan     = $hash( 'orphan' );
	$paths      = array();
	foreach ( array( $shared, $live_only, $data_json, $batch_ref, $terminal, $retryable, $orphan ) as $h ) {
		$paths[ $h ] = $write( $h, 90 );
	}
	$ref = static fn( string $h ): string => json_encode(
		array(
			'is_data_packet_ref' => true,
			'schema_version'     => 1,
			'hash_algorithm'     => 'sha256',
			'content_hash'       => $h,
		)
	);
	$wpdb->query( "INSERT INTO wp_datamachine_jobs VALUES (1, '3', '4', 'processing', " . $wpdb->pdo->quote( '{"step_input_packets":{"s":[' . $ref( $shared ) . ',' . $ref( $live_only ) . ']}}' ) . ')' );
	$wpdb->query( "INSERT INTO wp_datamachine_jobs VALUES (2, '3', '4', 'completed', " . $wpdb->pdo->quote( '{"packets":[' . $ref( $shared ) . ',' . $ref( $terminal ) . ']}' ) . ')' );
	$wpdb->query( "INSERT INTO wp_datamachine_jobs VALUES (3, '3', '4', 'waiting', NULL)" );
	$wpdb->query( "INSERT INTO wp_datamachine_jobs VALUES (6, '3', '4', 'failed', " . $wpdb->pdo->quote( '{"step_input_packets":{"s":[' . $ref( $retryable ) . ']}}' ) . ')' );
	$wpdb->query( "INSERT INTO wp_datamachine_jobs VALUES (7, '3', '4', 'failed', NULL)" );
	$wpdb->query( 'INSERT INTO wp_datamachine_batch_items VALUES (9, 0, ' . $wpdb->pdo->quote( '{"data_packets":[' . $ref( $batch_ref ) . ']}' ) . ')' );
	$job_dir = ( new \DataMachine\Core\FilesRepository\DirectoryManager() )->get_job_directory( '3', '4', 3 );
	mkdir( $job_dir, 0777, true );
	file_put_contents( $job_dir . '/data.json', '[' . $ref( $data_json ) . ']' );

	$protected = RetentionCleanup::liveReferencedPacketHashes();
	assert_packet_retention( 'live engine_data refs are protected', isset( $protected[ $live_only ] ) );
	assert_packet_retention( 'shared packet is protected while a live job references it', isset( $protected[ $shared ] ) );
	assert_packet_retention( 'live job data.json refs are protected', isset( $protected[ $data_json ] ) );
	assert_packet_retention( 'batch worklist payload refs are protected', isset( $protected[ $batch_ref ] ) );
	assert_packet_retention( 'terminal-only refs are not protected', ! isset( $protected[ $terminal ] ) );
	assert_packet_retention( 'failed job still carrying engine_data (retryable) is protected', isset( $protected[ $retryable ] ) );

	$GLOBALS['packet_smoke_filters']['datamachine_completed_jobs_max_age_days'] = 14;
	$GLOBALS['packet_smoke_filters']['datamachine_failed_jobs_max_age_days']    = 14;
	$result = RetentionCleanup::cleanupPacketStore();
	assert_packet_retention( 'cleanup task deletes the terminal-only and orphan packets', 2 === $result['deleted'] && ! is_file( $paths[ $terminal ] ) && ! is_file( $paths[ $orphan ] ) );
	assert_packet_retention( 'cleanup task keeps every live-referenced packet', is_file( $paths[ $shared ] ) && is_file( $paths[ $live_only ] ) && is_file( $paths[ $data_json ] ) && is_file( $paths[ $batch_ref ] ) );
	assert_packet_retention( 'cleanup task persists the cursor', '' === get_option( RetentionCleanup::PACKET_STORE_CURSOR_OPTION, 'unset' ) );
	$stats = RetentionCleanup::packetStoreStats();
	assert_packet_retention( 'completed pass records retained file count for retention show', 5 === (int) ( $stats['last_pass']['files'] ?? -1 ) );

	// Once the live job finishes, the shared packet loses its last live reference.
	$wpdb->query( "UPDATE wp_datamachine_jobs SET status = 'completed' WHERE job_id = 1" );
	$result = RetentionCleanup::cleanupPacketStore();
	assert_packet_retention( 'shared packet is deleted after its last live reference ends', ! is_file( $paths[ $shared ] ) && ! is_file( $paths[ $live_only ] ) );
	assert_packet_retention( 'still-live data.json and batch refs remain protected', is_file( $paths[ $data_json ] ) && is_file( $paths[ $batch_ref ] ) );

	echo "[4] fail closed\n";
	$guard           = $hash( 'guard' );
	$paths[ $guard ] = $write( $guard, 90 );
	$wpdb->fail_next = true;
	$result          = RetentionCleanup::cleanupPacketStore();
	assert_packet_retention( 'db error skips the run', true === $result['skipped'] && 0 === $result['deleted'] );
	assert_packet_retention( 'db error deletes nothing', is_file( $paths[ $guard ] ) );

	$GLOBALS['packet_smoke_filters']['datamachine_packet_store_reference_scan_max_rows'] = 1;
	$wpdb->query( "INSERT INTO wp_datamachine_jobs VALUES (4, '3', '4', 'pending', NULL), (5, '3', '4', 'pending', NULL)" );
	$result = RetentionCleanup::cleanupPacketStore();
	assert_packet_retention( 'reference scan over the row cap skips the run', true === $result['skipped'] && is_file( $paths[ $guard ] ) );
	unset( $GLOBALS['packet_smoke_filters']['datamachine_packet_store_reference_scan_max_rows'] );

	// ------------------------------------------------------------------
	echo "[5] age floor\n";
	assert_packet_retention( 'default floor is job retention + 7 day margin', 21 === RetentionCleanup::packetStoreMaxAgeDays() );
	$GLOBALS['packet_smoke_filters']['datamachine_packet_store_max_age_days'] = 3;
	assert_packet_retention( 'filter cannot lower the floor to job retention', 15 === RetentionCleanup::packetStoreMaxAgeDays() );
	$GLOBALS['packet_smoke_filters']['datamachine_packet_store_max_age_days'] = 60;
	assert_packet_retention( 'filter can widen the floor', 60 === RetentionCleanup::packetStoreMaxAgeDays() );
	unset( $GLOBALS['packet_smoke_filters']['datamachine_packet_store_max_age_days'] );
	$GLOBALS['packet_smoke_filters']['datamachine_packet_store_batch_size'] = 5;
	assert_packet_retention( 'batch size is clamped to a sane floor', 100 === RetentionCleanup::packetStoreBatchSize() );
	unset( $GLOBALS['packet_smoke_filters']['datamachine_packet_store_batch_size'] );

	// ------------------------------------------------------------------
	echo "[6] DataPacketStore refreshes mtime on dedupe\n";
	$packet  = array( 'type' => 'smoke', 'data' => array( 'body' => 'dedupe me' ) );
	$ref_one = DataPacketStore::store( $packet );
	$path    = (string) $ref_one['file_path'];
	touch( $path, time() - 120 * DAY_IN_SECONDS );
	clearstatcache( true, $path );
	$ref_two = DataPacketStore::store( $packet );
	clearstatcache( true, $path );
	assert_packet_retention( 'dedupe returns the same content hash', $ref_one['content_hash'] === $ref_two['content_hash'] );
	assert_packet_retention( 'dedupe hit refreshes mtime so the packet ages from its latest use', filemtime( $path ) >= time() - 60 );
	assert_packet_retention( 'refreshed packet still hydrates', is_array( DataPacketStore::hydrate( $ref_two ) ) );

	$rrmdir( $GLOBALS['packet_smoke_root'] );

	echo $failures ? "\nFAILED: {$failures} packet-store retention assertions failed.\n" : "\nAll {$passes} packet-store retention assertions passed.\n";
	exit( $failures ? 1 : 0 );
}
