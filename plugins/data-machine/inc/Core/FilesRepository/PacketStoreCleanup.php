<?php
/**
 * Bounded, resumable retention sweep for the content-addressed DataPacket store.
 *
 * The store lives at `datamachine-files/data-packets/v<schema>/<prefix>/<sha256>.json`
 * and is deduplicated: one packet file can be shared by many jobs. Nothing
 * else removes these files (FileCleanup only walks pipeline/flow/job
 * directories), so this sweep is the store's only retention path
 * (Extra-Chill/data-machine#3938).
 *
 * Safety contract — a packet file is deleted only when ALL of these hold:
 *  1. Its file name is a well-formed `<64 hex>.json` content hash.
 *  2. Its hash is not in the caller-supplied protected set (hashes still
 *     referenced by live, non-terminal jobs and pending batch worklists).
 *  3. Its mtime is older than the caller-supplied age floor. DataPacketStore
 *     refreshes mtime whenever a write dedupes onto an existing file, so mtime
 *     tracks the most recent job that referenced the packet, not its first
 *     write.
 *
 * The walk is bounded by a per-run file budget and resumable through an
 * opaque cursor (`v1/ab/<hash>.json`), so a large backlog is cleared across
 * many runs without one pass stat()ing a million files.
 *
 * This class is filesystem-only and side-effect free beyond the deletes it
 * reports; reference collection and cursor persistence live in
 * RetentionCleanup.
 *
 * @package DataMachine\Core\FilesRepository
 */

namespace DataMachine\Core\FilesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PacketStoreCleanup {

	/** Directory under the repository root that holds the packet store. */
	public const STORE_DIR = 'data-packets';

	/** Packet file name: lowercase SHA-256 hex digest plus `.json`. */
	private const PACKET_FILE_PATTERN = '/^([a-f0-9]{64})\.json$/';

	/** Schema directory name, e.g. `v1`. */
	private const SCHEMA_DIR_PATTERN = '/^v\d+$/';

	/** Prefix directory name: the first two hex characters of the hash. */
	private const PREFIX_DIR_PATTERN = '/^[a-f0-9]{2}$/';

	/**
	 * Absolute path to the packet store root.
	 *
	 * @return string Store root (`.../datamachine-files/data-packets`).
	 */
	public static function store_root(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'datamachine-files/' . self::STORE_DIR;
	}

	/**
	 * Run one bounded sweep step.
	 *
	 * @param int                 $max_age_days     Files with mtime newer than this are kept.
	 * @param array<string,mixed> $protected_hashes Hashes that must be kept, as array keys.
	 * @param int                 $batch_limit      Maximum packet files examined in this run.
	 * @param string              $cursor           Resume point from the previous run ('' = start).
	 * @param bool                $dry_run          Count eligible files without deleting.
	 * @param string|null         $root             Store root override (tests).
	 * @return array{examined:int,deleted:int,eligible:int,kept_referenced:int,kept_fresh:int,failed:int,bytes_deleted:int,bytes_kept:int,removed_dirs:int,cursor:string,complete:bool,cutoff:int}
	 */
	public function sweep( int $max_age_days, array $protected_hashes, int $batch_limit, string $cursor = '', bool $dry_run = false, ?string $root = null ): array {
		$root        = null === $root ? self::store_root() : rtrim( $root, '/\\' );
		$batch_limit = max( 1, $batch_limit );
		$cutoff      = time() - ( max( 1, $max_age_days ) * DAY_IN_SECONDS );

		$result = array(
			'examined'        => 0,
			'deleted'         => 0,
			'eligible'        => 0,
			'kept_referenced' => 0,
			'kept_fresh'      => 0,
			'failed'          => 0,
			'bytes_deleted'   => 0,
			'bytes_kept'      => 0,
			'removed_dirs'    => 0,
			'cursor'          => $cursor,
			'complete'        => false,
			'cutoff'          => $cutoff,
		);

		if ( ! is_dir( $root ) ) {
			$result['cursor']   = '';
			$result['complete'] = true;
			return $result;
		}

		list( $cursor_schema, $cursor_prefix, $cursor_file ) = self::parse_cursor( $cursor );

		foreach ( self::list_entries( $root, self::SCHEMA_DIR_PATTERN, true ) as $schema ) {
			if ( '' !== $cursor_schema && strcmp( $schema, $cursor_schema ) < 0 ) {
				continue;
			}
			$schema_dir = $root . '/' . $schema;

			foreach ( self::list_entries( $schema_dir, self::PREFIX_DIR_PATTERN, true ) as $prefix ) {
				$resuming_here = $schema === $cursor_schema && '' !== $cursor_prefix;
				if ( $resuming_here && strcmp( $prefix, $cursor_prefix ) < 0 ) {
					continue;
				}
				$prefix_dir = $schema_dir . '/' . $prefix;

				foreach ( self::list_entries( $prefix_dir, self::PACKET_FILE_PATTERN, false ) as $file ) {
					if ( $resuming_here && $prefix === $cursor_prefix && '' !== $cursor_file && strcmp( $file, $cursor_file ) <= 0 ) {
						continue;
					}

					if ( $result['examined'] >= $batch_limit ) {
						// Budget spent: the cursor already points at the last
						// examined file, so the next run resumes right here.
						self::maybe_remove_empty_dir( $prefix_dir, $dry_run, $result );
						return $result;
					}

					++$result['examined'];
					$result['cursor'] = $schema . '/' . $prefix . '/' . $file;
					$this->evaluate_file( $prefix_dir . '/' . $file, substr( $file, 0, 64 ), $cutoff, $protected_hashes, $dry_run, $result );
				}

				self::maybe_remove_empty_dir( $prefix_dir, $dry_run, $result );
			}
		}

		// Walked to the end of the store: the next run starts a fresh pass.
		$result['cursor']   = '';
		$result['complete'] = true;
		return $result;
	}

	/**
	 * Decide and apply the fate of one packet file.
	 *
	 * @param string              $path             Absolute file path.
	 * @param string              $hash             Content hash parsed from the file name.
	 * @param int                 $cutoff           Unix timestamp age floor.
	 * @param array<string,mixed> $protected_hashes Protected hashes as keys.
	 * @param bool                $dry_run          Whether to skip deletion.
	 * @param array{examined:int,deleted:int,eligible:int,kept_referenced:int,kept_fresh:int,failed:int,bytes_deleted:int,bytes_kept:int,removed_dirs:int,cursor:string,complete:bool,cutoff:int} $result Running result (by reference).
	 * @param-out array{examined:int,deleted:int,eligible:int,kept_referenced:int,kept_fresh:int,failed:int,bytes_deleted:int,bytes_kept:int,removed_dirs:int,cursor:string,complete:bool,cutoff:int} $result
	 */
	private function evaluate_file( string $path, string $hash, int $cutoff, array $protected_hashes, bool $dry_run, array &$result ): void {
		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- File may vanish between scandir and stat.
		$size  = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- File may vanish between scandir and stat.
		$size  = false === $size ? 0 : (int) $size;
		if ( false === $mtime ) {
			return;
		}

		if ( isset( $protected_hashes[ $hash ] ) ) {
			++$result['kept_referenced'];
			$result['bytes_kept'] += $size;
			return;
		}

		if ( $mtime >= $cutoff ) {
			++$result['kept_fresh'];
			$result['bytes_kept'] += $size;
			return;
		}

		++$result['eligible'];
		if ( $dry_run ) {
			return;
		}

		// Re-stat immediately before unlinking: DataPacketStore::store()
		// touches a file when a new write dedupes onto it, and that refresh
		// must win over a stale stat from earlier in this pass.
		clearstatcache( true, $path );
		$fresh_mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- File may vanish concurrently.
		if ( false === $fresh_mtime ) {
			return;
		}
		if ( $fresh_mtime >= $cutoff ) {
			--$result['eligible'];
			++$result['kept_fresh'];
			$result['bytes_kept'] += $size;
			return;
		}

		if ( wp_delete_file( $path ) || ! file_exists( $path ) ) {
			++$result['deleted'];
			$result['bytes_deleted'] += $size;
			return;
		}

		++$result['failed'];
		$result['bytes_kept'] += $size;
	}

	/**
	 * Remove a prefix directory once it holds no entries.
	 *
	 * @param string $dir     Prefix directory.
	 * @param bool   $dry_run Whether to skip removal.
	 * @param array{examined:int,deleted:int,eligible:int,kept_referenced:int,kept_fresh:int,failed:int,bytes_deleted:int,bytes_kept:int,removed_dirs:int,cursor:string,complete:bool,cutoff:int} $result Running result (by reference).
	 * @param-out array{examined:int,deleted:int,eligible:int,kept_referenced:int,kept_fresh:int,failed:int,bytes_deleted:int,bytes_kept:int,removed_dirs:int,cursor:string,complete:bool,cutoff:int} $result
	 */
	private static function maybe_remove_empty_dir( string $dir, bool $dry_run, array &$result ): void {
		if ( $dry_run || ! is_dir( $dir ) ) {
			return;
		}
		$entries = scandir( $dir );
		if ( false === $entries || array() !== array_diff( $entries, array( '.', '..' ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent store() may recreate the directory; failure is benign.
		if ( @rmdir( $dir ) ) {
			++$result['removed_dirs'];
		}
	}

	/**
	 * Sorted directory entries matching a pattern.
	 *
	 * @param string $dir      Directory to list.
	 * @param string $pattern  Regex an entry name must match.
	 * @param bool   $dirs     True to return directories, false for files.
	 * @return array<int,string> Sorted entry names.
	 */
	private static function list_entries( string $dir, string $pattern, bool $dirs ): array {
		$entries = is_dir( $dir ) ? scandir( $dir ) : false;
		if ( false === $entries ) {
			return array();
		}

		$matched = array();
		foreach ( $entries as $entry ) {
			if ( 1 !== preg_match( $pattern, $entry ) ) {
				continue;
			}
			if ( is_dir( $dir . '/' . $entry ) !== $dirs ) {
				continue;
			}
			$matched[] = $entry;
		}
		sort( $matched, SORT_STRING );

		return $matched;
	}

	/**
	 * Split a cursor into schema, prefix, and file components.
	 *
	 * Malformed cursors restart the pass from the beginning, which is always
	 * safe (the sweep is idempotent).
	 *
	 * @param string $cursor Cursor string.
	 * @return array{0:string,1:string,2:string}
	 */
	private static function parse_cursor( string $cursor ): array {
		$parts = explode( '/', $cursor );
		if ( 3 !== count( $parts )
			|| 1 !== preg_match( self::SCHEMA_DIR_PATTERN, $parts[0] )
			|| 1 !== preg_match( self::PREFIX_DIR_PATTERN, $parts[1] )
			|| 1 !== preg_match( self::PACKET_FILE_PATTERN, $parts[2] ) ) {
			return array( '', '', '' );
		}

		return array( $parts[0], $parts[1], $parts[2] );
	}
}
