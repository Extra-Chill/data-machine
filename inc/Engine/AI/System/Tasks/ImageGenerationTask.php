<?php
/**
 * Image Generation Task for System Agent.
 *
 * Handles async image generation through Replicate API. Polls for prediction
 * status and handles completion, failure, or rescheduling for continued polling.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.22.4
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\HttpClient;

class ImageGenerationTask extends SystemTask {

	/**
	 * Maximum attempts for polling (24 attempts = ~120 seconds with 5s intervals).
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 24;

	/**
	 * JPEG quality for converted images (0-100).
	 *
	 * @var int
	 */
	const JPEG_QUALITY = 85;

	/**
	 * Execute image generation task.
	 *
	 * Polls Replicate API once for prediction status. If still processing,
	 * reschedules for another check. If succeeded, downloads image and completes
	 * job. If failed, fails the job with error.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters containing prediction_id, api_key, etc.
	 */
	public function execute( int $jobId, array $params ): void {
		$prediction_id = $params['prediction_id'] ?? '';
		$model = $params['model'] ?? 'unknown';

		// Read API key from tool config — never store secrets in engine_data
		$config  = \DataMachine\Abilities\Media\ImageGenerationAbilities::get_config();
		$api_key = $config['api_key'] ?? '';
		$prompt = $params['prompt'] ?? '';
		$aspect_ratio = $params['aspect_ratio'] ?? '';

		if ( empty( $prediction_id ) ) {
			$this->failJob( $jobId, 'Missing prediction_id in task parameters' );
			return;
		}

		if ( empty( $api_key ) ) {
			$this->failJob( $jobId, 'Replicate API key not configured' );
			return;
		}

		// Set max attempts in engine_data if not already set
		$jobs_db = new \DataMachine\Core\Database\Jobs\Jobs();
		$job = $jobs_db->get_job( $jobId );
		$engine_data = $job['engine_data'] ?? [];
		if ( ! isset( $engine_data['max_attempts'] ) ) {
			$engine_data['max_attempts'] = self::MAX_ATTEMPTS;
			$jobs_db->store_engine_data( $jobId, $engine_data );
		}

		// Poll Replicate API for prediction status
		$result = HttpClient::get(
			"https://api.replicate.com/v1/predictions/{$prediction_id}",
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Token ' . $api_key,
				],
				'context' => 'System Agent Image Generation Poll',
			]
		);

		if ( ! $result['success'] ) {
			// HTTP error - reschedule to try again
			do_action(
				'datamachine_log',
				'warning',
				"System Agent image generation HTTP error for job {$jobId}: " . ( $result['error'] ?? 'Unknown error' ),
				[
					'job_id'        => $jobId,
					'task_type'     => $this->getTaskType(),
					'agent_type'    => 'system',
					'prediction_id' => $prediction_id,
					'error'         => $result['error'] ?? 'Unknown HTTP error',
				]
			);

			$this->reschedule( $jobId, 5 ); // Try again in 5 seconds
			return;
		}

		$status_data = json_decode( $result['data'], true );
		$status = $status_data['status'] ?? '';

		switch ( $status ) {
			case 'succeeded':
				$this->handleSuccess( $jobId, $status_data, $model, $prompt, $aspect_ratio, $params );
				break;

			case 'failed':
			case 'canceled':
				$error = $status_data['error'] ?? "Prediction {$status}";
				$this->failJob( $jobId, "Replicate prediction failed: {$error}" );
				break;

			case 'starting':
			case 'processing':
				// Still processing - reschedule for another check
				$this->reschedule( $jobId, 5 ); // Check again in 5 seconds
				break;

			default:
				$this->failJob( $jobId, "Unknown prediction status: {$status}" );
		}
	}

	/**
	 * Handle successful prediction completion.
	 *
	 * Downloads the generated image and completes the job with image URL.
	 *
	 * @param int    $jobId       Job ID.
	 * @param array  $statusData  Replicate prediction status data.
	 * @param string $model       Model used for generation.
	 * @param string $prompt      Original prompt.
	 * @param string $aspectRatio Original aspect ratio.
	 * @param array  $params      Task params (contains context.pipeline_job_id).
	 */
	protected function handleSuccess( int $jobId, array $statusData, string $model, string $prompt, string $aspectRatio, array $params ): void {
		$output = $statusData['output'] ?? null;

		// Handle different output formats (string URL or array)
		$image_url = null;
		if ( is_string( $output ) ) {
			$image_url = $output;
		} elseif ( is_array( $output ) && ! empty( $output[0] ) ) {
			$image_url = $output[0];
		}

		if ( empty( $image_url ) ) {
			$this->failJob( $jobId, 'Replicate prediction succeeded but no image URL found in output' );
			return;
		}

		// Sideload into WordPress media library so the image persists
		$attachment_id  = null;
		$attachment_url = null;

		$sideload_result = $this->sideloadImage( $image_url, $prompt, $model );

		if ( is_wp_error( $sideload_result ) ) {
			do_action(
				'datamachine_log',
				'warning',
				"System Agent: Image sideload failed for job {$jobId}: " . $sideload_result->get_error_message(),
				[
					'job_id'     => $jobId,
					'task_type'  => $this->getTaskType(),
					'agent_type' => 'system',
					'image_url'  => $image_url,
					'error'      => $sideload_result->get_error_message(),
				]
			);
			// Don't fail the job — we still have the remote URL
		} else {
			$attachment_id  = $sideload_result['attachment_id'];
			$attachment_url = $sideload_result['attachment_url'];
		}

		// Get local file path for engine data — publish handler uses image_file_path
		$image_file_path = null;
		if ( $attachment_id ) {
			$image_file_path = get_attached_file( $attachment_id );
		}

		// If we have an attachment and a pipeline job context, try to set featured image
		if ( $attachment_id ) {
			$this->trySetFeaturedImage( $jobId, $attachment_id, $params );
		}

		// Complete job with success data
		$result = [
			'success'      => true,
			'data'         => [
				'message'         => "Image generated successfully using {$model}.",
				'image_url'       => $image_url,
				'attachment_id'   => $attachment_id,
				'attachment_url'  => $attachment_url,
				'image_file_path' => $image_file_path,
				'prompt'          => $prompt,
				'model'           => $model,
				'aspect_ratio'    => $aspectRatio,
			],
			'tool_name'    => 'image_generation',
			'completed_at' => current_time( 'mysql' ),
		];

		$this->completeJob( $jobId, $result );
	}

	/**
	 * Try to set the generated image as the featured image on the published post.
	 *
	 * Reads the pipeline's engine data to find the post_id written by the
	 * publish step. If the post hasn't been published yet (race condition),
	 * schedules a deferred attempt via Action Scheduler.
	 *
	 * @param int   $jobId        System Agent job ID.
	 * @param int   $attachmentId WordPress attachment ID.
	 * @param array $params       Task params (contains context.pipeline_job_id).
	 */
	protected function trySetFeaturedImage( int $jobId, int $attachmentId, array $params ): void {
		$context         = $params['context'] ?? [];
		$pipeline_job_id = $context['pipeline_job_id'] ?? 0;

		if ( empty( $pipeline_job_id ) ) {
			// No pipeline context — this was a chat or standalone request
			return;
		}

		// Read the pipeline job's engine data to find the published post_id
		$pipeline_engine_data = datamachine_get_engine_data( (int) $pipeline_job_id );
		$post_id              = $pipeline_engine_data['post_id'] ?? 0;

		if ( empty( $post_id ) ) {
			// Post hasn't been published yet — schedule a deferred attempt
			$this->scheduleFeaturedImageRetry( $attachmentId, $pipeline_job_id );
			return;
		}

		// Check if post already has a featured image
		if ( has_post_thumbnail( $post_id ) ) {
			do_action(
				'datamachine_log',
				'debug',
				"System Agent: Post #{$post_id} already has a featured image, skipping",
				[
					'job_id'        => $jobId,
					'post_id'       => $post_id,
					'attachment_id' => $attachmentId,
					'agent_type'    => 'system',
				]
			);
			return;
		}

		// Set the featured image
		$result = set_post_thumbnail( $post_id, $attachmentId );

		if ( $result ) {
			do_action(
				'datamachine_log',
				'info',
				"System Agent: Featured image set on post #{$post_id} (attachment #{$attachmentId})",
				[
					'job_id'        => $jobId,
					'post_id'       => $post_id,
					'attachment_id' => $attachmentId,
					'agent_type'    => 'system',
				]
			);
		} else {
			do_action(
				'datamachine_log',
				'warning',
				"System Agent: Failed to set featured image on post #{$post_id}",
				[
					'job_id'        => $jobId,
					'post_id'       => $post_id,
					'attachment_id' => $attachmentId,
					'agent_type'    => 'system',
				]
			);
		}
	}

	/**
	 * Schedule a deferred attempt to set the featured image.
	 *
	 * Used when the System Agent finishes image generation before the
	 * pipeline's publish step has written the post_id to engine data.
	 *
	 * @param int $attachmentId     WordPress attachment ID.
	 * @param int $pipelineJobId    Pipeline job ID to check for post_id.
	 */
	private function scheduleFeaturedImageRetry( int $attachmentId, int $pipelineJobId ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action(
			time() + 15,
			'datamachine_system_agent_set_featured_image',
			[
				'attachment_id'   => $attachmentId,
				'pipeline_job_id' => $pipelineJobId,
				'attempt'         => 1,
			],
			'data-machine'
		);

		do_action(
			'datamachine_log',
			'debug',
			"System Agent: Scheduled deferred featured image set (attachment #{$attachmentId}, pipeline job #{$pipelineJobId})",
			[
				'attachment_id'   => $attachmentId,
				'pipeline_job_id' => $pipelineJobId,
				'agent_type'      => 'system',
			]
		);
	}

	/**
	 * Sideload a remote image into the WordPress media library.
	 *
	 * Downloads the image from the remote URL, converts to JPEG for optimal
	 * file size, and creates a WordPress attachment with metadata for traceability.
	 *
	 * @param string $image_url Remote image URL.
	 * @param string $prompt    Generation prompt (used for title/description).
	 * @param string $model     Model used for generation.
	 * @return array|\WP_Error Array with attachment_id and attachment_url on success, WP_Error on failure.
	 */
	protected function sideloadImage( string $image_url, string $prompt, string $model ): array|\WP_Error {
		// Ensure required WordPress functions are available
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Download to temp file
		$tmp_file = download_url( $image_url );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		// Convert non-JPEG images to JPEG for smaller file sizes.
		// Some providers (e.g. Replicate/Imagen) return PNG data even when
		// JPEG is requested, so we normalize to JPEG after download.
		$tmp_file = $this->maybeConvertToJpeg( $tmp_file );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		// Always use .jpg — maybeConvertToJpeg ensures the content is JPEG.
		$slug     = sanitize_title( mb_substr( $prompt, 0, 80 ) );
		$filename = "ai-generated-{$slug}.jpg";

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		];

		// Sideload into media library (parent_post_id = 0, unattached)
		$attachment_id = media_handle_sideload( $file_array, 0 );

		// Clean up temp file if sideload failed
		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			return $attachment_id;
		}

		// Set attachment metadata for traceability
		$title = mb_substr( $prompt, 0, 200 );
		wp_update_post( [
			'ID'           => $attachment_id,
			'post_title'   => $title,
			'post_content' => $prompt,
		] );

		update_post_meta( $attachment_id, '_datamachine_generated', true );
		update_post_meta( $attachment_id, '_datamachine_generation_model', $model );
		update_post_meta( $attachment_id, '_datamachine_generation_prompt', $prompt );

		$attachment_url = wp_get_attachment_url( $attachment_id );

		do_action(
			'datamachine_log',
			'info',
			"System Agent: Image sideloaded to media library (attachment #{$attachment_id})",
			[
				'attachment_id'  => $attachment_id,
				'attachment_url' => $attachment_url,
				'model'          => $model,
				'agent_type'     => 'system',
			]
		);

		return [
			'attachment_id'  => $attachment_id,
			'attachment_url' => $attachment_url,
		];
	}

	/**
	 * Convert a downloaded image to JPEG if it isn't already.
	 *
	 * Uses WP_Image_Editor (GD/Imagick) for reliable conversion. If the file
	 * is already JPEG or conversion fails, returns the original path unchanged.
	 *
	 * @param string $tmp_file Path to the temporary downloaded file.
	 * @return string|\WP_Error Path to the (possibly converted) temp file, or WP_Error on critical failure.
	 */
	protected function maybeConvertToJpeg( string $tmp_file ): string|\WP_Error {
		// Read actual MIME from file content using WordPress image editor.
		$editor = wp_get_image_editor( $tmp_file );

		if ( is_wp_error( $editor ) ) {
			// Can't load image — return as-is, let sideload handle it.
			return $tmp_file;
		}

		$current_mime = $editor->get_mime_type();

		// Already JPEG — nothing to do.
		if ( in_array( $current_mime, [ 'image/jpeg', 'image/jpg' ], true ) ) {
			return $tmp_file;
		}

		// Set quality and save as JPEG to a new temp file.
		$editor->set_quality( self::JPEG_QUALITY );
		$converted = $editor->save( $tmp_file . '.jpg', 'image/jpeg' );

		if ( is_wp_error( $converted ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'System Agent: JPEG conversion failed, using original file: ' . $converted->get_error_message(),
				[ 'agent_type' => 'system' ]
			);
			// Fall back to original file — non-critical failure.
			return $tmp_file;
		}

		// Clean up the original temp file and return the converted path.
		wp_delete_file( $tmp_file );

		do_action(
			'datamachine_log',
			'debug',
			"System Agent: Converted {$current_mime} to JPEG for sideload",
			[
				'original_mime' => $current_mime,
				'agent_type'    => 'system',
			]
		);

		return $converted['path'];
	}

	/**
	 * Get the task type identifier.
	 *
	 * @return string Task type identifier.
	 */
	public function getTaskType(): string {
		return 'image_generation';
	}
}
