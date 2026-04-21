<?php
/**
 * Image Generation Task for System Agent.
 *
 * Handles async image generation through Replicate API. Polls for prediction
 * status and handles completion, failure, or rescheduling for continued polling.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.22.4
 * @since 0.72.0 Migrated to getWorkflow() + executeTask() contract.
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\HttpClient;

class ImageGenerationTask extends SystemTask {

	const MAX_ATTEMPTS = 24;
	const JPEG_QUALITY = 85;

	/**
	 * Execute image generation task.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters containing prediction_id, api_key, etc.
	 */
	public function executeTask( int $jobId, array $params ): void {
		$prediction_id = $params['prediction_id'] ?? '';
		$model         = $params['model'] ?? 'unknown';

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

		$jobs_db     = new \DataMachine\Core\Database\Jobs\Jobs();
		$job         = $jobs_db->get_job( $jobId );
		$engine_data = $job['engine_data'] ?? array();
		if ( ! isset( $engine_data['max_attempts'] ) ) {
			$engine_data['max_attempts'] = self::MAX_ATTEMPTS;
			$jobs_db->store_engine_data( $jobId, $engine_data );
		}

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
			do_action(
				'datamachine_log',
				'warning',
				"System Agent image generation HTTP error for job {$jobId}: " . ( $result['error'] ?? 'Unknown error' ),
				array(
					'job_id'        => $jobId,
					'task_type'     => $this->getTaskType(),
					'context'       => 'system',
					'prediction_id' => $prediction_id,
					'error'         => $result['error'] ?? 'Unknown HTTP error',
				)
			);

			$this->reschedule( $jobId, 5 );
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
				$this->reschedule( $jobId, 5 );
				break;

			default:
				$this->failJob( $jobId, "Unknown prediction status: {$status}" );
		}
	}

	/**
	 * Handle successful prediction completion.
	 *
	 * @param int    $jobId       Job ID.
	 * @param array  $statusData  Replicate prediction status data.
	 * @param string $model       Model used for generation.
	 * @param string $prompt      Original prompt.
	 * @param string $aspectRatio Original aspect ratio.
	 * @param array  $params      Task params.
	 */
	protected function handleSuccess( int $jobId, array $statusData, string $model, string $prompt, string $aspectRatio, array $params ): void {
		$output = $statusData['output'] ?? null;

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

		$attachment_id  = null;
		$attachment_url = null;

		$sideload_result = $this->sideloadImage( $image_url, $prompt, $model );

		if ( is_wp_error( $sideload_result ) ) {
			do_action(
				'datamachine_log',
				'warning',
				"System Agent: Image sideload failed for job {$jobId}: " . $sideload_result->get_error_message(),
				array(
					'job_id'    => $jobId,
					'task_type' => $this->getTaskType(),
					'context'   => 'system',
					'image_url' => $image_url,
					'error'     => $sideload_result->get_error_message(),
				)
			);
		} else {
			$attachment_id  = $sideload_result['attachment_id'];
			$attachment_url = $sideload_result['attachment_url'];
		}

		$image_file_path = null;
		if ( $attachment_id ) {
			$image_file_path = get_attached_file( $attachment_id );
		}

		$effects = array();

		if ( $attachment_id ) {
			$effects[] = array(
				'type'   => 'attachment_created',
				'target' => array( 'attachment_id' => $attachment_id ),
			);
		}

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

	protected function trySetFeaturedImage( int $jobId, int $attachmentId, array $params ): array {
		$context         = $params['context'] ?? array();
		$pipeline_job_id = $context['pipeline_job_id'] ?? 0;
		$direct_post_id  = $context['post_id'] ?? 0;

		if ( ! empty( $direct_post_id ) ) {
			$post_id = (int) $direct_post_id;
		} elseif ( ! empty( $pipeline_job_id ) ) {
			$pipeline_engine_data = datamachine_get_engine_data( (int) $pipeline_job_id );
			$post_id              = $pipeline_engine_data['post_id'] ?? 0;

			if ( empty( $post_id ) ) {
				$this->scheduleFeaturedImageRetry( $attachmentId, $pipeline_job_id );
				return array();
			}
		} else {
			return array();
		}

		$previous_thumbnail = get_post_thumbnail_id( $post_id );

		if ( has_post_thumbnail( $post_id ) ) {
			do_action(
				'datamachine_log',
				'debug',
				"System Agent: Post #{$post_id} already has a featured image, skipping",
				array( 'job_id' => $jobId, 'post_id' => $post_id, 'attachment_id' => $attachmentId, 'context' => 'system' )
			);
			return array();
		}

		$result = set_post_thumbnail( $post_id, $attachmentId );

		if ( $result ) {
			do_action(
				'datamachine_log',
				'info',
				"System Agent: Featured image set on post #{$post_id} (attachment #{$attachmentId})",
				array( 'job_id' => $jobId, 'post_id' => $post_id, 'attachment_id' => $attachmentId, 'context' => 'system' )
			);

			return array(
				array(
					'type'           => 'featured_image_set',
					'target'         => array( 'post_id' => $post_id ),
					'previous_value' => $previous_thumbnail ? $previous_thumbnail : 0,
				),
			);
		}

		return array();
	}

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
	}

	protected function sideloadImage( string $image_url, string $prompt, string $model ): array|\WP_Error {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp_file = download_url( $image_url );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$tmp_file = $this->maybeConvertToJpeg( $tmp_file );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$slug     = sanitize_title( mb_substr( $prompt, 0, 80 ) );
		$filename = "ai-generated-{$slug}.jpg";

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			return $attachment_id;
		}

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

		return array(
			'attachment_id'  => $attachment_id,
			'attachment_url' => $attachment_url,
		);
	}

	protected function maybeConvertToJpeg( string $tmp_file ): string|\WP_Error {
		$check = wp_get_image_mime( $tmp_file );

		if ( ! $check ) {
			return $tmp_file;
		}

		if ( in_array( $check, array( 'image/jpeg', 'image/jpg' ), true ) ) {
			return $tmp_file;
		}

		$editor = wp_get_image_editor( $tmp_file );
		if ( is_wp_error( $editor ) ) {
			return $tmp_file;
		}

		$editor->set_quality( self::JPEG_QUALITY );
		$converted = $editor->save( $tmp_file . '.jpg', 'image/jpeg' );

		if ( is_wp_error( $converted ) ) {
			return $tmp_file;
		}

		wp_delete_file( $tmp_file );

		return $converted['path'];
	}

	protected function insertImageInContent( int $jobId, int $attachmentId, array $params ): array {
		$context  = $params['context'] ?? array();
		$post_id  = $context['post_id'] ?? 0;
		$position = $context['position'] ?? 'auto';

		if ( empty( $post_id ) ) {
			$pipeline_job_id = $context['pipeline_job_id'] ?? 0;
			if ( ! empty( $pipeline_job_id ) ) {
				$pipeline_engine_data = datamachine_get_engine_data( (int) $pipeline_job_id );
				$post_id              = $pipeline_engine_data['post_id'] ?? 0;
			}
		}

		if ( empty( $post_id ) ) {
			return array();
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$image_block = $this->buildImageBlock( $attachmentId );
		if ( empty( $image_block ) ) {
			return array();
		}

		wp_update_post( array(
			'ID'          => $attachmentId,
			'post_parent' => $post_id,
		) );

		$revision_id = wp_save_post_revision( $post_id );

		$content = $post->post_content;
		$blocks  = parse_blocks( $content );

		$insert_index = $this->findInsertionIndex( $blocks, $position );

		array_splice( $blocks, $insert_index, 0, array( $image_block ) );

		$new_content = serialize_blocks( $blocks );

		wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $new_content,
		) );

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

	protected function findInsertionIndex( array $blocks, string $position ): int {
		if ( str_starts_with( $position, 'index:' ) ) {
			$index = (int) substr( $position, 6 );
			return min( max( 0, $index ), count( $blocks ) );
		}

		switch ( $position ) {
			case 'after_intro':
				foreach ( $blocks as $i => $block ) {
					if ( 'core/paragraph' === ( $block['blockName'] ?? '' ) ) {
						return $i + 1;
					}
				}
				return 0;

			case 'before_heading':
				foreach ( $blocks as $i => $block ) {
					if ( 'core/heading' === ( $block['blockName'] ?? '' ) ) {
						return $i;
					}
				}
				return $this->findInsertionIndex( $blocks, 'after_intro' );

			case 'end':
				return count( $blocks );

			case 'auto':
			default:
				$image_positions = array();
				foreach ( $blocks as $i => $block ) {
					if ( 'core/image' === ( $block['blockName'] ?? '' ) ) {
						$image_positions[] = $i;
					}
				}

				if ( empty( $image_positions ) ) {
					return $this->findInsertionIndex( $blocks, 'after_intro' );
				}

				$total_blocks = count( $blocks );
				$gaps         = array();

				$gaps[] = array( 'start' => 0, 'end' => $image_positions[0], 'size' => $image_positions[0] );

				$image_positions_count = count( $image_positions );
				for ( $j = 0; $j < $image_positions_count - 1; $j++ ) {
					$gaps[] = array(
						'start' => $image_positions[ $j ],
						'end'   => $image_positions[ $j + 1 ],
						'size'  => $image_positions[ $j + 1 ] - $image_positions[ $j ],
					);
				}

				$last   = end( $image_positions );
				$gaps[] = array( 'start' => $last, 'end' => $total_blocks, 'size' => $total_blocks - $last );

				usort( $gaps, fn( $a, $b ) => $b['size'] <=> $a['size'] );
				$largest = $gaps[0];

				$insert_at = $largest['start'] + (int) ceil( $largest['size'] / 2 );
				return min( $insert_at, $total_blocks );
		}
	}

	/**
	 * @return string
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
			'trigger'         => 'AI tool call',
			'trigger_type'    => 'tool',
			'supports_run'    => false,
		);
	}

	/**
	 * @return bool
	 */
	public function supportsUndo(): bool {
		return true;
	}
}
