<?php
/**
 * Event Block Content Builder
 *
 * Assembles the `data-machine-events/event-details` block markup and the
 * inner paragraph blocks generated from the AI-written event description.
 * Extracted from EventUpsert in #425. Pure refactor — no behavior change.
 *
 * @package DataMachineEvents\Steps\Upsert\Events
 */

namespace DataMachineEvents\Steps\Upsert\Events;

use DataMachineEvents\Core\AffiliateRedirectShape;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Event_Type_Taxonomy;
use DataMachineEvents\Core\TextNormalization;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the event-details block content written to the post.
 */
class EventBlockContentBuilder {

	/**
	 * Generate Event Details block content
	 *
	 * @param array $event_data Event data
	 * @param array $parameters Full parameters (includes engine data)
	 * @return string Block content
	 */
	public function generate_event_block_content( array $event_data, array $parameters = array() ): string {
		$this->guard_affiliate_redirect_urls( $event_data );

		$block_attributes = array(
			'startDate'         => $event_data['startDate'] ?? '',
			'startTime'         => $event_data['startTime'] ?? '',
			'endDate'           => $event_data['endDate'] ?? '',
			'endTime'           => $event_data['endTime'] ?? '',
			// `??` only substitutes for null, so a non-array here would be
			// serialised into block markup as-is and fatal the renderer. The
			// writer upstream now preserves arrays, but this is the last point
			// before the value becomes stored markup, so it refuses to emit a
			// shape block.json does not declare.
			'occurrenceDates'   => is_array( $event_data['occurrenceDates'] ?? null ) ? $event_data['occurrenceDates'] : array(),
			'venue'             => $event_data['venue'] ?? $parameters['venue'] ?? '',
			'address'           => $event_data['venueAddress'] ?? $parameters['venueAddress'] ?? '',
			'price'             => $event_data['price'] ?? '',
			'ticketUrl'         => $event_data['ticketUrl'] ?? '',

			'performer'         => $event_data['performer'] ?? '',
			'performerType'     => $event_data['performerType'] ?? 'PerformingGroup',
			'organizer'         => $event_data['organizer'] ?? '',
			'organizerType'     => $event_data['organizerType'] ?? 'Organization',
			'organizerUrl'      => $event_data['organizerUrl'] ?? '',
			'eventStatus'       => $event_data['eventStatus'] ?? 'EventScheduled',
			'previousStartDate' => $event_data['previousStartDate'] ?? '',
			'priceCurrency'     => $event_data['priceCurrency'] ?? 'USD',
			'offerAvailability' => $event_data['offerAvailability'] ?? 'InStock',
			'validFrom'         => $event_data['validFrom'] ?? '',
			// Derived output only. The `event_type` taxonomy term is the input
			// of record (#761); this attribute mirrors the Schema.org @type the
			// resolved term maps to, so an editorial consumer term never leaks
			// into block markup or JSON-LD.
			'eventType'         => Event_Type_Taxonomy::resolve_schema_type( $event_data['eventType'] ?? '' ),

			'showPrice'         => true,
			'showTicketLink'    => true,
		);

		$block_attributes = array_filter(
			$block_attributes,
			function ( $value ) {
				return '' !== $value;
			}
		);

		$block_attributes['showPrice']      = true;
		$block_attributes['showTicketLink'] = true;

		$block_json = wp_json_encode( $block_attributes, JSON_UNESCAPED_UNICODE );
		// Decode entities before kses (issue #844): source-fed descriptions
		// carry the same encoded text as titles, and wp_kses_post normalizes
		// rather than removes entities. Decoding first stores literal
		// characters that render and serialize consistently.
		$description = ! empty( $event_data['description'] ) ? wp_kses_post( TextNormalization::decode_entities( (string) $event_data['description'] ) ) : '';

		$inner_blocks = $this->generate_description_blocks( $description );

		return '<!-- wp:' . Event_Post_Type::EVENT_DETAILS_BLOCK_NAME . ' ' . $block_json . ' -->' . "\n" .
				'<div class="wp-block-data-machine-events-event-details">' .
				( $inner_blocks ? "\n" . $inner_blocks . "\n" : '' ) .
				'</div>' . "\n" .
				'<!-- /wp:' . Event_Post_Type::EVENT_DETAILS_BLOCK_NAME . ' -->';
	}

	/**
	 * Block-level HTML tags that must never be nested inside a paragraph.
	 *
	 * @var string[]
	 */
	private const BLOCK_LEVEL_TAGS = array(
		'address',
		'article',
		'aside',
		'blockquote',
		'details',
		'dl',
		'div',
		'figure',
		'figcaption',
		'footer',
		'form',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'header',
		'hr',
		'iframe',
		'nav',
		'ol',
		'pre',
		'section',
		'table',
		'ul',
		'video',
		'audio',
	);

	/**
	 * Generate inner blocks from an HTML description.
	 *
	 * - Content that already carries block comments passes through unchanged.
	 * - Content with block-level HTML is converted via html-to-blocks-converter
	 *   when available, otherwise preserved inside a core/html block.
	 * - Plain text and inline-only HTML becomes paragraph blocks.
	 *
	 * @param string $description HTML description content
	 * @return string InnerBlocks content
	 */
	private function generate_description_blocks( string $description ): string {
		$description = trim( $description );
		if ( '' === $description ) {
			return '';
		}

		if ( false !== strpos( $description, '<!-- wp:' ) ) {
			return $description;
		}

		if ( self::contains_block_level_html( $description ) ) {
			return $this->convert_block_level_html( $description );
		}

		return $this->generate_paragraph_blocks( $description );
	}

	/**
	 * Whether the HTML contains any block-level tag.
	 *
	 * @param string $html HTML content.
	 * @return bool
	 */
	private static function contains_block_level_html( string $html ): bool {
		$pattern = '/<(?:' . implode( '|', self::BLOCK_LEVEL_TAGS ) . ')(?=[\s>\/])/i';
		if ( 1 === preg_match( $pattern, $html ) ) {
			return true;
		}

		// An <img> inside a paragraph is inline; a standalone one is not.
		$outside_paragraphs = preg_replace( '/<p(?=[\s>])[^>]*>.*?<\/p>/is', '', $html );
		return 1 === preg_match( '/<img(?=[\s>\/])/i', (string) $outside_paragraphs );
	}

	/**
	 * Convert block-level HTML to serialized blocks.
	 *
	 * Uses html_to_blocks_convert() from the html-to-blocks-converter plugin
	 * when present. Falls back to a core/html block so block-level markup is
	 * never nested inside a paragraph.
	 *
	 * @param string $html HTML content.
	 * @return string Serialized block markup.
	 */
	private function convert_block_level_html( string $html ): string {
		if ( function_exists( 'html_to_blocks_convert' ) && function_exists( 'serialize_blocks' ) ) {
			$blocks = html_to_blocks_convert( $html );
			if ( is_array( $blocks ) && ! empty( $blocks ) ) {
				$serialized = trim( (string) serialize_blocks( array_values( array_filter( $blocks ) ) ) );
				if ( '' !== $serialized ) {
					return $serialized;
				}
			}
		}

		return '<!-- wp:html -->' . "\n" . $html . "\n" . '<!-- /wp:html -->';
	}

	/**
	 * Generate paragraph blocks from plain text or inline-only HTML.
	 *
	 * @param string $description Description content.
	 * @return string Paragraph block markup.
	 */
	private function generate_paragraph_blocks( string $description ): string {
		// Split on closing/opening p tags or double line breaks
		$paragraphs = preg_split( '/<\/p>\s*<p[^>]*>|<\/p>\s*<p>|\n\n+/', $description );
		if ( false === $paragraphs ) {
			return '';
		}

		$blocks = array();
		foreach ( $paragraphs as $para ) {
			// Strip outer p tags but keep inline formatting
			$para = preg_replace( '/^<p[^>]*>|<\/p>$/', '', trim( $para ) );
			$para = trim( $para );

			if ( ! empty( $para ) ) {
				$blocks[] = '<!-- wp:paragraph -->' . "\n" . '<p>' . $para . '</p>' . "\n" . '<!-- /wp:paragraph -->';
			}
		}

		return implode( "\n", $blocks );
	}

	/**
	 * Write-path guard: heal or discard corrupted affiliate redirect URLs
	 * before storage.
	 *
	 * The import pipeline's AI step constructs the affiliate wrappers, and
	 * occasionally transcribes the redirect parameter with its URL punctuation
	 * stripped (`?u=httpswww.ticketmaster.comevent...`). Once stored, such a
	 * wrapper redirects real visitors to a garbage destination. Production
	 * evidence (post 405414, imported 2026-09-09) showed this class of
	 * corruption was still being produced by live import flows — see issue
	 * #823, and #829 for confirmation the write path (not just historical
	 * rows) was still active and could land on `ticketUrl`, not only
	 * `organizerUrl`.
	 *
	 * When the corrupted destination reconstructs with confidence it is
	 * replaced here, so no import path can persist the corruption. When it
	 * does NOT reconstruct confidently, this attribute is discarded (not
	 * stored) rather than persisted corrupted: a value that is missing is a
	 * visible, honest gap, while a broken or wrong redirect looks legitimate
	 * and can be clicked by a real visitor — on `ticketUrl` that is the
	 * purchase funnel itself. (Issue #823/#826 originally chose to store
	 * unreconstructable corruption as-is to avoid "silent data loss"; #829
	 * supersedes that ruling — silent corruption is worse than a visible
	 * gap.) The quality-audit detector
	 * (`AffiliateRedirectShape::find_corrupted_redirect()`, surfaced via
	 * `wp data-machine-events check quality`) remains the safety net for rows
	 * already in storage from before this guard existed. Healthy URLs and
	 * non-affiliate URLs pass through untouched.
	 *
	 * @param array $event_data Event data (by reference; URL attrs may be
	 *                          healed or removed).
	 */
	private function guard_affiliate_redirect_urls( array &$event_data ): void {
		foreach ( array( 'ticketUrl', 'organizerUrl' ) as $attr ) {
			$candidate = (string) ( $event_data[ $attr ] ?? '' );
			if ( '' === $candidate ) {
				continue;
			}

			$repair = AffiliateRedirectShape::repair_stored_url( $candidate );
			if ( null !== $repair ) {
				$event_data[ $attr ] = $repair['after'];

				do_action(
					'datamachine_log',
					'warning',
					'Import URL carried a corrupted affiliate redirect parameter; destination reconstructed at write time',
					array(
						'attribute'   => $attr,
						'before'      => $repair['before'],
						'after'       => $repair['after'],
						'destination' => $repair['destination'],
					)
				);
				continue;
			}

			if ( null === AffiliateRedirectShape::find_corrupted_redirect( $candidate ) ) {
				// Healthy, not an affiliate wrapper, or no redirect parameter
				// present to evaluate — nothing to do.
				continue;
			}

			// Corrupted AND not confidently reconstructable: the true
			// destination is unknowable. Discard rather than persist a value
			// that could send a real visitor to a broken or wrong page.
			unset( $event_data[ $attr ] );

			do_action(
				'datamachine_log',
				'warning',
				'Import URL carried a corrupted affiliate redirect parameter with no confident reconstruction; discarded before storage',
				array(
					'attribute' => $attr,
					'discarded' => $candidate,
				)
			);
		}
	}
}
