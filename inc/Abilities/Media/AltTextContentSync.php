<?php
/**
 * Alt Text Content Sync
 *
 * Propagates attachment alt text into image markup that was saved with an
 * empty alt attribute. Generated alt text lives in attachment meta, but
 * `core/image` blocks serialize their own copy of `alt` at insertion time.
 * Images inserted before alt generation finished therefore keep `alt=""`
 * forever unless something copies the generated value into the markup.
 *
 * Two entry points share one tag-level primitive:
 *  - {@see self::syncPostsForAttachment()} rewrites stored post content after
 *    alt text is generated (fixes feeds, REST content, and exports).
 *  - {@see self::filterImageBlock()} fills empty alt at render time from
 *    attachment meta (covers the existing backlog without content mutation).
 *
 * Only empty or missing alt attributes are ever filled. Non-empty alt text,
 * including hand-written alt, is never touched.
 *
 * @package DataMachine\Abilities\Media
 */

namespace DataMachine\Abilities\Media;

defined( 'ABSPATH' ) || exit;

class AltTextContentSync {

	/**
	 * Maximum posts rewritten per attachment in one pass.
	 */
	public const MAX_POSTS = 50;

	/**
	 * Fill empty alt attributes on `<img>` tags for one attachment.
	 *
	 * Matches images by the `wp-image-{id}` class core adds to attachment
	 * images. Tags whose alt attribute is non-empty are left untouched.
	 *
	 * @param string $html          HTML to scan.
	 * @param int    $attachment_id Attachment ID whose images should be filled.
	 * @param string $alt           Alt text to apply.
	 * @return string Updated HTML (unchanged when nothing matched).
	 */
	public static function fillEmptyAlt( string $html, int $attachment_id, string $alt ): string {
		$alt = trim( $alt );
		if ( '' === $alt || $attachment_id <= 0 || false === strpos( $html, 'wp-image-' . $attachment_id ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		$changed   = false;
		$class     = 'wp-image-' . $attachment_id;

		$query = array(
			'tag_name'   => 'IMG',
			'class_name' => $class,
		);

		while ( $processor->next_tag( $query ) ) {
			if ( ! self::altIsEmpty( $processor->get_attribute( 'alt' ) ) ) {
				continue;
			}
			$processor->set_attribute( 'alt', $alt );
			$changed = true;
		}

		return $changed ? $processor->get_updated_html() : $html;
	}

	/**
	 * Rewrite stored content of posts that embed the attachment with empty alt.
	 *
	 * Saves a revision before each change so the job's undo effects can
	 * restore the previous content.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $alt           Alt text to apply.
	 * @return array<int, array{post_id:int, revision_id:int|null}> Updated posts.
	 */
	public static function syncPostsForAttachment( int $attachment_id, string $alt ): array {
		if ( $attachment_id <= 0 || '' === trim( $alt ) ) {
			return array();
		}

		$updated = array();

		foreach ( self::findReferencingPostIds( $attachment_id ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$content = self::fillEmptyAlt( (string) $post->post_content, $attachment_id, $alt );
			if ( $content === $post->post_content ) {
				continue;
			}

			$revision_id = wp_save_post_revision( $post_id );

			if ( ! self::updateContent( $post_id, $content ) ) {
				continue;
			}

			$updated[] = array(
				'post_id'     => $post_id,
				'revision_id' => ( $revision_id && ! is_wp_error( $revision_id ) ) ? (int) $revision_id : null,
			);
		}

		return $updated;
	}

	/**
	 * Render-time fallback for `core/image` blocks.
	 *
	 * @param string $block_content Rendered block HTML.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function filterImageBlock( string $block_content, array $block ): string {
		if ( '' === $block_content ) {
			return $block_content;
		}

		$attachment_id = absint( $block['attrs']['id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return $block_content;
		}

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$alt = is_string( $alt ) ? trim( $alt ) : '';
		if ( '' === $alt ) {
			return $block_content;
		}

		return self::fillEmptyAlt( $block_content, $attachment_id, $alt );
	}

	/**
	 * Find posts whose content references the attachment's image class.
	 *
	 * The LIKE prefilter can over-match (`wp-image-11` inside `wp-image-111`);
	 * {@see self::fillEmptyAlt()} matches the exact class, so over-matches are
	 * no-ops.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int[]
	 */
	private static function findReferencingPostIds( int $attachment_id ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( 'wp-image-' . $attachment_id ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh lookup of posts embedding a just-updated attachment.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_content LIKE %s
				AND post_type NOT IN ( 'revision', 'attachment', 'nav_menu_item' )
				AND post_status NOT IN ( 'auto-draft', 'trash', 'inherit' )
				ORDER BY ID DESC
				LIMIT %d",
				$like,
				self::MAX_POSTS
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Persist new content without running it through KSES.
	 *
	 * Alt generation runs in a background context with no privileged user, so
	 * `wp_update_post()` would otherwise filter the whole post through KSES
	 * and could strip markup the author was allowed to save. Only alt
	 * attribute values change here.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content New content.
	 * @return bool
	 */
	private static function updateContent( int $post_id, string $content ): bool {
		$kses_active = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses_active ) {
			kses_remove_filters();
		}

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
				)
			),
			true
		);

		if ( $kses_active ) {
			kses_init_filters();
		}

		return ! is_wp_error( $result );
	}

	/**
	 * @param mixed $value Attribute value from WP_HTML_Tag_Processor.
	 */
	private static function altIsEmpty( $value ): bool {
		// null = attribute missing; true = boolean attribute with no value.
		return null === $value || true === $value || '' === trim( (string) $value );
	}
}
