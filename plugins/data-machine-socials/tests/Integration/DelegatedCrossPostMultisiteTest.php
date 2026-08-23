<?php
/**
 * Multisite identity coverage for delegated social publishing.
 *
 * @package DataMachineSocials\Tests\Integration
 */

use DataMachineSocials\Operations\DelegatedCrossPostAction;
use DataMachineSocials\Publisher;
use DataMachineSocials\Tracking\SocialShareTracker;

final class DelegatedCrossPostMultisiteTest extends WP_UnitTestCase {
	private int $canonical_site_id;
	private int $asset_site_id;
	private int $attribution_site_id;
	private int $post_id;
	private int $attribution_post_id;
	private int $attachment_id;
	private string $canonical_url;
	private string $asset_url;
	private array $authorized_inputs = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			return;
		}

		$this->canonical_site_id = self::factory()->blog->create();
		$this->asset_site_id     = self::factory()->blog->create();
		$this->attribution_site_id = self::factory()->blog->create();

		switch_to_blog( $this->canonical_site_id );
		try {
			$this->attachment_id = self::factory()->attachment->create(
				array(
					'post_mime_type' => 'image/jpeg',
					'guid'           => 'https://canonical.example.test/colliding-image.jpg',
				)
			);
			$this->post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
			$this->canonical_url = get_permalink( $this->post_id );
		} finally {
			restore_current_blog();
		}

		switch_to_blog( $this->asset_site_id );
		try {
			$this->asset_url = 'https://assets.example.test/canonical-image.jpg';
			$created         = wp_insert_attachment(
				array(
					'import_id'      => $this->attachment_id,
					'post_mime_type' => 'image/jpeg',
					'post_status'    => 'inherit',
					'guid'           => $this->asset_url,
				)
			);
			$this->assertSame( $this->attachment_id, $created, 'The fixture must create colliding attachment IDs.' );
		} finally {
			restore_current_blog();
		}

		switch_to_blog( $this->attribution_site_id );
		try {
			$this->attribution_post_id = wp_insert_post(
				array(
					'import_id'   => $this->post_id,
					'post_status' => 'publish',
					'post_title'  => 'Attribution owner',
				)
			);
			$this->assertSame( $this->post_id, $this->attribution_post_id, 'The fixture must create colliding post IDs.' );
		} finally {
			restore_current_blog();
		}

		add_filter( 'datamachine_socials_delegated_cross_post_authorized', array( $this, 'authorize' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_socials_delegated_cross_post_authorized', array( $this, 'authorize' ), 10 );
		parent::tear_down();
	}

	public function authorize( bool $authorized, array $context ): bool {
		unset( $authorized );
		$this->authorized_inputs[] = $context['input'] ?? array();
		return true;
	}

	public function test_attribution_keeps_operation_resource_and_scopes_receipts_to_owner_site(): void {
		$this->require_multisite();
		$original_site_id = get_current_blog_id();

		switch_to_blog( $this->canonical_site_id );
		try {
			$input                     = $this->input( $this->asset_site_id . ':' . $this->attachment_id );
			$input['attribution_post'] = array(
				'site_id' => $this->attribution_site_id,
				'post_id' => $this->attribution_post_id,
			);
			$normalized                = DelegatedCrossPostAction::normalize_input( $input );
			$this->assertIsArray( $normalized );
			$operation_ref = 'dop_' . str_repeat( 'c', 64 );
			$validated     = DelegatedCrossPostAction::validate_effect( $normalized, array( 'user_id' => 1 ), $operation_ref );
			$this->assertIsArray( $validated );
			$this->assertSame( $this->post_id, $this->authorized_inputs[0]['post_id'] );
			$this->assertSame( $this->canonical_site_id, $this->authorized_inputs[0]['post_site_id'] );
			$owner = static fn(): array => array( 'user_id' => 1, 'agent_id' => 1 );
			add_filter( 'datamachine_socials_delegated_cross_post_execution_owner', $owner );
			$prepared = DelegatedCrossPostAction::prepare( $normalized, array( 'operation_ref' => $operation_ref ) );
			remove_filter( 'datamachine_socials_delegated_cross_post_execution_owner', $owner );
			$params = $prepared['workflow']['steps'][0]['flow_step_settings']['params'];
			$this->assertSame( $this->post_id, $params['post_id'] );
			$this->assertSame( $normalized['attribution_post'], $params['attribution_post'] );

		} finally {
			restore_current_blog();
		}

		switch_to_blog( $this->attribution_site_id );
		try {
			$this->assertTrue(
				SocialShareTracker::record(
					$this->attribution_post_id,
					'instagram',
					'owner-receipt',
					'https://www.instagram.com/p/owner-receipt/',
					array( 'operation_ref' => $operation_ref )
				)
			);
		} finally {
			restore_current_blog();
		}

		switch_to_blog( $this->canonical_site_id );
		try {
			$result = Publisher::cross_post(
				array(
					'post_site_id'            => $this->canonical_site_id,
					'post_id'                 => $this->post_id,
					'attribution_post'        => $normalized['attribution_post'],
					'platforms'               => array( 'instagram' ),
					'caption'                 => 'Approved multisite caption.',
					'images'                  => array( array( 'url' => $this->asset_url ) ),
					'delegated_operation_ref' => $operation_ref,
				)
			);
			$this->assertTrue( $result['success'] );
			$this->assertTrue( $result['results'][0]['replayed'] );
			$this->assertSame( array(), SocialShareTracker::get_shares( $this->post_id ) );
		} finally {
			restore_current_blog();
		}

		switch_to_blog( $this->attribution_site_id );
		try {
			$this->assertSame( 1, SocialShareTracker::count_shares( $this->attribution_post_id, 'instagram' ) );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( $original_site_id, get_current_blog_id() );

		$run_result = array(
			'status'      => 'failed',
			'packet_refs' => array(
				array(
					'type'           => 'social_share_ref',
					'source_type'    => 'datamachine_socials_cross_post',
					'source_id'      => 'instagram',
					'source_item_id' => 'owner-receipt',
				),
			),
		);
		$context = array( 'input' => $normalized, 'actor' => array( 'user_id' => 1 ), 'operation_ref' => $operation_ref );
		$this->assertSame( 'success', DelegatedCrossPostAction::project( $run_result, $context )['classification'] );
		$this->assertTrue( DelegatedCrossPostAction::retry( $run_result, $context ) );
	}

	public function test_attribution_omission_and_invalid_references_are_bounded(): void {
		$this->require_multisite();

		switch_to_blog( $this->canonical_site_id );
		try {
			$input      = $this->input( $this->asset_site_id . ':' . $this->attachment_id );
			$normalized = DelegatedCrossPostAction::normalize_input( $input );
			$this->assertIsArray( $normalized );
			$this->assertArrayNotHasKey( 'attribution_post', $normalized );

			$malformed                     = $input;
			$malformed['attribution_post'] = array( 'site_id' => $this->attribution_site_id, 'post_id' => $this->attribution_post_id, 'extra' => 1 );
			$this->assertSame( 'social_cross_post_invalid_attribution_post', DelegatedCrossPostAction::normalize_input( $malformed )->get_error_code() );

			$missing_site                     = $input;
			$missing_site['attribution_post'] = array( 'site_id' => PHP_INT_MAX, 'post_id' => $this->attribution_post_id );
			$this->assertSame( 'social_cross_post_invalid_attribution_post', DelegatedCrossPostAction::normalize_input( $missing_site )->get_error_code() );

			switch_to_blog( $this->attribution_site_id );
			wp_update_post( array( 'ID' => $this->attribution_post_id, 'post_status' => 'draft' ) );
			restore_current_blog();
			$draft                     = $input;
			$draft['attribution_post'] = array( 'site_id' => $this->attribution_site_id, 'post_id' => $this->attribution_post_id );
			$this->assertSame( 'social_cross_post_invalid_attribution_post', DelegatedCrossPostAction::normalize_input( $draft )->get_error_code() );
		} finally {
			if ( get_current_blog_id() !== $this->canonical_site_id ) {
				restore_current_blog();
			}
			restore_current_blog();
		}
	}

	public function test_cross_site_asset_identity_survives_effect_validation_and_retry(): void {
		$this->require_multisite();
		$original_site_id = get_current_blog_id();

		switch_to_blog( $this->canonical_site_id );
		try {
			$normalized = DelegatedCrossPostAction::normalize_input( $this->input( $this->asset_site_id . ':' . $this->attachment_id ) );
			$this->assertIsArray( $normalized );
			$this->assertSame( $this->canonical_site_id, get_current_blog_id() );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( $original_site_id, get_current_blog_id() );
		$this->assertSame( $this->canonical_site_id, $normalized['post_site_id'] );
		$this->assertSame( $this->asset_site_id . ':' . $this->attachment_id, $normalized['asset_refs'][0]['source_id'] );
		$this->assertSame( $this->asset_url, $normalized['images'][0]['url'] );

		$operation_ref = 'dop_' . str_repeat( 'a', 64 );
		$revalidated   = DelegatedCrossPostAction::validate_effect( $normalized, array( 'user_id' => 1 ), $operation_ref );
		$this->assertIsArray( $revalidated );
		$this->assertSame( $this->asset_site_id . ':' . $this->attachment_id, $revalidated['asset_refs'][0]['source_id'] );
		$this->assertSame( $original_site_id, get_current_blog_id() );

		$retry = DelegatedCrossPostAction::retry(
			array(
				'status'      => 'failed',
				'packet_refs' => array(
					array(
						'type'           => 'social_share_error',
						'source_type'    => 'datamachine_socials_cross_post',
						'source_id'      => 'instagram',
						'source_item_id' => 'undelivered',
					),
				),
			),
			array(
				'input'         => $normalized,
				'actor'         => array( 'user_id' => 1 ),
				'operation_ref' => $operation_ref,
			)
		);
		$this->assertTrue( $retry );
		$this->assertSame( $original_site_id, get_current_blog_id() );
	}

	public function test_invalid_sites_and_attachments_fail_without_leaking_context(): void {
		$this->require_multisite();
		$original_site_id = get_current_blog_id();

		switch_to_blog( $this->canonical_site_id );
		try {
			$missing_site = DelegatedCrossPostAction::normalize_input( $this->input( PHP_INT_MAX . ':' . $this->attachment_id ) );
			$this->assertWPError( $missing_site );
			$this->assertSame( $this->canonical_site_id, get_current_blog_id() );

			$missing_attachment = DelegatedCrossPostAction::normalize_input( $this->input( $this->asset_site_id . ':' . PHP_INT_MAX ) );
			$this->assertWPError( $missing_attachment );
			$this->assertSame( $this->canonical_site_id, get_current_blog_id() );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( $original_site_id, get_current_blog_id() );
	}

	public function test_bare_attachment_compatibility_is_explicitly_same_site(): void {
		$this->require_multisite();

		switch_to_blog( $this->canonical_site_id );
		try {
			$input                         = $this->input( $this->canonical_site_id . ':' . $this->attachment_id );
			$input['asset_refs'][0]        = array( 'attachment_id' => $this->attachment_id, 'role' => 'image' );
			$normalized                    = DelegatedCrossPostAction::normalize_input( $input );
			$this->assertIsArray( $normalized );
			$this->assertSame( $this->canonical_site_id . ':' . $this->attachment_id, $normalized['asset_refs'][0]['source_id'] );
			$this->assertSame( 'https://canonical.example.test/colliding-image.jpg', $normalized['images'][0]['url'] );
		} finally {
			restore_current_blog();
		}
	}

	private function input( string $source_id ): array {
		$caption = 'Approved multisite caption.';
		return array(
			'post_id'      => $this->post_id,
			'source_url'   => $this->canonical_url,
			'caption'      => $caption,
			'content_hash' => hash( 'sha256', $caption ),
			'channels'     => array( 'instagram' ),
			'media_kind'   => 'image',
			'asset_refs'   => array( array( 'source_id' => $source_id, 'role' => 'image' ) ),
		);
	}

	private function require_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite is required for site-scoped attachment coverage.' );
		}
	}
}
