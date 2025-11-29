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
use DataMachineEvents\Steps\Upsert\Events\EventUpsertSettings;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventSchemaProvider;
use DataMachineEvents\Core\VenueParameterProvider;
use DataMachine\Core\WordPress\TaxonomyHandler;

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
            EventUpsertSettings::class,
            [self::class, 'registerAITools']
        );
    }

    /**
     * Register AI tool for event upsert
     *
     * @param array $tools Registered tools
     * @param string|null $handler_slug Handler slug
     * @param array $handler_config Handler configuration
     * @param array $engine_data Engine data snapshot for dynamic tool generation
     * @return array Modified tools array
     */
    public static function registerAITools($tools, $handler_slug = null, $handler_config = [], $engine_data = []) {
        // Only register tool when upsert_event handler is the target
        if ($handler_slug === 'upsert_event') {
            $tools['upsert_event'] = self::getDynamicEventTool($handler_config, $engine_data);
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
            'parameters' => EventSchemaProvider::getCoreToolParameters()
        ];
    }

    /**
     * Generate dynamic event tool based on taxonomy, venue settings, and engine data
     *
     * @param array $handler_config Handler configuration
     * @param array $engine_data Engine data snapshot
     * @return array Tool definition
     */
    private static function getDynamicEventTool(array $handler_config, array $engine_data = []): array {
        $ue_config = $handler_config['upsert_event'] ?? $handler_config;

        $tool = self::getBaseTool();

        // Add schema enrichment parameters (performer, organizer, status, etc.)
        $schema_params = EventSchemaProvider::getSchemaToolParameters();
        $tool['parameters'] = array_merge($tool['parameters'], $schema_params);

        // Add dynamic taxonomy parameters
        $taxonomy_params = TaxonomyHandler::getTaxonomyToolParameters($ue_config, Event_Post_Type::POST_TYPE);
        $tool['parameters'] = array_merge($tool['parameters'], $taxonomy_params);

        // Add dynamic venue parameters (excludes params that exist in engine data)
        $venue_params = VenueParameterProvider::getToolParameters($ue_config, $engine_data);
        $tool['parameters'] = array_merge($tool['parameters'], $venue_params);

        $tool['handler_config'] = $ue_config;

        return $tool;
    }
}

/**
 * Register Event Upsert handler filters
 */
function datamachine_register_event_upsert_filters() {
    EventUpsertFilters::register();
}

datamachine_register_event_upsert_filters();
