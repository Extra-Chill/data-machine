<?php
/**
 * Temporary file cleanup with daily WP-Cron for dms-temp directory.
 *
 * @package DataMachineSocials
 * @since 0.2.1
 */

namespace DataMachineSocials;

defined( 'ABSPATH' ) || exit;

/**
 * Temporary file cleanup system with WP-Cron integration.
 *
 * Cleans up cropped images and other temporary files from the
 * dms-temp upload directory on a daily basis.
 */
class Cleanup {

	/**
	 * WP-Cron hook name.
	 */
	const CRON_HOOK = 'dms_temp_cleanup_cron';

	/**
	 * Temp directory name within wp-content/uploads.
	 */
	const TEMP_DIR = 'dms-temp';

	/**
	 * Max age of temp files in seconds (24 hours).
	 */
	const MAX_AGE = DAY_IN_SECONDS;

	/**
	 * Register WordPress hooks for cleanup.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'schedule_cron' ) );
		add_action( self::CRON_HOOK, array( self::class, 'cleanup_temp_files' ) );
	}

	/**
	 * Schedule the cron event if not already scheduled.
	 *
	 * @return void
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Delete temporary files older than MAX_AGE.
	 *
	 * @return array{deleted: int, skipped: int, errors: int} Cleanup results.
	 */
	public static function cleanup_temp_files(): array {
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/' . self::TEMP_DIR;
		$results    = array(
			'deleted' => 0,
			'skipped' => 0,
			'errors'  => 0,
		);

		if ( ! is_dir( $temp_dir ) ) {
			return $results;
		}

		$cutoff = time() - self::MAX_AGE;
		$files  = glob( $temp_dir . '/*' );

		if ( empty( $files ) ) {
			return $results;
		}

		foreach ( $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}

			if ( filemtime( $file ) < $cutoff ) {
				if ( wp_delete_file( $file ) !== false ) {
					++$results['deleted'];
				} else {
					++$results['errors'];
				}
			} else {
				++$results['skipped'];
			}
		}

		return $results;
	}

	/**
	 * Ensure temp directory has an index.php to prevent directory listing.
	 *
	 * Called on plugin activation or when temp dir is first created.
	 *
	 * @return void
	 */
	public static function secure_temp_dir(): void {
		global $wp_filesystem;
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/' . self::TEMP_DIR;

		if ( ! is_dir( $temp_dir ) ) {
			return;
		}

		$index_file = $temp_dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			$wp_filesystem->put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Clean up scheduled events on plugin deactivation.
	 *
	 * @return void
	 */
	public static function on_deactivation(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
