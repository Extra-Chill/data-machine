<?php
/**
 * WP-CLI command for updating events
 *
 * Wraps EventUpdateAbilities for CLI consumption.
 *
 * @package DataMachineEvents\Cli
 * @since 0.9.15
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\EventUpdateAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UpdateEventCommand {

	/**
	 * The `data-machine-events/update-event` ability this command wraps.
	 *
	 * The CLI is a thin adapter: field names accepted here are derived at
	 * runtime from this ability's registered input schema (see
	 * `fieldMap()`), so this docblock is the only place the field list is
	 * hand-maintained. Keep it in sync with
	 * `EventUpdateAbilities::registerAbility()`'s `input_schema`.
	 */
	private const ABILITY_NAME = 'data-machine-events/update-event';

	/**
	 * Update one or more events' date, time, venue, and other fields.
	 *
	 * ## OPTIONS
	 *
	 * <event_ids>
	 * : One or more event IDs (comma-separated).
	 *
	 * [--start-date=<date>]
	 * : New start date (any parseable format, normalized to YYYY-MM-DD).
	 *
	 * [--start-time=<time>]
	 * : New start time (any parseable format like "8pm", "20:00").
	 *
	 * [--end-date=<date>]
	 * : New end date (any parseable format, normalized to YYYY-MM-DD).
	 *
	 * [--end-time=<time>]
	 * : New end time (any parseable format, normalized to HH:MM).
	 *
	 * [--venue=<venue>]
	 * : Existing venue term ID to assign.
	 *
	 * [--description=<description>]
	 * : Event description (HTML allowed).
	 *
	 * [--price=<price>]
	 * : Ticket price (e.g., "$25" or "$20 adv / $25 door").
	 *
	 * [--ticket-url=<url>]
	 * : URL to purchase tickets.
	 *
	 * [--performer=<name>]
	 * : Performer name.
	 *
	 * [--performer-type=<type>]
	 * : Performer type: Person, PerformingGroup, or MusicGroup.
	 *
	 * [--event-status=<status>]
	 * : Event status: EventScheduled, EventPostponed, EventCancelled, or EventRescheduled.
	 *
	 * [--event-type=<type>]
	 * : Event format. Must be a value from the event_type vocabulary.
	 *
	 * [--occurrence-dates=<json>]
	 * : JSON array of specific dates (YYYY-MM-DD) when the event occurs.
	 *
	 * [--format=<format>]
	 * : Output format (default: table).
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events update-event 123 --start-time=20:00
	 *     wp data-machine-events update-event 123,456 --venue="The Pour House"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		// wp-cli stubs unconditionally define WP_CLI, so static analysis cannot
		// see this guard; argv distinguishes the real CLI runtime.
		if ( empty( $_SERVER['argv'] ) ) {
			return;
		}

		$event_ids_raw = $args[0] ?? '';

		if ( empty( $event_ids_raw ) ) {
			\WP_CLI::error( 'Missing required event ID(s). Usage: wp data-machine-events update-event <event_ids> [--start-time=<time>]' );
		}

		$event_ids = $this->parseEventIds( $event_ids_raw );

		if ( empty( $event_ids ) ) {
			\WP_CLI::error( 'No valid event IDs provided.' );
		}

		$format = $assoc_args['format'] ?? 'table';
		unset( $assoc_args['format'] );

		$field_map = $this->fieldMap();

		if ( empty( $field_map ) ) {
			\WP_CLI::error( 'The ' . self::ABILITY_NAME . ' ability is not registered; cannot resolve updatable fields.' );
		}

		$fields = $this->extractUpdateFields( $assoc_args, $field_map );

		if ( empty( $fields ) ) {
			$flags = array_map( static fn( string $flag ): string => "--{$flag}", array_keys( $field_map ) );
			\WP_CLI::error( 'No fields to update. Provide at least one of: ' . implode( ', ', $flags ) );
		}

		$abilities = new EventUpdateAbilities();
		$result    = $this->executeUpdate( $abilities, $event_ids, $fields );

		if ( $result instanceof \WP_Error ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
		}

		if ( 'json' === $format ) {
			$this->outputJson( $result );
			return;
		}

		$this->outputTable( $result );
	}

	private function parseEventIds( string $raw ): array {
		$ids = array_map( 'trim', explode( ',', $raw ) );
		$ids = array_filter( $ids, fn( $id ) => is_numeric( $id ) && (int) $id > 0 );
		return array_map( 'intval', $ids );
	}

	/**
	 * Map CLI-legal kebab-case flag names to the ability's camelCase field
	 * names, derived from the ability's own registered input schema.
	 *
	 * WP-CLI's synopsis parser only accepts lowercase option names
	 * (`[a-z-_0-9]+`), so this is the single place that reconciles CLI
	 * naming conventions with the ability's camelCase contract. Deriving
	 * from the schema (rather than hand-listing field names here) is
	 * deliberate: a hand-maintained duplicate list is exactly what drifted
	 * out of sync in #858.
	 *
	 * @return array<string, string> Map of kebab-case flag => camelCase field.
	 */
	private function fieldMap(): array {
		$ability = wp_get_ability( self::ABILITY_NAME );

		if ( ! $ability instanceof \WP_Ability ) {
			return array();
		}

		$properties = $ability->get_input_schema()['properties'] ?? array();

		$map = array();
		foreach ( array_keys( $properties ) as $property_name ) {
			$field = (string) $property_name;

			// 'event' and 'events' are structural (event identification),
			// not updatable fields, and are handled separately from
			// positional event IDs.
			if ( in_array( $field, array( 'event', 'events' ), true ) ) {
				continue;
			}

			$map[ $this->toKebabCase( $field ) ] = $field;
		}

		return $map;
	}

	/** Convert a camelCase ability field name to a WP-CLI-legal kebab-case flag. */
	private function toKebabCase( string $camel ): string {
		return strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '-$0', $camel ) );
	}

	/**
	 * @param array                $assoc_args Named CLI arguments (kebab-case flags).
	 * @param array<string, string> $field_map  Map of kebab-case flag => camelCase field, from fieldMap().
	 */
	private function extractUpdateFields( array $assoc_args, array $field_map ): array {
		$fields = array();

		foreach ( $field_map as $flag => $field ) {
			if ( ! isset( $assoc_args[ $flag ] ) ) {
				continue;
			}

			$value = $assoc_args[ $flag ];

			// Parse JSON for array fields
			if ( 'occurrenceDates' === $field && is_string( $value ) ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					$value = $decoded;
				}
			}

			$fields[ $field ] = $value;
		}

		return $fields;
	}

	private function executeUpdate( EventUpdateAbilities $abilities, array $event_ids, array $fields ): array|\WP_Error {
		if ( count( $event_ids ) === 1 ) {
			$params          = $fields;
			$params['event'] = $event_ids[0];
			return $abilities->executeUpdateEvent( $params );
		}

		$events = array();
		foreach ( $event_ids as $id ) {
			$event_update          = $fields;
			$event_update['event'] = $id;
			$events[]              = $event_update;
		}

		return $abilities->executeUpdateEvent( array( 'events' => $events ) );
	}

	private function outputJson( array $data ): void {
		\WP_CLI::log( (string) wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function outputTable( array $data ): void {
		$summary = $data['summary'] ?? array();
		$results = $data['results'] ?? array();

		\WP_CLI::log( 'Summary: ' . ( $data['message'] ?? '' ) );
		\WP_CLI::log( 'Updated: ' . ( $summary['updated'] ?? 0 ) . ', Failed: ' . ( $summary['failed'] ?? 0 ) . ', Total: ' . ( $summary['total'] ?? 0 ) );
		\WP_CLI::log( '' );

		if ( empty( $results ) ) {
			return;
		}

		$table_data = array();
		foreach ( $results as $result ) {
			$updated_fields = $result['updated_fields'] ?? array();
			$warnings       = $result['warnings'] ?? array();

			$fields_str  = implode( ', ', $updated_fields );
			$warning_str = implode( '; ', $warnings );

			$table_data[] = array(
				'ID'      => $result['post_id'] ?? $result['event'] ?? 'N/A',
				'Title'   => mb_substr( $result['title'] ?? 'N/A', 0, 40 ),
				'Status'  => $result['status'],
				'Fields'  => ! empty( $fields_str ) ? $fields_str : '-',
				'Warning' => ! empty( $warning_str ) ? $warning_str : '-',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $table_data, array( 'ID', 'Title', 'Status', 'Fields', 'Warning' ) );
	}
}
