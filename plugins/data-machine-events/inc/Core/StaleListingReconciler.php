<?php
/**
 * Stale listing reconciliation for venue-pinned scraper flows.
 *
 * Venue import flows with a fixed `venue` term in their fetch handler config
 * are insert/upsert-only: when the venue swaps who plays on a date, the new
 * act is imported and the previously imported post stays published forever.
 * Dedup correctly treats the two as different events; the gap is that nobody
 * retires the act the source no longer lists.
 *
 * This reconciler compares the published upcoming events attached to a
 * flow+venue against a fresh extraction of the flow's source URL and
 * identifies published flow events with no same-date source counterpart.
 * Retirement is explicit (trash + audit meta), never delete, and is guarded
 * against bad/partial scrapes: if the source extraction looks unhealthy
 * relative to the database, no candidates are produced.
 *
 * Matching is identity-first, then by date/slot. Imported posts carry the
 * source item identifier they were last upserted from
 * (`_datamachine_event_source_id`, a hash of title + local start + venue for
 * scraper items). A post whose stored identifier is still in the extraction
 * is listed. A post without a stored identifier (legacy imports) is "still
 * listed" when a source event on the same calendar date has a title that
 * matches through the same title contract dedup uses, or when titles match
 * within the dedup time window (late-night shows that cross midnight on the
 * source side).
 *
 * A post whose stored identifier is absent from the extraction falls back to
 * that same title/slot matching, with one difference: a title match whose
 * start time differs from the post is a different listing, not this one.
 * This is the shape a superseded recurring series leaves behind (a venue
 * splits a weekly series and the replacement lists the same title an hour
 * earlier): the old occurrences must not be kept alive by the new ones. The
 * post that owns the replacement listing's identifier, when one exists, is
 * reported as `superseded_by` so reviewers can see the duplicate pair.
 *
 * No Extra Chill specific behaviour lives here; this class is generic
 * reconciliation logic over Data Machine events.
 *
 * @package DataMachineEvents\Core
 * @since   0.57.1
 */

namespace DataMachineEvents\Core;

use DataMachineEvents\Abilities\EventDateQueryAbilities;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;
use DataMachineEvents\Utilities\EventIdentifierGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StaleListingReconciler {

	/**
	 * Meta key recording why a post was retired.
	 */
	public const RETIRED_REASON_META = '_datamachine_retired_reason';

	/**
	 * Meta key recording when a post was retired (UTC mysql).
	 */
	public const RETIRED_AT_META = '_datamachine_retired_at';

	/**
	 * Reason written to RETIRED_REASON_META for source-unlisted retirement.
	 */
	public const RETIRE_REASON_SOURCE_UNLISTED = 'source_unlisted';

	/**
	 * Minimum fraction of published upcoming flow+venue events that the source
	 * extraction must account for before any retirement is allowed. A scrape
	 * that returns far less than what the database already holds is treated as
	 * a bad or partial extraction, and reconciliation aborts.
	 */
	public const MIN_SOURCE_COVERAGE_RATIO = 0.5;

	/**
	 * Default lookahead window for "upcoming" events, in days.
	 */
	public const DEFAULT_DAYS_AHEAD = 120;

	/**
	 * Same-slot tolerance used by duplicate detection, in seconds. A source
	 * event whose title matches and whose start is within this window of a
	 * published event counts as "still listed" even if its calendar date
	 * differs (late-night shows crossing midnight).
	 */
	public const MATCH_TIME_WINDOW_SECONDS = 7200;

	/**
	 * Post meta holding the source item identifier an event was last upserted
	 * from. Mirrors EventUpsert::SOURCE_ID_META_KEY.
	 */
	public const SOURCE_ID_META_KEY = '_datamachine_event_source_id';

	/**
	 * Optional injected flow config loader: fn( int $flow_id ): ?array.
	 *
	 * @var callable|null
	 */
	private $flow_config_loader;

	/**
	 * Optional injected source fetcher: fn( array $scraper_config ): array|\WP_Error.
	 *
	 * @var callable|null
	 */
	private $source_fetcher;

	/**
	 * Constructor.
	 *
	 * @param callable|null $flow_config_loader Optional flow config loader for tests.
	 * @param callable|null $source_fetcher     Optional source fetcher for tests.
	 */
	public function __construct( ?callable $flow_config_loader = null, ?callable $source_fetcher = null ) {
		$this->flow_config_loader = $flow_config_loader;
		$this->source_fetcher     = $source_fetcher;
	}

	/**
	 * Load the venue-pinned universal web scraper config for a flow.
	 *
	 * A flow is only eligible for stale-listing reconciliation when its
	 * event_import step runs the universal web scraper with a fixed venue
	 * term in its handler config.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array{source_url:string,venue_term_id:int,handler_config:array}|\WP_Error Resolved config or error.
	 */
	public function loadVenuePinnedScraperConfig( int $flow_id ) {
		$flow_config = $this->loadFlowConfig( $flow_id );

		if ( null === $flow_config ) {
			return new \WP_Error(
				'stale_listings_flow_not_found',
				sprintf( 'Flow %d was not found or has no readable config.', $flow_id )
			);
		}

		$step = $this->findEventImportStep( $flow_config );

		if ( null === $step ) {
			return new \WP_Error(
				'stale_listings_no_event_import_step',
				sprintf( 'Flow %d has no enabled event_import step.', $flow_id )
			);
		}

		$handler_slugs = is_array( $step['handler_slugs'] ?? null ) ? $step['handler_slugs'] : array();

		if ( ! in_array( 'universal_web_scraper', $handler_slugs, true ) ) {
			return new \WP_Error(
				'stale_listings_handler_not_supported',
				sprintf(
					'Flow %d event_import step does not use the universal_web_scraper handler; only venue-pinned scraper flows are supported.',
					$flow_id
				)
			);
		}

		$handler_config = is_array( $step['handler_configs']['universal_web_scraper'] ?? null )
			? $step['handler_configs']['universal_web_scraper']
			: array();

		$source_url    = trim( (string) ( $handler_config['source_url'] ?? '' ) );
		$venue_term_id = (int) ( $handler_config['venue'] ?? 0 );
		$venue_name    = trim( (string) ( $handler_config['venue_name'] ?? '' ) );

		if ( '' === $source_url ) {
			return new \WP_Error(
				'stale_listings_missing_source_url',
				sprintf( 'Flow %d handler config has no source_url.', $flow_id )
			);
		}

		// Flows may pin their venue by name (+ optional geography) instead of
		// a term ID. Resolve that to the existing venue term through the same
		// identity resolver the upsert path uses — read-only: an unresolvable
		// name errors instead of creating a term.
		if ( $venue_term_id <= 0 && '' !== $venue_name ) {
			$identity = Venue_Taxonomy::resolve_venue_identity(
				$venue_name,
				array(
					'address' => (string) ( $handler_config['venue_address'] ?? '' ),
					'city'    => (string) ( $handler_config['venue_city'] ?? '' ),
					'state'   => (string) ( $handler_config['venue_state'] ?? '' ),
					'country' => (string) ( $handler_config['venue_country'] ?? '' ),
				)
			);

			if ( 'matched' === $identity['match_status'] && (int) ( $identity['term_id'] ?? 0 ) > 0 ) {
				$venue_term_id = (int) $identity['term_id'];
			}
		}

		if ( $venue_term_id <= 0 ) {
			return new \WP_Error(
				'stale_listings_venue_not_pinned',
				sprintf(
					'Flow %1$d handler config has no fixed venue term and its venue_name (%2$s) could not be resolved to an existing venue term; stale-listing reconciliation only runs on venue-pinned flows.',
					$flow_id,
					'' !== $venue_name ? $venue_name : 'none configured'
				)
			);
		}

		return array(
			'source_url'     => $source_url,
			'venue_term_id'  => $venue_term_id,
			'handler_config' => $handler_config,
		);
	}

	/**
	 * Fetch the events the source currently lists.
	 *
	 * Uses the same direct-mode handler path the scraper test ability uses,
	 * with no production item cap. Returns one entry per structured source
	 * event with title/startDate/startTime.
	 *
	 * @param array $scraper_config Result of loadVenuePinnedScraperConfig().
	 * Each entry also carries `identifiers`: the source item identifiers the
	 * import pipeline attached to the packet (event_identifier and the
	 * selected item_identifier), which is what upserted posts persist as
	 * `_datamachine_event_source_id`.
	 *
	 * @return array<int, array{title:string,startDate:string,startTime:string,identifiers:list<string>}>|\WP_Error Source events or error.
	 */
	public function fetchSourceEvents( array $scraper_config ) {
		if ( $this->source_fetcher ) {
			$events = ( $this->source_fetcher )( $scraper_config );

			return is_array( $events ) ? $events : new \WP_Error(
				'stale_listings_fetch_failed',
				'Injected source fetcher did not return an event list.'
			);
		}

		$config = array_merge(
			is_array( $scraper_config['handler_config'] ?? null ) ? $scraper_config['handler_config'] : array(),
			array(
				'source_url'   => (string) ( $scraper_config['source_url'] ?? '' ),
				'flow_step_id' => 'stale_listings_' . wp_generate_uuid4(),
				'flow_id'      => 'direct',
			)
		);

		// Reconciliation needs the full listing; the production item cap must
		// not silently truncate the source inventory being compared against.
		unset( $config['max_items'] );

		$handler = new UniversalWebScraper();
		$results = $handler->get_fetch_data( 'direct', $config, null );

		if ( empty( $results ) ) {
			return new \WP_Error(
				'stale_listings_fetch_failed',
				'Source fetch returned no results; refusing to reconcile against an empty extraction.'
			);
		}

		$events = array();

		foreach ( $results as $packet ) {
			$entries = $packet->addTo( array() );

			foreach ( $entries as $entry ) {
				$body = is_array( $entry ) ? (string) ( $entry['data']['body'] ?? '' ) : '';
				if ( '' === $body ) {
					continue;
				}

				$payload = json_decode( $body, true );
				$event   = is_array( $payload ) ? ( $payload['event'] ?? null ) : null;

				if ( ! is_array( $event ) ) {
					continue;
				}

				$metadata    = is_array( $entry['metadata'] ?? null ) ? $entry['metadata'] : array();
				$identifiers = array();
				foreach ( array( 'event_identifier', 'item_identifier' ) as $key ) {
					$value = trim( (string) ( $metadata[ $key ] ?? '' ) );
					if ( '' !== $value ) {
						$identifiers[] = $value;
					}
				}

				$events[] = array(
					'title'       => (string) ( $event['title'] ?? '' ),
					'startDate'   => (string) ( $event['startDate'] ?? '' ),
					'startTime'   => (string) ( $event['startTime'] ?? '' ),
					'identifiers' => array_values( array_unique( $identifiers ) ),
				);
			}
		}

		return $events;
	}

	/**
	 * Find published flow events the source no longer lists.
	 *
	 * Candidates are published data_machine_events posts that carry this
	 * flow's provenance meta, are attached to the flow's venue term, start
	 * within the lookahead window, and have no matched source event. Posts
	 * with a different or missing flow ID are never touched.
	 *
	 * @param int   $flow_id       Flow ID.
	 * @param int   $venue_term_id Venue term ID from the flow's handler config.
	 * @param array $source_events Source events from fetchSourceEvents().
	 * @param int   $days_ahead    Lookahead window in days.
	 * @return array<int, array{candidate_id:string,post_id:int,title:string,start:string,venue:string,source_listed_that_date:string,superseded_by:int}>|\WP_Error Candidates or error.
	 */
	public function findStaleCandidates( int $flow_id, int $venue_term_id, array $source_events, int $days_ahead = self::DEFAULT_DAYS_AHEAD ) {
		$db_events = $this->queryFlowVenueEvents( $flow_id, $venue_term_id, $days_ahead );

		if ( empty( $db_events ) ) {
			// Nothing attached to this flow+venue: nothing to retire, and the
			// guard-rail coverage comparison is meaningless without a baseline.
			return array();
		}

		$source_slots = $this->buildSourceSlots( $source_events, $days_ahead, (string) ( $db_events[0]['venue'] ?? '' ) );

		if ( 0 === $source_slots['total_extracted'] ) {
			return new \WP_Error(
				'stale_listings_source_empty',
				'Source extraction returned 0 structured events; refusing to retire anything against an empty listing.'
			);
		}

		// Guard rail: a healthy scrape of a venue calendar accounts for at
		// least MIN_SOURCE_COVERAGE_RATIO of what the database already holds.
		// Below that the extraction is presumed bad or partial.
		$required_minimum = (int) ceil( count( $db_events ) * self::MIN_SOURCE_COVERAGE_RATIO );

		if ( count( $source_slots['upcoming'] ) < $required_minimum ) {
			return new \WP_Error(
				'stale_listings_low_source_coverage',
				sprintf(
					'Source extraction lists %1$d upcoming event(s) but the database holds %2$d published upcoming event(s) for this flow+venue; below the %3$s coverage threshold the extraction is treated as bad or partial. Retiring nothing.',
					count( $source_slots['upcoming'] ),
					count( $db_events ),
					(string) self::MIN_SOURCE_COVERAGE_RATIO
				)
			);
		}

		$candidates = array();

		foreach ( $db_events as $db_event ) {
			$source_id = $db_event['source_id'];

			if ( '' !== $source_id && isset( $source_slots['identifiers'][ $source_id ] ) ) {
				continue;
			}

			$matched_source = $this->findMatchingSourceEvent( $db_event, $source_slots, '' !== $source_id );
			$superseded_by  = 0;

			if ( null !== $matched_source ) {
				if ( empty( $matched_source['superseded'] ) ) {
					continue;
				}

				$superseded_by = $this->findIdentifierOwner( $matched_source['identifiers'], (int) $db_event['post_id'] );
			}

			$start = $db_event['start'];
			$title = $db_event['title'];
			$venue = $db_event['venue'];

			$fingerprint = implode( "\0", array( 'v1', (string) $flow_id, (string) $db_event['post_id'], $title, $start, $venue ) );

			$candidates[] = array(
				'candidate_id'            => hash( 'sha256', $fingerprint ),
				'post_id'                 => $db_event['post_id'],
				'title'                   => $title,
				'start'                   => $start,
				'venue'                   => $venue,
				'source_listed_that_date' => $this->summarizeSourceTitles( $source_slots['by_date'][ $db_event['date'] ] ?? array() ),
				'superseded_by'           => $superseded_by,
			);
		}

		return $candidates;
	}

	/**
	 * Retire reviewed candidates by trashing them with audit meta.
	 *
	 * Posts are trashed (reversible), never deleted. The audit meta is
	 * written before the trash so a crash between the two leaves an
	 * auditable trail; if the trash itself fails the meta is removed again.
	 *
	 * @param array $candidates Reviewed candidate rows from findStaleCandidates().
	 * @return array{trashed:int,failed:int}|\WP_Error Counts or error.
	 */
	public function retireCandidates( array $candidates ) {
		$trashed = 0;
		$failed  = 0;

		foreach ( $candidates as $candidate ) {
			$post_id = (int) ( $candidate['post_id'] ?? 0 );

			if ( $post_id <= 0 ) {
				++$failed;
				continue;
			}

			update_post_meta( $post_id, self::RETIRED_REASON_META, self::RETIRE_REASON_SOURCE_UNLISTED );
			update_post_meta( $post_id, self::RETIRED_AT_META, gmdate( 'Y-m-d H:i:s' ) );

			$result = wp_trash_post( $post_id );

			if ( ! $result instanceof \WP_Post ) {
				delete_post_meta( $post_id, self::RETIRED_REASON_META );
				delete_post_meta( $post_id, self::RETIRED_AT_META );
				++$failed;
				continue;
			}

			++$trashed;
		}

		return array(
			'trashed' => $trashed,
			'failed'  => $failed,
		);
	}

	/**
	 * Load and decode a flow's config from the Data Machine flows table.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array|null Decoded flow config, or null when unavailable.
	 */
	private function loadFlowConfig( int $flow_id ): ?array {
		if ( $this->flow_config_loader ) {
			$config = ( $this->flow_config_loader )( $flow_id );

			return is_array( $config ) ? $config : null;
		}

		global $wpdb;

		$table  = $wpdb->prefix . 'datamachine_flows';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists !== $table ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- flows table has no cache surface and is not part of the object cache domain.
		$raw = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT flow_config FROM {$table} WHERE flow_id = %d", $flow_id )
		);

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Find the enabled event_import step in a decoded flow config.
	 *
	 * Flow configs map flow step IDs to step config arrays; tolerate any
	 * array-of-arrays shape by scanning values.
	 *
	 * @param array $flow_config Decoded flow config.
	 * @return array|null Step config or null.
	 */
	private function findEventImportStep( array $flow_config ): ?array {
		foreach ( $flow_config as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			if ( 'event_import' === ( $step['step_type'] ?? '' ) && ! empty( $step['enabled'] ) ) {
				return $step;
			}
		}

		return null;
	}

	/**
	 * Query published upcoming events attached to a flow+venue.
	 *
	 * Uses the same query ability the other check commands use, with a
	 * provenance meta clause so posts from other flows (and manually created
	 * events with no flow meta) are excluded by construction.
	 *
	 * @param int $flow_id       Flow ID.
	 * @param int $venue_term_id Venue term ID.
	 * @param int $days_ahead    Lookahead window in days.
	 * @return array<int, array{post_id:int,title:string,start:string,date:string,venue:string,source_id:string}>
	 */
	private function queryFlowVenueEvents( int $flow_id, int $venue_term_id, int $days_ahead ): array {
		$input = array(
			'scope'       => 'upcoming',
			'order'       => 'ASC',
			'status'      => 'publish',
			'per_page'    => -1,
			'tax_filters' => array( 'venue' => array( $venue_term_id ) ),
			'meta_query'  => array(
				array(
					'key'     => '_datamachine_post_flow_id',
					'value'   => (string) $flow_id,
					'compare' => '=',
				),
			),
		);

		if ( $days_ahead > 0 ) {
			$input['days_ahead'] = $days_ahead;
		}

		$ability = new EventDateQueryAbilities();
		$result  = $ability->executeQueryEvents( $input );
		$posts   = is_array( $result['posts'] ?? null ) ? $result['posts'] : array();

		$venue_name = $this->getVenueName( $venue_term_id );
		$events     = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$dates = EventDatesTable::get( $post->ID );

			if ( ! $dates || '' === $dates->start_datetime ) {
				continue;
			}

			$start = EventIdentifierGenerator::normalizeStartDateTime( $dates->start_datetime );

			if ( '' === $start || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $start ) ) {
				continue;
			}

			$events[] = array(
				'post_id'   => (int) $post->ID,
				'title'     => (string) $post->post_title,
				'start'     => $start,
				'date'      => substr( $start, 0, 10 ),
				'venue'     => $venue_name,
				'source_id' => trim( (string) get_post_meta( $post->ID, self::SOURCE_ID_META_KEY, true ) ),
			);
		}

		return $events;
	}

	/**
	 * Resolve the display name of a venue term.
	 *
	 * @param int $venue_term_id Venue term ID.
	 * @return string Venue name or empty string.
	 */
	private function getVenueName( int $venue_term_id ): string {
		$term = get_term( $venue_term_id, 'venue' );

		return ( $term instanceof \WP_Term ) ? $term->name : '';
	}

	/**
	 * Normalize source events into date-indexed slots.
	 *
	 * Every source identifier is indexed (including ones outside the
	 * lookahead window) so identity matching never depends on the window.
	 * The legacy date-only identifier is derived from the title, date, and
	 * pinned venue name, because posts imported before time-aware identity
	 * persisted that form.
	 *
	 * @param array  $source_events Raw source event list.
	 * @param int    $days_ahead    Lookahead window in days.
	 * @param string $venue_name    Pinned venue display name.
	 * @return array{total_extracted:int,upcoming:list<array{title:string,start:string,date:string,identifiers:list<string>}>,by_date:array<string, list<array{title:string,start:string,date:string,identifiers:list<string>}>>,identifiers:array<string,true>}
	 */
	private function buildSourceSlots( array $source_events, int $days_ahead, string $venue_name = '' ): array {
		$today = substr( current_time( 'mysql' ), 0, 10 );
		$limit = ( new \DateTimeImmutable( $today ) )
			->modify( '+' . max( 0, $days_ahead ) . ' days' )
			->format( 'Y-m-d' );

		$upcoming    = array();
		$by_date     = array();
		$identifiers = array();
		$total       = 0;

		foreach ( $source_events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			++$total;

			$start = EventIdentifierGenerator::normalizeStartDateTime(
				(string) ( $event['startDate'] ?? '' ),
				(string) ( $event['startTime'] ?? '' )
			);

			if ( '' === $start || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $start ) ) {
				continue;
			}

			$slot_identifiers = array();
			foreach ( (array) ( $event['identifiers'] ?? array() ) as $identifier ) {
				$identifier = trim( (string) $identifier );
				if ( '' !== $identifier ) {
					$slot_identifiers[] = $identifier;
				}
			}
			if ( ! empty( $slot_identifiers ) && '' !== $venue_name ) {
				$slot_identifiers[] = EventIdentifierGenerator::generateLegacy(
					(string) ( $event['title'] ?? '' ),
					(string) ( $event['startDate'] ?? '' ),
					$venue_name
				);
			}
			$slot_identifiers = array_values( array_unique( $slot_identifiers ) );
			foreach ( $slot_identifiers as $identifier ) {
				$identifiers[ $identifier ] = true;
			}

			$slot = array(
				'title'       => (string) ( $event['title'] ?? '' ),
				'start'       => $start,
				'date'        => substr( $start, 0, 10 ),
				'identifiers' => $slot_identifiers,
			);

			if ( $slot['date'] < $today || $slot['date'] > $limit ) {
				continue;
			}

			$upcoming[]                 = $slot;
			$by_date[ $slot['date'] ][] = $slot;
		}

		return array(
			'total_extracted' => $total,
			'upcoming'        => $upcoming,
			'by_date'         => $by_date,
			'identifiers'     => $identifiers,
		);
	}

	/**
	 * Find a source event that keeps a published event "listed".
	 *
	 * A source event matches when its title fuzzy-matches the published
	 * title through the same contract dedup uses AND either shares the
	 * published event's calendar date or starts within the dedup time
	 * window (source-side midnight crossing).
	 *
	 * When the post's stored source identifier is known to be absent from the
	 * extraction, a title match that also carries its own identifiers and
	 * starts at a different time is a different listing. It is returned
	 * flagged `superseded` (unless an exact-start match exists), so the caller
	 * treats the post as stale. Same-start matches still count as listed: a
	 * retitled listing keeps its slot and is re-keyed on the next import.
	 *
	 * @param array $db_event         Published event row (post_id/title/start/date/venue).
	 * @param array $source_slots     Date-indexed source slots.
	 * @param bool  $identity_missing Whether the post has a stored identifier absent from the source.
	 * @return array|null Matched source slot (with `superseded` flag) or null.
	 */
	private function findMatchingSourceEvent( array $db_event, array $source_slots, bool $identity_missing = false ): ?array {
		$superseded = null;

		foreach ( $source_slots['upcoming'] as $slot ) {
			if ( ! EventIdentifierGenerator::titlesMatch( $db_event['title'], $slot['title'] ) ) {
				continue;
			}

			if ( $slot['date'] !== $db_event['date'] && ! $this->isWithinTimeWindow( $db_event['start'], $slot['start'] ) ) {
				continue;
			}

			if ( $identity_missing && ! empty( $slot['identifiers'] ) && ! $this->isSameStart( $db_event['start'], $slot['start'] ) ) {
				$superseded = $superseded ?? $slot;
				continue;
			}

			$slot['superseded'] = false;
			return $slot;
		}

		if ( null !== $superseded ) {
			$superseded['superseded'] = true;
			return $superseded;
		}

		return null;
	}

	/**
	 * Whether two normalized starts describe the same slot.
	 *
	 * A date-only side carries no time evidence, so it never proves a move.
	 *
	 * @param string $start1 First start (Y-m-d or Y-m-d H:i).
	 * @param string $start2 Second start (Y-m-d or Y-m-d H:i).
	 * @return bool True when both share the date and time (or either lacks a time).
	 */
	private function isSameStart( string $start1, string $start2 ): bool {
		if ( ! preg_match( '/\d{2}:\d{2}/', $start1 ) || ! preg_match( '/\d{2}:\d{2}/', $start2 ) ) {
			return substr( $start1, 0, 10 ) === substr( $start2, 0, 10 );
		}

		return substr( $start1, 0, 16 ) === substr( $start2, 0, 16 );
	}

	/**
	 * Find the event post that owns one of a source listing's identifiers.
	 *
	 * @param array $identifiers     Source identifiers for the superseding listing.
	 * @param int   $exclude_post_id Post being evaluated.
	 * @return int Owning post ID, or 0 when the listing is not imported (yet).
	 */
	private function findIdentifierOwner( array $identifiers, int $exclude_post_id ): int {
		$identifiers = array_values( array_filter( array_map( 'strval', $identifiers ) ) );

		if ( empty( $identifiers ) ) {
			return 0;
		}

		$owners = get_posts(
			array(
				'post_type'        => Event_Post_Type::POST_TYPE,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'post__not_in'     => array( $exclude_post_id ),
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded review-time lookup.
				'meta_query'       => array(
					array(
						'key'     => self::SOURCE_ID_META_KEY,
						'value'   => $identifiers,
						'compare' => 'IN',
					),
				),
			)
		);

		return empty( $owners ) ? 0 : (int) $owners[0];
	}

	/**
	 * Whether two datetimes sit within the dedup time window.
	 *
	 * Only reached when the calendar dates differ, so both sides must carry
	 * a time component for a cross-date match; a date-only source slot
	 * matches only on its own calendar date.
	 *
	 * @param string $datetime1 First datetime (Y-m-d H:i).
	 * @param string $datetime2 Second datetime (Y-m-d H:i).
	 * @return bool True when both times exist and sit within the window.
	 */
	private function isWithinTimeWindow( string $datetime1, string $datetime2 ): bool {
		if ( ! preg_match( '/\d{2}:\d{2}/', $datetime1 ) || ! preg_match( '/\d{2}:\d{2}/', $datetime2 ) ) {
			return false;
		}

		$time1 = strtotime( $datetime1 );
		$time2 = strtotime( $datetime2 );

		if ( false === $time1 || false === $time2 ) {
			return true;
		}

		return abs( $time1 - $time2 ) <= self::MATCH_TIME_WINDOW_SECONDS;
	}

	/**
	 * Summarize the source titles listed on a date for review output.
	 *
	 * @param array $slots Source slots on one date.
	 * @return string Comma-joined titles, truncated for table display.
	 */
	private function summarizeSourceTitles( array $slots ): string {
		if ( empty( $slots ) ) {
			return '';
		}

		$titles = array_values(
			array_unique(
				array_filter( array_map( static fn( array $slot ): string => trim( $slot['title'] ), $slots ) )
			)
		);

		$summary = implode( ', ', $titles );

		return mb_substr( $summary, 0, 80 );
	}
}
