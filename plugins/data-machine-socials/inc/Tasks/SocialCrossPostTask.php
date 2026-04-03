<?php
/**
 * Social Cross-Post Task for Data Machine Task System.
 *
 * Async cross-posting of published content to social platforms.
 * Fires via Action Scheduler after a post transitions to 'publish'.
 * Each platform is posted sequentially within a single job.
 *
 * @package DataMachineSocials\Tasks
 * @since 0.9.0
 */

namespace DataMachineSocials\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\System\Tasks\SystemTask;

class SocialCrossPostTask extends SystemTask {

	/**
	 * Execute the cross-post for a specific job.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function execute( int $jobId, array $params ): void {
		$post_id   = absint( $params['post_id'] ?? 0 );
		$platforms = $params['platforms'] ?? array();
		$caption   = $params['caption'] ?? '';
		$images    = $params['images'] ?? array();
		$media_kind   = $params['media_kind'] ?? 'image';
		$aspect_ratio = $params['aspect_ratio'] ?? '4:5';
		$video_url    = $params['video_url'] ?? '';
		$cover_url    = $params['cover_url'] ?? '';

		if ( $post_id <= 0 ) {
			$this->failJob( $jobId, 'Missing or invalid post_id' );
			return;
		}

		if ( empty( $platforms ) || ! is_array( $platforms ) ) {
			$this->failJob( $jobId, 'No platforms specified' );
			return;
		}

		if ( empty( $caption ) ) {
			$this->failJob( $jobId, 'Empty caption' );
			return;
		}

		// Call the cross-post REST endpoint internally.
		$request = new \WP_REST_Request( 'POST', '/datamachine/v1/socials/post' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'platforms'     => $platforms,
			'caption'       => $caption,
			'images'        => $images,
			'aspect_ratio'  => $aspect_ratio,
			'media_kind'    => $media_kind,
			'video_url'     => $video_url,
			'cover_url'     => $cover_url,
			'post_id'       => $post_id,
			'share_to_feed' => true,
		) ) );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		// Build per-platform result log.
		$log      = array();
		$failures = array();

		if ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
			foreach ( $data['results'] as $result ) {
				$entry = array(
					'platform'  => $result['platform'] ?? 'unknown',
					'success'   => $result['success'] ?? false,
					'post_id'   => $result['platform_post_id'] ?? '',
					'url'       => $result['platform_url'] ?? '',
					'error'     => $result['error'] ?? '',
					'timestamp' => gmdate( 'c' ),
				);
				$log[] = $entry;

				if ( empty( $result['success'] ) ) {
					$failures[] = ( $result['platform'] ?? 'unknown' ) . ': ' . ( $result['error'] ?? 'Unknown error' );
				}
			}
		} else {
			$log[] = array(
				'platform'  => 'system',
				'success'   => false,
				'error'     => $data['error'] ?? 'Cross-post API returned unexpected response.',
				'timestamp' => gmdate( 'c' ),
			);
			$failures[] = $data['error'] ?? 'Unexpected response';
		}

		// Store results in post meta.
		$existing_log = get_post_meta( $post_id, '_studio_social_publish_log', true ) ?: array();
		$merged_log   = array_merge( $existing_log, $log );
		update_post_meta( $post_id, '_studio_social_publish_log', $merged_log );

		// Complete or fail the job.
		$successes = array_filter( $log, function ( $entry ) {
			return ! empty( $entry['success'] );
		} );

		if ( empty( $successes ) ) {
			$this->failJob( $jobId, 'All platforms failed: ' . implode( '; ', $failures ) );
			return;
		}

		$this->completeJob( $jobId, array(
			'post_id'       => $post_id,
			'platforms'     => $platforms,
			'results'       => $log,
			'success_count' => count( $successes ),
			'failure_count' => count( $failures ),
		) );
	}

	/**
	 * Get the task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'social_cross_post';
	}

	/**
	 * Get task metadata for UI display.
	 *
	 * @return array
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => __( 'Social Cross-Post', 'data-machine-socials' ),
			'description'     => __( 'Cross-posts published content to social platforms via Data Machine Socials.', 'data-machine-socials' ),
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'Post publish',
			'trigger_type'    => 'event',
			'supports_run'    => true,
		);
	}
}
