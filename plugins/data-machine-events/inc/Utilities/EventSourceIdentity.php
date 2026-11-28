<?php
/**
 * Event source identity transition utility.
 *
 * @package DataMachineEvents\Utilities
 */

namespace DataMachineEvents\Utilities;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\EngineData;
use DataMachineEvents\Core\DuplicateDetection\EventIdentity;
use DataMachineEvents\Core\EventOwnership;

defined( 'ABSPATH' ) || exit;

class EventSourceIdentity {

	/**
	 * Resolve provider, current and persisted legacy source identities.
	 *
	 * A processed legacy hash suppresses migration only when the canonical event
	 * already exists. Otherwise the time-aware hash is allowed through so an event
	 * lost under the old date-only identity is not stranded. Core owns claims and
	 * marks the selected identity only after successful pipeline completion.
	 * Provider IDs advance content identities by linking the canonical post before
	 * returning the new identifier; processed history is never written here.
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

		$stable = trim( (string) ( $event['source_item_id'] ?? '' ) );
		if ( '' !== $stable ) {
			return self::resolveStable( $event, $context, $stable, $current, $legacy );
		}

		if ( $current === $legacy ) {
			return self::result( $current, $current, $legacy );
		}

		$classification = $context->classifySourceItems( array( $current, $legacy ) );
		$states         = array();
		foreach ( $classification['classifications'] as $state ) {
			$states[ $state['item_identifier'] ] = $state;
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
	 * Advance content identities to a provider identity, retaining canonical claims.
	 *
	 * Active content claims must finish before a new identity can be admitted.
	 * Processed history alone is not proof that a canonical event still exists.
	 *
	 * @param array            $event   Standardized event packet.
	 * @param ExecutionContext $context Fetch execution context.
	 * @param string           $stable  Provider item ID.
	 * @param string           $current Time-aware content hash.
	 * @param string           $legacy  Date-only content hash.
	 * @return array{event_identifier:string,item_identifier:string,legacy_identifier:string}
	 * @throws \RuntimeException When canonical identity resolution fails.
	 */
	private static function resolveStable( array $event, ExecutionContext $context, string $stable, string $current, string $legacy ): array {
		$classification = $context->classifySourceItems( array_values( array_unique( array( $stable, $current, $legacy ) ) ) );
		$states         = array();
		foreach ( $classification['classifications'] as $state ) {
			$states[ $state['item_identifier'] ] = $state;
		}

		foreach ( array_unique( array( $current, $legacy ) ) as $previous ) {
			if ( ! empty( $states[ $previous ]['actively_claimed'] ) ) {
				return self::result( $current, $previous, $legacy );
			}
		}

		$engine   = new EngineData( $event, 0 );
		$existing = EventIdentity::resolve(
			array(
				'source'    => $context->getHandlerType(),
				'source_id' => $stable,
			),
			$engine,
			array( 'duplicates' => false )
		);
		if ( $existing['error'] instanceof \WP_Error ) {
			throw new \RuntimeException( esc_html( $existing['error']->get_error_message() ) );
		}
		if ( $existing['post_id'] > 0 ) {
			return self::result( $current, $stable, $legacy );
		}

		foreach ( array_unique( array( $current, $legacy ) ) as $previous ) {
			if ( empty( $states[ $previous ]['processed'] ) ) {
				continue;
			}
			$identity = EventIdentity::resolve(
				array(
					'title'     => (string) ( $event['title'] ?? '' ),
					'startDate' => EventIdentifierGenerator::normalizeStartDateTime(
						(string) ( $event['startDate'] ?? '' ),
						(string) ( $event['startTime'] ?? '' ),
						(string) ( $event['venueTimezone'] ?? '' )
					),
					'source'    => $context->getHandlerType(),
					'source_id' => $previous,
				),
				$engine
			);
			if ( $identity['error'] instanceof \WP_Error ) {
				throw new \RuntimeException( esc_html( $identity['error']->get_error_message() ) );
			}
			if ( $identity['post_id'] > 0 ) {
				$stable_identity = EventIdentity::sourceIdentity(
					array(
						'source'    => $context->getHandlerType(),
						'source_id' => $stable,
					),
					$engine
				);
				EventOwnership::linkSourceIdentity( $identity['post_id'], $stable_identity );
				break;
			}
		}

		return self::result( $current, $stable, $legacy );
	}

	/**
	 * Check the canonical duplicate ability used by upsert.
	 *
	 * @param array $event Standardized event packet.
	 * @return bool True when upsert would resolve an existing canonical event.
	 */
	private static function canonicalEventExists( array $event ): bool {
		// The packet is the engine data the upsert will see, so the venue
		// (explicit, pinned, or source-aliased term) resolves the same way.
		$identity = EventIdentity::resolve(
			array(
				'title'     => (string) ( $event['title'] ?? '' ),
				'startDate' => EventIdentifierGenerator::normalizeStartDateTime(
					(string) ( $event['startDate'] ?? '' ),
					(string) ( $event['startTime'] ?? '' ),
					(string) ( $event['venueTimezone'] ?? '' )
				),
			),
			new EngineData( $event, 0 ),
			array( 'source_claim' => false )
		);
		if ( $identity['error'] instanceof \WP_Error ) {
			throw new \RuntimeException( esc_html( $identity['error']->get_error_message() ) );
		}

		return $identity['post_id'] > 0;
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
