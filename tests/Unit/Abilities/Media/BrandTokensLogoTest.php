<?php
/**
 * Tests for BrandTokens' logo resolution (explicit token -> site icon ->
 * empty, leaving text fallback to the consuming template).
 *
 * Moved here from data-machine-events (Extra-Chill/data-machine-events#856)
 * so every GD-rendered template gets zero-config site-icon branding
 * instead of each one reimplementing the same resolution chain.
 *
 * @package DataMachine\Tests\Unit\Abilities\Media
 */

namespace DataMachine\Tests\Unit\Abilities\Media;

use DataMachine\Abilities\Media\BrandTokens;
use WP_UnitTestCase;

class BrandTokensLogoTest extends WP_UnitTestCase {

	/**
	 * Attachment IDs created during a test, cleaned up in tear_down().
	 *
	 * @var int[]
	 */
	private array $attachment_ids = array();

	public function tear_down(): void {
		remove_all_filters( 'datamachine/image_template/brand_tokens' );
		foreach ( $this->attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		$this->attachment_ids = array();
		delete_option( 'site_icon' );

		parent::tear_down();
	}

	/**
	 * Write a tiny real PNG inside the uploads directory and create a
	 * real attachment pointing at it (no thumbnails generated), tracked
	 * for cleanup.
	 *
	 * @return int Attachment ID.
	 */
	private function make_temp_icon_attachment(): int {
		$upload_dir = wp_upload_dir();
		$path       = trailingslashit( $upload_dir['path'] ) . 'brandtokens-test-icon-' . uniqid() . '.png';

		$image = imagecreatetruecolor( 64, 64 );
		imagepng( $image, $path );
		imagedestroy( $image );

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => basename( $path ),
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
			),
			$path
		);
		$this->assertNotWPError( $attachment_id );
		$attachment_id          = (int) $attachment_id;
		$this->attachment_ids[] = $attachment_id;

		return $attachment_id;
	}

	public function test_get_returns_null_logo_path_when_nothing_is_available(): void {
		$tokens = BrandTokens::get( 'some_template' );

		$this->assertNull( $tokens['logo_path'] );
		$this->assertNull( $tokens['logo_path_inverse'] );
	}

	public function test_get_falls_back_to_the_site_icon_when_no_filter_sets_a_logo(): void {
		$icon_id = $this->make_temp_icon_attachment();
		update_option( 'site_icon', $icon_id );

		$tokens = BrandTokens::get( 'some_template' );

		$this->assertNotNull( $tokens['logo_path'], 'Should fall back to the site icon with zero token wiring' );
		$this->assertFileExists( $tokens['logo_path'] );
		$this->assertNull( $tokens['logo_path_inverse'], 'Site icon fallback never populates the inverse variant' );
	}

	public function test_get_prefers_an_explicit_filter_token_over_the_site_icon(): void {
		$icon_id = $this->make_temp_icon_attachment();
		update_option( 'site_icon', $icon_id );

		$explicit_path = $this->make_temp_icon_attachment(); // reuse helper just for a real file
		$explicit_file = get_attached_file( $explicit_path );

		add_filter(
			'datamachine/image_template/brand_tokens',
			function ( $tokens ) use ( $explicit_file ) {
				$tokens['logo_path'] = $explicit_file;
				return $tokens;
			}
		);

		$tokens = BrandTokens::get( 'some_template' );

		$this->assertSame( $explicit_file, $tokens['logo_path'], 'Explicit token must win over the site icon' );
	}

	public function test_get_does_not_use_the_site_icon_when_only_the_inverse_variant_is_explicitly_set(): void {
		// A filter that explicitly sets logo_path_inverse (even without a
		// base logo_path) is "something supplied" — the site icon
		// fallback should not clobber it.
		$icon_id = $this->make_temp_icon_attachment();
		update_option( 'site_icon', $icon_id );

		$inverse_id   = $this->make_temp_icon_attachment();
		$inverse_file = get_attached_file( $inverse_id );

		add_filter(
			'datamachine/image_template/brand_tokens',
			function ( $tokens ) use ( $inverse_file ) {
				$tokens['logo_path_inverse'] = $inverse_file;
				return $tokens;
			}
		);

		$tokens = BrandTokens::get( 'some_template' );

		$this->assertNull( $tokens['logo_path'], 'Site icon fallback must not fire when the inverse variant was explicitly supplied' );
		$this->assertSame( $inverse_file, $tokens['logo_path_inverse'] );
	}

	public function test_get_leaves_logo_path_null_when_site_icon_option_is_unset(): void {
		delete_option( 'site_icon' );

		$tokens = BrandTokens::get( 'some_template' );

		$this->assertNull( $tokens['logo_path'] );
	}

	public function test_get_leaves_logo_path_null_when_site_icon_points_at_a_missing_file(): void {
		$icon_id = $this->make_temp_icon_attachment();
		update_option( 'site_icon', $icon_id );

		// Simulate DB/filesystem drift: the file backing the attachment
		// is gone, but the site_icon option and attachment post still
		// point at it.
		$file = get_attached_file( $icon_id );
		wp_delete_file( $file );

		$tokens = BrandTokens::get( 'some_template' );

		$this->assertNull( $tokens['logo_path'], 'A dangling site icon file must not surface as a broken logo_path' );
	}
}
