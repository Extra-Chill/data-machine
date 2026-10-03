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
			$handle = self::replace_unusable_lock_file( $lock_path, $filepath );
		}
		if ( false === $handle ) {
			return array(
				'acquired'   => false,
				'lock'       => null,
				'diagnostic' => self::diagnostic( self::unopenable_status( $lock_path ), $filename, $filepath, $lock_path, array() ),
			);
		}

		// Re-run on every acquisition, not only on creation. An install where a
		// privileged composer already created a too-restrictive lock heals the
		// next time that same composer runs, instead of staying wedged until
		// someone deletes the file by hand.
		self::inherit_guard_permissions( $lock_path, $filepath );

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
		if ( false === $handle && is_file( $lock_path ) ) {
			// Reporting must not need write access to the thing it reports on.
			// flock() only needs an open descriptor, so a lock file this user
			// cannot write can still be asked who owns it. Restricted to regular
			// files: a directory opens read-only and then flocks clean, which
			// would report an unusable path as unlocked.
			$handle = @fopen( $lock_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( false === $handle ) {
			return self::diagnostic( self::unopenable_status( $lock_path ), $filename, $filepath, $lock_path, array() );
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
	 * Classify a lock file this process could not open.
	 *
	 * An existing-but-unopenable lock is a permission fault and is recoverable by
	 * deleting one file. A missing one that cannot be created is a directory
	 * fault. Collapsing both into "unavailable" sends operators looking for a
	 * stuck process that does not exist.
	 *
	 * @param string $lock_path Lock-file path.
	 */
	private static function unopenable_status( string $lock_path ): string {
		return file_exists( $lock_path ) ? 'unusable' : 'unavailable';
	}

	/**
	 * Give the lock file the same reach as the file it guards.
	 *
	 * The lock is only useful if every identity allowed to write the target can
	 * also write the lock. Left at the creating process's umask, the first
	 * privileged composer creates a lock no service user can open, and since
	 * acquisition needs write access that install can never compose that file
	 * again. Mirroring the target's group and read/write bits keeps the two in
	 * step; execute and setuid bits are never inherited.
	 *
	 * @param string $lock_path Lock-file path.
	 * @param string $filepath  Canonical output path the lock guards.
	 */
	private static function inherit_guard_permissions( string $lock_path, string $filepath ): void {
		$owner = @fileowner( $lock_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $owner || ! self::can_change_permissions( $owner ) ) {
			return;
		}

		// Before its first composition the target does not exist yet; the
		// directory it will be written into carries the same intent.
		$reference = file_exists( $filepath ) ? $filepath : dirname( $lock_path );

		$group = @filegroup( $reference ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_int( $group ) && @filegroup( $lock_path ) !== $group ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@chgrp( $lock_path, $group ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chgrp,WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$reference_mode = @fileperms( $reference ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $reference_mode ) {
			return;
		}

		$desired = ( $reference_mode & 0666 ) | 0600;
		$current = @fileperms( $lock_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $current || ( $current & 0777 ) !== $desired ) {
			@chmod( $lock_path, $desired ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Report whether chmod/chgrp on a file with this owner is worth attempting.
	 *
	 * Only a known-futile attempt is skipped. Without the POSIX extension the
	 * effective user is unknowable, and refusing there would silently disable
	 * the inheritance on exactly the hosts that cannot be inspected; the calls
	 * are already silenced and are harmless when the kernel refuses them.
	 *
	 * @param int $owner Owning user ID of the file.
	 */
	private static function can_change_permissions( int $owner ): bool {
		if ( ! function_exists( 'posix_geteuid' ) ) {
			return true;
		}
		$euid = posix_geteuid();
		return 0 === $euid || $euid === $owner;
	}

	/**
	 * Replace a lock file that exists but cannot be opened for writing.
	 *
	 * Recovers installs already wedged by a lock created before
	 * inherit_guard_permissions() existed. Replacement is allowed only when
	 * nobody currently holds the lock: flock() needs an open descriptor but not
	 * a writable one, so the question is answerable even here. Unlinking a held
	 * lock would leave the holder on the old inode and let a second composer
	 * start on a new one.
	 *
	 * @param string $lock_path Lock-file path.
	 * @param string $filepath  Canonical output path the lock guards.
	 * @return resource|false Opened handle, or false when recovery is unsafe.
	 */
	private static function replace_unusable_lock_file( string $lock_path, string $filepath ) {
		// is_file(), not file_exists(): a directory at this path opens read-only
		// and flocks clean, which would read as an unheld lock and send the
		// replacement into an unlink() that cannot succeed.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( ! is_file( $lock_path ) || ! is_writable( dirname( $lock_path ) ) ) {
			return false;
		}

		$probe = @fopen( $lock_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $probe ) {
			return false;
		}
		$unheld = flock( $probe, LOCK_EX | LOCK_NB ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
		if ( $unheld ) {
			flock( $probe, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
		}
		fclose( $probe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! $unheld || ! @unlink( $lock_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		$handle = @fopen( $lock_path, 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $handle ) {
			self::inherit_guard_permissions( $lock_path, $filepath );
		}

		return $handle;
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
			'recovery_command' => self::recovery_command( $status, $filename, $lock_path, $pid ),
		);
	}

	/**
	 * Name the command that actually clears the observed state.
	 *
	 * A lock nobody can open is not fixed by re-running the composition that
	 * just failed on it, so pointing at the compose command there sends the
	 * operator in a circle.
	 *
	 * @param string $status    Observed lock state.
	 * @param string $filename  Composable filename.
	 * @param string $lock_path Lock-file path.
	 * @param int    $pid       Owner process ID, when known.
	 */
	private static function recovery_command( string $status, string $filename, string $lock_path, int $pid ): string {
		if ( 'unusable' === $status ) {
			return 'rm -f ' . escapeshellarg( $lock_path );
		}
		if ( in_array( $status, array( 'held', 'blocked' ), true ) && $pid > 0 ) {
			return 'kill -TERM -- ' . $pid;
		}
		return 'wp datamachine memory compose ' . escapeshellarg( $filename );
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
