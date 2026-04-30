<?php
/**
 * Image Generation Task for System Agent.
 *
 * Handles async media processing for wp-ai-client generated images.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.22.4
 * @since 0.72.0 Migrated to getWorkflow() + executeTask() contract.
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

class ImageGenerationTask extends SystemTask {

	const JPEG_QUALITY = 85;

	/**
	 * Execute image generation task.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters containing generated image file data.
	 */
	public function executeTask( int $jobId, array $params ): void {
		$image_url      = $params['image_url'] ?? '';
		$image_data_uri = $params['image_data_uri'] ?? '';
		$model          = $params['model'] ?? 'unknown';
		$prompt         = $params['prompt'] ?? '';
		$aspect_ratio   = $params['aspect_ratio'] ?? '';

		if ( empty( $image_url ) && empty( $image_data_uri ) ) {
			$this->failJob( $jobId, 'Missing generated image file in task parameters' );
			return;
		}

		$this->handleSuccess(
			$jobId,
			array( 'output' => ! empty( $image_url ) ? $image_url : $image_data_uri ),
			$model,
			$prompt,
			$aspect_ratio,
			$params
		);
	}

	/**
	 * Handle successful prediction completion.
	 *
	 * @param int    $jobId       Job ID.
	 * @param array  $statusData  Generated image status data.
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
			$this->failJob( $jobId, 'Image generation succeeded but no image file found in output' );
			return;
		}

		$attachment_id  = null;
		$attachment_url = null;

		$sideload_result = $this->sideloadImage( $image_url, $prompt, $model );

		if ( $sideload_result instanceof \WP_Error ) {
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
		} elseif ( is_array( $sideload_result ) ) {
			$attachment_id  = (int) ( $sideload_result['attachment_id'] ?? 0 );
			$attachment_url = (string) ( $sideload_result['attachment_url'] ?? '' );
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
				array(
					'job_id'        => $jobId,
					'post_id'       => $post_id,
					'attachment_id' => $attachmentId,
					'context'       => 'system',
				)
			);
			return array();
		}

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
					'context'       => 'system',
				)
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

		$tmp_file = str_starts_with( $image_url, 'data:' )
			? $this->dataUriToTempFile( $image_url )
			: download_url( $image_url );

		if ( $tmp_file instanceof \WP_Error ) {
			return $tmp_file;
		}

		$tmp_file = $this->maybeConvertToJpeg( $tmp_file );

		if ( $tmp_file instanceof \WP_Error ) {
			return $tmp_file;
		}

		$slug     = sanitize_title( mb_substr( $prompt, 0, 80 ) );
		$filename = "ai-generated-{$slug}.jpg";

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( $attachment_id instanceof \WP_Error ) {
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			return $attachment_id;
		}

		$attachment_id = (int) $attachment_id;

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

	protected function dataUriToTempFile( string $dataUri ): string|\WP_Error {
		if ( ! preg_match( '/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUri, $matches ) ) {
			return new \WP_Error(
				'datamachine_invalid_image_data_uri',
				'Generated image data URI is invalid.'
			);
		}

		$decoded = base64_decode( $matches[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded ) {
			return new \WP_Error(
				'datamachine_invalid_image_base64',
				'Generated image data could not be decoded.'
			);
		}

		$tmp_file = wp_tempnam( 'datamachine-generated-image' );
		if ( ! $tmp_file ) {
			return new \WP_Error(
				'datamachine_image_tempfile_failed',
				'Could not create a temporary file for generated image data.'
			);
		}

		if ( false === file_put_contents( $tmp_file, $decoded ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $tmp_file );
			return new \WP_Error(
				'datamachine_image_tempfile_write_failed',
				'Could not write generated image data to a temporary file.'
			);
		}

		return $tmp_file;
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
		if ( $editor instanceof \WP_Error ) {
			return $tmp_file;
		}

		$editor->set_quality( self::JPEG_QUALITY );
		$converted = $editor->save( $tmp_file . '.jpg', 'image/jpeg' );

		if ( $converted instanceof \WP_Error ) {
			return $tmp_file;
		}

		if ( empty( $converted['path'] ) ) {
			return $tmp_file;
		}

		wp_delete_file( $tmp_file );

		return (string) $converted['path'];
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
		if ( ! $post instanceof \WP_Post ) {
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

				$gaps[] = array(
					'start' => 0,
					'end'   => $image_positions[0],
					'size'  => $image_positions[0],
				);

				$image_positions_count = count( $image_positions );
				for ( $j = 0; $j < $image_positions_count - 1; $j++ ) {
					$gaps[] = array(
						'start' => $image_positions[ $j ],
						'end'   => $image_positions[ $j + 1 ],
						'size'  => $image_positions[ $j + 1 ] - $image_positions[ $j ],
					);
				}

				$last   = end( $image_positions );
				$gaps[] = array(
					'start' => $last,
					'end'   => $total_blocks,
					'size'  => $total_blocks - $last,
				);

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
			'description'     => 'Process wp-ai-client generated images and assign as featured images or insert into content.',
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
