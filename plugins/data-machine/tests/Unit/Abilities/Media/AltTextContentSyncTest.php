<?php
/**
 * Tests for AltTextContentSync.
 *
 * @package DataMachine\Tests\Unit\Abilities\Media
 */

namespace DataMachine\Tests\Unit\Abilities\Media;

use DataMachine\Abilities\Media\AltTextContentSync;
use WP_UnitTestCase;

class AltTextContentSyncTest extends WP_UnitTestCase {

	private function imageBlock( int $id, string $alt = '' ): string {
		return '<!-- wp:image {"id":' . $id . ',"sizeSlug":"large","linkDestination":"none"} -->' . "\n"
			. '<figure class="wp-block-image size-large"><img src="https://example.com/wp-content/uploads/' . $id . '.jpg" alt="' . $alt . '" class="wp-image-' . $id . '"/></figure>' . "\n"
			. '<!-- /wp:image -->';
	}

	private function attachment( string $alt = '' ): int {
		$id = self::factory()->attachment->create_object(
			array(
				'file'           => 'image-' . wp_generate_password( 6, false ) . '.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		return $id;
	}

	public function test_fills_empty_alt_for_matching_attachment(): void {
		$html = AltTextContentSync::fillEmptyAlt( $this->imageBlock( 42 ), 42, 'A drummer onstage.' );

		$this->assertStringContainsString( 'alt="A drummer onstage."', $html );
		$this->assertStringContainsString( '<!-- wp:image {"id":42', $html );
	}

	public function test_fills_missing_alt_attribute(): void {
		$html = AltTextContentSync::fillEmptyAlt( '<img src="x.jpg" class="wp-image-7">', 7, 'Crowd.' );

		$this->assertStringContainsString( 'alt="Crowd."', $html );
	}

	public function test_preserves_non_empty_alt(): void {
		$block = $this->imageBlock( 42, 'Hand written alt' );

		$this->assertSame( $block, AltTextContentSync::fillEmptyAlt( $block, 42, 'Generated alt.' ) );
	}

	public function test_does_not_touch_other_attachments_or_prefix_ids(): void {
		$html = $this->imageBlock( 11 ) . $this->imageBlock( 111 );

		$result = AltTextContentSync::fillEmptyAlt( $html, 11, 'Eleven.' );

		$this->assertSame( 1, substr_count( $result, 'alt="Eleven."' ) );
		$this->assertStringContainsString( 'alt="" class="wp-image-111"', $result );
	}

	public function test_escapes_alt_value(): void {
		$html = AltTextContentSync::fillEmptyAlt( $this->imageBlock( 5 ), 5, 'Say "hi" <b>' );

		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'Say &quot;hi&quot;', $html );
	}

	public function test_sync_updates_stored_content_including_gallery_images(): void {
		$target = $this->attachment();
		$other  = $this->attachment();

		$content = $this->imageBlock( $target )
			. "\n\n<!-- wp:gallery {\"linkTo\":\"none\"} -->\n<figure class=\"wp-block-gallery has-nested-images\">"
			. $this->imageBlock( $target ) . $this->imageBlock( $other ) . $this->imageBlock( $target, 'Kept by hand' )
			. "</figure>\n<!-- /wp:gallery -->";

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'pending',
				'post_content' => $content,
			)
		);

		$updated = AltTextContentSync::syncPostsForAttachment( $target, 'Generated alt.' );

		$this->assertSame( array( $post_id ), array_column( $updated, 'post_id' ) );

		$saved = get_post( $post_id );
		$this->assertSame( 2, substr_count( $saved->post_content, 'alt="Generated alt."' ) );
		$this->assertStringContainsString( 'alt="Kept by hand"', $saved->post_content );
		$this->assertStringContainsString( 'alt="" class="wp-image-' . $other . '"', $saved->post_content );
		$this->assertSame( 'pending', $saved->post_status );
		$this->assertCount( 3, parse_blocks( $saved->post_content )[2]['innerBlocks'] );
	}

	public function test_sync_skips_posts_without_empty_alt(): void {
		$target  = $this->attachment();
		$post_id = self::factory()->post->create( array( 'post_content' => $this->imageBlock( $target, 'Already set' ) ) );
		$before  = get_post( $post_id )->post_content;

		$this->assertSame( array(), AltTextContentSync::syncPostsForAttachment( $target, 'Generated alt.' ) );
		$this->assertSame( $before, get_post( $post_id )->post_content );
	}

	public function test_sync_preserves_unfiltered_markup_without_privileged_user(): void {
		wp_set_current_user( 0 );
		kses_init_filters();

		$target  = $this->attachment();
		$post_id = self::factory()->post->create( array( 'post_content' => '' ) );
		$content = $this->imageBlock( $target ) . '<iframe src="https://example.com/embed"></iframe>';
		$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_content' => $content ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		AltTextContentSync::syncPostsForAttachment( $target, 'Generated alt.' );

		$saved = get_post( $post_id )->post_content;
		$this->assertStringContainsString( '<iframe', $saved );
		$this->assertStringContainsString( 'alt="Generated alt."', $saved );
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'KSES filters must be restored.' );

		kses_remove_filters();
	}

	public function test_render_filter_fills_empty_alt_from_meta(): void {
		$target = $this->attachment( 'Meta alt.' );

		$rendered = apply_filters(
			'render_block_core/image',
			'<figure class="wp-block-image"><img src="x.jpg" alt="" class="wp-image-' . $target . '"/></figure>',
			array( 'attrs' => array( 'id' => $target ) )
		);

		$this->assertStringContainsString( 'alt="Meta alt."', $rendered );
	}

	public function test_render_filter_preserves_existing_alt(): void {
		$target = $this->attachment( 'Meta alt.' );
		$html   = '<figure class="wp-block-image"><img src="x.jpg" alt="Block alt" class="wp-image-' . $target . '"/></figure>';

		$this->assertSame( $html, AltTextContentSync::filterImageBlock( $html, array( 'attrs' => array( 'id' => $target ) ) ) );
	}

	public function test_render_filter_noop_without_meta_or_id(): void {
		$target = $this->attachment();
		$html   = '<figure class="wp-block-image"><img src="x.jpg" alt="" class="wp-image-' . $target . '"/></figure>';

		$this->assertSame( $html, AltTextContentSync::filterImageBlock( $html, array( 'attrs' => array( 'id' => $target ) ) ) );
		$this->assertSame( $html, AltTextContentSync::filterImageBlock( $html, array( 'attrs' => array() ) ) );
	}
}
