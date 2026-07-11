<?php
/**
 * CLI Command Registry
 *
 * Single source of truth mapping `wp data-machine-events ...` command strings
 * to their implementing command classes. Both the WP-CLI bootstrap (which calls
 * WP_CLI::add_command for each entry) and the AGENTS.md section generator
 * (which reflects over each class to enumerate real subcommands) read from this
 * map, so the documented CLI surface can never drift from what is actually
 * registered.
 *
 * Each entry carries the command class's source FILE alongside the class name.
 * The section generator runs on `plugins_loaded` in web/cron compose contexts
 * where the plugin's `vendor/` PSR-4 autoloader may not be present (these
 * command classes are otherwise only required under the `WP_CLI` guard). The
 * file path lets the generator `require_once` the class on demand and reflect
 * over it without relying on any autoloader or the live WP-CLI runner.
 *
 * @package DataMachineEvents\Cli
 */

namespace DataMachineEvents\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for the events WP-CLI command map.
 */
class CommandRegistry {

	/**
	 * Map of command string => command descriptor.
	 *
	 * Keys are the exact strings passed to WP_CLI::add_command (the command
	 * namespace, e.g. "data-machine-events check times"). Order here determines
	 * both registration order and documentation order. Each value is an array
	 * with:
	 *   - `file`  Absolute path to the command class source file.
	 *   - `class` Fully-qualified command class name.
	 *
	 * @return array<string, array{file: string, class: class-string}>
	 */
	public static function map() {
		$cli = DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Cli/';

		return array(
			// Handler / scraper testing.
			'data-machine-events test-event-scraper'     => array(
				'file'  => $cli . 'UniversalWebScraperTestCommand.php',
				'class' => UniversalWebScraperTestCommand::class,
			),
			'data-machine-events test-ticketmaster'      => array(
				'file'  => $cli . 'TicketmasterTestCommand.php',
				'class' => TicketmasterTestCommand::class,
			),
			'data-machine-events test-dice-fm'           => array(
				'file'  => $cli . 'DiceFmTestCommand.php',
				'class' => DiceFmTestCommand::class,
			),

			// Settings + venue event lookups.
			'data-machine-events settings'               => array(
				'file'  => $cli . 'SettingsCommand.php',
				'class' => SettingsCommand::class,
			),
			'data-machine-events get-venue-events'       => array(
				'file'  => $cli . 'GetVenueEventsCommand.php',
				'class' => GetVenueEventsCommand::class,
			),

			// Data quality checks (each a leaf __invoke command).
			'data-machine-events check times'            => array(
				'file'  => $cli . 'Check/CheckTimesCommand.php',
				'class' => Check\CheckTimesCommand::class,
			),
			'data-machine-events check venues'           => array(
				'file'  => $cli . 'Check/CheckVenuesCommand.php',
				'class' => Check\CheckVenuesCommand::class,
			),
			'data-machine-events check encoding'         => array(
				'file'  => $cli . 'Check/CheckEncodingCommand.php',
				'class' => Check\CheckEncodingCommand::class,
			),
			'data-machine-events check duration'         => array(
				'file'  => $cli . 'Check/CheckDurationCommand.php',
				'class' => Check\CheckDurationCommand::class,
			),
			'data-machine-events check duplicates'       => array(
				'file'  => $cli . 'Check/CheckDuplicatesCommand.php',
				'class' => Check\CheckDuplicatesCommand::class,
			),
			'data-machine-events check clean-duplicates' => array(
				'file'  => $cli . 'Check/CleanDuplicatesCommand.php',
				'class' => Check\CleanDuplicatesCommand::class,
			),
			'data-machine-events check merged-bills'     => array(
				'file'  => $cli . 'Check/CheckMergedBillsCommand.php',
				'class' => Check\CheckMergedBillsCommand::class,
			),
			'data-machine-events check merge-duplicate-venues' => array(
				'file'  => $cli . 'Check/CheckMergeDuplicateVenuesCommand.php',
				'class' => Check\CheckMergeDuplicateVenuesCommand::class,
			),
			'data-machine-events check missing-venue-addresses' => array(
				'file'  => $cli . 'Check/CheckMissingVenueAddressesCommand.php',
				'class' => Check\CheckMissingVenueAddressesCommand::class,
			),
			'data-machine-events check orphan-venues'    => array(
				'file'  => $cli . 'Check/CheckOrphanVenuesCommand.php',
				'class' => Check\CheckOrphanVenuesCommand::class,
			),
			'data-machine-events check orphan-pipelines' => array(
				'file'  => $cli . 'Check/CheckOrphanPipelinesCommand.php',
				'class' => Check\CheckOrphanPipelinesCommand::class,
			),
			'data-machine-events check quality'          => array(
				'file'  => $cli . 'Check/CheckQualityCommand.php',
				'class' => Check\CheckQualityCommand::class,
			),
			'data-machine-events check malformed-dates'   => array(
				'file'  => $cli . 'Check/CheckMalformedDatesCommand.php',
				'class' => Check\CheckMalformedDatesCommand::class,
			),
			'data-machine-events check all'              => array(
				'file'  => $cli . 'Check/CheckAllCommand.php',
				'class' => Check\CheckAllCommand::class,
			),

			// Event + venue maintenance.
			'data-machine-events update-event'           => array(
				'file'  => $cli . 'UpdateEventCommand.php',
				'class' => UpdateEventCommand::class,
			),
			'data-machine-events batch-time-fix'         => array(
				'file'  => $cli . 'BatchTimeFixCommand.php',
				'class' => BatchTimeFixCommand::class,
			),
			'data-machine-events fix-encoding'           => array(
				'file'  => $cli . 'EncodingFixCommand.php',
				'class' => EncodingFixCommand::class,
			),
			'data-machine-events resync-ticket-urls'     => array(
				'file'  => $cli . 'TicketUrlResyncCommand.php',
				'class' => TicketUrlResyncCommand::class,
			),
			'data-machine-events geocode-venues'         => array(
				'file'  => $cli . 'GeocodeVenuesCommand.php',
				'class' => GeocodeVenuesCommand::class,
			),
			'data-machine-events audit-venues'           => array(
				'file'  => $cli . 'AuditVenuesCommand.php',
				'class' => AuditVenuesCommand::class,
			),
			'data-machine-events backfill-event-dates'   => array(
				'file'  => $cli . 'BackfillEventDatesCommand.php',
				'class' => BackfillEventDatesCommand::class,
			),
		);
	}
}
