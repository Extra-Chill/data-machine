<?php
/**
 * BlogContext — multisite target-blog resolution for content abilities.
 *
 * The block-content abilities (`get_post_blocks`, `edit_post_blocks`,
 * `replace_post_blocks`, `insert_content`) operate on a post by `post_id`.
 * On a single-site install that post always lives on the only blog there is.
 * On a multisite network the chat surface and the post can live on different
 * blogs — e.g. a chat drawer running on one subsite editing a draft that was
 * authored on the main site.
 *
 * Without a blog dimension the abilities silently resolve `post_id` against
 * whatever blog the request landed on, which on multisite is the wrong post
 * (or no post at all). This helper lets each ability accept an optional
 * `blog_id` and run its post read/write inside `switch_to_blog()` when the
 * target differs from the current blog.
 *
 * The dimension is deliberately scoped to the content abilities and their
 * pending-action handlers — the generic PendingActionStore, PendingActionHelper,
 * and ResolvePendingActionAbility need no change. `blog_id` rides inside the
 * ability input (and, for staged edits, inside `apply_input`, which already
 * round-trips through the store), so the same `switch_to_blog()` that runs the
 * preview also runs the apply when the user accepts.
 *
 * On a non-multisite install, or when no `blog_id` is supplied, this helper is
 * a no-op: every method falls through to the current blog and nothing switches.
 *
 * @package DataMachine\Abilities\Content
 * @since   0.149.0
 */

namespace DataMachine\Abilities\Content;

defined( 'ABSPATH' ) || exit;

class BlogContext {

	/**
	 * Resolve the target blog id from an ability input array.
	 *
	 * Returns 0 when no targeting is requested or possible:
	 *   - not a multisite install, or
	 *   - no `blog_id` supplied, or
	 *   - `blog_id` equals the current blog (no switch needed).
	 *
	 * A non-zero return means "switch to this blog for the post operation".
	 * Validation of the blog's existence happens in switch_to(); this method
	 * only normalizes intent.
	 *
	 * @param array $input Ability input. Reads the optional `blog_id` key.
	 * @return int Target blog id to switch to, or 0 for "use current blog".
	 */
	public static function target_from_input( array $input ): int {
		if ( ! is_multisite() ) {
			return 0;
		}

		$blog_id = isset( $input['blog_id'] ) ? absint( $input['blog_id'] ) : 0;
		if ( $blog_id <= 0 ) {
			return 0;
		}

		if ( get_current_blog_id() === $blog_id ) {
			return 0;
		}

		return $blog_id;
	}

	/**
	 * Whether a target blog id refers to a real, usable site on the network.
	 *
	 * @param int $blog_id Candidate blog id.
	 * @return bool
	 */
	public static function is_valid( int $blog_id ): bool {
		if ( ! is_multisite() || $blog_id <= 0 ) {
			return false;
		}

		$site = get_site( $blog_id );
		if ( null === $site ) {
			return false;
		}

		// Refuse archived/deleted/spam sites — there is no legitimate
		// content-edit target there.
		if ( 1 === (int) $site->archived || 1 === (int) $site->deleted || 1 === (int) $site->spam ) {
			return false;
		}

		return true;
	}

	/**
	 * Switch to the target blog when one is requested, validating first.
	 *
	 * Returns a context token the caller passes to restore(). The token records
	 * whether a switch actually happened so restore() is a safe no-op when it
	 * didn't. Validation failure returns a WP_Error — the caller should surface
	 * it rather than silently operating on the wrong blog.
	 *
	 * Usage:
	 *
	 *   $ctx = BlogContext::enter( $input );
	 *   if ( is_wp_error( $ctx ) ) { return ...error...; }
	 *   try {
	 *       // post read/write runs in the target blog context
	 *   } finally {
	 *       BlogContext::leave( $ctx );
	 *   }
	 *
	 * @param array $input Ability input (reads optional `blog_id`).
	 * @return array{switched:bool,blog_id:int}|\WP_Error Context token, or error
	 *                                                     when the blog is invalid.
	 */
	public static function enter( array $input ) {
		$target = self::target_from_input( $input );

		if ( $target <= 0 ) {
			return array(
				'switched' => false,
				'blog_id'  => get_current_blog_id(),
			);
		}

		if ( ! self::is_valid( $target ) ) {
			return new \WP_Error(
				'datamachine_invalid_blog',
				sprintf(
					/* translators: %d: requested blog id. */
					__( 'Blog #%d is not a valid site on this network.', 'data-machine' ),
					$target
				)
			);
		}

		switch_to_blog( $target );

		return array(
			'switched' => true,
			'blog_id'  => $target,
		);
	}

	/**
	 * Add a `blog_id` key to an array only when it is a positive, meaningful
	 * target.
	 *
	 * Used to stamp the resolved target blog into a staged action's
	 * `apply_input` (and preview context) so the resolve-time replay re-enters
	 * the same blog. A zero/absent blog_id is left out entirely so single-site
	 * payloads stay clean and unchanged.
	 *
	 * @param array $data    Array to augment.
	 * @param int   $blog_id Target blog id (0 = none).
	 * @return array
	 */
	public static function with_blog_id( array $data, int $blog_id ): array {
		if ( $blog_id > 0 ) {
			$data['blog_id'] = $blog_id;
		}

		return $data;
	}

	/**
	 * Restore the original blog context recorded by enter().
	 *
	 * Safe no-op when no switch happened.
	 *
	 * @param array|\WP_Error $ctx Context token from enter().
	 * @return void
	 */
	public static function leave( $ctx ): void {
		if ( is_array( $ctx ) && ! empty( $ctx['switched'] ) ) {
			restore_current_blog();
		}
	}

	/**
	 * Run a callback in the ORIGIN (calling) blog, temporarily leaving the
	 * target blog entered by enter().
	 *
	 * The content abilities switch to the target blog to read/write the post,
	 * but pending-action **staging** must happen on the calling blog: the
	 * pending-action store is `$wpdb->prefix`-scoped (per-blog), and both the
	 * propose turn and the later accept/resolve turn run on the calling blog.
	 * Staging inside the target-blog switch would persist the action in the
	 * wrong blog's table, and the resolve — running on the calling blog — would
	 * never find it (the cross-site accept would silently fail).
	 *
	 * When no switch is active (single-site, same-blog, or invalid-target
	 * no-op token), this simply runs the callback in place. Otherwise it pops
	 * back to the origin blog for the duration of the callback and re-enters
	 * the target blog afterward, so the surrounding `leave()` still balances.
	 *
	 * @template T
	 * @param array|\WP_Error $ctx      Context token from enter().
	 * @param callable        $callback Work to run on the origin blog.
	 * @return mixed The callback's return value.
	 */
	public static function run_on_origin( $ctx, callable $callback ) {
		$switched = is_array( $ctx ) && ! empty( $ctx['switched'] );

		if ( ! $switched ) {
			return $callback();
		}

		$target = (int) $ctx['blog_id'];

		// Pop back to the origin blog for the staging write.
		restore_current_blog();
		try {
			return $callback();
		} finally {
			// Re-enter the target blog so the caller's `leave()` still balances
			// the original enter().
			switch_to_blog( $target );
		}
	}

	/**
	 * Resolve the freshest authored content for a post, preferring the calling
	 * user's in-flight autosave revision over the stored parent post.
	 *
	 * Editors that autosave (e.g. an iframe compose surface posting to
	 * `/wp/v2/posts/<id>/autosaves` every few seconds) deliberately leave the
	 * parent post untouched until an explicit Save/Submit. Reading
	 * `$post->post_content` therefore surfaces stale — or, for a brand-new
	 * draft, empty — content versus what the author is actively typing.
	 *
	 * WordPress core stores one autosave revision per user (`wp_get_post_autosave`
	 * keyed on the user id), so the lookup is inherently scoped to the current
	 * user — another user's in-flight draft is never returned. When that
	 * autosave exists and is newer than the parent post, its `post_content` is
	 * the freshest authored content and is returned in place of the parent's.
	 *
	 * This is a READ-only preference: it changes only which content is parsed
	 * and shown to a caller proofreading/diffing a draft. It does not alter
	 * where edits are written — write paths continue to target the parent post
	 * through the normal pending-action/apply flow.
	 *
	 * Must be called inside the post's blog context (after BlogContext::enter()
	 * on multisite) so the autosave lookup runs against the correct site.
	 *
	 * @param \WP_Post $post           The stored parent post.
	 * @param bool     $prefer_autosave Whether to prefer a newer user autosave.
	 *                                  Defaults to true (freshest-authored).
	 * @return string The post_content to parse (autosave's when newer, else parent's).
	 */
	public static function freshest_authored_content( \WP_Post $post, bool $prefer_autosave = true ): string {
		if ( ! $prefer_autosave ) {
			return (string) $post->post_content;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return (string) $post->post_content;
		}

		$autosave = wp_get_post_autosave( $post->ID, $user_id );
		if ( ! $autosave instanceof \WP_Post ) {
			return (string) $post->post_content;
		}

		// Only prefer the autosave when it is strictly newer than the parent.
		if ( strtotime( (string) $autosave->post_modified_gmt ) <= strtotime( (string) $post->post_modified_gmt ) ) {
			return (string) $post->post_content;
		}

		return (string) $autosave->post_content;
	}
}
