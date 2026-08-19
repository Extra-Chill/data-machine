<?php
/**
 * Image Optimization System Task
 *
 * Compresses oversized images and generates WebP variants using WordPress's
 * native image editor (Imagick or GD). No external API dependencies.
 *
 * @package DataMachineBusiness\Engine\AI\System\Tasks
 * @since 0.42.0
 * @since 0.72.0 Migrated to getWorkflow() + executeTask() contract.
 */

namespace DataMachineBusiness\Engine\AI\System\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;

defined( 'ABSPATH' ) || exit;

class ImageOptimizationTask extends SystemTask {

	private static bool $registered = false;

	/**
	 * Register this task with Data Machine's task registry.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		add_filter( 'datamachine_tasks', array( self::class, 'registerTask' ) );
		self::$registered = true;
	}

	/**
	 * @param array<string, string> $tasks Registered task handlers.
	 * @return array<string, string>
	 */
	public static function registerTask( array $tasks ): array {
		$tasks['image_optimization'] = self::class;

		return $tasks;
	}

	/**
	 * @return string
	 */
	public function getTaskType(): string {
		return 'image_optimization';
	}

	/**
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
	public function executeTask( int $jobId, array $params ): void {
		$attachment_id = absint( $params['attachment_id'] ?? 0 );
		$quality       = absint( $params['quality'] ?? 82 );
		$webp          = $params['webp'] ?? true;

		if ( $attachment_id <= 0 ) {
			$this->failJob( $jobId, 'Missing attachment_id.' );
			return;
		}

		$file_path = get_attached_file( $attachment_id );
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			$this->failJob( $jobId, 'Attachment file not found: ' . ( $file_path ? $file_path : 'empty path' ) );
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

				$metadata = wp_get_attachment_metadata( $attachment_id );
				if ( is_array( $metadata ) ) {
					$metadata['filesize'] = $compress_result['new_size'];
					wp_update_attachment_metadata( $attachment_id, $metadata );
				}
			}
		}

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
	 * @param string $file_path     Absolute path.
	 * @param string $mime_type     MIME type.
	 * @param int    $quality       Compression quality.
	 * @param int    $attachment_id Attachment ID.
	 * @return array{success: bool, new_size: int, error: string}
	 */
	private function compressImage( string $file_path, string $mime_type, int $quality, int $attachment_id ): array {
		$editor = wp_get_image_editor( $file_path );

		if ( is_wp_error( $editor ) ) {
			return array(
				'success' => false,
				'new_size' => 0,
				'error'   => 'Image editor not available: ' . $editor->get_error_message(),
			);
		}

		$editor->set_quality( $quality );
		$saved = $editor->save( $file_path, $mime_type );

		if ( is_wp_error( $saved ) ) {
			return array(
				'success' => false,
				'new_size' => 0,
				'error'   => 'Compression failed: ' . $saved->get_error_message(),
			);
		}

		clearstatcache( true, $file_path );
		return array(
			'success'  => true,
			'new_size' => (int) filesize( $file_path ),
			'error'    => '',
		);
	}

	/**
	 * @param string $file_path     Source image.
	 * @param int    $quality       WebP quality.
	 * @param int    $attachment_id Attachment ID.
	 * @return array{success: bool, webp_path: string, webp_size: int, error: string}
	 */
	private function generateWebP( string $file_path, int $quality, int $attachment_id ): array {
		$webp_path = (string) preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file_path );

		if ( file_exists( $webp_path ) ) {
			return array(
				'success'   => true,
				'webp_path' => $webp_path,
				'webp_size' => (int) filesize( $webp_path ),
				'error'     => '',
			);
		}

		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return array(
				'success' => false,
				'webp_path' => '',
				'webp_size' => 0,
				'error'   => 'Image editor not available: ' . $editor->get_error_message(),
			);
		}

		$editor->set_quality( $quality );
		$saved = $editor->save( $webp_path, 'image/webp' );

		if ( is_wp_error( $saved ) ) {
			return array(
				'success' => false,
				'webp_path' => '',
				'webp_size' => 0,
				'error'   => 'WebP generation failed: ' . $saved->get_error_message(),
			);
		}

		return array(
			'success'   => true,
			'webp_path' => (string) $saved['path'],
			'webp_size' => (int) filesize( $saved['path'] ),
			'error'     => '',
		);
	}
}
