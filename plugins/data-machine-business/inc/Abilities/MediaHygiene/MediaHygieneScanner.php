<?php
/**
 * Shared filesystem + DB scanning utilities for media hygiene abilities.
 *
 * Pure read helpers — no mutations. The orphan-file and unused-attachment
 * abilities both compose these helpers; keeping the SQL/glob logic here
 * avoids duplication and keeps the abilities thin.
 *
 * @package DataMachineBusiness\Abilities\MediaHygiene
 */

namespace DataMachineBusiness\Abilities\MediaHygiene;

defined( 'ABSPATH' ) || exit;

class MediaHygieneScanner {

	/**
	 * File extensions considered "media" for orphan scanning.
	 *
	 * Conservative list — everything WordPress core treats as an attachable
	 * media type. Configurable via the `datamachine_media_hygiene_extensions`
	 * filter.
	 */
	private const DEFAULT_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico', 'tiff',
		'mp4', 'mov', 'webm', 'ogv', 'mp3', 'wav', 'm4a', 'ogg',
		'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip',
	);

	/**
	 * Subdirectories under `wp-content/uploads/` to skip entirely when
	 * scanning for orphan files. These are managed by other systems (caches,
	 * exports, plugin internals) and should not be reported as orphans.
	 *
	 * Configurable via the `datamachine_media_hygiene_ignored_dirs` filter.
	 */
	private const DEFAULT_IGNORED_DIRS = array(
		'cache',
		'breeze',
		'wpo-cache',
		'wc-logs',
		'woocommerce_uploads',
		'data-machine-logs',
		'datamachine-files',
		'fonts',
		'backup', // Imagify originals — managed separately by `find-dead-imagify-backups`.
	);

	/**
	 * Returns the list of media file extensions to scan (lowercased, no dots).
	 *
	 * @return string[]
	 */
	public static function extensions(): array {
		$exts = apply_filters( 'datamachine_media_hygiene_extensions', self::DEFAULT_EXTENSIONS );
		return array_values( array_unique( array_map( 'strtolower', (array) $exts ) ) );
	}

	/**
	 * Returns the list of subdirectory names to skip during orphan scans.
	 *
	 * @return string[]
	 */
	public static function ignored_dirs(): array {
		$dirs = apply_filters( 'datamachine_media_hygiene_ignored_dirs', self::DEFAULT_IGNORED_DIRS );
		return array_values( array_unique( array_map( 'strtolower', (array) $dirs ) ) );
	}

	/**
	 * Returns the absolute uploads basedir for the current site context.
	 *
	 * Caller is responsible for `switch_to_blog()` if scanning a specific site.
	 *
	 * @return string
	 */
	public static function uploads_basedir(): string {
		$uploads = wp_get_upload_dir();
		return rtrim( (string) ( $uploads['basedir'] ?? '' ), '/' );
	}

	/**
	 * Walks the current site's uploads directory and returns every media file
	 * relative to `uploads_basedir`, skipping ignored subdirs.
	 *
	 * Caller scopes the site via `switch_to_blog()` before calling. For
	 * multisite subsites, this naturally walks only `uploads/sites/N/` because
	 * `wp_get_upload_dir()` returns the site-scoped basedir under that context.
	 *
	 * @param int $limit Maximum files to return. 0 means no limit.
	 * @return array<int, array{path:string, relative:string, size:int}>
	 */
	public static function walk_uploads( int $limit = 0 ): array {
		$basedir = self::uploads_basedir();
		if ( '' === $basedir || ! is_dir( $basedir ) ) {
			return array();
		}

		$ignored = self::ignored_dirs();
		$exts    = self::extensions();
		$results = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $basedir, \FilesystemIterator::SKIP_DOTS ),
				static function ( $file, $key, $iterator ) use ( $basedir, $ignored ) {
					if ( $file->isDir() ) {
						$name = strtolower( $file->getFilename() );
						if ( in_array( $name, $ignored, true ) ) {
							return false;
						}
						// On the network site, skip per-site subdirs — those are scanned
						// in their own switch_to_blog() context, not via the main site.
						if ( is_multisite() && get_current_blog_id() === get_main_site_id() ) {
							$rel = substr( $file->getPathname(), strlen( $basedir ) + 1 );
							if ( 'sites' === strtolower( $rel ) ) {
								return false;
							}
						}
						return true;
					}
					return true;
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, $exts, true ) ) {
				continue;
			}
			$path     = $file->getPathname();
			$relative = substr( $path, strlen( $basedir ) + 1 );

			$results[] = array(
				'path'     => $path,
				'relative' => $relative,
				'size'     => (int) $file->getSize(),
			);

			if ( $limit > 0 && count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Returns the set of `_wp_attached_file` postmeta values for the current
	 * site, keyed by attachment ID. These are paths relative to the uploads
	 * basedir — same shape as `walk_uploads()` `relative` values.
	 *
	 * @return array<int, string> attachment_id => relative_path
	 */
	public static function attached_file_index(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '_wp_attached_file'",
			ARRAY_A
		);

		$index = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$index[ (int) $row['post_id'] ] = (string) $row['meta_value'];
			}
		}
		return $index;
	}

	/**
	 * Returns the set of every image-size variant filename derived from
	 * `_wp_attachment_metadata` for the current site.
	 *
	 * WordPress stores resized variants (thumbnail, medium, large, etc.) as
	 * separate files alongside the original. The original's relative path
	 * comes from `_wp_attached_file`, but the variants are only enumerated
	 * in the serialized `_wp_attachment_metadata` blob. An orphan scan that
	 * only checks `_wp_attached_file` would falsely flag every thumbnail.
	 *
	 * Returns a set of basenames keyed for O(1) lookup.
	 *
	 * @return array<string, true> map of variant relative path => true
	 */
	public static function variant_index(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			"SELECT meta_value
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '_wp_attachment_metadata'"
		);

		$variants = array();
		if ( ! is_array( $rows ) ) {
			return $variants;
		}

		foreach ( $rows as $blob ) {
			$meta = @maybe_unserialize( $blob );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$file = isset( $meta['file'] ) ? (string) $meta['file'] : '';
			$dir  = '' !== $file ? trim( dirname( $file ), '/.' ) : '';
			$dir  = '' !== $dir ? $dir . '/' : '';

			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! is_array( $size ) || empty( $size['file'] ) ) {
						continue;
					}
					$variants[ $dir . $size['file'] ] = true;
				}
			}

			// Imagify / WebP / scaled originals sometimes register additional
			// derivative files via the `original_image` key.
			if ( ! empty( $meta['original_image'] ) ) {
				$variants[ $dir . (string) $meta['original_image'] ] = true;
			}
		}

		return $variants;
	}

	/**
	 * Scans the current site for attachment IDs that appear unreferenced.
	 *
	 * "Unreferenced" = the attachment's filename does not appear in any of:
	 * - `wp_posts.post_content` (all post types except `attachment`)
	 * - `wp_postmeta.meta_value` (any postmeta row)
	 * - `wp_term_taxonomy.description`
	 * - `wp_options.option_value`
	 *
	 * Featured-image references are detected via `_thumbnail_id` postmeta
	 * (which is included in the postmeta scan automatically).
	 *
	 * Caller is responsible for `switch_to_blog()`.
	 *
	 * @param int $limit Maximum attachments to check (0 = no limit).
	 * @return array<int, array{id:int, file:string, size:int, url:string}>
	 */
	public static function find_unreferenced_attachments( int $limit = 0 ): array {
		global $wpdb;

		$basedir = self::uploads_basedir();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sql = "SELECT p.ID, m.meta_value AS file
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m
					ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
				WHERE p.post_type = 'attachment'
				ORDER BY p.ID ASC";
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$unreferenced = array();
		foreach ( $rows as $row ) {
			$id   = (int) $row['ID'];
			$file = (string) $row['file'];
			if ( '' === $file ) {
				continue;
			}
			if ( self::is_referenced( $id, $file ) ) {
				continue;
			}
			$abs  = $basedir . '/' . $file;
			$size = is_readable( $abs ) ? (int) @filesize( $abs ) : 0;

			$unreferenced[] = array(
				'id'   => $id,
				'file' => $file,
				'size' => $size,
				'url'  => wp_get_attachment_url( $id ) ?: '',
			);
		}

		return $unreferenced;
	}

	/**
	 * Checks whether an attachment is referenced anywhere meaningful.
	 *
	 * Heuristic, not exhaustive — third-party plugins can store references
	 * in ways we don't probe (e.g. block-bound media in custom block JSON
	 * patterns). False positives (claiming attachment is unreferenced when
	 * it isn't) are possible. For safety, the `delete-unused-attachments`
	 * ability is gated by `--apply` and a confirmation prompt.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $file          `_wp_attached_file` value.
	 * @return bool
	 */
	public static function is_referenced( int $attachment_id, string $file ): bool {
		global $wpdb;

		$basename = basename( $file );

		// 1. post_content LIKE '%basename%' across all non-attachment post types.
		$content_sql = $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->posts}
			 WHERE post_type != 'attachment'
			   AND post_status NOT IN ('trash','auto-draft')
			   AND post_content LIKE %s
			 LIMIT 1",
			'%' . $wpdb->esc_like( $basename ) . '%'
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $content_sql ) ) {
			return true;
		}

		// 2. postmeta LIKE '%basename%' (catches _thumbnail_id JSON refs,
		//    ACF image fields storing IDs/URLs, gallery shortcode IDs, etc.)
		//    Note: _thumbnail_id stores the integer attachment ID, so we also
		//    probe for the literal ID.
		$id_str   = (string) $attachment_id;
		$meta_sql = $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->postmeta}
			 WHERE meta_value LIKE %s OR meta_value = %s
			 LIMIT 1",
			'%' . $wpdb->esc_like( $basename ) . '%',
			$id_str
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $meta_sql ) ) {
			return true;
		}

		// 3. term_taxonomy.description LIKE '%basename%'
		$term_sql = $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->term_taxonomy}
			 WHERE description LIKE %s
			 LIMIT 1",
			'%' . $wpdb->esc_like( $basename ) . '%'
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $term_sql ) ) {
			return true;
		}

		// 4. options.option_value LIKE '%basename%' (site icon, logo, custom
		//    header, theme mods, widget configs all live here).
		$opt_sql = $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->options}
			 WHERE option_value LIKE %s OR option_value = %s
			 LIMIT 1",
			'%' . $wpdb->esc_like( $basename ) . '%',
			$id_str
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $opt_sql ) ) {
			return true;
		}

		return false;
	}
}
