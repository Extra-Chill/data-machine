<?php
/**
 * Real Data Machine delegated-operation integration coverage.
 *
 * @package DataMachineSocials\Tests\Integration
 */

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\DelegatedOperations\DelegatedOperationRegistry;
use DataMachine\Core\DelegatedOperations\DelegatedOperationService;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\SystemTask\SystemTaskStep;
use DataMachineSocials\Operations\DelegatedCrossPostAction;
use DataMachineSocials\Tracking\SocialShareTracker;

final class DelegatedCrossPostIntegrationTest extends WP_UnitTestCase {
	private int $first_actor;
	private int $second_actor;
	private int $owner;
	private int $agent;
	private int $post_id;
	private int $attachment_id;
	private bool $authority = true;
	private array $authorization_observations = array();

	public function set_up(): void {
		parent::set_up();
		$this->first_actor  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->second_actor = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->owner        = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->agent        = ( new Agents() )->create_if_missing( 'socials-delegated-owner-' . $this->owner, 'Socials Delegated Owner', $this->owner );
		$this->post_id      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->attachment_id = self::factory()->attachment->create(
			array(
				'post_parent'    => $this->post_id,
				'post_mime_type' => 'image/jpeg',
				'guid'           => 'https://example.org/media/delegated-cross-post.jpg',
			)
		);
		add_filter( 'datamachine_socials_delegated_cross_post_authorized', array( $this, 'authorize' ), 10, 2 );
		add_filter( 'datamachine_socials_delegated_cross_post_execution_owner', array( $this, 'owner' ) );
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_socials_delegated_cross_post_authorized', array( $this, 'authorize' ), 10 );
		remove_filter( 'datamachine_socials_delegated_cross_post_execution_owner', array( $this, 'owner' ) );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function authorize( bool $authorized, array $context ): bool {
		unset( $authorized );
		$this->authorization_observations[] = array(
			'phase' => (string) ( $context['phase'] ?? '' ),
			'actor' => $context['actor'] ?? array(),
		);
		return $this->authority && in_array( (int) ( $context['actor']['user_id'] ?? 0 ), array( $this->first_actor, $this->second_actor ), true );
	}

	public function owner(): array {
		return array( 'user_id' => $this->owner, 'agent_id' => $this->agent );
	}

	public function test_real_registry_service_and_cancelled_projection(): void {
		$action = ( new DelegatedOperationRegistry() )->get( DelegatedCrossPostAction::ACTION_ID );
		$this->assertIsArray( $action );
		$this->assertSame( '2', $action['version'] );
		$this->assertIsCallable( $action['retry'] );

		wp_set_current_user( $this->first_actor );
		$request              = $this->submission( 'cancelled-parent', array() );
		$request['timestamp'] = time() + HOUR_IN_SECONDS;
		$service              = new DelegatedOperationService();
		$submitted            = $service->submit( $request );
		$cancelled            = $service->cancel( $this->operation_request( $submitted ) );

		$this->assertTrue( $submitted['success'] );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertSame( 'cancelled', $cancelled['projection']['classification'] );
		$this->assertSame( 0, $cancelled['projection']['effect_count'] );
	}

	public function test_effect_time_authority_and_live_resources_fail_closed(): void {
		wp_set_current_user( $this->first_actor );
		$service    = new DelegatedOperationService();
		$submitted  = $service->submit( $this->submission( 'effect-authority', array( 'twitter' ) ) );
		$parent_job = $this->job( 'effect-authority' );
		$actor      = DelegatedCrossPostAction::resolve_effect_actor( (int) $parent_job['job_id'], $submitted['operation_ref'] );
		$this->assertSame( $this->first_actor, $actor['user_id'] );

		$this->authority                  = false;
		$this->authorization_observations = array();
		( new SystemTaskStep() )->execute(
			array(
				'job_id'       => (int) $parent_job['job_id'],
				'flow_step_id' => (string) $parent_job['operation_step_id'],
				'data'         => array(),
				'engine'       => new EngineData( $parent_job['engine_data'], (int) $parent_job['job_id'] ),
			)
		);
		$this->assertSame( 'effect', $this->authorization_observations[0]['phase'] );
		$this->assertSame( $this->first_actor, (int) $this->authorization_observations[0]['actor']['user_id'] );
		$children = ( new Jobs() )->get_children( (int) $parent_job['job_id'] );
		$this->assertCount( 1, $children );
		$denied = $children[0];
		$this->assertSame( 'failed - delegated_cross_post_effect_denied', $denied['engine_data']['job_status'] );
		$this->assertSame( 'effect_authorization_failed', $denied['engine_data']['output_data_packets'][0]['metadata']['source_item_id'] );
		$this->assertSame( array(), SocialShareTracker::get_shares( $this->post_id ) );

		$this->authority = true;
		$normalized      = DelegatedCrossPostAction::normalize_input( $this->submission_input( array( 'twitter' ) ) );
		$this->assertIsArray( $normalized );
		wp_update_post( array( 'ID' => $this->post_id, 'post_status' => 'draft' ) );
		$this->assertWPError( DelegatedCrossPostAction::validate_effect( $normalized, array( 'user_id' => $this->first_actor ), 'dop_' . str_repeat( 'a', 64 ) ) );
		wp_update_post( array( 'ID' => $this->post_id, 'post_status' => 'publish' ) );

		wp_update_post( array( 'ID' => $this->attachment_id, 'post_mime_type' => 'application/pdf' ) );
		$this->assertWPError( DelegatedCrossPostAction::validate_effect( $normalized, array( 'user_id' => $this->first_actor ), 'dop_' . str_repeat( 'a', 64 ) ) );
		wp_update_post( array( 'ID' => $this->attachment_id, 'post_mime_type' => 'image/jpeg' ) );

		$changed_caption                 = $normalized;
		$changed_caption['caption']      = 'Changed after approval.';
		$this->assertWPError( DelegatedCrossPostAction::validate_effect( $changed_caption, array( 'user_id' => $this->first_actor ), 'dop_' . str_repeat( 'a', 64 ) ) );
	}

	public function test_cross_actor_replay_reuses_receipt_and_job_while_changed_input_conflicts(): void {
		$service = new DelegatedOperationService();
		$request = $this->submission( 'cross-actor-replay', array( 'instagram' ) );

		wp_set_current_user( $this->first_actor );
		$first     = $service->submit( $request );
		$first_job = $this->job( 'cross-actor-replay' );

		wp_set_current_user( $this->second_actor );
		$second     = $service->submit( $request );
		$second_job = $this->job( 'cross-actor-replay' );

		$this->assertTrue( $first['success'] ?? false, wp_json_encode( $first ) );
		$this->assertTrue( $second['success'] ?? false, wp_json_encode( $second ) );
		$this->assertTrue( $second['replayed'] );
		$this->assertSame( $first['operation_ref'], $second['operation_ref'] );
		$this->assertSame( (int) $first_job['job_id'], (int) $second_job['job_id'] );

		$changed                         = $request;
		$changed['input']['caption']      = 'A different approved caption.';
		$changed['input']['content_hash'] = hash( 'sha256', $changed['input']['caption'] );
		$conflict                        = $service->submit( $changed );
		$this->assertFalse( $conflict['success'] );
		$this->assertSame( 'delegated_operation_conflict', $conflict['error_code'] );
	}

	public function test_cross_actor_partial_retry_reuses_parent_and_live_receipts(): void {
		wp_set_current_user( $this->first_actor );
		$service   = new DelegatedOperationService();
		$submitted = $service->submit( $this->submission( 'partial-retry', array( 'instagram', 'twitter' ) ) );
		$this->assertTrue( $submitted['success'] ?? false, wp_json_encode( $submitted ) );
		$job       = $this->job( 'partial-retry' );
		$this->assertSame(
			get_current_blog_id() . ':' . $this->attachment_id,
			$job['operation_envelope']['delegated_operation']['input']['asset_refs'][0]['source_id']
		);
		$this->assertTrue(
			SocialShareTracker::record(
				$this->post_id,
				'instagram',
				'instagram-123',
				'https://www.instagram.com/p/instagram-123/',
				array( 'operation_ref' => $submitted['operation_ref'], 'media_kind' => 'image' )
			)
		);
		$this->terminal_result(
			$job,
			array(
				$this->packet( 'social_share_ref', 'instagram', 'instagram-123' ),
				$this->packet( 'social_share_error', 'twitter', 'undelivered' ),
			)
		);

		wp_set_current_user( $this->second_actor );
		$failed = $service->reconcile( $this->operation_request( $submitted ) );
		$this->assertSame( 'failed', $failed['status'] );
		$this->assertSame( 'partial', $failed['projection']['classification'] );
		$this->assertSame( 'instagram-123', $failed['projection']['share_refs'][0]['platform_post_id'] );

		$retried = $service->retry( $this->operation_request( $submitted ) );
		$this->assertTrue( $retried['success'] );
		$this->assertSame( $submitted['operation_ref'], $retried['operation_ref'] );
		$this->assertSame( (int) $job['job_id'], (int) $this->job( 'partial-retry' )['job_id'] );
		$this->assertSame( 1, SocialShareTracker::count_shares( $this->post_id, 'instagram' ) );
	}

	public function test_retry_rejects_missing_receipt_and_unknown_crash_boundaries(): void {
		wp_set_current_user( $this->first_actor );
		$service = new DelegatedOperationService();

		$missing = $service->submit( $this->submission( 'missing-receipt', array( 'instagram' ) ) );
		$this->assertTrue( $missing['success'] ?? false, wp_json_encode( $missing ) );
		$this->terminal_result( $this->job( 'missing-receipt' ), array( $this->packet( 'social_share_ref', 'instagram', 'instagram-123' ) ) );
		$this->assertSame( 'social_cross_post_retry_unsafe', $service->retry( $this->operation_request( $missing ) )['error_code'] );

		$unknown = $service->submit( $this->submission( 'unknown-delivery', array( 'twitter' ) ) );
		$this->terminal_result( $this->job( 'unknown-delivery' ), array( $this->packet( 'social_share_error', 'twitter', 'delivery_receipt_failed' ) ) );
		$this->assertSame( 'social_cross_post_retry_unsafe', $service->retry( $this->operation_request( $unknown ) )['error_code'] );
	}

	private function submission( string $operation_id, array $channels ): array {
		return array(
			'action'       => DelegatedCrossPostAction::ACTION_ID,
			'operation_id' => $operation_id,
			'timestamp'    => time() + HOUR_IN_SECONDS,
			'input'        => $this->submission_input( $channels ),
		);
	}

	private function submission_input( array $channels ): array {
		$caption = 'Approved integration caption.';
		return array(
			'post_id'      => $this->post_id,
			'source_url'   => get_permalink( $this->post_id ),
			'caption'      => $caption,
			'content_hash' => hash( 'sha256', $caption ),
			'channels'     => $channels,
			'media_kind'   => 'image',
			'asset_refs'   => array( array( 'attachment_id' => $this->attachment_id, 'role' => 'image' ) ),
		);
	}

	private function operation_request( array $submitted ): array {
		return array( 'action' => DelegatedCrossPostAction::ACTION_ID, 'operation_ref' => $submitted['operation_ref'] );
	}

	private function packet( string $type, string $channel, string $item_id ): array {
		return array(
			'type'           => $type,
			'source_type'    => 'datamachine_socials_cross_post',
			'source_id'      => $channel,
			'source_item_id' => $item_id,
		);
	}

	private function terminal_result( array $job, array $packet_refs ): void {
		$jobs   = new Jobs();
		$job_id = (int) $job['job_id'];
		$this->assertTrue( $jobs->complete_job( $job_id, 'failed - delegated_cross_post_test' ) );
		$job                             = $jobs->get_job( $job_id );
		$envelope                        = $job['operation_envelope'];
		$envelope['run_result']['status'] = 'failed - delegated_cross_post_test';
		$envelope['run_result']['packet_refs'] = $packet_refs;
		$this->assertTrue( $jobs->store_operation_envelope( $job_id, $envelope ) );
	}

	private function job( string $operation_id ): array {
		$key = 'delegated:' . hash( 'sha256', "delegated-idempotency\0" . DelegatedCrossPostAction::ACTION_ID . "\0{$operation_id}" );
		$job = ( new Jobs() )->get_job_by_idempotency_key( $key );
		$this->assertIsArray( $job );
		return $job;
	}
}
