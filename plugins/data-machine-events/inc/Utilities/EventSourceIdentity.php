<?php
/**
 * Event source identity transition utility.
 *
 * @package DataMachineEvents\Utilities
 */

namespace DataMachineEvents\Utilities;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Core\DuplicateDetection\EventDuplicateStrategy;
use DataMachineEvents\Core\Event_Post_Type;

defined( 'ABSPATH' ) || exit;

class EventSourceIdentity {

	/**
	 * Resolve current and persisted legacy source identities without mutating state.
	 *
	 * A processed legacy hash suppresses migration only when the canonical event
	 * already exists. Otherwise the time-aware hash is allowed through so an event
	 * lost under the old date-only identity is not stranded. Core owns claims and
	 * marks the selected identity only after successful pipeline completion.
	 *
	 * @param array            $event   Standardized event packet.
	 * @param ExecutionContext $context Fetch execution context.
	 * @return array{event_identifier:string,item_identifier:string,legacy_identifier:string}
	 */
	public static function resolve( array $event, ExecutionContext $context ): array {
		$title      = (string) ( $event['title'] ?? '' );
		$start_date = (string) ( $event['startDate'] ?? '' );
		$venue      = (string) ( $event['venue'] ?? '' );
		$current    = EventIdentifierGenerator::generate(
			$title,
			$start_date,
			$venue,
			(string) ( $event['startTime'] ?? '' ),
			(string) ( $event['venueTimezone'] ?? '' )
		);
		$legacy     = EventIdentifierGenerator::generateLegacy( $title, $start_date, $venue );

		if ( $current === $legacy ) {
			return self::result( $current, $current, $legacy );
		}

		$classification = $context->classifySourceItems( array( $current, $legacy ) );
		$states         = array();
		foreach ( $classification['classifications'] ?? array() as $state ) {
			$states[ (string) ( $state['item_identifier'] ?? '' ) ] = $state;
		}

		$current_state = $states[ $current ] ?? array();
		$legacy_state  = $states[ $legacy ] ?? array();

		// Preserve explicit reprocessing and let core enforce current claims/history.
		if ( ! empty( $current_state['processed'] ) || ! empty( $current_state['actively_claimed'] ) ) {
			return self::result( $current, $current, $legacy );
		}

		if ( ! empty( $legacy_state['actively_claimed'] ) ) {
			return self::result( $current, $legacy, $legacy );
		}

		if ( empty( $legacy_state['processed'] ) || ! empty( $legacy_state['selected'] ) ) {
			return self::result( $current, $current, $legacy );
		}

		if ( self::canonicalEventExists( $event ) ) {
			return self::result( $current, $legacy, $legacy );
		}

		$context->log(
			'info',
			'Event source identity: advancing processed legacy hash because no canonical event exists',
			array(
				'title'             => $title,
				'start_datetime'    => EventIdentifierGenerator::normalizeStartDateTime(
					$start_date,
					(string) ( $event['startTime'] ?? '' ),
					(string) ( $event['venueTimezone'] ?? '' )
				),
				'legacy_identifier' => $legacy,
				'event_identifier'  => $current,
			)
		);

		return self::result( $current, $current, $legacy );
	}

	/**
	 * Check the existing event-domain identity index used by upsert.
	 *
	 * @param array $event Standardized event packet.
	 * @return bool True when upsert would resolve an existing canonical event.
	 */
	private static function canonicalEventExists( array $event ): bool {
		$result = EventDuplicateStrategy::check(
			array(
				'title'     => (string) ( $event['title'] ?? '' ),
				'post_type' => Event_Post_Type::POST_TYPE,
				'context'   => array(
					'venue'     => (string) ( $event['venue'] ?? '' ),
					'startDate' => EventIdentifierGenerator::normalizeStartDateTime(
						(string) ( $event['startDate'] ?? '' ),
						(string) ( $event['startTime'] ?? '' ),
						(string) ( $event['venueTimezone'] ?? '' )
					),
					'ticketUrl' => (string) ( $event['ticketUrl'] ?? '' ),
					'address'   => (string) ( $event['venueAddress'] ?? '' ),
					'city'      => (string) ( $event['venueCity'] ?? '' ),
					'state'     => (string) ( $event['venueState'] ?? '' ),
					'country'   => (string) ( $event['venueCountry'] ?? '' ),
				),
			)
		);

		return is_array( $result ) && 'duplicate' === ( $result['verdict'] ?? '' );
	}

	/**
	 * Build a typed identity result.
	 *
	 * @return array{event_identifier:string,item_identifier:string,legacy_identifier:string}
	 */
	private static function result( string $current, string $selected, string $legacy ): array {
		return array(
			'event_identifier'  => $current,
			'item_identifier'   => $selected,
			'legacy_identifier' => $legacy,
		);
	}
}
