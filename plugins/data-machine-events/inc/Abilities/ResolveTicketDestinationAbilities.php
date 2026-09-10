<?php
/**
 * Resolve Ticket Destination Ability
 *
 * Resolves a published event post ID to its ticket destination URL and
 * whether that URL is an affiliate/redirect wrapper. Backs the public,
 * first-party ticket redirect endpoint (`extrachill-api`, sibling issue)
 * so affiliate URLs and affiliate IDs never need to appear in page source
 * or in any JS bundle — the client only ever knows the event's post ID.
 *
 * `show_in_rest` is intentionally false: this ability's REST surface is a
 * dedicated public redirect route, not the generic ability runner. See
 * https://github.com/Extra-Chill/data-machine-events/issues/816.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.62.0
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;
use function DataMachineEvents\Core\data_machine_events_is_affiliate_ticket_url;
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ResolveTicketDestinationAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbility();
			self::$registered = true;
		}
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/resolve-ticket-destination',
				array(
					'label'               => __( 'Resolve Ticket Destination', 'data-machine-events' ),
					'description'         => __( 'Resolve a published event post ID to its ticket destination URL and whether it is an affiliate/redirect wrapper. Backs the public first-party ticket redirect so affiliate URLs never appear in page source.', 'data-machine-events' ),
					'category'            => AbilityCategories::EVENTS,
					'input_schema'        => array(
						'type'                 => 'object',
						'required'             => array( 'event_id' ),
						'additionalProperties' => false,
						'properties'           => array(
							'event_id' => array(
								'type'        => 'integer',
								'description' => __( 'Published event post ID.', 'data-machine-events' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'url'          => array( 'type' => 'string' ),
							'is_affiliate' => array( 'type' => 'boolean' ),
						),
					),
					'execute_callback'    => array( $this, 'executeResolveTicketDestination' ),
					// Public read: this backs a public ticket redirect with no
					// auth. It leaks nothing beyond what the event's own
					// published page already discloses.
					'permission_callback' => '__return_true',
					'meta'                => array(
						'show_in_rest' => false,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute resolve-ticket-destination ability.
	 *
	 * @param array $input Input parameters (`event_id`).
	 * @return array{url:string,is_affiliate:bool}|\WP_Error
	 */
	public function executeResolveTicketDestination( array $input ): array|\WP_Error {
		$event_id = (int) ( $input['event_id'] ?? 0 );
		if ( $event_id <= 0 ) {
			return new \WP_Error(
				'invalid_event_id',
				__( 'event_id must be a positive integer.', 'data-machine-events' ),
				array( 'status' => 400 )
			);
		}

		$post = get_post( $event_id );
		if ( ! $post || Event_Post_Type::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return new \WP_Error(
				'event_not_found',
				__( 'No published event found for the given ID.', 'data-machine-events' ),
				array( 'status' => 404 )
			);
		}

		$ticket_url = $this->resolveTicketUrl( $event_id, $post );
		if ( '' === $ticket_url ) {
			return new \WP_Error(
				'no_ticket_url',
				__( 'This event has no ticket URL.', 'data-machine-events' ),
				array( 'status' => 404 )
			);
		}

		return array(
			'url'          => $ticket_url,
			'is_affiliate' => data_machine_events_is_affiliate_ticket_url( $ticket_url ),
		);
	}

	/**
	 * Resolve the ticket URL for an event.
	 *
	 * Prefers parsing the Event Details block directly (mirrors
	 * `TicketUrlResyncAbilities::extractTicketUrl()`) — that is the
	 * complete, as-authored URL, query string and all.
	 *
	 * Falls back to the `_datamachine_ticket_url` meta
	 * (`EVENT_TICKET_URL_META_KEY`) only when the post has no parseable
	 * Event Details block. That meta is NOT interchangeable with the block
	 * value: `datamachine_normalize_ticket_url()` (event-dates-sync.php)
	 * intentionally strips non-identity query parameters — UTM tags,
	 * `utm_medium=affiliate`, etc. — keeping only `u`/`e`, because every
	 * other consumer of this meta key (duplicate detection, merge
	 * decisions) only needs a stable comparison key, never a URL a real
	 * visitor is redirected to. Preferring the meta here would silently
	 * drop affiliate-network tracking parameters on every click-through —
	 * this ability is the one consumer where that fallback's lossiness
	 * actually matters, so it is deliberately the fallback, not the
	 * primary source. See the discussion on PR #820 and the follow-up
	 * tracking issue #821 (the meta key's lossy shape is a footgun for
	 * any future consumer, even though every consumer audited today is
	 * dedup-only and unaffected).
	 *
	 * @param int      $event_id Event post ID.
	 * @param \WP_Post $post     Event post object.
	 * @return string Ticket URL, or empty string when none is set.
	 */
	private function resolveTicketUrl( int $event_id, \WP_Post $post ): string {
		$blocks    = parse_blocks( $post->post_content );
		$block_url = $this->findTicketUrlInBlocks( $blocks );

		if ( '' !== $block_url ) {
			return $this->normalizeResolvedUrl( $block_url );
		}

		$meta_url = get_post_meta( $event_id, EVENT_TICKET_URL_META_KEY, true );
		return $this->normalizeResolvedUrl( is_string( $meta_url ) ? $meta_url : '' );
	}

	/**
	 * Normalize a resolved ticket URL for use as a redirect destination.
	 *
	 * Guarantee: returns the stored URL verbatim except for reversing HTML
	 * entity encoding introduced by however it was authored into
	 * `post_content` (`&amp;` -> `&`, etc.) — no path/host rewriting, no
	 * query reordering, no affiliate unwrapping.
	 *
	 * Roughly 54% of this network's ~74,600 published events with an
	 * affiliate ticket URL store it with the ampersand HTML-entity-encoded
	 * (`&amp;utm_medium=affiliate`) — correct for embedding in an `href`
	 * attribute, where browsers decode entities before following the
	 * link, but WRONG for a value assembled into an HTTP `Location`
	 * header, which is sent byte-for-byte with no entity decoding. Left
	 * un-normalized, the affiliate network receives a literal
	 * `amp;utm_medium` parameter name instead of `utm_medium`, silently
	 * breaking attribution on tens of thousands of events — the opposite
	 * of what this ability exists to protect. See PR #820.
	 *
	 * `wp_specialchars_decode( $url, ENT_QUOTES )` — not a hand-rolled
	 * `str_replace()` — reverses exactly the small, fixed set of entities
	 * `esc_html()`/`esc_attr()`-family functions produce (`&amp;` `&lt;`
	 * `&gt;` `&quot;` `&#039;` and their numeric forms), and is a no-op on
	 * an already-raw `&` (it only replaces matched entity substrings; a
	 * bare `&` never matches one). That makes it safe to call
	 * unconditionally regardless of which stored shape a given URL is in.
	 * Only single-level entity encoding was observed in production; this
	 * does not attempt to unwind double-encoding (`&amp;amp;`), for which
	 * there is no evidence in the current catalogue.
	 *
	 * @param string $url Raw ticket URL as stored (block content or meta).
	 * @return string Normalized URL suitable for a redirect `Location` header.
	 */
	private function normalizeResolvedUrl( string $url ): string {
		if ( '' === $url ) {
			return $url;
		}

		// A handful of legacy-imported posts store a literal six-character
		// `\u0026` JSON-escape sequence inside the ticketUrl string itself
		// (double-JSON-encoded at import time) rather than an actual
		// backslash-u character in the decoded value. wp_specialchars_decode()
		// does not recognize it, so normalize it to a literal ampersand first.
		$url = str_replace( '\u0026', '&', $url );

		return wp_specialchars_decode( $url, ENT_QUOTES );
	}

	/**
	 * Recursively search blocks for the Event Details ticketUrl attribute.
	 *
	 * @param array $blocks Block array.
	 * @return string Ticket URL or empty string.
	 */
	private function findTicketUrlInBlocks( array $blocks ): string {
		foreach ( $blocks as $block ) {
			if ( Event_Post_Type::EVENT_DETAILS_BLOCK_NAME === ( $block['blockName'] ?? '' ) ) {
				return (string) ( $block['attrs']['ticketUrl'] ?? '' );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$result = $this->findTicketUrlInBlocks( $block['innerBlocks'] );
				if ( '' !== $result ) {
					return $result;
				}
			}
		}
		return '';
	}
}
