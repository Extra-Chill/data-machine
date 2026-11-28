<?php
/** Event ownership transport. @package DataMachineEvents\Cli */
namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\EventOwnershipAbilities;

defined( 'ABSPATH' ) || exit;

class EventOwnershipCommand {
	/**
	 * Show or set whether an event is human-owned.
	 *
	 * Imports that match a human-owned event only fill empty fields. Use
	 * `--set=import` to hand an event back to the importer, `--set=human` to
	 * protect it, or `--set=auto` to clear the explicit state.
	 *
	 * ## OPTIONS
	 *
	 * <event-id>
	 * : Event post ID.
	 *
	 * [--set=<ownership>]
	 * : New ownership state.
	 * ---
	 * options:
	 *   - human
	 *   - import
	 *   - auto
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events event-ownership 123
	 *     wp data-machine-events event-ownership 123 --set=import
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$ability = wp_get_ability( EventOwnershipAbilities::ABILITY_NAME );
		if ( ! $ability ) {
			\WP_CLI::error( 'Event ownership ability unavailable.' );
		}
		$input = array( 'event' => (int) $args[0] );
		if ( isset( $assoc_args['set'] ) ) {
			$input['ownership'] = (string) $assoc_args['set'];
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::line( (string) wp_json_encode( $result ) );
	}
}
