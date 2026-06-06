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
			'upsert',
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
	 * Composes a canonical JSON Schema (`{ type: object, properties, required }`)
	 * for the `upsert_event` tool from four fragment sources. Each fragment
	 * provider returns either:
	 *   - canonical fragment: `{ properties: {...}, required?: [...] }`
	 *     (EventSchemaProvider, VenueParameterProvider), or
	 *   - flat property bag: `{ name => def, ... }` with no top-level
	 *     `properties`/`required` keys (TaxonomyHandler in data-machine core).
	 *
	 * The flat shape is treated as a degenerate fragment with no required
	 * fields. This keeps EventUpsertFilters tolerant of TaxonomyHandler's
	 * existing return shape until data-machine core converts it to the
	 * canonical fragment shape.
	 *
	 * All parameter methods filter by engine data - if a value exists in
	 * engine data, the parameter is excluded from the tool definition so
	 * the AI doesn't see it.
	 *
	 * @param array $handler_config Handler configuration
	 * @param array $engine_data Engine data snapshot
	 * @return array Tool definition with canonical JSON Schema parameters.
	 */
	private static function getDynamicEventTool( array $handler_config, array $engine_data = array() ): array {
		$ue_config = $handler_config['upsert_event'] ?? $handler_config;

		$fragments = array(
			// Core event parameters (title, dates, description) - filtered by engine data.
			EventSchemaProvider::getCoreToolParameters( $engine_data ),
			// Schema enrichment parameters (performer, organizer, status, etc.) - filtered by engine data.
			EventSchemaProvider::getSchemaToolParameters( $engine_data ),
			// Taxonomy parameters - config-driven (ai_decides vs skip vs preselected).
			// Owned by data-machine core; currently returns a flat property bag.
			TaxonomyHandler::getTaxonomyToolParameters( $ue_config, Event_Post_Type::POST_TYPE ),
			// Venue parameters - filtered by engine data.
			VenueParameterProvider::getToolParameters( $ue_config, $engine_data ),
		);

		$parameters = self::composeCanonicalParameters( $fragments );

		return array(
			'class'                   => EventUpsert::class,
			'client_context_bindings' => array( 'job_id' ),
			'method'                  => 'handle_tool_call',
			'handler'                 => 'upsert_event',
			'description'             => 'Create or update WordPress event post. Automatically finds existing events by title, venue, and date. Updates if data changed, skips if unchanged, creates if new.',
			'parameters'              => $parameters,
			'handler_config'          => $ue_config,
		);
	}

	/**
	 * Compose canonical JSON Schema parameters from a list of fragments.
	 *
	 * Each fragment is either:
	 *   - canonical: `{ properties: {...}, required?: [...] }`, or
	 *   - flat property bag: `{ name => def, ... }` (treated as
	 *     `{ properties: <bag> }` with no required fields).
	 *
	 * Empty fragments are skipped.
	 *
	 * @param array $fragments List of provider fragments.
	 * @return array Canonical JSON Schema: `{ type: object, properties, required? }`.
	 */
	private static function composeCanonicalParameters( array $fragments ): array {
		$properties = array();
		$required   = array();

		foreach ( $fragments as $fragment ) {
			if ( ! is_array( $fragment ) || empty( $fragment ) ) {
				continue;
			}

			if ( isset( $fragment['properties'] ) && is_array( $fragment['properties'] ) ) {
				$properties = array_merge( $properties, $fragment['properties'] );
				if ( isset( $fragment['required'] ) && is_array( $fragment['required'] ) ) {
					$required = array_merge( $required, $fragment['required'] );
				}
				continue;
			}

			// Legacy flat property bag (e.g. data-machine core's TaxonomyHandler).
			// Treat the entire fragment as the properties map; no required fields.
			$properties = array_merge( $properties, $fragment );
		}

		$required = array_values(
			array_unique(
				array_filter(
					$required,
					static fn( $name ) => is_string( $name ) && array_key_exists( $name, $properties )
				)
			)
		);

		$parameters = array(
			'type'       => 'object',
			'properties' => $properties,
		);

		if ( ! empty( $required ) ) {
			$parameters['required'] = $required;
		}

		return $parameters;
	}
}

/**
 * Register Event Upsert handler filters
 */
function datamachine_register_event_upsert_filters() {
	EventUpsertFilters::register();
}

datamachine_register_event_upsert_filters();
