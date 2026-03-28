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

if ( ! defined( 'ABSPATH' ) ) {
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
			__( 'Upsert to Events Calendar', 'data-machine-events' ),
			__( 'Create or update event posts with intelligent change detection', 'data-machine-events' ),
			false,
			null,
			EventUpsertSettings::class,
			array( self::class, 'registerAITools' )
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
	public static function registerAITools( $tools, $handler_slug = null, $handler_config = array(), $engine_data = array() ) {
		// Only register tool when upsert_event handler is the target
		if ( 'upsert_event' === $handler_slug ) {
			$tools['upsert_event'] = self::getDynamicEventTool( $handler_config, $engine_data );
		}

		return $tools;
	}

	/**
	 * Generate dynamic event tool based on taxonomy, venue settings, and engine data.
	 *
	 * All parameter methods filter by engine data - if a value exists in engine data,
	 * the parameter is excluded from the tool definition so the AI doesn't see it.
	 *
	 * @param array $handler_config Handler configuration
	 * @param array $engine_data Engine data snapshot
	 * @return array Tool definition
	 */
	private static function getDynamicEventTool( array $handler_config, array $engine_data = array() ): array {
		$ue_config = $handler_config['upsert_event'] ?? $handler_config;

		$tool = array(
			'class'       => EventUpsert::class,
			'method'      => 'handle_tool_call',
			'handler'     => 'upsert_event',
			'description' => 'Create or update WordPress event post. Automatically finds existing events by title, venue, and date. Updates if data changed, skips if unchanged, creates if new.',
			'parameters'  => array(),
		);

		// Core event parameters (title, dates, description) - filtered by engine data
		$core_params        = EventSchemaProvider::getCoreToolParameters( $engine_data );
		$tool['parameters'] = array_merge( $tool['parameters'], $core_params );

		// Schema enrichment parameters (performer, organizer, status, etc.) - filtered by engine data
		$schema_params      = EventSchemaProvider::getSchemaToolParameters( $engine_data );
		$tool['parameters'] = array_merge( $tool['parameters'], $schema_params );

		// Taxonomy parameters - config-driven (ai_decides vs skip vs preselected)
		$taxonomy_params    = TaxonomyHandler::getTaxonomyToolParameters( $ue_config, Event_Post_Type::POST_TYPE );
		$tool['parameters'] = array_merge( $tool['parameters'], $taxonomy_params );

		// Venue parameters - filtered by engine data
		$venue_params       = VenueParameterProvider::getToolParameters( $ue_config, $engine_data );
		$tool['parameters'] = array_merge( $tool['parameters'], $venue_params );

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
