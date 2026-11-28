<?php
/** Instagram backfill transport. @package DataMachineEvents\Cli */
namespace DataMachineEvents\Cli;

defined( 'ABSPATH' ) || exit;

class SetTermInstagramCommand {
	/**
	 * Set or clear a venue or artist Instagram handle.
	 *
	 * ## OPTIONS
	 *
	 * <taxonomy>
	 * : venue or artist.
	 *
	 * <term-id>
	 * : Local term ID.
	 *
	 * <instagram>
	 * : Username or profile URL. Pass an empty string to clear.
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events set-term-instagram venue 1531 '@MusicFarm'
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		$ability = wp_get_ability( 'data-machine-events/set-term-instagram' );
		if ( ! $ability ) {
			\WP_CLI::error( 'Instagram ability unavailable.' );
		}
		$result = $ability->execute(
			array(
				'taxonomy'  => $args[0],
				'term_id'   => (int) $args[1],
				'instagram' => $args[2],
			)
		);
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::line( (string) wp_json_encode( $result ) );
	}
}
