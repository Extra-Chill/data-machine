<?php
/**
 * Event Upsert Handler Registration
 *
 * Registers the Event Upsert handler with Data Machine.
 * Replaces Publisher with intelligent create-or-update logic.
 *
 * @package DataMachineEvents\Steps\Upsert\Events
 * @since   0.2.0
 */

namespace DataMachineEvents\Steps\Upsert\Events;

use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineEvents\Steps\Publish\Events\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Event Upsert handler registration and configuration
 */
class EventUpsertFilters {
    use HandlerRegistrationTrait;

    /**
     * Register Event Upsert handler with all required filters
     */
    public static function register(): void {
        self::registerHandler(
            'upsert_event',
            'update',
            EventUpsert::class,
            __('Upsert to Events Calendar', 'datamachine-events'),
            __('Create or update event posts with intelligent change detection', 'datamachine-events'),
            false,
            null,
            Settings::class, // Reuse Publisher settings
            [self::class, 'registerAITools']
        );
    }

    /**
     * Register AI tool for event upsert
     */
    public static function registerAITools($tools, $handler_slug = null, $handler_config = []) {
        // Only register tool when upsert_event handler is the target
        if ($handler_slug === 'upsert_event') {
            $tools['upsert_event'] = self::getDynamicEventTool($handler_config);
        }

        return $tools;
    }

    /**
     * Get base event upsert tool definition
     */
    private static function getBaseTool(): array {
        return [
            'class' => EventUpsert::class,
            'method' => 'handle_tool_call',
            'handler' => 'upsert_event',
            'description' => 'Create or update WordPress event post. Automatically finds existing events by title, venue, and date. Updates if data changed, skips if unchanged, creates if new.',
            'parameters' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Event title should be direct and descriptive (event name, venue, performer) but exclude dates',
                    'required' => true
                ],
                'startDate' => [
                    'type' => 'string',
                    'description' => 'Event start date (YYYY-MM-DD format)',
                    'required' => false
                ],
                'endDate' => [
                    'type' => 'string',
                    'description' => 'Event end date (YYYY-MM-DD format)',
                    'required' => false
                ],
                'startTime' => [
                    'type' => 'string',
                    'description' => 'Event start time (HH:MM format)',
                    'required' => false
                ],
                'endTime' => [
                    'type' => 'string',
                    'description' => 'Event end time (HH:MM format)',
                    'required' => false
                ],
                'price' => [
                    'type' => 'string',
                    'description' => 'Ticket price information',
                    'required' => false
                ],
                'ticketUrl' => [
                    'type' => 'string',
                    'description' => 'URL to purchase tickets',
                    'required' => false
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Generate an engaging, informative description for this event based on the provided details (venue, artist, dates, etc.). Make it compelling and informative for potential attendees.',
                    'required' => true
                ]
            ]
        ];
    }

    /**
     * Generate dynamic event tool based on taxonomy settings
     * (Reuses Publisher's dynamic tool logic)
     */
    private static function getDynamicEventTool(array $handler_config): array {
        // Extract config
        $ue_config = $handler_config['upsert_event'] ?? $handler_config;

        // Apply global defaults
        if (function_exists('apply_filters')) {
            $ue_config = apply_filters('datamachine_apply_global_defaults', $ue_config, 'upsert_event', 'update');
        }

        // Start with base tool
        $tool = self::getBaseTool();

        // Add dynamic schema parameters
        $schema_params = self::getDynamicSchemaParameters();
        $tool['parameters'] = array_merge($tool['parameters'], $schema_params);

        // Store resolved configuration
        $tool['handler_config'] = $ue_config;

        // Add venue parameters if no static venue data
        $has_static_venue = self::checkStaticVenueAvailability($ue_config);

        if (!$has_static_venue) {
            $tool['parameters']['venue'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'Venue name where the event takes place'
            ];
            $tool['parameters']['venueAddress'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'Street address of the venue'
            ];
            $tool['parameters']['venueCity'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'City where the venue is located'
            ];
            $tool['parameters']['venueState'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'State/province where the venue is located'
            ];
            $tool['parameters']['venueZip'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'Postal/zip code of the venue'
            ];
            $tool['parameters']['venueCountry'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'Country where the venue is located'
            ];
            $tool['parameters']['venuePhone'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'Phone number of the venue'
            ];
            $tool['parameters']['venueWebsite'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'Website URL of the venue'
            ];
            $tool['parameters']['venueCoordinates'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'GPS coordinates of the venue (latitude,longitude format)'
            ];
        }

        return $tool;
    }

    /**
     * Generate dynamic schema parameters
     */
    private static function getDynamicSchemaParameters(): array {
        return [
            'performerType' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Determine performer type: Person for solo artists, PerformingGroup for bands/groups',
                'enum' => ['Person', 'PerformingGroup', 'MusicGroup']
            ],
            'organizerName' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Event organizer name if available'
            ],
            'organizerType' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Organizer type: Person or Organization',
                'enum' => ['Person', 'Organization']
            ],
            'priceCurrency' => [
                'type' => 'string',
                'required' => false,
                'description' => 'ISO 4217 currency code (USD, EUR, GBP, etc.)'
            ],
            'eventStatus' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Event status - EventScheduled for normal events',
                'enum' => ['EventScheduled', 'EventPostponed', 'EventCancelled', 'EventRescheduled']
            ],
            'offerAvailability' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Ticket availability: InStock, SoldOut, or PreOrder',
                'enum' => ['InStock', 'SoldOut', 'PreOrder']
            ]
        ];
    }

    /**
     * Check if static venue data will be available
     */
    private static function checkStaticVenueAvailability(array $ue_config): bool {
        // Check for Web Scraper static venue configuration
        if (isset($ue_config['universal_web_scraper']['venue']) && !empty($ue_config['universal_web_scraper']['venue'])) {
            return true;
        }

        return false;
    }
}

/**
 * Register Event Upsert handler filters
 */
function datamachine_register_event_upsert_filters() {
    EventUpsertFilters::register();
}

datamachine_register_event_upsert_filters();
