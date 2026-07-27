<?php
/**
 * Real Data Machine delegated retry coverage.
 *
 * @package DataMachineSocials\Tests\Integration
 */

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\DelegatedOperations\DelegatedOperationService;
use DataMachineSocials\Operations\DelegatedCrossPostAction;
use DataMachineSocials\Tracking\SocialShareTracker;

final class DelegatedCrossPostRetryTest extends WP_UnitTestCase {
	public function test_failed_parent_reopens_and_successful_task_replay_executes(): void {
		datamachine_create_network_agent_tables();
		datamachine_ensure_all_tables();
		datamachine_register_core_actions();
		datamachine_load_step_types();
		datamachine_socials_bootstrap();
		\DataMachine\Engine\Tasks\TaskRegistry::reset();
		$actor         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$owner         = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$agent         = ( new Agents() )->create_if_missing( 'socials-retry-owner', 'Socials Retry Owner', $owner );
		$post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'post_parent'    => $post_id,
				'post_mime_type' => 'image/jpeg',
				'guid'           => 'https://example.org/uploads/retry.jpg',
			)
		);
		$authorize     = static fn( bool $authorized, array $context ): bool => $actor === (int) ( $context['actor']['user_id'] ?? 0 );
		$execution_owner = static fn(): array => array( 'user_id' => $owner, 'agent_id' => $agent );
		add_filter( 'datamachine_socials_delegated_cross_post_authorized', $authorize, 10, 2 );
		add_filter( 'datamachine_socials_delegated_cross_post_execution_owner', $execution_owner );
		wp_set_current_user( $actor );

		try {
			$service      = new DelegatedOperationService();
			$caption      = 'Approved retry caption.';
			$operation_id = 'parent-status-retry';
			$submitted    = $service->submit(
				array(
					'action'       => DelegatedCrossPostAction::ACTION_ID,
					'operation_id' => $operation_id,
					'input'        => array(
						'post_id'      => $post_id,
						'source_url'   => get_permalink( $post_id ),
						'caption'      => $caption,
						'content_hash' => hash( 'sha256', $caption ),
						'channels'     => array( 'instagram' ),
						'media_kind'   => 'image',
						'asset_refs'   => array( array( 'attachment_id' => $attachment_id, 'role' => 'image' ) ),
					),
				)
			);
			$this->assertTrue( $submitted['success'], wp_json_encode( $submitted ) );

			$jobs = new Jobs();
			$key  = 'delegated:' . hash( 'sha256', "delegated-idempotency\0" . DelegatedCrossPostAction::ACTION_ID . "\0{$operation_id}" );
			$job  = $jobs->get_job_by_idempotency_key( $key );
			$this->assertIsArray( $job );
			$this->assertTrue( SocialShareTracker::record( $post_id, 'instagram', 'instagram-existing', 'https://www.instagram.com/p/instagram-existing/', array( 'operation_ref' => $submitted['operation_ref'] ) ) );

			$engine               = $job['engine_data'];
			$engine['job_status'] = 'failed - delegated_cross_post_failed';
			$this->assertTrue( $jobs->store_engine_data( (int) $job['job_id'], $engine ) );
			$this->assertTrue( $jobs->complete_job( (int) $job['job_id'], 'failed - delegated_cross_post_failed' ) );
			$job                             = $jobs->get_job_by_idempotency_key( $key );
			$envelope                        = $job['operation_envelope'];
			$envelope['run_result']          = array(
				'schema_version' => 'datamachine.run_result.v1',
				'status'         => 'failed - delegated_cross_post_failed',
				'outputs'        => array(),
				'packet_refs'    => array(
					array(
						'type'           => 'social_share_ref',
						'source_type'    => 'datamachine_socials_cross_post',
						'source_id'      => 'instagram',
						'source_item_id' => 'instagram-existing',
					),
				),
			);
			$this->assertTrue( $jobs->store_operation_envelope( (int) $job['job_id'], $envelope ) );

			$retried = $service->retry( array( 'action' => DelegatedCrossPostAction::ACTION_ID, 'operation_ref' => $submitted['operation_ref'] ) );
			$this->assertTrue( $retried['success'] );
			$reopened = $jobs->get_job_by_idempotency_key( $key );
			$this->assertSame( 'pending', $reopened['status'] );
			$this->assertSame( 'failed - delegated_cross_post_failed', $reopened['engine_data']['job_status'] );

			$execution = wp_get_ability( 'datamachine/execute-step' )->execute(
				array(
					'job_id'                => (int) $reopened['job_id'],
					'flow_step_id'          => (string) $reopened['operation_step_id'],
					'operation_generation'  => (int) $reopened['operation_generation'],
					'operation_claim_token' => (string) $reopened['operation_claim_token'],
				)
			);
			$this->assertFalse( is_wp_error( $execution ), is_wp_error( $execution ) ? $execution->get_error_code() . ': ' . $execution->get_error_message() : '' );
			$this->assertTrue( $execution['success'], wp_json_encode( $execution ) );

			$completed = $jobs->get_job_by_idempotency_key( $key );
			$this->assertSame( 'completed', $completed['status'] );
			$this->assertSame( 'completed', $completed['engine_data']['job_status'] );
			$this->assertSame( 1, SocialShareTracker::count_shares( $post_id, 'instagram' ) );
			$reconciled = $service->reconcile( array( 'action' => DelegatedCrossPostAction::ACTION_ID, 'operation_ref' => $submitted['operation_ref'] ) );
			$this->assertSame( 'executed', $reconciled['status'] );
		} finally {
			remove_filter( 'datamachine_socials_delegated_cross_post_authorized', $authorize, 10 );
			remove_filter( 'datamachine_socials_delegated_cross_post_execution_owner', $execution_owner );
			wp_set_current_user( 0 );
		}
	}
}
