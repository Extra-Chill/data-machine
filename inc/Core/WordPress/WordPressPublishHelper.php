<?php
/**
 * WordPress Publish Helper
 *
 * WordPress-specific publishing operations including media attachment and content modification.
 * Provides centralized utilities for WordPress publishing handlers.
 *
 * @package DataMachine\Core\WordPress
 * @since 0.2.8
 */

namespace DataMachine\Core\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WordPressPublishHelper {

	/**
	 * Attach image to WordPress post as featured image.
	 *
	 * Sideloads the image from the Files Repository into the Media Library
	 * and sets it as the Featured Image.
	 *
	 * @param int         $post_id The WordPress Post ID.
	 * @param string|null $image_path Absolute path to image file.
	 * @param array       $config Handler configuration (checks 'include_images').
	 * @return int|null Attachment ID on success, null on failure.
	 */
	public static function attachImageToPost( int $post_id, ?string $image_path, array $config ): ?int {
		// 1. Check Configuration
		if ( empty( $config['include_images'] ) ) {
			return null;
		}

		// 2. Validate Image Path
		if ( ! $image_path || ! file_exists( $image_path ) ) {
			self::log( 'warning', 'WordPressPublishHelper: Image file not found for attachment', array( 'path' => $image_path ) );
			return null;
		}

		// 3. Validate Image Type
		$file_type = wp_check_filetype( $image_path );
		if ( ! str_starts_with( $file_type['type'] ?? '', 'image/' ) ) {
			self::log(
				'warning',
				'WordPressPublishHelper: File is not a valid image',
				array(
					'path' => $image_path,
					'type' => $file_type['type'],
				)
			);
			return null;
		}

		// 4. Sideload to Media Library
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_array = array(
			'name'     => basename( $image_path ),
			'tmp_name' => $image_path,
		);

		// Copy to temp location so original repo file isn't moved/deleted by WordPress
		$temp_file = sys_get_temp_dir() . '/' . basename( $image_path );
		if ( ! copy( $image_path, $temp_file ) ) {
			self::log( 'error', 'WordPressPublishHelper: Failed to copy image to temp dir', array( 'path' => $image_path ) );
			return null;
		}
		$file_array['tmp_name'] = $temp_file;

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			self::log(
				'error',
				'WordPressPublishHelper: Failed to create media attachment',
				array(
					'error'   => $attachment_id->get_error_message(),
					'post_id' => $post_id,
				)
			);
			wp_delete_file( $temp_file );
			return null;
		}

		// 5. Set Featured Image
		if ( set_post_thumbnail( $post_id, $attachment_id ) ) {
			self::log(
				'debug',
				'WordPressPublishHelper: Attached featured image',
				array(
					'post_id'       => $post_id,
					'attachment_id' => $attachment_id,
				)
			);
			return $attachment_id;
		}

		return null;
	}

	/**
	 * Apply source attribution to content.
	 *
	 * @param string      $content The content to modify.
	 * @param string|null $source_url Source URL to append.
	 * @param array       $config Handler configuration (checks 'link_handling' and 'content_format').
	 * @return string Modified content.
	 */
	public static function applySourceAttribution( string $content, ?string $source_url, array $config ): string {
		if ( ( $config['link_handling'] ?? 'append' ) !== 'append' ) {
			return $content;
		}

		if ( ! $source_url || ! filter_var( $source_url, FILTER_VALIDATE_URL ) ) {
			return $content;
		}

		$sanitized_url = esc_url( $source_url );
		$content_format = isset( $config['content_format'] )
			? sanitize_key( $config['content_format'] )
			: ( self::contentHasBlocks( $content ) ? 'blocks' : 'html' );
		$content_format = '' !== $content_format ? $content_format : 'html';

		if ( 'markdown' === $content_format ) {
			return $content . "\n\n**Source:** [{$sanitized_url}]({$sanitized_url})";
		}

		if ( 'blocks' === $content_format ) {
			return $content . "\n\n<!-- wp:paragraph -->\n<p><strong>Source:</strong> <a href=\"{$sanitized_url}\">{$sanitized_url}</a></p>\n<!-- /wp:paragraph -->";
		}

		return $content . "\n\n<p><strong>Source:</strong> <a href=\"{$sanitized_url}\">{$sanitized_url}</a></p>";
	}

	/**
	 * Determine whether legacy callers passed block markup without an explicit format.
	 */
	private static function contentHasBlocks( string $content ): bool {
		return strpos( $content, '<!-- wp:' ) !== false;
	}

	/**
	 * Internal logger helper.
	 *
	 * @param string $level Log level (debug, info, warning, error)
	 * @param string $message Log message
	 * @param array  $context Additional context data
	 */
	private static function log( string $level, string $message, array $context = array() ): void {
		do_action( 'datamachine_log', $level, $message, $context );
	}
}
