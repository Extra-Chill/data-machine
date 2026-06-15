<?php
/**
 * WP-CLI command to backfill the event dates table.
 *
 * Ensures the datamachine_event_dates table exists, then backfills every
 * existing event's start/end datetime into it from the Event Details block.
 * One-off maintenance command used after the dedicated dates table was
 * introduced.
 *
 * @package DataMachineEvents\Cli
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Core\EventDatesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill the event dates table from existing events.
 */
class BackfillEventDatesCommand {

	/**
	 * Backfill the datamachine_event_dates table from existing events.
	 *
	 * Creates the table if it does not exist, then walks every event and
	 * writes its parsed start/end datetime into the dedicated dates table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events backfill-event-dates
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments (unused).
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		EventDatesTable::create_table();
		\WP_CLI::log( 'Table ensured. Starting backfill...' );

		$total = EventDatesTable::backfill(
			500,
			function ( $count ) {
				if ( 0 === $count % 500 ) {
					\WP_CLI::log( "Backfilled {$count} events..." );
				}
			}
		);

		\WP_CLI::success( "Backfilled {$total} events into datamachine_event_dates table." );
	}
}
