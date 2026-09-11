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
			'occurrenceDates'   => $event_data['occurrenceDates'] ?? array(),
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

			'showVenue'         => true,
			'showPrice'         => true,
			'showTicketLink'    => true,
		);

		$block_attributes = array_filter(
			$block_attributes,
			function ( $value ) {
				return '' !== $value;
			}
		);

		$block_attributes['showVenue']      = true;
		$block_attributes['showPrice']      = true;
		$block_attributes['showTicketLink'] = true;

		$block_json  = wp_json_encode( $block_attributes, JSON_UNESCAPED_UNICODE );
		$description = ! empty( $event_data['description'] ) ? wp_kses_post( $event_data['description'] ) : '';

		$inner_blocks = $this->generate_description_blocks( $description );

		return '<!-- wp:' . Event_Post_Type::EVENT_DETAILS_BLOCK_NAME . ' ' . $block_json . ' -->' . "\n" .
				'<div class="wp-block-data-machine-events-event-details">' .
				( $inner_blocks ? "\n" . $inner_blocks . "\n" : '' ) .
				'</div>' . "\n" .
				'<!-- /wp:' . Event_Post_Type::EVENT_DETAILS_BLOCK_NAME . ' -->';
	}

	/**
	 * Generate paragraph blocks from HTML description
	 *
	 * @param string $description HTML description content
	 * @return string InnerBlocks content with proper paragraph blocks
	 */
	private function generate_description_blocks( string $description ): string {
		if ( empty( $description ) ) {
			return '';
		}

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
	 * Write-path guard: heal corrupted affiliate redirect URLs before storage.
	 *
	 * The import pipeline's AI step constructs the affiliate wrappers, and
	 * occasionally transcribes the redirect parameter with its URL punctuation
	 * stripped (`?u=httpswww.ticketmaster.comevent...`). Once stored, such a
	 * wrapper redirects real visitors to a garbage destination. Production
	 * evidence (post 405414, imported 2026-09-09) showed this class of
	 * corruption was still being produced by live import flows — see issue
	 * #823.
	 *
	 * When the corrupted destination reconstructs with confidence it is
	 * replaced here, so no import path can persist the corruption. When it
	 * does NOT reconstruct confidently the value is stored as-is (blanking an
	 * URL would be silent data loss) and the quality-audit detector
	 * (`AffiliateRedirectShape::find_corrupted_redirect()`, surfaced via
	 * `wp data-machine-events check quality`) remains the safety net. Healthy
	 * URLs pass through untouched.
	 *
	 * @param array $event_data Event data (by reference; URL attrs may be healed).
	 */
	private function guard_affiliate_redirect_urls( array &$event_data ): void {
		foreach ( array( 'ticketUrl', 'organizerUrl' ) as $attr ) {
			$candidate = (string) ( $event_data[ $attr ] ?? '' );
			if ( '' === $candidate ) {
				continue;
			}

			$repair = AffiliateRedirectShape::repair_stored_url( $candidate );
			if ( null === $repair ) {
				continue;
			}

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
		}
	}
}
