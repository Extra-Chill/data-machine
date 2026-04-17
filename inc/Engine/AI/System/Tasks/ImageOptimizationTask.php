<?php
/**
 * Image Optimization System Task
 *
 * Compresses oversized images and generates WebP variants using WordPress's
 * native image editor (Imagick or GD). No external API dependencies.
 *
 * Follows the diagnose → fix pattern: ImageOptimizationAbilities::diagnoseImages()
 * identifies issues, this task fixes individual images scheduled via batch.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.42.0
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

class ImageOptimizationTask extends SystemTask {

	/**
	 * Get the task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'image_optimization';
	}

	/**
	 * Get task metadata for admin UI and TaskRegistry.
	 *
	 * @return array
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Image Optimization',
			'description'     => 'Compress oversized images and generate WebP variants using WordPress image editor (Imagick/GD). No external API needed.',
			'setting_key'     => '',
			'default_enabled' => false,
			'trigger'         => 'On-demand via CLI or ability',
			'trigger_type'    => 'manual',
			'supports_run'    => false,
		);
	}

	/**
	 * Whether this task supports undo.
	 *
	 * @return bool
	 */
	public function supportsUndo(): bool {
		return true;
	}

	/**
	 * Execute image optimization for a single attachment.
	 *
	 * @param int   $jobId  DM Job ID.
	 * @param array $params Engine data with attachment_id, quality, webp.
	 */
	public function execute( int $jobId, array $params ): void {
		$attachment_id = absint( $params['attachment_id'] ?? 0 );
		$quality       = absint( $params['quality'] ?? 82 );
		$webp          = $params['webp'] ?? true;

		if ( $attachment_id <= 0 ) {
			$this->failJob( $jobId, 'Missing attachment_id.' );
			return;
		}

		$file_path = get_attached_file( $attachment_id );
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			$this->failJob( $jobId, 'Attachment file not found: ' . ( $file_path ?: 'empty path' ) );
			return;
		}

		$mime_type     = get_post_mime_type( $attachment_id );
		$original_size = filesize( $file_path );
		$effects       = array();
		$results       = array(
			'attachment_id' => $attachment_id,
			'original_size' => $original_size,
			'compressed'    => false,
			'webp_created'  => false,
		);

		// ── Compress the original file ────────────────────────────────
		if ( in_array( $mime_type, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			$compress_result = $this->compressImage( $file_path, $mime_type, $quality, $attachment_id );

			if ( $compress_result['success'] ) {
				$results['compressed']  = true;
				$results['new_size']    = $compress_result['new_size'];
				$results['savings']     = $original_size - $compress_result['new_size'];
				$results['savings_pct'] = $original_size > 0 ? round( ( $results['savings'] / $original_size ) * 100, 1 ) : 0;

				$effects[] = array(
					'type'          => 'attachment_file_modified',
					'target'        => array(
						'attachment_id' => $attachment_id,
						'file_path'     => $file_path,
					),
					'previous_size' => $original_size,
					'new_size'      => $compress_result['new_size'],
				);

				// Update attachment metadata with new file size.
				$metadata = wp_get_attachment_metadata( $attachment_id );
				if ( is_array( $metadata ) ) {
					$metadata['filesize'] = $compress_result['new_size'];
					wp_update_attachment_metadata( $attachment_id, $metadata );
				}
			}
		}

		// ── Generate WebP variant ─────────────────────────────────────
		if ( $webp && in_array( $mime_type, array( 'image/jpeg', 'image/png' ), true ) ) {
			$webp_result = $this->generateWebP( $file_path, $quality, $attachment_id );

			if ( $webp_result['success'] ) {
				$results['webp_created'] = true;
				$results['webp_path']    = $webp_result['webp_path'];
				$results['webp_size']    = $webp_result['webp_size'];

				$effects[] = array(
					'type'   => 'file_created',
					'target' => array(
						'file_path' => $webp_result['webp_path'],
					),
				);
			}
		}

		$results['effects']      = $effects;
		$results['completed_at'] = current_time( 'mysql' );

		$this->completeJob( $jobId, $results );
	}

	/**
	 * Compress an image file in place using WordPress image editor.
	 *
	 * @param string $file_path     Absolute path to image.
	 * @param string $mime_type     MIME type of the image.
	 * @param int    $quality       Compression quality (1-100).
	 * @param int    $attachment_id Attachment ID for logging.
	 * @return array{success: bool, new_size: int, error: string}
	 */
	private function compressImage( string $file_path, string $mime_type, int $quality, int $attachment_id ): array {
		$editor = wp_get_image_editor( $file_path );

		if ( is_wp_error( $editor ) ) {
			return array(
				'success' => false,
				'error'   => 'Image editor not available: ' . $editor->get_error_message(),
			);
		}

		$editor->set_quality( $quality );

		// Save back to same path and format.
		$saved = $editor->save( $file_path, $mime_type );

		if ( is_wp_error( $saved ) ) {
			return array(
				'success' => false,
				'error'   => 'Compression failed: ' . $saved->get_error_message(),
			);
		}

		clearstatcache( true, $file_path );
		$new_size = filesize( $file_path );

		return array(
			'success'  => true,
			'new_size' => $new_size,
		);
	}

	/**
	 * Generate a WebP variant of an image.
	 *
	 * @param string $file_path     Source image path.
	 * @param int    $quality       WebP compression quality.
	 * @param int    $attachment_id Attachment ID for logging.
	 * @return array{success: bool, webp_path: string, webp_size: int, error: string}
	 */
	private function generateWebP( string $file_path, int $quality, int $attachment_id ): array {
		$webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file_path );

		// Skip if WebP already exists.
		if ( file_exists( $webp_path ) ) {
			return array(
				'success'   => true,
				'webp_path' => $webp_path,
				'webp_size' => filesize( $webp_path ),
			);
		}

		$editor = wp_get_image_editor( $file_path );

		if ( is_wp_error( $editor ) ) {
			return array(
				'success' => false,
				'error'   => 'Image editor not available: ' . $editor->get_error_message(),
			);
		}

		$editor->set_quality( $quality );

		$saved = $editor->save( $webp_path, 'image/webp' );

		if ( is_wp_error( $saved ) ) {
			return array(
				'success' => false,
				'error'   => 'WebP generation failed: ' . $saved->get_error_message(),
			);
		}

		return array(
			'success'   => true,
			'webp_path' => $saved['path'],
			'webp_size' => filesize( $saved['path'] ),
		);
	}
}
