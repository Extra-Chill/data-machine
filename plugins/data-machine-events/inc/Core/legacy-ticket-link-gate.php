<?php
/**
 * Legacy Inline Affiliate Ticket Link Gate
 *
 * Import-time AI generation wrote the affiliate ticket URL as an inline
 * prose anchor inside `post_content` for events published before this
 * compliance pass (~36,627 published events at the time of writing) — a
 * surface the Event Details block's ticket-button gating never touched
 * because it isn't the block, it's plain post content.
 *
 * This `the_content` filter rewrites any such anchor into the same
 * JS-gated form as the Event Details block's ticket button: no `href`,
 * just `data-ticket-ref` on the anchor. Render-time rewrite, no DB
 * migration — it works retroactively across every existing post and also
 * catches future drift if the import pipeline ever regresses.
 *
 * @package DataMachineEvents\Core
 * @since   0.62.0
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'the_content', __NAMESPACE__ . '\\data_machine_events_gate_legacy_affiliate_ticket_links', 20 );

/**
 * Rewrite inline affiliate ticket anchors in event post content.
 *
 * Scoped to `data_machine_events` posts only (singular event views and
 * event archive/taxonomy loops both run `the_content` against the actual
 * event post being displayed, so a post-type check covers both without
 * needing separate `is_singular()` / `is_post_type_archive()` branches).
 * Bails via a cheap `stripos()` host scan before touching the HTML parser,
 * so the overwhelming majority of requests (non-event posts, and event
 * posts with no affiliate anchor) pay only the cost of one post_type check
 * plus a handful of string scans.
 *
 * @param string $content Post content, already run through other `the_content` filters.
 * @return string Filtered content.
 */
function data_machine_events_gate_legacy_affiliate_ticket_links( string $content ): string {
	if ( '' === $content || false === strpos( $content, '<a ' ) ) {
		return $content;
	}

	$post = get_post();
	if ( ! $post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
		return $content;
	}

	$host_hit = false;
	foreach ( data_machine_events_affiliate_ticket_hosts() as $affiliate_host ) {
		if ( '' !== $affiliate_host && false !== stripos( $content, (string) $affiliate_host ) ) {
			$host_hit = true;
			break;
		}
	}
	if ( ! $host_hit ) {
		return $content;
	}

	$rewritten = data_machine_events_rewrite_affiliate_anchors( $content, $post->ID );
	if ( $rewritten !== $content ) {
		wp_enqueue_script( 'data-machine-events-ticket-link-gate' );
	}

	return $rewritten;
}

/**
 * Rewrite every affiliate-hosted `<a href>` in `$content` into the gated form.
 *
 * Uses `WP_HTML_Tag_Processor` rather than regex/DOMDocument — it edits only
 * the matched anchor's attributes and leaves every other byte of the
 * (possibly malformed, AI-generated) markup untouched.
 *
 * Deliberately holds no loop itself — see `data_machine_events_gate_matching_anchors()`
 * for why this is split out as its own step rather than inlined here or
 * merged with unrelated loop-based functions elsewhere in this codebase.
 *
 * @param string $content Post content containing at least one affiliate host string.
 * @param int    $post_id Current post ID, used as the ticket ref.
 * @return string Rewritten content, or the original if nothing matched.
 */
function data_machine_events_rewrite_affiliate_anchors( string $content, int $post_id ): string {
	$processor     = new \WP_HTML_Tag_Processor( $content );
	$gated_anchors = data_machine_events_gate_matching_anchors( $processor, $post_id );

	return $gated_anchors > 0 ? $processor->get_updated_html() : $content;
}

/**
 * Walk a positioned `WP_HTML_Tag_Processor` and gate every affiliate anchor.
 *
 * Mutates `$processor` in place (its own streaming-cursor contract) and
 * returns a count rather than an array or a boolean, which is the actual,
 * substantive difference from a "collect matching items" accumulator like
 * `DateGrouper::build_paged_events()`: that function guards on an empty
 * result and returns a fully-built array of composite event structs
 * assembled via a second data-fetch step (`EventHydrator::parse_event_data()`)
 * per iteration, plus a post-loop `wp_reset_postdata()` cleanup call this
 * function has no equivalent of. This function does a single void mutation
 * call per match and tallies how many happened — no struct assembly, no
 * guard clause, no post-loop cleanup. An HTML-attribute rewrite over a
 * `WP_HTML_Tag_Processor` cursor and a `WP_Query`-to-struct-array
 * accumulation are different operations on different iterator protocols;
 * forcing them into one shared helper for the sake of a duplication
 * detector would produce a helper with no coherent single responsibility.
 * See the discussion on PR #820.
 *
 * @param \WP_HTML_Tag_Processor $processor Tag processor to walk.
 * @param int                    $post_id   Current post ID, used as the ticket ref.
 * @return int Number of anchors gated.
 */
function data_machine_events_gate_matching_anchors( \WP_HTML_Tag_Processor $processor, int $post_id ): int {
	$gated = 0;

	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		$href = $processor->get_attribute( 'href' );
		if ( is_string( $href ) && '' !== $href && data_machine_events_is_affiliate_ticket_url( $href ) ) {
			data_machine_events_gate_anchor_attributes( $processor, $post_id );
			++$gated;
		}
	}

	return $gated;
}

/**
 * Rewrite a single matched anchor's attributes into the gated form.
 *
 * @param \WP_HTML_Tag_Processor $processor Positioned at a matched `<a>` tag.
 * @param int                    $post_id   Current post ID, used as the ticket ref.
 */
function data_machine_events_gate_anchor_attributes( \WP_HTML_Tag_Processor $processor, int $post_id ): void {
	$processor->remove_attribute( 'href' );
	$processor->set_attribute( 'data-ticket-ref', (string) $post_id );
	$processor->set_attribute( 'role', 'button' );
	$processor->set_attribute( 'tabindex', '0' );
	$processor->set_attribute( 'rel', 'noopener nofollow' );
}
