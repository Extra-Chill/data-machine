<?php
/**
 * Canonical event identity resolution.
 *
 * One read-only seam answers "which venue is this event at?" and "which
 * existing event is this?" for every pre-write check: the pre-AI dedup gate,
 * source-identity hash migration, upsert advisory locks, the upsert itself,
 * the public upsert ability's venue validation, and the find-duplicate-event
 * ability. Before this seam each caller resolved the venue on its own, with
 * different inputs, so the answers disagreed and re-imports created duplicate
 * events (#3800, #3887, #883).
 *
 * Resolution order:
 *
 * 1. Source claim — a stable source identity (`source` + `source_id`, or an
 *    explicit `source_identity`) that already claims an event, including
 *    linked identities recorded by fill-only imports. A claimant that was
 *    merged into another event resolves to the merge winner.
 * 2. Venue — an explicit `venue_term_id` (engine data or the write path's
 *    persisted term) is authoritative and never re-resolved by name.
 *    Otherwise Venue_Taxonomy::resolve_venue_identity() runs with the same
 *    metadata, source venue alias and unverified-field context that
 *    persistence uses. An ambiguous name on an already-claimed event keeps
 *    that event's venue.
 * 3. Duplicate — EventDuplicateStrategy (via datamachine/check-duplicate)
 *    with that venue term, followed by the recurring-series date guard.
 *
 * The seam is read-only. Venue creation (find_or_create_venue) only happens
 * when the write path injects its persisting resolver via `persist_venue`.
 *
 * @package DataMachineEvents\Core\DuplicateDetection
 */

namespace DataMachineEvents\Core\DuplicateDetection;

use DataMachine\Core\EngineData;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\VenueParameterProvider;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Steps\Upsert\Events\EventTaxonomyAssigner;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventIdentity {

	/**
	 * Resolve the canonical identity of an incoming event.
	 *
	 * Event fields read from `$event` (engine data wins for venue fields, the
	 * same precedence persistence uses):
	 * - `title`, `startDate` (caller-normalized identity start), `ticketUrl`
	 * - `venue`, `venueAddress`, `venueCity`, `venueState`, `venueCountry`, …
	 * - `source`, `source_id`, `source_identity`, `festival`
	 *
	 * Supported `$config` keys:
	 * - `handler_config` (array) Handler configuration (artist collision guard).
	 * - `venue_term_id` (int) Venue term the caller already knows (authoritative).
	 * - `source_claim` (callable|false) `fn( string $source_identity ): int|\WP_Error`.
	 *   Defaults to the upsert's source-claim lookup; `false` skips the step.
	 * - `persist_venue` (callable) Write-path resolver returning
	 *   `array{term_id:int, action:string, matched_via?:string, festival?:string}`.
	 *   Only the upsert passes this; it runs after the source claim step.
	 * - `duplicates` (bool) Run duplicate detection when no claim matched. Default true.
	 *
	 * @param array      $event  Event fields.
	 * @param EngineData $engine Engine data.
	 * @param array      $config Resolution options.
	 * @return array{post_id:int, venue_term_id:int, venue_match:array, strategy:string, source_identity:string, match:array, merged_from:int, reason:string, error:?\WP_Error}
	 */
	public static function resolve( array $event, EngineData $engine, array $config = array() ): array {
		$source_identity = self::sourceIdentity( $event, $engine );
		$result          = array(
			'post_id'         => 0,
			'venue_term_id'   => 0,
			'venue_match'     => self::venueMatch( 'none' ),
			'strategy'        => '',
			'source_identity' => $source_identity,
			'match'           => array(),
			'merged_from'     => 0,
			'reason'          => '',
			'error'           => null,
		);

		// 1. Source claim, including linked identities and the merge winner.
		$source_claim = $config['source_claim'] ?? null;
		if ( false !== $source_claim ) {
			$claim_id = is_callable( $source_claim )
				? call_user_func( $source_claim, $source_identity )
				: EventUpsert::findExistingEventBySourceIdentity( $source_identity );
			if ( is_wp_error( $claim_id ) ) {
				$result['error'] = $claim_id;
				return $result;
			}
			$claim_id = (int) $claim_id;
			if ( $claim_id > 0 ) {
				$result['post_id']  = $claim_id;
				$result['strategy'] = 'source_identity';
				if ( 'trash' === get_post_status( $claim_id ) ) {
					// A source merged into another canonical event resolves to the
					// winner; ordinary trash keeps the claim so re-import restores it.
					$winner_id = EventMergeHelper::resolveMergedWinner( $claim_id );
					if ( $winner_id > 0 ) {
						$result['post_id']     = $winner_id;
						$result['strategy']    = 'merged_winner';
						$result['merged_from'] = $claim_id;
						return $result;
					}
				}
			}
		}

		// 2. One venue answer.
		if ( isset( $config['persist_venue'] ) && is_callable( $config['persist_venue'] ) ) {
			$persisted = (array) call_user_func( $config['persist_venue'] );
			$action    = (string) ( $persisted['action'] ?? 'skip' );
			$term_id   = 'assign' === $action ? absint( $persisted['term_id'] ?? 0 ) : 0;
			$status    = 'clear' === $action ? 'none' : ( $term_id > 0 ? 'matched' : 'unresolved' );
			if ( ! empty( $persisted['festival'] ) ) {
				$event['festival'] = $persisted['festival'];
			}
			$venue = array(
				'venue_term_id' => $term_id,
				'venue_match'   => self::venueMatch( $status, (string) ( $persisted['matched_via'] ?? '' ), array(), $action ),
			);
		} else {
			$venue = self::resolveVenue( $event, $engine, $config );
		}

		if ( $venue['venue_term_id'] <= 0 && $result['post_id'] > 0 && 'ambiguous' === $venue['venue_match']['status'] ) {
			// An ambiguous name never moves an already-claimed event: persistence
			// keeps the event's single assigned venue, so identity answers the same.
			$existing = wp_get_object_terms( $result['post_id'], 'venue', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $existing ) && 1 === count( $existing ) ) {
				$venue['venue_term_id']        = (int) reset( $existing );
				$venue['venue_match']['tier']  = 'existing_event';
				$venue['venue_match']['claim'] = true;
			}
		}

		$result['venue_term_id'] = $venue['venue_term_id'];
		$result['venue_match']   = $venue['venue_match'];

		// 3. Duplicate detection with the authoritative venue term.
		if ( $result['post_id'] > 0 || false === ( $config['duplicates'] ?? true ) ) {
			return $result;
		}

		$duplicate = self::findDuplicate(
			array(
				'title'         => (string) ( $event['title'] ?? '' ),
				'venue'         => VenueParameterProvider::resolveField( 'venue', $event, $engine->all() ),
				'startDate'     => (string) ( $event['startDate'] ?? '' ),
				'ticketUrl'     => (string) ( $engine->get( 'ticketUrl' ) ?? $event['ticketUrl'] ?? '' ),
				'address'       => VenueParameterProvider::resolveField( 'venueAddress', $event, $engine->all() ),
				'city'          => VenueParameterProvider::resolveField( 'venueCity', $event, $engine->all() ),
				'state'         => VenueParameterProvider::resolveField( 'venueState', $event, $engine->all() ),
				'country'       => VenueParameterProvider::resolveField( 'venueCountry', $event, $engine->all() ),
				'venue_term_id' => $result['venue_term_id'],
				'source'        => (string) ( $event['source'] ?? '' ),
				'festival'      => $engine->get( 'festival' ) ?? $event['festival'] ?? '',
			)
		);
		if ( is_wp_error( $duplicate ) ) {
			$result['error'] = $duplicate;
			return $result;
		}

		$result['post_id']  = $duplicate['post_id'];
		$result['strategy'] = $duplicate['strategy'];
		$result['match']    = $duplicate['match'];
		$result['reason']   = (string) ( $duplicate['reason'] ?? '' );

		return $result;
	}

	/**
	 * Resolve the venue of an incoming event without writing anything.
	 *
	 * @param array      $event  Event fields (see resolve()).
	 * @param EngineData $engine Engine data.
	 * @param array      $config Resolution options (see resolve()).
	 * @return array{venue_term_id:int, venue_match:array}
	 */
	public static function resolveVenue( array $event, EngineData $engine, array $config = array() ): array {
		$explicit = absint( $config['venue_term_id'] ?? 0 );
		$explicit = $explicit > 0 && get_term( $explicit, 'venue' ) instanceof \WP_Term ? $explicit : self::explicitVenueTermId( $engine );
		if ( $explicit > 0 ) {
			return array(
				'venue_term_id' => $explicit,
				'venue_match'   => self::venueMatch( 'explicit', 'explicit_term' ),
			);
		}

		$lookup = ( new EventTaxonomyAssigner() )->venueLookup( $event, $engine, (array) ( $config['handler_config'] ?? array() ), 0, false );
		if ( 'resolve' !== $lookup['action'] ) {
			return array(
				'venue_term_id' => 0,
				'venue_match'   => self::venueMatch( 'clear' === $lookup['action'] ? 'none' : 'skipped', '', array(), $lookup['action'] ),
			);
		}

		$identity = Venue_Taxonomy::resolve_venue_identity( $lookup['name'], $lookup['metadata'] );
		$term_id  = 'matched' === $identity['match_status'] ? absint( $identity['term_id'] ?? 0 ) : 0;

		return array(
			'venue_term_id' => $term_id,
			'venue_match'   => self::venueMatch(
				(string) $identity['match_status'],
				(string) $identity['matched_via'],
				array_map( 'intval', (array) $identity['conflicting_term_ids'] )
			),
		);
	}

	/**
	 * Venue term the caller explicitly chose for this event, if any.
	 *
	 * Engine data is set by the workflow's caller (a submission form, or a
	 * flow pinned to one venue), never by AI tool parameters, so the model
	 * cannot redirect an event through this key. An ID that is not an existing
	 * venue term is ignored and resolution falls back to the venue name.
	 *
	 * @param EngineData $engine  Engine data.
	 * @param int        $post_id Event post ID for log context.
	 * @return int Venue term ID, or 0.
	 */
	public static function explicitVenueTermId( EngineData $engine, int $post_id = 0 ): int {
		$term_id = absint( $engine->get( 'venue_term_id' ) ?? 0 );
		if ( $term_id <= 0 ) {
			return 0;
		}

		$term = get_term( $term_id, 'venue' );
		if ( $term instanceof \WP_Term ) {
			return (int) $term->term_id;
		}

		do_action(
			'datamachine_log',
			'warning',
			'Explicit venue_term_id is not an existing venue term; falling back to venue name resolution',
			array(
				'post_id'       => $post_id,
				'venue_term_id' => $term_id,
			)
		);

		return 0;
	}

	/**
	 * Stable source identity for an event, matching the upsert's derivation.
	 *
	 * @param array      $event  Event fields.
	 * @param EngineData $engine Engine data.
	 * @return string Source identity hash, or '' when the source is unknown.
	 */
	public static function sourceIdentity( array $event, EngineData $engine ): string {
		$explicit = trim( (string) ( $event['source_identity'] ?? '' ) );
		if ( '' !== $explicit ) {
			return $explicit;
		}

		$source    = trim( (string) ( $event['source'] ?? $engine->get( 'source_type' ) ?? '' ) );
		$source_id = trim( (string) ( $event['source_id'] ?? $engine->get( 'item_identifier' ) ?? '' ) );

		return '' !== $source && '' !== $source_id ? hash( 'sha256', $source . "\0" . $source_id ) : '';
	}

	/**
	 * Run event duplicate detection through the canonical contract.
	 *
	 * Only the event identity strategy counts; title-only core strategies are
	 * ignored. A match on a different calendar date, or more than two hours
	 * apart when both sides carry a time, is a distinct occurrence of a
	 * recurring series (#1108).
	 *
	 * @param array $context check-duplicate context, plus `title`.
	 * @return array{post_id:int, strategy:string, match:array, reason?:string}|\WP_Error
	 */
	public static function findDuplicate( array $context ): array|\WP_Error {
		$clear = array(
			'post_id'  => 0,
			'strategy' => '',
			'match'    => array(),
		);

		$duplicate_check = function_exists( 'wp_has_ability' ) && wp_has_ability( 'datamachine/check-duplicate' )
			? wp_get_ability( 'datamachine/check-duplicate' )
			: null;
		if ( ! $duplicate_check ) {
			return new \WP_Error(
				'datamachine_duplicate_contract_unavailable',
				'Data Machine 0.39.0 or newer is required: datamachine/check-duplicate is unavailable.',
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}

		$title     = (string) ( $context['title'] ?? '' );
		$startDate = (string) ( $context['startDate'] ?? '' );
		unset( $context['title'] );

		$result = $duplicate_check->execute(
			array(
				'title'     => $title,
				'post_type' => Event_Post_Type::POST_TYPE,
				'scope'     => 'published',
				'context'   => $context,
			)
		);

		if (
			! is_array( $result )
			|| 'duplicate' !== ( $result['verdict'] ?? '' )
			|| 'event_identity_index' !== ( $result['strategy'] ?? '' )
		) {
			return $clear;
		}

		$post_id = (int) ( $result['match']['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return $clear;
		}

		$match = array(
			'post_id'  => $post_id,
			'strategy' => (string) $result['strategy'],
			'match'    => is_array( $result['match'] ) ? $result['match'] : array(),
			'reason'   => (string) ( $result['reason'] ?? '' ),
		);

		if ( 'festival_lineup' === ( $match['match']['merge_mode'] ?? '' ) || '' === $startDate ) {
			return $match;
		}

		$existing           = self::eventDetailsAttributes( $post_id );
		$existing_startDate = (string) ( $existing['startDate'] ?? '' );
		if ( '' === $existing_startDate ) {
			return $match;
		}

		$incoming_date_only = self::dateOnly( $startDate );
		$existing_date_only = self::dateOnly( $existing_startDate );
		if ( $existing_date_only !== $incoming_date_only ) {
			do_action(
				'datamachine_log',
				'info',
				'Event Upsert: Ability matched title but date differs — treating as new event (recurring series)',
				array(
					'title'            => $title,
					'venue'            => (string) ( $context['venue'] ?? '' ),
					'incoming_date'    => $incoming_date_only,
					'existing_date'    => $existing_date_only,
					'existing_post_id' => $post_id,
				)
			);

			return $clear;
		}

		$existing_time  = trim( (string) ( $existing['startTime'] ?? '' ) );
		$existing_start = ( '' === $existing_time || preg_match( '/\d{2}:\d{2}/', $existing_startDate ) )
			? $existing_startDate
			: trim( $existing_startDate ) . ' ' . $existing_time;
		$existing_ts    = strtotime( $existing_start );
		$incoming_ts    = strtotime( $startDate );
		if ( preg_match( '/\d{2}:\d{2}/', $existing_start )
			&& preg_match( '/\d{2}:\d{2}/', $startDate )
			&& false !== $existing_ts
			&& false !== $incoming_ts
			&& abs( $existing_ts - $incoming_ts ) > 7200
		) {
			return $clear;
		}

		return $match;
	}

	/**
	 * Build a venue match descriptor.
	 *
	 * @param string $status     explicit|matched|ambiguous|conflict|no_match|none|skipped|unresolved.
	 * @param string $tier       Rule that produced the match (source_alias, address, name, explicit_term, …).
	 * @param int[]  $candidates Candidate term IDs rejected by geography.
	 * @param string $action     Persistence action when known.
	 * @return array{status:string, tier:string, candidates:int[], action:string}
	 */
	private static function venueMatch( string $status, string $tier = '', array $candidates = array(), string $action = '' ): array {
		return array(
			'status'     => $status,
			'tier'       => $tier,
			'candidates' => $candidates,
			'action'     => $action,
		);
	}

	/**
	 * Event-details block attributes of an existing event.
	 *
	 * @param int $post_id Event post ID.
	 * @return array Block attributes.
	 */
	private static function eventDetailsAttributes( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( Event_Post_Type::EVENT_DETAILS_BLOCK_NAME === $block['blockName'] ) {
				return $block['attrs'];
			}
		}

		return array();
	}

	/**
	 * Date portion (YYYY-MM-DD) of a date or datetime.
	 *
	 * @param string $datetime Date or datetime.
	 * @return string Date portion.
	 */
	private static function dateOnly( string $datetime ): string {
		return preg_match( '/^(\d{4}-\d{2}-\d{2})/', $datetime, $matches ) ? $matches[1] : $datetime;
	}
}
