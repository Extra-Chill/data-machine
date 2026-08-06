<?php
/**
 * Multisite identity coverage for delegated social publishing.
 *
 * @package DataMachineSocials\Tests\Integration
 */

use DataMachineSocials\Operations\DelegatedCrossPostAction;

final class DelegatedCrossPostMultisiteTest extends WP_UnitTestCase {
	private int $canonical_site_id;
	private int $asset_site_id;
	private int $post_id;
	private int $attachment_id;
	private string $canonical_url;
	private string $asset_url;

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			return;
		}

		$this->canonical_site_id = self::factory()->blog->create();
		$this->asset_site_id     = self::factory()->blog->create();

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

		add_filter( 'datamachine_socials_delegated_cross_post_authorized', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_socials_delegated_cross_post_authorized', '__return_true' );
		parent::tear_down();
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
