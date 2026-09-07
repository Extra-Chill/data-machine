<?php
/**
 * Single Recurring Event Handler
 *
 * Creates events for weekly recurring occurrences (open mics, trivia nights, etc.).
 * Each flow execution generates one event for the next upcoming occurrence of the
 * configured day of week. Supports expiration dates for seasonal or time-limited events.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\SingleRecurring
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\SingleRecurring;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\Handlers\VenueFieldsTrait;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SingleRecurring extends EventImportHandler {

	use HandlerRegistrationTrait;
	use VenueFieldsTrait;

	private const DAY_NAMES = array(
		0 => 'Sunday',
		1 => 'Monday',
		2 => 'Tuesday',
		3 => 'Wednesday',
		4 => 'Thursday',
		5 => 'Friday',
		6 => 'Saturday',
	);

	public function __construct() {
		parent::__construct( 'single_recurring' );

		self::registerHandler(
			'single_recurring',
			'event_import',
			self::class,
			__( 'Single Recurring Event', 'data-machine-events' ),
			__( 'Create events for weekly recurring occurrences like open mics, trivia nights, etc.', 'data-machine-events' ),
			false,
			null,
			SingleRecurringSettings::class,
			null
		);
	}

	protected function getSourceInventoryCapabilities(): array {
		return array(
			'can_enumerate'    => true,
			'stable_ids'       => true,
			'has_total_count'  => true,
			'inventory_source' => 'handler_config',
		);
	}

	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$context->log( 'info', 'SingleRecurring: Starting event handler' );

		$event_title = $config['event_title'] ?? '';
		if ( empty( $event_title ) ) {
			$context->log( 'error', 'SingleRecurring: Event title not configured' );
			return array();
		}

		if ( $this->shouldSkipEventTitle( $event_title ) ) {
			return array();
		}

		if ( ! $this->applyKeywordSearch( $event_title, $config['search'] ?? '' ) ) {
			return array();
		}

		if ( $this->applyExcludeKeywords( $event_title, $config['exclude_keywords'] ?? '' ) ) {
			return array();
		}

		$expiration_date = $config['expiration_date'] ?? '';
		if ( ! empty( $expiration_date ) && strtotime( $expiration_date ) < strtotime( 'today' ) ) {
			$context->log(
				'info',
				'SingleRecurring: Event handler expired',
				array(
					'event_title'     => $event_title,
					'expiration_date' => $expiration_date,
				)
			);
			return array();
		}

		$day_of_week = (int) ( $config['day_of_week'] ?? 0 );
		if ( $day_of_week < 0 || $day_of_week > 6 ) {
			$context->log( 'error', 'SingleRecurring: Invalid day of week configured', array( 'day_of_week' => $day_of_week ) );
			return array();
		}

		$next_occurrence = $this->calculateNextOccurrence( $day_of_week, (string) ( $config['start_time'] ?? '' ) );
		$next_date       = $next_occurrence->format( 'Y-m-d' );

		$standardized_event = $this->buildEventData( $config, $next_date );
		$venue_name         = $config['venue_name'] ?? '';
		$source_identity    = \DataMachineEvents\Utilities\EventSourceIdentity::resolve( $standardized_event, $context );
		$event_identifier   = $source_identity['event_identifier'];

		$context->log(
			'info',
			'SingleRecurring: Created event',
			array(
				'title' => $event_title,
				'date'  => $next_date,
				'day'   => self::DAY_NAMES[ $day_of_week ],
				'venue' => $venue_name,
			)
		);

		$venue_metadata = $this->extractVenueMetadata( $standardized_event );
		$engine_data    = $this->buildEventEngineData( $standardized_event, $venue_metadata );
		$this->stripVenueMetadataFromEvent( $standardized_event );

		return array(
			'title'    => $standardized_event['title'],
			'content'  => wp_json_encode(
				array(
					'event'          => $standardized_event,
					'venue_metadata' => $venue_metadata,
					'import_source'  => 'single_recurring',
				),
				JSON_PRETTY_PRINT
			),
			'metadata' => array(
				'source_type'      => 'single_recurring',
				'pipeline_id'      => $context->getPipelineId(),
				'flow_id'          => $context->getFlowId(),
				'original_title'   => $event_title,
				'event_identifier' => $event_identifier,
				'item_identifier'  => $source_identity['item_identifier'],
				'import_timestamp' => time(),
				'_engine_data'     => $engine_data,
			),
		);
	}

	/**
	 * Calculate the next occurrence of a given day of week
	 *
	 * Today counts as the next occurrence when it is the configured day and
	 * the configured start time has not yet passed. An empty start time is
	 * treated as end of day, so today still counts.
	 *
	 * @param int                     $target_day Day of week (0=Sunday, 6=Saturday)
	 * @param string                  $start_time Configured start time (HH:MM). Empty means end of day.
	 * @param \DateTimeInterface|null $now        Optional current time for testing. Defaults to wp_timezone() now.
	 * @return \DateTime Next occurrence date
	 */
	protected function calculateNextOccurrence( int $target_day, string $start_time = '', ?\DateTimeInterface $now = null ): \DateTime {
		$today       = null === $now
			? new \DateTime( 'today', wp_timezone() )
			: \DateTime::createFromInterface( $now )->setTime( 0, 0, 0 );
		$current_day = (int) $today->format( 'w' );

		$days_until = $target_day - $current_day;
		if ( $days_until < 0 ) {
			$days_until += 7;
		} elseif ( 0 === $days_until && '' !== $start_time ) {
			// Today is the configured day: roll to next week only once today's
			// start time has already passed.
			$now_time    = null === $now ? new \DateTime( 'now', wp_timezone() ) : \DateTime::createFromInterface( $now );
			$start_today = strtotime( $today->format( 'Y-m-d' ) . ' ' . $start_time, $now_time->getTimestamp() );
			if ( false !== $start_today && $now_time->getTimestamp() >= $start_today ) {
				$days_until += 7;
			}
		}

		$next = clone $today;
		$next->modify( "+{$days_until} days" );

		return $next;
	}

	/**
	 * Build standardized event data from handler config
	 *
	 * Venue fields are mapped through VenueFieldsTrait so phone, website,
	 * ticketing, and capacity config reach the upsert step. An end time
	 * earlier than the start time is an after-midnight end: the event ends
	 * on the following calendar day.
	 *
	 * @param array $config Handler configuration
	 * @param string $event_date Event date (Y-m-d)
	 * @return array Standardized event data
	 */
	protected function buildEventData( array $config, string $event_date ): array {
		$start_time = sanitize_text_field( $config['start_time'] ?? '' );
		$end_time   = sanitize_text_field( $config['end_time'] ?? '' );

		$end_date = $event_date;
		if ( '' !== $end_time && '' !== $start_time && $end_time < $start_time ) {
			$overnight = new \DateTime( $event_date, wp_timezone() );
			$overnight->modify( '+1 day' );
			$end_date = $overnight->format( 'Y-m-d' );
		}

		return array_merge(
			array(
				'title'       => sanitize_text_field( $config['event_title'] ?? '' ),
				'description' => sanitize_textarea_field( $config['event_description'] ?? '' ),
				'startDate'   => $event_date,
				'endDate'     => $end_date,
				'startTime'   => $start_time,
				'endTime'     => $end_time,
			),
			self::map_venue_config_to_event_data( $config ),
			array(
				'ticketUrl'  => esc_url_raw( $config['ticket_url'] ?? '' ),
				'image'      => '',
				'price'      => sanitize_text_field( $config['price'] ?? '' ),
				'performer'  => '',
				'organizer'  => '',
				'source_url' => '',
			)
		);
	}
}
