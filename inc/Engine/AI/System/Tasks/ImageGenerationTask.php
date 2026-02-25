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
		$model         = $params['model'] ?? 'unknown';

		// Read API key from tool config — never store secrets in engine_data
		$config       = \DataMachine\Abilities\Media\ImageGenerationAbilities::get_config();
		$api_key      = $config['api_key'] ?? '';
		$prompt       = $params['prompt'] ?? '';
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
		$jobs_db     = new \DataMachine\Core\Database\Jobs\Jobs();
		$job         = $jobs_db->get_job( $jobId );
		$engine_data = $job['engine_data'] ?? array();
		if ( ! isset( $engine_data['max_attempts'] ) ) {
			$engine_data['max_attempts'] = self::MAX_ATTEMPTS;
			$jobs_db->store_engine_data( $jobId, $engine_data );
		}

		// Poll Replicate API for prediction status
		$result = HttpClient::get(
			"https://api.replicate.com/v1/predictions/{$prediction_id}",
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Token ' . $api_key,
				),
				'context' => 'System Agent Image Generation Poll',
			)
		);

		if ( ! $result['success'] ) {
			// HTTP error - reschedule to try again
			do_action(
				'datamachine_log',
				'warning',
				"System Agent image generation HTTP error for job {$jobId}: " . ( $result['error'] ?? 'Unknown error' ),
				array(
					'job_id'        => $jobId,
					'task_type'     => $this->getTaskType(),
					'agent_type'    => 'system',
					'prediction_id' => $prediction_id,
					'error'         => $result['error'] ?? 'Unknown HTTP error',
				)
			);

			$this->reschedule( $jobId, 5 ); // Try again in 5 seconds
			return;
		}

		$status_data = json_decode( $result['data'], true );
		$status      = $status_data['status'] ?? '';

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
				array(
					'job_id'     => $jobId,
					'task_type'  => $this->getTaskType(),
					'agent_type' => 'system',
					'image_url'  => $image_url,
					'error'      => $sideload_result->get_error_message(),
				)
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

		// Build standardized effects array for undo.
		$effects = array();

		// Track attachment creation (undo deletes the attachment + its file).
		if ( $attachment_id ) {
			$effects[] = array(
				'type'   => 'attachment_created',
				'target' => array( 'attachment_id' => $attachment_id ),
			);
		}

		// Route based on mode: featured (default) or insert
		if ( $attachment_id ) {
			$context = $params['context'] ?? array();
			$mode    = $context['mode'] ?? 'featured';

			if ( 'insert' === $mode ) {
				$mode_effects = $this->insertImageInContent( $jobId, $attachment_id, $params );
			} else {
				$mode_effects = $this->trySetFeaturedImage( $jobId, $attachment_id, $params );
			}

			if ( ! empty( $mode_effects ) ) {
				$effects = array_merge( $effects, $mode_effects );
			}
		}

		// Complete job with success data
		$result = array(
			'success'      => true,
			'data'         => array(
				'message'         => "Image generated successfully using {$model}.",
				'image_url'       => $image_url,
				'attachment_id'   => $attachment_id,
				'attachment_url'  => $attachment_url,
				'image_file_path' => $image_file_path,
				'prompt'          => $prompt,
				'model'           => $model,
				'aspect_ratio'    => $aspectRatio,
			),
			'tool_name'    => 'image_generation',
			'effects'      => $effects,
			'completed_at' => current_time( 'mysql' ),
		);

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
	 * @return array Standardized effects for undo (empty if no action taken).
	 */
	protected function trySetFeaturedImage( int $jobId, int $attachmentId, array $params ): array {
		$context         = $params['context'] ?? array();
		$pipeline_job_id = $context['pipeline_job_id'] ?? 0;
		$direct_post_id  = $context['post_id'] ?? 0;

		// Direct post_id takes priority (standalone/direct ability calls)
		if ( ! empty( $direct_post_id ) ) {
			$post_id = (int) $direct_post_id;
		} elseif ( ! empty( $pipeline_job_id ) ) {
			// Read the pipeline job's engine data to find the published post_id
			$pipeline_engine_data = datamachine_get_engine_data( (int) $pipeline_job_id );
			$post_id              = $pipeline_engine_data['post_id'] ?? 0;

			if ( empty( $post_id ) ) {
				// Post hasn't been published yet — schedule a deferred attempt
				$this->scheduleFeaturedImageRetry( $attachmentId, $pipeline_job_id );
				return array();
			}
		} else {
			// No pipeline context and no direct post_id — nothing to do
			return array();
		}

		// Capture current thumbnail for undo support.
		$previous_thumbnail = get_post_thumbnail_id( $post_id );

		// Check if post already has a featured image
		if ( has_post_thumbnail( $post_id ) ) {
			do_action(
				'datamachine_log',
				'debug',
				"System Agent: Post #{$post_id} already has a featured image, skipping",
				array(
					'job_id'        => $jobId,
					'post_id'       => $post_id,
					'attachment_id' => $attachmentId,
					'agent_type'    => 'system',
				)
			);
			return array();
		}

		// Set the featured image
		$result = set_post_thumbnail( $post_id, $attachmentId );

		if ( $result ) {
			do_action(
				'datamachine_log',
				'info',
				"System Agent: Featured image set on post #{$post_id} (attachment #{$attachmentId})",
				array(
					'job_id'        => $jobId,
					'post_id'       => $post_id,
					'attachment_id' => $attachmentId,
					'agent_type'    => 'system',
				)
			);

			return array(
				array(
					'type'           => 'featured_image_set',
					'target'         => array( 'post_id' => $post_id ),
					'previous_value' => $previous_thumbnail ?: 0,
				),
			);
		}

		do_action(
			'datamachine_log',
			'warning',
			"System Agent: Failed to set featured image on post #{$post_id}",
			array(
				'job_id'        => $jobId,
				'post_id'       => $post_id,
				'attachment_id' => $attachmentId,
				'agent_type'    => 'system',
			)
		);

		return array();
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
			array(
				'attachment_id'   => $attachmentId,
				'pipeline_job_id' => $pipelineJobId,
				'attempt'         => 1,
			),
			'data-machine'
		);

		do_action(
			'datamachine_log',
			'debug',
			"System Agent: Scheduled deferred featured image set (attachment #{$attachmentId}, pipeline job #{$pipelineJobId})",
			array(
				'attachment_id'   => $attachmentId,
				'pipeline_job_id' => $pipelineJobId,
				'agent_type'      => 'system',
			)
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

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

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
		wp_update_post( array(
			'ID'           => $attachment_id,
			'post_title'   => $title,
			'post_content' => $prompt,
		) );

		update_post_meta( $attachment_id, '_datamachine_generated', true );
		update_post_meta( $attachment_id, '_datamachine_generation_model', $model );
		update_post_meta( $attachment_id, '_datamachine_generation_prompt', $prompt );

		$attachment_url = wp_get_attachment_url( $attachment_id );

		do_action(
			'datamachine_log',
			'info',
			"System Agent: Image sideloaded to media library (attachment #{$attachment_id})",
			array(
				'attachment_id'  => $attachment_id,
				'attachment_url' => $attachment_url,
				'model'          => $model,
				'agent_type'     => 'system',
			)
		);

		return array(
			'attachment_id'  => $attachment_id,
			'attachment_url' => $attachment_url,
		);
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
		// Detect actual MIME type from file content.
		$check = wp_get_image_mime( $tmp_file );

		if ( ! $check ) {
			// Can't determine MIME — return as-is, let sideload handle it.
			return $tmp_file;
		}

		// Already JPEG — nothing to do.
		if ( in_array( $check, array( 'image/jpeg', 'image/jpg' ), true ) ) {
			return $tmp_file;
		}

		$current_mime = $check;

		// Load into WP_Image_Editor for conversion.
		$editor = wp_get_image_editor( $tmp_file );

		if ( is_wp_error( $editor ) ) {
			// Can't load image — return as-is.
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
				array( 'agent_type' => 'system' )
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
			array(
				'original_mime' => $current_mime,
				'agent_type'    => 'system',
			)
		);

		return $converted['path'];
	}

	/**
	 * Insert the generated image as a Gutenberg image block into post content.
	 *
	 * @param int   $jobId        System Agent job ID.
	 * @param int   $attachmentId WordPress attachment ID.
	 * @param array $params       Task params (contains context with post_id, position).
	 * @return array Standardized effects for undo (empty if no action taken).
	 */
	protected function insertImageInContent( int $jobId, int $attachmentId, array $params ): array {
		$context  = $params['context'] ?? array();
		$post_id  = $context['post_id'] ?? 0;
		$position = $context['position'] ?? 'auto';

		// Also check pipeline job for post_id if not directly provided
		if ( empty( $post_id ) ) {
			$pipeline_job_id = $context['pipeline_job_id'] ?? 0;
			if ( ! empty( $pipeline_job_id ) ) {
				$pipeline_engine_data = datamachine_get_engine_data( (int) $pipeline_job_id );
				$post_id              = $pipeline_engine_data['post_id'] ?? 0;
			}
		}

		if ( empty( $post_id ) ) {
			do_action( 'datamachine_log', 'warning', "System Agent: Cannot insert image — no post_id available for job {$jobId}", array(
				'job_id'        => $jobId,
				'attachment_id' => $attachmentId,
				'agent_type'    => 'system',
			) );
			return array();
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			do_action( 'datamachine_log', 'warning', "System Agent: Post #{$post_id} not found for image insert", array(
				'job_id'     => $jobId,
				'agent_type' => 'system',
			) );
			return array();
		}

		// Build the wp:image block
		$image_block = $this->buildImageBlock( $attachmentId );
		if ( empty( $image_block ) ) {
			return array();
		}

		// Attach image to the post
		wp_update_post( array(
			'ID'          => $attachmentId,
			'post_parent' => $post_id,
		) );

		// Capture pre-modification revision for undo support.
		$revision_id = wp_save_post_revision( $post_id );

		// Parse existing content into blocks
		$content = $post->post_content;
		$blocks  = parse_blocks( $content );

		// Find insertion index based on position
		$insert_index = $this->findInsertionIndex( $blocks, $position );

		// Splice the image block in
		array_splice( $blocks, $insert_index, 0, array( $image_block ) );

		// Serialize back to content
		$new_content = serialize_blocks( $blocks );

		wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $new_content,
		) );

		do_action( 'datamachine_log', 'info', "System Agent: Image inserted into post #{$post_id} content at position '{$position}' (attachment #{$attachmentId})", array(
			'job_id'        => $jobId,
			'post_id'       => $post_id,
			'attachment_id' => $attachmentId,
			'position'      => $position,
			'insert_index'  => $insert_index,
			'agent_type'    => 'system',
		) );

		// Build effects for undo.
		$effects = array();

		if ( ! empty( $revision_id ) && ! is_wp_error( $revision_id ) ) {
			$effects[] = array(
				'type'        => 'post_content_modified',
				'target'      => array( 'post_id' => $post_id ),
				'revision_id' => $revision_id,
			);
		}

		return $effects;
	}

	/**
	 * Build a Gutenberg image block array for the given attachment.
	 *
	 * @param int $attachmentId WordPress attachment ID.
	 * @return array Parsed block array suitable for serialize_blocks().
	 */
	protected function buildImageBlock( int $attachmentId ): array {
		$image_url = wp_get_attachment_image_url( $attachmentId, 'large' );
		$alt_text  = get_post_meta( $attachmentId, '_wp_attachment_image_alt', true );

		if ( empty( $alt_text ) ) {
			$alt_text = get_the_title( $attachmentId );
		}

		if ( empty( $image_url ) ) {
			return array();
		}

		$block_attrs = array(
			'id'              => $attachmentId,
			'sizeSlug'        => 'large',
			'linkDestination' => 'none',
		);

		$escaped_alt = esc_attr( $alt_text );
		$escaped_url = esc_url( $image_url );

		$inner_html = '<figure class="wp-block-image size-large"><img src="' . $escaped_url . '" alt="' . $escaped_alt . '" class="wp-image-' . $attachmentId . '"/></figure>';

		return array(
			'blockName'    => 'core/image',
			'attrs'        => $block_attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $inner_html,
			'innerContent' => array( $inner_html ),
		);
	}

	/**
	 * Find the block index to insert an image based on position strategy.
	 *
	 * @param array  $blocks   Parsed blocks array.
	 * @param string $position Position strategy: auto (default, finds largest gap between existing images), after_intro, before_heading, end, or index:N.
	 * @return int Block index for insertion.
	 */
	protected function findInsertionIndex( array $blocks, string $position ): int {
		// Handle index:N format
		if ( str_starts_with( $position, 'index:' ) ) {
			$index = (int) substr( $position, 6 );
			return min( max( 0, $index ), count( $blocks ) );
		}

		switch ( $position ) {
			case 'after_intro':
				// After the first paragraph block
				foreach ( $blocks as $i => $block ) {
					if ( 'core/paragraph' === ( $block['blockName'] ?? '' ) ) {
						return $i + 1;
					}
				}
				// No paragraph found — insert at beginning
				return 0;

			case 'before_heading':
				// Before the first H2 or H3 heading
				foreach ( $blocks as $i => $block ) {
					if ( 'core/heading' === ( $block['blockName'] ?? '' ) ) {
						return $i;
					}
				}
				// No heading found — insert after first paragraph
				return $this->findInsertionIndex( $blocks, 'after_intro' );

			case 'end':
				return count( $blocks );

			case 'auto':
			default:
				// Find all existing image block positions.
				$image_positions = array();
				foreach ( $blocks as $i => $block ) {
					if ( 'core/image' === ( $block['blockName'] ?? '' ) ) {
						$image_positions[] = $i;
					}
				}

				// No existing images — fall back to after_intro.
				if ( empty( $image_positions ) ) {
					return $this->findInsertionIndex( $blocks, 'after_intro' );
				}

				// Calculate gaps between images (and before first / after last).
				$total_blocks = count( $blocks );
				$gaps         = array();

				// Gap before first image.
				$gaps[] = array(
					'start' => 0,
					'end'   => $image_positions[0],
					'size'  => $image_positions[0],
				);

				// Gaps between consecutive images.
				for ( $j = 0; $j < count( $image_positions ) - 1; $j++ ) {
					$gaps[] = array(
						'start' => $image_positions[ $j ],
						'end'   => $image_positions[ $j + 1 ],
						'size'  => $image_positions[ $j + 1 ] - $image_positions[ $j ],
					);
				}

				// Gap after last image.
				$last   = end( $image_positions );
				$gaps[] = array(
					'start' => $last,
					'end'   => $total_blocks,
					'size'  => $total_blocks - $last,
				);

				// Find largest gap.
				usort( $gaps, fn( $a, $b ) => $b['size'] <=> $a['size'] );
				$largest = $gaps[0];

				// Insert in the middle of the largest gap.
				$insert_at = $largest['start'] + (int) ceil( $largest['size'] / 2 );

				return min( $insert_at, $total_blocks );
		}
	}

	/**
	 * Get the task type identifier.
	 *
	 * @return string Task type identifier.
	 */
	public function getTaskType(): string {
		return 'image_generation';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Image Generation',
			'description'     => 'Generate images via Replicate API and assign as featured images or insert into content.',
			'setting_key'     => null,
			'default_enabled' => true,
		);
	}

	/**
	 * Image generation supports undo — deletes attachment, reverts featured image and content.
	 *
	 * @return bool
	 * @since 0.33.0
	 */
	public function supportsUndo(): bool {
		return true;
	}
}
