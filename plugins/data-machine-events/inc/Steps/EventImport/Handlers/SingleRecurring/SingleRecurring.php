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
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

class SingleRecurring extends EventImportHandler {

    use HandlerRegistrationTrait;

    private const DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    public function __construct() {
        parent::__construct('single_recurring');

        self::registerHandler(
            'single_recurring',
            'event_import',
            self::class,
            __('Single Recurring Event', 'datamachine-events'),
            __('Create events for weekly recurring occurrences like open mics, trivia nights, etc.', 'datamachine-events'),
            false,
            null,
            SingleRecurringSettings::class,
            null
        );
    }

    protected function executeFetch(array $config, ExecutionContext $context): array {
        $context->log('info', 'SingleRecurring: Starting event handler');

         $event_title = $config['event_title'] ?? '';
         if (empty($event_title)) {
             $context->log('error', 'SingleRecurring: Event title not configured');
             return [];
         }

         if ($this->shouldSkipEventTitle($event_title)) {
             return [];
         }

         if (!$this->applyKeywordSearch($event_title, $config['search'] ?? '')) {
             return [];
         }

         if ($this->applyExcludeKeywords($event_title, $config['exclude_keywords'] ?? '')) {
             return [];
         }


        $expiration_date = $config['expiration_date'] ?? '';
        if (!empty($expiration_date) && strtotime($expiration_date) < strtotime('today')) {
            $context->log('info', 'SingleRecurring: Event handler expired', [
                'event_title' => $event_title,
                'expiration_date' => $expiration_date
            ]);
            return [];
        }

        $day_of_week = (int) ($config['day_of_week'] ?? 0);
        if ($day_of_week < 0 || $day_of_week > 6) {
            $context->log('error', 'SingleRecurring: Invalid day of week configured', ['day_of_week' => $day_of_week]);
            return [];
        }

        $next_occurrence = $this->calculateNextOccurrence($day_of_week);
        $next_date = $next_occurrence->format('Y-m-d');

        $venue_name = $config['venue_name'] ?? '';
        $event_identifier = EventIdentifierGenerator::generate($event_title, $next_date, $venue_name);

        if ($this->checkItemProcessed($context, $event_identifier)) {
            $context->log('info', 'SingleRecurring: Event occurrence already processed', [
                'event_title' => $event_title,
                'date' => $next_date,
                'venue' => $venue_name
            ]);
            return [];
        }

        $standardized_event = $this->buildEventData($config, $next_date);

        $this->markItemAsProcessed($context, $event_identifier);

        $context->log('info', 'SingleRecurring: Created event', [
            'title' => $event_title,
            'date' => $next_date,
            'day' => self::DAY_NAMES[$day_of_week],
            'venue' => $venue_name
        ]);

        $venue_metadata = $this->extractVenueMetadata($standardized_event);
        $job_id = $context->getJobId();
        EventEngineData::storeVenueContext($job_id, $standardized_event, $venue_metadata);
        $this->stripVenueMetadataFromEvent($standardized_event);

        $dataPacket = new DataPacket(
            [
                'title' => $standardized_event['title'],
                'body' => wp_json_encode([
                    'event' => $standardized_event,
                    'venue_metadata' => $venue_metadata,
                    'import_source' => 'single_recurring'
                ], JSON_PRETTY_PRINT)
            ],
            [
                'source_type' => 'single_recurring',
                'pipeline_id' => $context->getPipelineId(),
                'flow_id' => $context->getFlowId(),
                'original_title' => $event_title,
                'event_identifier' => $event_identifier,
                'import_timestamp' => time()
            ],
            'event_import'
        );

        return [$dataPacket];
    }

    /**
     * Calculate the next occurrence of a given day of week
     *
     * @param int $target_day Day of week (0=Sunday, 6=Saturday)
     * @return \DateTime Next occurrence date
     */
    private function calculateNextOccurrence(int $target_day): \DateTime {
        $today = new \DateTime('today', wp_timezone());
        $current_day = (int) $today->format('w');

        $days_until = $target_day - $current_day;
        if ($days_until <= 0) {
            $days_until += 7;
        }

        $next = clone $today;
        $next->modify("+{$days_until} days");

        return $next;
    }

    /**
     * Build standardized event data from handler config
     *
     * @param array $config Handler configuration
     * @param string $event_date Event date (Y-m-d)
     * @return array Standardized event data
     */
    private function buildEventData(array $config, string $event_date): array {
        return [
            'title' => sanitize_text_field($config['event_title'] ?? ''),
            'description' => sanitize_textarea_field($config['event_description'] ?? ''),
            'startDate' => $event_date,
            'endDate' => $event_date,
            'startTime' => sanitize_text_field($config['start_time'] ?? ''),
            'endTime' => sanitize_text_field($config['end_time'] ?? ''),
            'venue' => sanitize_text_field($config['venue_name'] ?? ''),
            'venueAddress' => sanitize_text_field($config['venue_address'] ?? ''),
            'venueCity' => sanitize_text_field($config['venue_city'] ?? ''),
            'venueState' => sanitize_text_field($config['venue_state'] ?? ''),
            'venueZip' => sanitize_text_field($config['venue_zip'] ?? ''),
            'venueCountry' => sanitize_text_field($config['venue_country'] ?? ''),
            'ticketUrl' => esc_url_raw($config['ticket_url'] ?? ''),
            'image' => '',
            'price' => sanitize_text_field($config['price'] ?? ''),
            'performer' => '',
            'organizer' => '',
            'source_url' => ''
        ];
    }
}
