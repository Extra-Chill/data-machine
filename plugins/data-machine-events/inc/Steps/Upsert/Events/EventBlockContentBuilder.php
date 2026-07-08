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

			'showVenue'         => true,
			'showPrice'         => true,
			'showTicketLink'    => true,
		);

		$block_attributes = array_filter(
			$block_attributes,
			function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		$block_attributes['showVenue']      = true;
		$block_attributes['showPrice']      = true;
		$block_attributes['showTicketLink'] = true;

		$block_json  = wp_json_encode( $block_attributes, JSON_UNESCAPED_UNICODE );
		$description = ! empty( $event_data['description'] ) ? wp_kses_post( $event_data['description'] ) : '';

		$inner_blocks = $this->generate_description_blocks( $description );

		return '<!-- wp:data-machine-events/event-details ' . $block_json . ' -->' . "\n" .
				'<div class="wp-block-data-machine-events-event-details">' .
				( $inner_blocks ? "\n" . $inner_blocks . "\n" : '' ) .
				'</div>' . "\n" .
				'<!-- /wp:data-machine-events/event-details -->';
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
}
