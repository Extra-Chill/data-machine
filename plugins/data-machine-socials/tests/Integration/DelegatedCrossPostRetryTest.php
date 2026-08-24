<?php
/**
 * Real Data Machine delegated retry coverage.
 *
 * @package DataMachineSocials\Tests\Integration
 */

use DataMachine\Core\Bootstrap\ActivationServiceProvider;
use DataMachine\Core\Bootstrap\RuntimeServiceProvider;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\DelegatedOperations\DelegatedOperationService;
use DataMachineSocials\Abilities\SocialPublishAbility;
use DataMachineSocials\Operations\DelegatedCrossPostAction;
use DataMachineSocials\Tracking\SocialShareTracker;

final class DelegatedCrossPostRetryTest extends WP_UnitTestCase {
	private array $original_abilities = array();

	private array $provider_calls = array(
		'instagram' => 0,
		'bluesky'   => 0,
	);

	public function tear_down(): void {
		$registry = WP_Abilities_Registry::get_instance();
		foreach ( $this->original_abilities as $slug => $definition ) {
			$registry->unregister( $slug );
			if ( null !== $definition ) {
				$registry->register( $slug, $definition );
			}
		}

		parent::tear_down();
	}

	public function test_failed_parent_reopens_and_successful_task_replay_executes(): void {
		ActivationServiceProvider::ensure_all_tables();
		datamachine_register_core_actions();
		RuntimeServiceProvider::register_step_types();
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
						'channels'     => array( 'instagram', 'bluesky' ),
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
			$this->replace_publish_abilities();
			$initial_execution = $this->execute_job( $job );
			$this->assertTrue( $initial_execution['success'], wp_json_encode( $initial_execution ) );
			$failed = $jobs->get_job_by_idempotency_key( $key );
			$this->assertSame( 'failed', $failed['status'] );
			$this->assertSame( 'failed - delegated_cross_post_partial', $failed['engine_data']['job_status'] );
			$this->assertSame( array( 'instagram' => 1, 'bluesky' => 1 ), $this->provider_calls );

			$ability      = new SocialPublishAbility();
			$failed_state = $ability->getState( array( 'delivery_ref' => $submitted['operation_ref'] ) );
			$this->assertSame( 'failed', $failed_state['delivery']['status'] );
			$this->assertTrue( $failed_state['delivery']['retryable'] );
			$this->assertSame( array( array( 'channel' => 'bluesky', 'code' => 'undelivered' ) ), $failed_state['delivery']['errors'] );

			$retried = $ability->retry( array( 'delivery_ref' => $submitted['operation_ref'] ) );
			$this->assertTrue( $retried['success'] );
			$this->assertSame( $submitted['operation_ref'], $retried['delivery']['delivery_ref'] );
			$reopened = $jobs->get_job_by_idempotency_key( $key );
			$this->assertSame( $job['job_id'], $reopened['job_id'] );
			$this->assertSame( 'pending', $reopened['status'] );
			$this->assertSame( 'failed - delegated_cross_post_partial', $reopened['engine_data']['job_status'] );

			$execution = $this->execute_job( $reopened );
			$this->assertFalse( is_wp_error( $execution ), is_wp_error( $execution ) ? $execution->get_error_code() . ': ' . $execution->get_error_message() : '' );
			$this->assertTrue( $execution['success'], wp_json_encode( $execution ) );

			$completed = $jobs->get_job_by_idempotency_key( $key );
			$this->assertSame( 'completed', $completed['status'] );
			$this->assertSame( 'completed', $completed['engine_data']['job_status'] );
			$this->assertSame( array( 'instagram' => 1, 'bluesky' => 2 ), $this->provider_calls );
			$this->assertSame( 1, SocialShareTracker::count_shares( $post_id, 'instagram' ) );
			$this->assertSame( 1, SocialShareTracker::count_shares( $post_id, 'bluesky' ) );

			$packet_refs = $completed['operation_envelope']['run_result']['packet_refs'];
			$this->assertCount( 2, $packet_refs );
			$this->assertSame( array( 'social_share_ref', 'social_share_ref' ), array_column( $packet_refs, 'type' ) );
			$projected_channels = array_column( $packet_refs, 'source_id' );
			sort( $projected_channels );
			$this->assertSame( array( 'bluesky', 'instagram' ), $projected_channels );
			$this->assertSame( 1, $jobs->get_jobs_count( array( 'source' => 'delegated' ) ) );

			$delivered = $ability->getState( array( 'delivery_ref' => $submitted['operation_ref'] ) );
			$this->assertSame( 'delivered', $delivered['delivery']['status'], wp_json_encode( $delivered ) );
			$this->assertCount( 2, $delivered['delivery']['deliveries'] );
			$this->assertSame( array(), $delivered['delivery']['errors'] );
		} finally {
			remove_filter( 'datamachine_socials_delegated_cross_post_authorized', $authorize, 10 );
			remove_filter( 'datamachine_socials_delegated_cross_post_execution_owner', $execution_owner );
			wp_set_current_user( 0 );
		}
	}

	private function execute_job( array $job ) {
		return wp_get_ability( 'datamachine/execute-step' )->execute(
			array(
				'job_id'                => (int) $job['job_id'],
				'flow_step_id'          => (string) $job['operation_step_id'],
				'operation_generation'  => (int) $job['operation_generation'],
				'operation_claim_token' => (string) $job['operation_claim_token'],
			)
		);
	}

	private function replace_publish_abilities(): void {
		$this->replace_ability(
			'datamachine/instagram-publish',
			function (): array {
				++$this->provider_calls['instagram'];
				return array(
					'success'   => true,
					'media_id'  => 'instagram-249',
					'permalink' => 'https://www.instagram.com/p/instagram-249/',
				);
			}
		);
		$this->replace_ability(
			'datamachine/bluesky-publish',
			function () {
				++$this->provider_calls['bluesky'];
				if ( 1 === $this->provider_calls['bluesky'] ) {
					return new WP_Error( 'media_upload_failed', 'Transient Bluesky upload failure.' );
				}
				return array(
					'success'  => true,
					'post_id'  => 'bluesky-249',
					'post_url' => 'https://bsky.app/profile/example.test/post/bluesky-249',
				);
			}
		);
	}

	private function replace_ability( string $slug, callable $callback ): void {
		$registry = WP_Abilities_Registry::get_instance();
		$original = $registry->unregister( $slug );
		if ( $original ) {
			$reflection = new ReflectionClass( $original );
			$property   = static fn( string $name ) => $reflection->getProperty( $name )->getValue( $original );
			$this->original_abilities[ $slug ] = array(
				'label'               => $original->get_label(),
				'description'         => $original->get_description(),
				'category'            => $original->get_category(),
				'input_schema'        => $original->get_input_schema(),
				'output_schema'       => $original->get_output_schema(),
				'permission_callback' => $property( 'permission_callback' ),
				'execute_callback'    => $property( 'execute_callback' ),
				'meta'                => $original->get_meta(),
			);
		} else {
			$this->original_abilities[ $slug ] = null;
		}

		$registry->register(
			$slug,
			array(
				'label'               => 'Retry projection test provider',
				'description'         => 'Returns controlled delegated retry receipts.',
				'category'            => 'datamachine-socials',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => $callback,
			)
		);
	}

}
