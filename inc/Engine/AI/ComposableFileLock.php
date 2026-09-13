<?php
/**
 * Bounded per-file lock for composable memory generation.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Serializes composition without making unrelated requests wait on the lock.
 */
class ComposableFileLock {

	private const METADATA_LIMIT = 8192;

	/**
	 * Owned lock-file handle.
	 *
	 * @var resource|null
	 */
	private $handle;

	/** Acquiring process ID, used to fence forked destructors. */
	private int $owner_pid;

	/**
	 * Create an owned lock instance.
	 *
	 * @param resource $handle Locked file handle.
	 */
	private function __construct( $handle ) {
		$pid             = getmypid();
		$this->handle    = $handle;
		$this->owner_pid = false === $pid ? 0 : $pid;
	}

	/**
	 * Acquire a per-target lock within a bounded wait.
	 *
	 * @param string $filename          Composable filename.
	 * @param string $filepath          Canonical output path.
	 * @param int    $wait_milliseconds Maximum acquisition wait.
	 * @return array{acquired:bool,lock:?self,diagnostic:array<string,int|string|bool>}
	 */
	public static function acquire( string $filename, string $filepath, int $wait_milliseconds = 2000 ): array {
		$lock_path = self::path_for( $filepath );
		$handle    = @fopen( $lock_path, 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return array(
				'acquired'   => false,
				'lock'       => null,
				'diagnostic' => self::diagnostic( 'unavailable', $filename, $filepath, $lock_path, array() ),
			);
		}

		$deadline = microtime( true ) + ( max( 0, $wait_milliseconds ) / 1000 );
		do {
			if ( flock( $handle, LOCK_EX | LOCK_NB ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
				$metadata = array(
					'type'       => 'composable_file_lock',
					'operation'  => 'memory compose ' . $filename,
					'filename'   => $filename,
					'filepath'   => $filepath,
					'pid'        => getmypid(),
					'run_id'     => bin2hex( random_bytes( 8 ) ),
					'started_at' => time(),
				);
				self::write_metadata( $handle, $metadata );

				return array(
					'acquired'   => true,
					'lock'       => new self( $handle ),
					'diagnostic' => self::diagnostic( 'held', $filename, $filepath, $lock_path, $metadata ),
				);
			}
			$remaining_microseconds = (int) ( ( $deadline - microtime( true ) ) * 1000000 );
			if ( $remaining_microseconds <= 0 ) {
				break;
			}
			usleep( min( 50000, $remaining_microseconds ) );
		} while ( microtime( true ) < $deadline );

		$metadata = self::read_metadata( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'acquired'   => false,
			'lock'       => null,
			'diagnostic' => self::diagnostic( 'blocked', $filename, $filepath, $lock_path, $metadata ),
		);
	}

	/**
	 * Return an immediate, read-only lock snapshot.
	 *
	 * @param string $filename Composable filename.
	 * @param string $filepath Canonical output path.
	 * @return array<string,int|string|bool>
	 */
	public static function snapshot( string $filename, string $filepath ): array {
		$lock_path = self::path_for( $filepath );
		$handle    = @fopen( $lock_path, 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return self::diagnostic( 'unavailable', $filename, $filepath, $lock_path, array() );
		}

		$metadata = self::read_metadata( $handle );
		if ( flock( $handle, LOCK_EX | LOCK_NB ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			$status = empty( $metadata ) ? 'unlocked' : 'stale';
			flock( $handle, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
		} else {
			$status = 'held';
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return self::diagnostic( $status, $filename, $filepath, $lock_path, $metadata );
	}

	/**
	 * Release this process's owned lock.
	 */
	public function release(): void {
		if ( ! is_resource( $this->handle ) || getmypid() !== $this->owner_pid ) {
			return;
		}

		self::write_metadata( $this->handle, array() );
		flock( $this->handle, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
		fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->handle = null;
	}

	/**
	 * Release the lock if the owner unwinds unexpectedly.
	 */
	public function __destruct() {
		$this->release();
	}

	/**
	 * Resolve the lock file adjacent to its target.
	 *
	 * @param string $filepath Canonical output path.
	 */
	private static function path_for( string $filepath ): string {
		return dirname( $filepath ) . '/.' . basename( $filepath ) . '.compose.lock';
	}

	/**
	 * Read bounded owner metadata.
	 *
	 * @param resource $handle Lock-file handle.
	 */
	private static function read_metadata( $handle ): array {
		rewind( $handle );
		$raw     = stream_get_contents( $handle, self::METADATA_LIMIT );
		$decoded = json_decode( is_string( $raw ) ? $raw : '', true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Replace owner metadata while holding the kernel lock.
	 *
	 * @param resource            $handle   Lock-file handle.
	 * @param array<string,mixed> $metadata Owner metadata.
	 */
	private static function write_metadata( $handle, array $metadata ): void {
		$encoded = empty( $metadata ) ? '' : (string) wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES );
		rewind( $handle );
		ftruncate( $handle, 0 );
		if ( '' !== $encoded ) {
			fwrite( $handle, $encoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		fflush( $handle );
	}

	/**
	 * Normalize untrusted lock-file metadata into bounded typed output.
	 *
	 * @param string              $status    Observed lock state.
	 * @param string              $filename  Composable filename.
	 * @param string              $filepath  Canonical output path.
	 * @param string              $lock_path Lock-file path.
	 * @param array<string,mixed> $metadata  Stored owner metadata.
	 * @return array<string,int|string|bool>
	 */
	private static function diagnostic( string $status, string $filename, string $filepath, string $lock_path, array $metadata ): array {
		$started_at = max( 0, (int) ( $metadata['started_at'] ?? 0 ) );
		$pid        = max( 0, (int) ( $metadata['pid'] ?? 0 ) );

		return array(
			'type'             => 'composable_file_lock',
			'lock_status'      => $status,
			'filename'         => $filename,
			'filepath'         => $filepath,
			'lock_path'        => $lock_path,
			'owner_operation'  => substr( (string) ( $metadata['operation'] ?? '' ), 0, 160 ),
			'owner_pid'        => $pid,
			'owner_run_id'     => substr( (string) ( $metadata['run_id'] ?? '' ), 0, 64 ),
			'lock_started_at'  => $started_at,
			'lock_age_seconds' => $started_at > 0 ? max( 0, time() - $started_at ) : 0,
			'owner_alive'      => self::pid_is_alive( $pid ),
			'recovery_command' => in_array( $status, array( 'held', 'blocked' ), true ) && $pid > 0
				? 'kill -TERM -- ' . $pid
				: 'wp datamachine memory compose ' . escapeshellarg( $filename ),
		);
	}

	/**
	 * Check whether an owner PID currently exists.
	 *
	 * @param int $pid Owner process ID.
	 */
	private static function pid_is_alive( int $pid ): bool {
		return $pid > 0 && function_exists( 'posix_kill' ) && @posix_kill( $pid, 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
