<?php
/**
 * Ticketmaster Discovery API integration with batch processing
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch processing with Discovery API v2 integration.
 *
 * Returns all eligible events as DataPackets. The pipeline engine
 * fans each one out into its own child job automatically.
 */
class Ticketmaster extends EventImportHandler {

	use HandlerRegistrationTrait;

	const API_BASE = 'https://app.ticketmaster.com/discovery/v2/';

	const DEFAULT_PARAMS = array(
		'size' => 50,
		'sort' => 'date,asc',
		'page' => 0,
	);

	const MAX_PAGE = 19;

	/**
	 * Maximum number of retry attempts when the Discovery API answers HTTP 429
	 * (spike-arrest). Ticketmaster's spike-arrest window is short (rate is
	 * expressed per-second/per-minute), so a few bounded retries reliably clear
	 * it without hard-failing the job or losing import volume.
	 */
	const RATE_LIMIT_MAX_RETRIES = 3;

	/**
	 * Base back-off in seconds for the first 429 retry. Subsequent retries grow
	 * exponentially (base, base*2, base*4, ...) with added jitter so that the
	 * many per-city geo-radius pipelines that tripped the limit together do not
	 * all wake up and re-fire on the same instant.
	 */
	const RATE_LIMIT_BACKOFF_BASE_SECONDS = 1;

	/**
	 * Upper bound on a single back-off sleep, in seconds. Keeps a job from
	 * parking on a sleep() longer than is useful inside one fetch run.
	 */
	const RATE_LIMIT_BACKOFF_MAX_SECONDS = 8;

	public function __construct() {
		parent::__construct( 'ticketmaster' );

		self::registerHandler(
			'ticketmaster',
			'event_import',
			self::class,
			__( 'Ticketmaster Events', 'data-machine-events' ),
			__( 'Import events from Ticketmaster Discovery API with venue data', 'data-machine-events' ),
			true,
			TicketmasterAuth::class,
			TicketmasterSettings::class,
			null
		);
	}

	protected function getSourceInventoryCapabilities(): array {
		return array(
			'stable_ids'            => true,
			'has_total_count'       => true,
			'supports_time_windows' => true,
			'supports_query_shards' => true,
			'pagination'            => 'page',
			'max_pages'             => self::MAX_PAGE + 1,
		);
	}

	/**
	 * Execute fetch logic
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$context->log( 'info', 'Ticketmaster: Starting event import' );

		$auth = $this->getAuthProvider( 'ticketmaster' );
		if ( ! $auth ) {
			$context->log( 'error', 'Ticketmaster: Authentication provider not found' );
			return array();
		}

		$api_config = $auth->get_account();
		if ( empty( $api_config['api_key'] ) ) {
			$context->log( 'error', 'Ticketmaster: API key not configured' );
			return array();
		}

		try {
			$search_params = $this->build_search_params( $config, $api_config['api_key'], $context );
		} catch ( \Exception $e ) {
			$context->log( 'error', 'Ticketmaster: ' . $e->getMessage() );
			return array();
		}

		$current_page   = 0;
		$has_more_pages = false;
		$eligible_items = array();

		do {
			$search_params['page'] = $current_page;
			$result                = $this->fetch_events( $search_params, $context );
			$raw_events            = $result['events'];
			$page_info             = $result['page'];

			if ( empty( $raw_events ) ) {
				if ( 0 === $current_page ) {
					$context->log( 'info', 'Ticketmaster: No events found from API' );
				}
				break;
			}

			$context->log(
				'info',
				'Ticketmaster: Processing events',
				array(
					'page'           => $current_page,
					'events_on_page' => count( $raw_events ),
					'total_pages'    => $page_info['totalPages'],
				)
			);

			foreach ( $raw_events as $raw_event ) {
				$event_status = $raw_event['dates']['status']['code'] ?? '';
				if ( 'onsale' !== $event_status ) {
					continue;
				}

				$standardized_event = $this->map_ticketmaster_event( $raw_event );

				if ( empty( $standardized_event['title'] ) ) {
					continue;
				}

				if ( $this->shouldSkipEventTitle( $standardized_event['title'] ) ) {
					continue;
				}

				$search_text = $standardized_event['title'] . ' ' . ( $standardized_event['description'] ?? '' );

				if ( ! $this->applyKeywordSearch( $search_text, $config['search'] ?? '' ) ) {
					continue;
				}

				if ( $this->applyExcludeKeywords( $search_text, $config['exclude_keywords'] ?? '' ) ) {
					continue;
				}

				$event_identifier = \DataMachineEvents\Utilities\EventIdentifierGenerator::generate(
					$standardized_event['title'],
					$standardized_event['startDate'] ?? '',
					$standardized_event['venue'] ?? ''
				);

				$context->log(
					'info',
					'Ticketmaster: Found eligible event',
					array(
						'title' => $standardized_event['title'],
						'date'  => $standardized_event['startDate'],
						'venue' => $standardized_event['venue'],
						'page'  => $current_page,
					)
				);

				$venue_metadata = $this->extractVenueMetadata( $standardized_event );
				$engine_data    = $this->buildEventEngineData( $standardized_event, $venue_metadata );
				$this->stripVenueMetadataFromEvent( $standardized_event );

				$eligible_items[] = array(
					'title'    => $standardized_event['title'],
					'content'  => wp_json_encode(
						array(
							'event'          => $standardized_event,
							'venue_metadata' => $venue_metadata,
							'import_source'  => 'ticketmaster',
						),
						JSON_PRETTY_PRINT
					),
					'metadata' => array(
						'source_type'      => 'ticketmaster',
						'pipeline_id'      => $context->getPipelineId(),
						'flow_id'          => $context->getFlowId(),
						'original_title'   => $standardized_event['title'],
						'event_identifier' => $event_identifier,
						'item_identifier'  => $event_identifier,
						'import_timestamp' => time(),
						'_engine_data'     => $engine_data,
					),
				);
			}

			$has_more_pages = $page_info['number'] < ( $page_info['totalPages'] - 1 )
								&& $current_page < self::MAX_PAGE;

			++$current_page;

		} while ( $has_more_pages );

		if ( empty( $eligible_items ) ) {
			$context->log(
				'info',
				'Ticketmaster: No eligible events found',
				array( 'pages_searched' => $current_page )
			);
			return array();
		}

		$context->log(
			'info',
			sprintf( 'Ticketmaster: Found %d eligible events', count( $eligible_items ) ),
			array( 'pages_searched' => $current_page )
		);

		return array( 'items' => $eligible_items );
	}

	/**
	 * Build search parameters for API request
	 */
	private function build_search_params( array $handler_config, string $api_key, ExecutionContext $context ): array {
		$params = array_merge(
			self::DEFAULT_PARAMS,
			array(
				'apikey' => $api_key,
			)
		);

		if ( empty( $handler_config['classification_type'] ) ) {
			throw new \Exception( 'Ticketmaster handler requires classification_type setting. Job failed.' );
		}

		$classifications     = self::get_classifications( $api_key );
		$classification_slug = strtolower( $handler_config['classification_type'] );

		if ( ! isset( $classifications[ $classification_slug ] ) ) {
			throw new \Exception( 'Invalid Ticketmaster classification_type: ' . esc_html( $classification_slug ) );
		}

		$params['segmentName'] = $classifications[ $classification_slug ];

		$context->log(
			'info',
			'Ticketmaster: Added segment filter',
			array(
				'slug'         => $classification_slug,
				'segment_name' => $classifications[ $classification_slug ],
			)
		);

		$location    = $handler_config['location'] ?? '32.7765,-79.9311'; // Charleston, SC
		$coordinates = $this->parseCoordinates( $location );
		if ( $coordinates ) {
			$params['geoPoint'] = $coordinates['lat'] . ',' . $coordinates['lng'];
			$radius             = ! empty( $handler_config['radius'] ) ? $handler_config['radius'] : '50';
			$params['radius']   = $radius;
			$params['unit']     = 'miles';
		}

		$page           = ! empty( $handler_config['page'] ) ? intval( $handler_config['page'] ) : 0;
		$params['page'] = $page;

		$params['startDateTime'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+1 hour' ) );

		if ( ! empty( $handler_config['genre'] ) ) {
			$params['genreId'] = $handler_config['genre'];
		}

		if ( ! empty( $handler_config['venue_id'] ) ) {
			$params['venueId'] = $handler_config['venue_id'];
		}

		return $params;
	}

	/**
	 * Get event type classifications with 24-hour caching
	 */
	public static function get_classifications( $api_key = '' ) {
		$cache_key              = 'data_machine_events_ticketmaster_classifications';
		$cached_classifications = get_transient( $cache_key );

		if ( false !== $cached_classifications ) {
			return $cached_classifications;
		}

		if ( empty( $api_key ) ) {
			// We can't easily access the auth provider statically here without dependency injection or a service locator.
			// However, since we are moving away from global filters, we should rely on the caller passing the key.
			// If no key is passed, we can try to instantiate the auth class directly as a fallback,
			// or just return fallback classifications.
			// Given the architecture, instantiating the auth class is safe since it's a simple provider.
			$auth       = new TicketmasterAuth();
			$api_config = $auth->get_account();
			$api_key    = $api_config['api_key'] ?? '';
		}

		if ( empty( $api_key ) ) {
			return self::get_fallback_classifications();
		}

		$api_url = 'https://app.ticketmaster.com/discovery/v2/classifications.json?apikey=' . rawurlencode( $api_key );
		$result  = \DataMachine\Core\HttpClient::get(
			$api_url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/json',
				),
				'context' => 'Ticketmaster Classifications',
			)
		);

		if ( ! $result['success'] || 200 !== $result['status_code'] ) {
			return self::get_fallback_classifications();
		}

		$body = $result['data'];
		$data = json_decode( $body, true );

		if ( ! $data || ! isset( $data['_embedded']['classifications'] ) ) {
			return self::get_fallback_classifications();
		}

		$classifications = self::parse_classifications_response( $data );
		set_transient( $cache_key, $classifications, 24 * HOUR_IN_SECONDS );

		return $classifications;
	}

	private static function parse_classifications_response( $api_data ) {
		$classifications = array();
		$seen_segments   = array();

		foreach ( $api_data['_embedded']['classifications'] as $classification ) {
			if ( isset( $classification['segment'] ) ) {
				$segment      = $classification['segment'];
				$segment_name = $segment['name'] ?? '';

				if ( ! empty( $segment_name ) && ! isset( $seen_segments[ $segment_name ] ) ) {
					$slug = sanitize_key( strtolower( $segment_name ) );
					$slug = str_replace( '_', '-', $slug );

					$classifications[ $slug ]       = $segment_name;
					$seen_segments[ $segment_name ] = true;
				}
			}
		}

		return $classifications;
	}

	private static function get_fallback_classifications() {
		return array(
			'music'        => __( 'Music', 'data-machine-events' ),
			'sports'       => __( 'Sports', 'data-machine-events' ),
			'arts-theatre' => __( 'Arts & Theatre', 'data-machine-events' ),
			'film'         => __( 'Film', 'data-machine-events' ),
			'family'       => __( 'Family', 'data-machine-events' ),
		);
	}

	public static function get_classifications_for_dropdown( $current_config = array() ) {
		$auth       = new TicketmasterAuth();
		$api_config = $auth->get_account();
		$api_key    = $api_config['api_key'] ?? '';
		return self::get_classifications( $api_key );
	}

	private function fetch_events( array $params, ExecutionContext $context ): array {
		$url = self::API_BASE . 'events.json?' . http_build_query( $params );

		$result = $this->request_events_page( $url, $context );

		if ( ! $result['success'] ) {
			// A 429 that survived every retry is a transient throttle, not a
			// Data-Machine-side fault — log it at warning so it stops flooding
			// the error log. Any other failure stays an error.
			$severity = $this->is_rate_limited( $result ) ? 'warning' : 'error';
			$context->log(
				$severity,
				'Ticketmaster: API request failed',
				array( 'error' => $result['error'] ?? 'Unknown error' )
			);
			return array(
				'events' => array(),
				'page'   => array(
					'number'     => 0,
					'totalPages' => 1,
				),
			);
		}

		$body = $result['data'];
		$data = json_decode( $body, true );

		return array(
			'events' => $data['_embedded']['events'] ?? array(),
			'page'   => $data['page'] ?? array(
				'number'     => 0,
				'totalPages' => 1,
			),
		);
	}

	/**
	 * GET an events page, retrying through Ticketmaster spike-arrest (HTTP 429).
	 *
	 * Multiple per-city geo-radius pipelines drain on the same heartbeat cron
	 * tick and collectively blow Ticketmaster's spike-arrest limit (5 messages
	 * per period). The window is short, so instead of hard-failing the job we
	 * back off and retry in-process. Events still import — just a beat later —
	 * so import volume is preserved and the error log stays quiet.
	 *
	 * The back-off respects the API's `Retry-After` header when present (and
	 * when the underlying client surfaces it), otherwise it grows exponentially
	 * with jitter to de-synchronise the pipelines that tripped the limit
	 * together.
	 *
	 * @param string           $url     Fully built Discovery API events URL.
	 * @param ExecutionContext $context Execution context for logging.
	 * @return array HttpClient-shaped result (success/data/error/...).
	 */
	private function request_events_page( string $url, ExecutionContext $context ): array {
		$attempt = 0;

		do {
			$result = $this->httpGet(
				$url,
				array(
					'timeout' => 30,
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			if ( $result['success'] || ! $this->is_rate_limited( $result ) ) {
				return $result;
			}

			if ( $attempt >= self::RATE_LIMIT_MAX_RETRIES ) {
				return $result;
			}

			$delay = $this->rate_limit_backoff_seconds( $result, $attempt );

			$context->log(
				'warning',
				'Ticketmaster: spike-arrest (HTTP 429), backing off before retry',
				array(
					'attempt'       => $attempt + 1,
					'max_retries'   => self::RATE_LIMIT_MAX_RETRIES,
					'delay_seconds' => $delay,
				)
			);

			if ( $delay > 0 ) {
				sleep( $delay );
			}

			++$attempt;
		} while ( $attempt <= self::RATE_LIMIT_MAX_RETRIES );

		return $result;
	}

	/**
	 * Whether an HttpClient result represents a Ticketmaster spike-arrest (429).
	 *
	 * HttpClient collapses non-2xx responses to `{ success: false, error }` and
	 * does not surface the numeric status code on the failure path, so the 429
	 * is detected from the error message it builds
	 * (`"<context> GET returned HTTP 429: ..."`).
	 *
	 * @param array $result HttpClient-shaped result.
	 * @return bool
	 */
	private function is_rate_limited( array $result ): bool {
		if ( ! empty( $result['success'] ) ) {
			return false;
		}

		if ( isset( $result['status_code'] ) && 429 === (int) $result['status_code'] ) {
			return true;
		}

		$error = (string) ( $result['error'] ?? '' );

		return false !== stripos( $error, 'HTTP 429' );
	}

	/**
	 * Compute the back-off delay (seconds) before the next 429 retry.
	 *
	 * Prefers the server's `Retry-After` hint when the client surfaces response
	 * headers; otherwise falls back to exponential growth with jitter, clamped
	 * to a sane upper bound.
	 *
	 * @param array $result  HttpClient-shaped result for the throttled request.
	 * @param int   $attempt Zero-based retry attempt index.
	 * @return int Delay in whole seconds (>= 0).
	 */
	private function rate_limit_backoff_seconds( array $result, int $attempt ): int {
		$retry_after = $this->retry_after_seconds( $result );
		if ( null !== $retry_after ) {
			return min( max( $retry_after, 0 ), self::RATE_LIMIT_BACKOFF_MAX_SECONDS );
		}

		$base   = self::RATE_LIMIT_BACKOFF_BASE_SECONDS * ( 2 ** $attempt );
		$base   = min( $base, self::RATE_LIMIT_BACKOFF_MAX_SECONDS );
		$jitter = wp_rand( 0, 1 );

		return min( $base + $jitter, self::RATE_LIMIT_BACKOFF_MAX_SECONDS );
	}

	/**
	 * Extract a `Retry-After` delay in seconds from a result's response headers.
	 *
	 * Supports both delta-seconds (`Retry-After: 5`) and HTTP-date forms. When
	 * the client does not surface headers on the failure path (the common case
	 * today) this returns null and the caller falls back to exponential backoff.
	 *
	 * @param array $result HttpClient-shaped result.
	 * @return int|null Seconds to wait, or null when unavailable.
	 */
	private function retry_after_seconds( array $result ): ?int {
		$headers = $result['headers'] ?? null;

		$value = null;
		if ( is_array( $headers ) ) {
			foreach ( $headers as $name => $header_value ) {
				if ( 0 === strcasecmp( (string) $name, 'Retry-After' ) ) {
					$value = is_array( $header_value ) ? reset( $header_value ) : $header_value;
					break;
				}
			}
		} elseif ( is_object( $headers ) && method_exists( $headers, 'offsetGet' ) && method_exists( $headers, 'offsetExists' ) ) {
			// wp_remote_retrieve_headers() returns a Requests case-insensitive
			// dictionary implementing ArrayAccess.
			if ( $headers->offsetExists( 'retry-after' ) ) {
				$value = $headers->offsetGet( 'retry-after' );
			}
		}

		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = trim( (string) $value );

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return null;
		}

		return max( $timestamp - time(), 0 );
	}

	private function map_ticketmaster_event( array $tm_event ): array {
		$title       = $tm_event['name'] ?? '';
		$description = $tm_event['info'] ?? $tm_event['pleaseNote'] ?? '';

		$start_parsed = $this->parseDateTimeLocal(
			$tm_event['dates']['start']['localDate'] ?? '',
			$tm_event['dates']['start']['localTime'] ?? '',
			$tm_event['_embedded']['venues'][0]['timezone'] ?? ''
		);

		$venue_name        = '';
		$venue_address     = '';
		$venue_city        = '';
		$venue_state       = '';
		$venue_zip         = '';
		$venue_country     = '';
		$venue_phone       = '';
		$venue_website     = '';
		$venue_coordinates = '';
		$venue_timezone    = '';

		if ( ! empty( $tm_event['_embedded']['venues'][0] ) ) {
			$venue          = $tm_event['_embedded']['venues'][0];
			$venue_name     = $venue['name'] ?? '';
			$venue_timezone = $venue['timezone'] ?? '';

			if ( ! empty( $venue['address'] ) ) {
				if ( ! empty( $venue['address']['line1'] ) ) {
					$venue_address = $venue['address']['line1'];
				}
				if ( ! empty( $venue['address']['line2'] ) ) {
					$venue_address .= ( ! empty( $venue_address ) ? ', ' : '' ) . $venue['address']['line2'];
				}
				if ( ! empty( $venue['address']['line3'] ) ) {
					$venue_address .= ( ! empty( $venue_address ) ? ', ' : '' ) . $venue['address']['line3'];
				}
			}

			$venue_city    = $venue['city']['name'] ?? '';
			$venue_state   = $venue['state']['stateCode'] ?? '';
			$venue_zip     = $venue['postalCode'] ?? '';
			$venue_country = $venue['country']['countryCode'] ?? '';
			$venue_phone   = $venue['boxOfficeInfo']['phoneNumberDetail'] ?? '';
			$venue_website = '';

			if ( ! empty( $venue['location']['latitude'] ) && ! empty( $venue['location']['longitude'] ) ) {
				$venue_coordinates = $venue['location']['latitude'] . ',' . $venue['location']['longitude'];
			}
		}

		$artist = $tm_event['_embedded']['attractions'][0]['name'] ?? '';

		$organizer = '';
		if ( ! empty( $tm_event['promoter']['name'] ) ) {
			$organizer = $tm_event['promoter']['name'];
		} elseif ( ! empty( $tm_event['promoters'][0]['name'] ) ) {
			$organizer = $tm_event['promoters'][0]['name'];
		}

		$price = '';
		if ( ! empty( $tm_event['priceRanges'][0] ) ) {
			$price_range = $tm_event['priceRanges'][0];
			$price       = $this->formatPriceRange(
				$price_range['min'] ?? null,
				$price_range['max'] ?? null
			);
			do_action(
				'datamachine_log',
				'debug',
				'Ticketmaster price extracted',
				array(
					'title'     => $title,
					'price'     => $price,
					'price_min' => $price_range['min'] ?? null,
					'price_max' => $price_range['max'] ?? null,
				)
			);
		} else {
			do_action(
				'datamachine_log',
				'debug',
				'Ticketmaster event has no priceRanges',
				array( 'title' => $title )
			);
		}

		$ticket_url = $tm_event['url'] ?? '';

		return array(
			'title'            => $this->sanitizeText( $title ),
			'startDate'        => $start_parsed['date'],
			'endDate'          => '',
			'startTime'        => $start_parsed['time'],
			'endTime'          => '',
			'venue'            => $this->sanitizeText( $venue_name ),
			'artist'           => $this->sanitizeText( $artist ),
			'organizer'        => $this->sanitizeText( $organizer ),
			'price'            => $this->sanitizeText( $price ),
			'ticketUrl'        => $this->sanitizeUrl( $ticket_url ),
			'description'      => $this->cleanHtml( $description ),
			'venueAddress'     => $this->sanitizeText( $venue_address ),
			'venueCity'        => $this->sanitizeText( $venue_city ),
			'venueState'       => $this->sanitizeText( $venue_state ),
			'venueZip'         => $this->sanitizeText( $venue_zip ),
			'venueCountry'     => $this->sanitizeText( $venue_country ),
			'venuePhone'       => $this->sanitizeText( $venue_phone ),
			'venueWebsite'     => $this->sanitizeUrl( $venue_website ),
			'venueCoordinates' => $this->sanitizeText( $venue_coordinates ),
			'venueTimezone'    => $this->sanitizeText( $venue_timezone ),
		);
	}
}
