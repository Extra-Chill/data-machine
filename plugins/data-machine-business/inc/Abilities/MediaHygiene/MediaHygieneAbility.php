<?php
/**
 * Media Hygiene ability — orphan files + unused attachments.
 *
 * Registers a single `datamachine/media-hygiene` ability that dispatches on
 * an `action` input:
 *
 *   - `orphan-files`    — list files on disk with no attachment row
 *   - `unused`          — list attachments referenced nowhere
 *   - `diagnose`        — summary roll-up of all detectors (no deletion)
 *   - `delete-orphans`  — gated deletion of orphan files (requires `apply`)
 *   - `delete-unused`   — gated deletion of unused attachments (requires `apply`)
 *
 * All read actions are pure / non-destructive. All write actions require
 * `apply = true` and respect a `limit` for batched safety. Default `limit`
 * for delete is 100, max 1000 per invocation.
 *
 * @package DataMachineBusiness\Abilities\MediaHygiene
 */

namespace DataMachineBusiness\Abilities\MediaHygiene;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class MediaHygieneAbility {

	private const VALID_ACTIONS = array(
		'diagnose',
		'orphan-files',
		'unused',
		'delete-orphans',
		'delete-unused',
	);

	private const DEFAULT_LIMIT       = 500;
	private const MAX_DELETE_PER_CALL = 1000;

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$register_callback = function () {
			wp_register_ability(
				'datamachine/media-hygiene',
				array(
					'label'               => __( 'Media Hygiene', 'data-machine-business' ),
					'description'         => __( 'Detect orphan files on disk and unused attachments in the WordPress media library. Supports gated deletion of confirmed dead weight.', 'data-machine-business' ),
					'category'            => 'datamachine-media',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'action' ),
						'properties' => array(
							'action' => array(
								'type'        => 'string',
								'enum'        => self::VALID_ACTIONS,
								'description' => __( 'Action to perform: diagnose, orphan-files, unused, delete-orphans, delete-unused.', 'data-machine-business' ),
							),
							'limit'  => array(
								'type'        => 'integer',
								'description' => __( 'Maximum items to scan or delete per invocation. Default 500 for scans, 100 for deletes (max 1000).', 'data-machine-business' ),
							),
							'apply'  => array(
								'type'        => 'boolean',
								'description' => __( 'Required for delete actions. Without apply=true, delete actions return a dry-run preview.', 'data-machine-business' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'action'  => array( 'type' => 'string' ),
							'dry_run' => array( 'type' => 'boolean' ),
							'summary' => array( 'type' => 'object' ),
							'results' => array( 'type' => 'array' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'run' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );

		self::$registered = true;
	}

	/**
	 * Ability execute callback. Dispatches on action.
	 *
	 * @param array $input
	 * @return array
	 */
	public static function run( array $input ): array {
		$action = sanitize_text_field( $input['action'] ?? '' );
		if ( ! in_array( $action, self::VALID_ACTIONS, true ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid action. Must be one of: ' . implode( ', ', self::VALID_ACTIONS ),
			);
		}

		$limit = isset( $input['limit'] ) ? max( 0, (int) $input['limit'] ) : self::DEFAULT_LIMIT;
		$apply = ! empty( $input['apply'] );

		switch ( $action ) {
			case 'diagnose':
				return self::diagnose( $limit );
			case 'orphan-files':
				return self::list_orphan_files( $limit );
			case 'unused':
				return self::list_unused_attachments( $limit );
			case 'delete-orphans':
				return self::delete_orphans( $limit, $apply );
			case 'delete-unused':
				return self::delete_unused( $limit, $apply );
		}

		// Unreachable — guarded by VALID_ACTIONS check above.
		return array(
			'success' => false,
			'error'   => 'Unhandled action.',
		);
	}

	/**
	 * Summary roll-up — counts + bytes for each detector, no per-item list.
	 *
	 * @param int $limit Per-detector cap for the scan.
	 * @return array
	 */
	private static function diagnose( int $limit ): array {
		$orphans = self::scan_orphan_files( $limit );
		$unused  = MediaHygieneScanner::find_unreferenced_attachments( $limit );

		$orphan_bytes = array_sum( array_column( $orphans, 'size' ) );
		$unused_bytes = array_sum( array_column( $unused, 'size' ) );

		return array(
			'success' => true,
			'action'  => 'diagnose',
			'dry_run' => true,
			'summary' => array(
				'orphan_files_count'      => count( $orphans ),
				'orphan_files_bytes'      => $orphan_bytes,
				'orphan_files_human'      => size_format( $orphan_bytes, 2 ),
				'unused_attachments'      => count( $unused ),
				'unused_bytes'            => $unused_bytes,
				'unused_human'            => size_format( $unused_bytes, 2 ),
				'total_reclaimable'       => $orphan_bytes + $unused_bytes,
				'total_human'             => size_format( $orphan_bytes + $unused_bytes, 2 ),
				'scan_limit_per_detector' => $limit,
			),
		);
	}

	/**
	 * Lists orphan files (no associated attachment row).
	 *
	 * @param int $limit
	 * @return array
	 */
	private static function list_orphan_files( int $limit ): array {
		$orphans = self::scan_orphan_files( $limit );

		return array(
			'success' => true,
			'action'  => 'orphan-files',
			'dry_run' => true,
			'summary' => array(
				'count'       => count( $orphans ),
				'total_bytes' => array_sum( array_column( $orphans, 'size' ) ),
				'scan_limit'  => $limit,
			),
			'results' => $orphans,
		);
	}

	/**
	 * Lists attachments with no detected references.
	 *
	 * @param int $limit
	 * @return array
	 */
	private static function list_unused_attachments( int $limit ): array {
		$unused = MediaHygieneScanner::find_unreferenced_attachments( $limit );

		return array(
			'success' => true,
			'action'  => 'unused',
			'dry_run' => true,
			'summary' => array(
				'count'       => count( $unused ),
				'total_bytes' => array_sum( array_column( $unused, 'size' ) ),
				'scan_limit'  => $limit,
			),
			'results' => $unused,
		);
	}

	/**
	 * Deletes orphan files. Requires `apply = true`; otherwise dry-run.
	 *
	 * @param int  $limit
	 * @param bool $apply
	 * @return array
	 */
	private static function delete_orphans( int $limit, bool $apply ): array {
		global $wp_filesystem;
		$limit   = $limit > 0 ? min( $limit, self::MAX_DELETE_PER_CALL ) : 100;
		$orphans = self::scan_orphan_files( $limit );

		if ( ! $apply ) {
			return array(
				'success' => true,
				'action'  => 'delete-orphans',
				'dry_run' => true,
				'summary' => array(
					'would_delete'  => count( $orphans ),
					'bytes_to_free' => array_sum( array_column( $orphans, 'size' ) ),
					'note'          => 'Pass apply=true to actually delete.',
				),
				'results' => $orphans,
			);
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return array(
				'success' => false,
				'action'  => 'delete-orphans',
				'dry_run' => false,
				'error'   => 'Unable to initialize the WordPress filesystem.',
			);
		}

		$deleted     = 0;
		$bytes_freed = 0;
		$failed      = array();

		foreach ( $orphans as $orphan ) {
			$path = $orphan['path'];
			if ( ! $wp_filesystem->is_writable( $path ) ) {
				$failed[] = array(
					'path'   => $orphan['relative'],
					'reason' => 'not_writable',
				);
				continue;
			}
			if ( $wp_filesystem->delete( $path, false, 'f' ) ) {
				++$deleted;
				$bytes_freed += (int) $orphan['size'];
			} else {
				$failed[] = array(
					'path'   => $orphan['relative'],
					'reason' => 'unlink_failed',
				);
			}
		}

		return array(
			'success' => true,
			'action'  => 'delete-orphans',
			'dry_run' => false,
			'summary' => array(
				'deleted'      => $deleted,
				'bytes_freed'  => $bytes_freed,
				'failed_count' => count( $failed ),
			),
			'results' => $failed,
		);
	}

	/**
	 * Deletes unused attachments. Requires `apply = true`; otherwise dry-run.
	 *
	 * Uses `wp_delete_attachment( $id, true )` for full WP-aware deletion
	 * (removes file + all size variants + DB rows).
	 *
	 * @param int  $limit
	 * @param bool $apply
	 * @return array
	 */
	private static function delete_unused( int $limit, bool $apply ): array {
		$limit  = $limit > 0 ? min( $limit, self::MAX_DELETE_PER_CALL ) : 100;
		$unused = MediaHygieneScanner::find_unreferenced_attachments( $limit );

		if ( ! $apply ) {
			return array(
				'success' => true,
				'action'  => 'delete-unused',
				'dry_run' => true,
				'summary' => array(
					'would_delete'  => count( $unused ),
					'bytes_to_free' => array_sum( array_column( $unused, 'size' ) ),
					'note'          => 'Pass apply=true to actually delete. Uses wp_delete_attachment( id, true ).',
				),
				'results' => $unused,
			);
		}

		$deleted     = 0;
		$bytes_freed = 0;
		$failed      = array();

		foreach ( $unused as $u ) {
			$id   = (int) $u['id'];
			$size = (int) $u['size'];
			$res  = wp_delete_attachment( $id, true );
			if ( false === $res || null === $res ) {
				$failed[] = array(
					'id'     => $id,
					'reason' => 'wp_delete_attachment_failed',
				);
				continue;
			}
			++$deleted;
			$bytes_freed += $size;
		}

		return array(
			'success' => true,
			'action'  => 'delete-unused',
			'dry_run' => false,
			'summary' => array(
				'deleted'      => $deleted,
				'bytes_freed'  => $bytes_freed,
				'failed_count' => count( $failed ),
			),
			'results' => $failed,
		);
	}

	/**
	 * Returns the orphan-file list for the current site. Combines the on-disk
	 * walk with the DB attached-file + variant index and returns files that
	 * appear in neither.
	 *
	 * @param int $limit
	 * @return array
	 */
	private static function scan_orphan_files( int $limit ): array {
		// Walk uploads with no limit at this level — we want to count
		// accurately, then cap the returned result list at $limit.
		$files = MediaHygieneScanner::walk_uploads( 0 );
		if ( empty( $files ) ) {
			return array();
		}

		$attached = array_flip( array_map( 'strval', MediaHygieneScanner::attached_file_index() ) );
		$variants = MediaHygieneScanner::appended_variant_index(
			$attached + MediaHygieneScanner::variant_index()
		);

		$orphans = array();
		foreach ( $files as $file ) {
			$rel = $file['relative'];
			if ( isset( $variants[ $rel ] ) ) {
				continue;
			}
			$orphans[] = $file;
			if ( $limit > 0 && count( $orphans ) >= $limit ) {
				break;
			}
		}

		return $orphans;
	}
}
