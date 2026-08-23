<?php
/**
 * Multisite coverage for generic REST cross-post site identity.
 *
 * @package DataMachineSocials\Tests\Integration
 */

use DataMachineSocials\Publisher;
use DataMachineSocials\RestApi;
use DataMachineSocials\Tasks\SocialCrossPostTask;
use DataMachineSocials\Tracking\SocialShareTracker;

final class RestCrossPostMultisiteTest extends WP_UnitTestCase {
	private int $caller_site_id;
	private int $canonical_site_id;
	private int $draft_site_id;
	private int $post_id;
	private ?array $original_instagram_ability = null;
	private bool $replaced_instagram_ability = false;

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			return;
		}

		$this->caller_site_id    = self::factory()->blog->create();
		$this->canonical_site_id = self::factory()->blog->create();
		$this->draft_site_id     = self::factory()->blog->create();

		$this->post_id = $this->create_post( $this->caller_site_id, 'publish' );
		$this->assertSame( $this->post_id, $this->create_post( $this->canonical_site_id, 'publish' ) );
		$this->assertSame( $this->post_id, $this->create_post( $this->draft_site_id, 'draft' ) );
	}

	public function tear_down(): void {
		if ( $this->replaced_instagram_ability ) {
			$registry = WP_Abilities_Registry::get_instance();
			$registry->unregister( 'datamachine/instagram-publish' );
			if ( null !== $this->original_instagram_ability ) {
				$registry->register( 'datamachine/instagram-publish', $this->original_instagram_ability );
			}
		}
		parent::tear_down();
	}

	public function test_publisher_scopes_tracker_receipts_to_the_declared_site(): void {
		$this->require_multisite();
		$original_site_id = get_current_blog_id();
		$this->replace_instagram_ability();

		switch_to_blog( $this->caller_site_id );
		$result = Publisher::cross_post(
			array(
				'post_id'                 => $this->post_id,
				'post_site_id'            => $this->canonical_site_id,
				'platforms'               => array( 'instagram' ),
				'caption'                 => 'Canonical site context.',
				'images'                  => array( array( 'url' => 'https://example.test/image.jpg' ) ),
			)
		);
		$this->assertSame( $this->caller_site_id, get_current_blog_id() );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'canonical-receipt', $result['results'][0]['platform_post_id'] );
		$this->assertSame( 0, SocialShareTracker::count_shares( $this->post_id, 'instagram' ) );
		restore_current_blog();

		switch_to_blog( $this->canonical_site_id );
		$this->assertSame( 1, SocialShareTracker::count_shares( $this->post_id, 'instagram' ) );
		restore_current_blog();
		$this->assertSame( $original_site_id, get_current_blog_id() );
	}

	public function test_rest_rejects_invalid_sites_and_mismatched_posts_before_scheduling(): void {
		$this->require_multisite();

		switch_to_blog( $this->caller_site_id );
		$invalid_site = RestApi::cross_post( $this->request( array( 'post_site_id' => PHP_INT_MAX ) ) );
		$this->assertSame( 400, $invalid_site->get_status() );
		$this->assertSame( 'social_cross_post_invalid_post_site', $invalid_site->get_data()['code'] );

		$mismatched_post = RestApi::cross_post( $this->request( array( 'post_site_id' => $this->draft_site_id ) ) );
		$this->assertSame( 400, $mismatched_post->get_status() );
		$this->assertSame( 'social_cross_post_invalid_post', $mismatched_post->get_data()['code'] );
		$this->assertSame( $this->caller_site_id, get_current_blog_id() );
		restore_current_blog();
	}

	public function test_omitted_site_defaults_to_the_current_blog(): void {
		$this->require_multisite();

		switch_to_blog( $this->caller_site_id );
		$response = RestApi::cross_post( $this->request() );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'No images provided', $response->get_data()['error'] );
		$this->assertSame( $this->caller_site_id, get_current_blog_id() );
		restore_current_blog();
	}

	public function test_task_workflow_preserves_the_canonical_site_parameter(): void {
		$this->require_multisite();
		$params   = array( 'post_id' => $this->post_id, 'post_site_id' => $this->canonical_site_id );
		$workflow = ( new SocialCrossPostTask() )->getWorkflow( $params );

		$this->assertSame( $this->canonical_site_id, $workflow['steps'][0]['flow_step_settings']['params']['post_site_id'] );
	}

	private function create_post( int $site_id, string $status ): int {
		switch_to_blog( $site_id );
		try {
			return self::factory()->post->create( array( 'post_status' => $status ) );
		} finally {
			restore_current_blog();
		}
	}

	private function request( array $overrides = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/datamachine/v1/socials/post' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array_merge(
					array(
						'post_id'   => $this->post_id,
						'platforms' => array( 'instagram' ),
						'caption'   => 'Canonical site context.',
					),
					$overrides
				)
			)
		);
		return $request;
	}

	private function replace_instagram_ability(): void {
		$registry = WP_Abilities_Registry::get_instance();
		$original = $registry->unregister( 'datamachine/instagram-publish' );
		if ( $original ) {
			$reflection = new ReflectionClass( $original );
			$callback   = static function ( string $property ) use ( $reflection, $original ) {
				return $reflection->getProperty( $property )->getValue( $original );
			};
			$this->original_instagram_ability = array(
				'label'               => $original->get_label(),
				'description'         => $original->get_description(),
				'category'            => $original->get_category(),
				'input_schema'        => $original->get_input_schema(),
				'output_schema'       => $original->get_output_schema(),
				'permission_callback' => $callback( 'permission_callback' ),
				'execute_callback'    => $callback( 'execute_callback' ),
				'meta'                => $original->get_meta(),
			);
		}

		$registered = $registry->register(
			'datamachine/instagram-publish',
			array(
				'label'               => 'Test Instagram Publish',
				'description'         => 'Returns a bounded test receipt.',
				'category'            => 'datamachine-socials',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn(): array => array(
					'success'   => true,
					'media_id'  => 'canonical-receipt',
					'permalink' => 'https://www.instagram.com/p/canonical-receipt/',
				),
			)
		);
		$this->assertInstanceOf( WP_Ability::class, $registered );
		$this->replaced_instagram_ability = true;
	}

	private function require_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite is required for cross-post site context coverage.' );
		}
	}
}
