<?php
/**
 * Social Cross-Post Task for Data Machine Task System.
 *
 * Async cross-posting of published content to social platforms.
 * Fires via the workflow engine (datamachine/execute-workflow).
 * Each platform is posted sequentially within a single job.
 *
 * @package DataMachineSocials\Tasks
 * @since   0.9.0
 * @since   0.12.0 Removed rest_do_request(); calls Publisher directly.
 */

namespace DataMachineSocials\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineSocials\Operations\DelegatedCrossPostAction;
use DataMachineSocials\Publisher;

defined( 'ABSPATH' ) || exit;

class SocialCrossPostTask extends SystemTask {

	/**
	 * Execute the cross-post for a specific job.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function executeTask( int $jobId, array $params ): void {
		$post_id       = absint( $params['post_id'] ?? 0 );
		$platforms     = $params['platforms'] ?? array();
		$caption       = $params['caption'] ?? '';
		$images        = $params['images'] ?? array();
		$media_kind    = $params['media_kind'] ?? 'image';
		$aspect_ratio  = $params['aspect_ratio'] ?? '4:5';
		$video_url     = $params['video_url'] ?? '';
		$cover_url     = $params['cover_url'] ?? '';
		$operation_ref = $params['delegated_operation_ref'] ?? '';

		if ( empty( $platforms ) || ! is_array( $platforms ) ) {
			if ( '' !== $operation_ref ) {
				$this->completeJob(
					$jobId,
					array(
						'job_status'             => 'completed_no_items',
						'skipped'                => true,
						'suppress_result_packet' => true,
					)
				);
				return;
			}
			$this->failJob( $jobId, 'No platforms specified' );
			return;
		}

		if ( empty( $caption ) ) {
			$this->failJob( $jobId, 'Empty caption' );
			return;
		}

		// Publish directly via Publisher utility (no REST round-trip).
		$publish_result = Publisher::cross_post( $params );

		if ( ! empty( $publish_result['error'] ) && empty( $publish_result['results'] ) ) {
			// Validation-level failure (e.g. missing video_url for reel).
			$this->failJob( $jobId, $publish_result['error'] );
			return;
		}

		$results   = $publish_result['results'] ?? array();
		$errors    = $publish_result['errors'] ?? array();
		$successes = array_filter( $results, fn( $r ) => ! empty( $r['success'] ) );

		// Build normalized log entries.
		$log = array();
		foreach ( $results as $result ) {
			$log[] = array(
				'platform'  => $result['platform'] ?? 'unknown',
				'success'   => $result['success'] ?? false,
				'post_id'   => $result['platform_post_id'] ?? '',
				'url'       => $result['platform_url'] ?? '',
				'error'     => $result['error'] ?? '',
				'timestamp' => gmdate( 'c' ),
			);
		}

		// Store results in post meta when post_id is available.
		if ( $post_id ) {
			$existing_log = get_post_meta( $post_id, '_studio_social_publish_log', true );
			$existing_log = $existing_log ? $existing_log : array();
			$merged_log   = array_merge( $existing_log, $log );
			update_post_meta( $post_id, '_studio_social_publish_log', $merged_log );
		}

		// Write full results to job engine_data.
		$completion_data = array(
			'post_id'       => $post_id ? $post_id : null,
			'platforms'     => $platforms,
			'results'       => $results,
			'log'           => $log,
			'success_count' => count( $successes ),
			'failure_count' => count( $errors ),
		);

		if ( '' !== $operation_ref ) {
			$completion_data['output_data_packets']    = self::delegated_result_packets( $results );
			$completion_data['suppress_result_packet'] = true;
		}

		if ( '' !== $operation_ref && ! empty( $errors ) ) {
			$completion_data['job_status'] = empty( $successes )
				? 'failed - delegated_cross_post_failed'
				: 'failed - delegated_cross_post_partial';
			$this->completeJob( $jobId, $completion_data );
			return;
		}

		if ( empty( $successes ) ) {
			$this->failJob( $jobId, 'All platforms failed: ' . implode( '; ', $errors ) );
			return;
		}

		$this->completeJob( $jobId, $completion_data );
	}

	/**
	 * Build safe canonical packet references for delegated projection.
	 *
	 * @param array $results Publisher results.
	 * @return array
	 */
	private static function delegated_result_packets( array $results ): array {
		$packets = array();
		foreach ( $results as $result ) {
			$platform = sanitize_key( (string) ( $result['platform'] ?? '' ) );
			if ( '' === $platform ) {
				continue;
			}

			$success = ! empty( $result['success'] );

			$item_ref = $success
				? sanitize_text_field( (string) ( $result['platform_post_id'] ?? '' ) )
				: DelegatedCrossPostAction::classify_error( $result['error'] ?? '' );

			$packets[] = array(
				'type'     => $success ? 'social_share_ref' : 'social_share_error',
				'data'     => array(),
				'metadata' => array(
					'source_type'    => 'datamachine_socials_cross_post',
					'source_id'      => $platform,
					'source_item_id' => $item_ref,
					'success'        => $success,
				),
			);
		}

		return $packets;
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
