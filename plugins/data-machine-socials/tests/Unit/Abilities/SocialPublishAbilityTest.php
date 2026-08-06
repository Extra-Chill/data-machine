<?php
/**
 * Contract tests for durable social publishing.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities
 */

namespace DataMachineSocials\Tests\Unit\Abilities;

use DataMachineSocials\Abilities\SocialPublishAbility;
use WP_UnitTestCase;

final class SocialPublishAbilityTest extends WP_UnitTestCase {

	public function test_duplicate_enqueue_returns_the_same_delivery_as_a_duplicate(): void {
		$ability = $this->ability(
			array(
				'submit' => array(
					'success'       => true,
					'operation_ref' => $this->deliveryRef(),
					'status'        => 'submitted',
					'replayed'      => true,
				),
			)
		);

		$result = $ability->enqueue( $this->enqueueInput() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $this->deliveryRef(), $result['delivery']['delivery_ref'] );
		$this->assertTrue( $result['delivery']['duplicate'] );
		$this->assertSame( 'duplicate-key', $ability->requests['submit']['operation_id'] );
		$this->assertSame( '2:84', $ability->requests['submit']['input']['asset_refs'][0]['source_id'] );
	}

	public function test_conflicting_idempotency_reuse_has_a_stable_error(): void {
		$ability = $this->ability(
			array(
				'submit' => array(
					'success'    => false,
					'error_code' => 'delegated_operation_conflict',
					'error'      => 'Private core conflict text.',
				),
			)
		);

		$result = $ability->enqueue( $this->enqueueInput() );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'social_publish_idempotency_conflict', $result['error']['code'] );
		$this->assertFalse( $result['error']['retryable'] );
	}

	public function test_provider_unavailable_fails_before_scheduling(): void {
		$ability = $this->ability( array(), array( 'instagram' => 'provider_not_configured' ) );

		$result = $ability->enqueue( $this->enqueueInput() );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'social_publish_provider_unavailable', $result['error']['code'] );
		$this->assertSame( 'provider_not_configured', $result['error']['details']['providers'][0]['status'] );
		$this->assertArrayNotHasKey( 'submit', $ability->requests );
	}

	public function test_scheduler_failure_is_retryable_and_stable(): void {
		$ability = $this->ability(
			array(
				'submit' => array(
					'success'    => false,
					'error_code' => 'delegated_enqueue_failed',
					'error'      => 'Internal scheduler failure.',
					'retryable'  => true,
				),
			)
		);

		$result = $ability->enqueue( $this->enqueueInput() );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'social_publish_scheduler_unavailable', $result['error']['code'] );
		$this->assertTrue( $result['error']['retryable'] );
	}

	public function test_transient_delivery_failure_is_explicitly_retryable(): void {
		$ability = $this->ability(
			array(
				'get' => $this->failedState( 'undelivered' ),
			)
		);

		$result = $ability->getState( array( 'delivery_ref' => $this->deliveryRef() ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'failed', $result['delivery']['status'] );
		$this->assertSame( 'transient', $result['delivery']['failure_kind'] );
		$this->assertTrue( $result['delivery']['retryable'] );
	}

	public function test_authorized_retry_reuses_the_delivery_reference(): void {
		$ability = $this->ability(
			array(
				'retry' => array(
					'success'       => true,
					'operation_ref' => $this->deliveryRef(),
					'status'        => 'submitted',
					'replayed'      => true,
				),
			)
		);

		$result = $ability->retry( array( 'delivery_ref' => $this->deliveryRef() ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'queued', $result['delivery']['status'] );
		$this->assertSame( $this->deliveryRef(), $ability->requests['retry']['operation_ref'] );
	}

	public function test_unauthorized_retry_has_a_stable_error(): void {
		$ability = $this->ability(
			array(
				'retry' => array(
					'success'    => false,
					'error_code' => 'social_cross_post_forbidden',
					'error'      => 'Owner denied actor.',
				),
			)
		);

		$result = $ability->retry( array( 'delivery_ref' => $this->deliveryRef() ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'social_publish_forbidden', $result['error']['code'] );
	}

	public function test_terminal_failure_is_not_retryable(): void {
		$ability = $this->ability( array( 'get' => $this->failedState( 'delivery_unknown' ) ) );

		$result = $ability->getState( array( 'delivery_ref' => $this->deliveryRef() ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'terminal', $result['delivery']['failure_kind'] );
		$this->assertFalse( $result['delivery']['retryable'] );
	}

	public function test_state_projection_exposes_only_delivery_receipts_and_bounded_errors(): void {
		$ability = $this->ability(
			array(
				'get' => array(
					'success'       => true,
					'operation_ref' => $this->deliveryRef(),
					'status'        => 'executed',
					'replayed'      => true,
					'projection'    => array(
						'effect_count'   => 1,
						'classification' => 'success',
						'share_refs'     => array( array( 'channel' => 'instagram', 'platform_post_id' => 'ig-123' ) ),
						'error_codes'    => array(),
					),
					'private_data'  => 'must not cross the Socials boundary',
				),
			)
		);

		$result = $ability->getState( array( 'delivery_ref' => $this->deliveryRef() ) );

		$this->assertSame( 'delivered', $result['delivery']['status'] );
		$this->assertSame( 'ig-123', $result['delivery']['deliveries'][0]['platform_post_id'] );
		$this->assertStringNotContainsString( 'private_data', wp_json_encode( $result ) );
	}

	private function ability( array $responses, array $providers = array() ): TestableSocialPublishAbility {
		return new TestableSocialPublishAbility( $responses, $providers );
	}

	private function enqueueInput(): array {
		$caption = 'Approved social caption.';
		return array(
			'content_ref'    => array(
				'post_id'      => 42,
				'source_url'   => 'https://example.org/canonical-post/',
				'caption'      => $caption,
				'content_hash' => hash( 'sha256', $caption ),
				'asset_refs'   => array( array( 'source_id' => '2:84', 'role' => 'image' ) ),
			),
			'target_policy' => array(
				'channels'   => array( 'instagram' ),
				'media_kind' => 'image',
			),
			'idempotency_key' => 'duplicate-key',
		);
	}

	private function failedState( string $error_code ): array {
		return array(
			'success'       => true,
			'operation_ref' => $this->deliveryRef(),
			'status'        => 'failed',
			'replayed'      => true,
			'projection'    => array(
				'effect_count'   => 0,
				'classification' => 'failure',
				'share_refs'     => array(),
				'error_codes'    => array( array( 'channel' => 'instagram', 'code' => $error_code ) ),
			),
		);
	}

	private function deliveryRef(): string {
		return 'dop_' . str_repeat( 'a', 64 );
	}
}

/** Test double that prevents provider and scheduler side effects. */
final class TestableSocialPublishAbility extends SocialPublishAbility {

	public array $requests = array();

	private array $responses;
	private array $providers;

	public function __construct( array $responses, array $providers ) {
		$this->responses = $responses;
		$this->providers = $providers;
	}

	protected function providerStatus( string $channel ): string {
		return $this->providers[ $channel ] ?? 'ready';
	}

	protected function invokeDelegated( string $verb, array $input ): array {
		$this->requests[ $verb ] = $input;
		return $this->responses[ $verb ] ?? array(
			'success'    => false,
			'error_code' => 'unexpected_test_call',
			'error'      => 'Unexpected delegated operation call.',
		);
	}
}
