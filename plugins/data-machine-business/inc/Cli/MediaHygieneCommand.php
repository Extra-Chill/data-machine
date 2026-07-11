<?php
/**
 * Media Hygiene WP-CLI command.
 *
 * Thin wrapper around the `datamachine/media-hygiene` ability. All business
 * logic lives in the ability; this command only handles CLI args, output
 * formatting, and multisite iteration.
 *
 * @package DataMachineBusiness\Cli
 */

namespace DataMachineBusiness\Cli;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class MediaHygieneCommand extends BaseCommand {

	/**
	 * Available actions for this flat `__invoke` command.
	 *
	 * The action is a positional argument dispatched inside `__invoke`, so it
	 * is invisible to method reflection. This static accessor is the
	 * machine-readable source consumed by the AGENTS.md section renderer
	 * without instantiating the command. Keep in sync with the `## OPTIONS`
	 * `<action>` list.
	 *
	 * @return array<string,string> Action name => short description.
	 */
	public static function actions(): array {
		return array(
			'diagnose'       => 'Summary of dead-weight media across detectors',
			'orphan-files'   => 'Files on disk with no attachment record',
			'unused'         => 'Attachments not referenced in any content',
			'delete-orphans' => 'Delete orphan files (requires --apply)',
			'delete-unused'  => 'Delete unused attachments (requires --apply)',
		);
	}

	/**
	 * Inspect and clean dead-weight media (orphan files + unreferenced attachments).
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: diagnose | orphan-files | unused | delete-orphans | delete-unused
	 *
	 * [--limit=<n>]
	 * : Maximum items per detector (default: 500 for scans, 100 for deletes; max 1000 for deletes).
	 *
	 * [--apply]
	 * : Required for delete actions. Without --apply, delete actions return a dry-run preview.
	 *
	 * [--all-sites]
	 * : Run across every site in the multisite network. Without this flag, runs only against
	 *   the current site (or the --url=<site> target if provided).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # See how much dead weight is on the current site
	 *     wp datamachine media diagnose
	 *
	 *     # Scan every site in the network
	 *     wp datamachine media diagnose --all-sites
	 *
	 *     # List the first 100 orphan files on disk
	 *     wp datamachine media orphan-files --limit=100
	 *
	 *     # Preview which attachments would be deleted as unused
	 *     wp datamachine media delete-unused --limit=50
	 *
	 *     # Actually delete them
	 *     wp datamachine media delete-unused --limit=50 --apply
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';
		if ( '' === $action ) {
			WP_CLI::error( 'Action is required. See `wp help datamachine media` for usage.' );
			return;
		}

		$input = array( 'action' => $action );

		if ( isset( $assoc_args['limit'] ) ) {
			$input['limit'] = (int) $assoc_args['limit'];
		}

		if ( ! empty( $assoc_args['apply'] ) ) {
			$input['apply'] = true;
		}

		$format     = $assoc_args['format'] ?? 'table';
		$all_sites  = ! empty( $assoc_args['all-sites'] );

		if ( $all_sites && is_multisite() ) {
			$this->run_across_network( $input, $format );
			return;
		}

		$result = $this->execute_one( $input );
		$this->emit( $result, $format );
	}

	/**
	 * Iterates the network, invoking the ability per site and aggregating.
	 */
	private function run_across_network( array $input, string $format ): void {
		$sites = get_sites( array( 'number' => 0 ) );
		if ( empty( $sites ) ) {
			WP_CLI::warning( 'No sites found.' );
			return;
		}

		$rows = array();
		foreach ( $sites as $site ) {
			$blog_id = (int) $site->blog_id;
			switch_to_blog( $blog_id );

			$result = $this->execute_one( $input );

			$summary = is_array( $result ) && isset( $result['summary'] ) && is_array( $result['summary'] )
				? $result['summary']
				: array();

			$rows[] = array_merge(
				array(
					'blog_id' => $blog_id,
					'url'     => get_site_url( $blog_id ),
				),
				$summary
			);

			restore_current_blog();
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $rows, JSON_PRETTY_PRINT ) );
			return;
		}

		// Pick a stable column set across rows for table output.
		$fields = ! empty( $rows ) ? array_keys( $rows[0] ) : array( 'blog_id', 'url' );
		\WP_CLI\Utils\format_items( 'table', $rows, $fields );
	}

	/**
	 * Invokes the ability for the current site context.
	 *
	 * @return array
	 */
	private function execute_one( array $input ): array {
		$ability = wp_get_ability( 'datamachine/media-hygiene' );
		if ( ! $ability ) {
			WP_CLI::error( 'Media Hygiene ability not registered. Ensure Data Machine Business is active and WordPress 6.9+ is available.' );
		}

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error.' );
		}

		return $result;
	}

	/**
	 * Emits a single-site result in the requested format.
	 */
	private function emit( array $result, string $format ): void {
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$summary = is_array( $result['summary'] ?? null ) ? $result['summary'] : array();
		$results = is_array( $result['results'] ?? null ) ? $result['results'] : array();

		if ( ! empty( $summary ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Summary:' );
			$summary_rows = array();
			foreach ( $summary as $k => $v ) {
				$summary_rows[] = array(
					'metric' => $k,
					'value'  => is_scalar( $v ) ? (string) $v : wp_json_encode( $v ),
				);
			}
			\WP_CLI\Utils\format_items( 'table', $summary_rows, array( 'metric', 'value' ) );
		}

		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $results ) );
			return;
		}

		if ( empty( $results ) ) {
			return;
		}

		// Per-item results table.
		$fields = array_keys( reset( $results ) );
		\WP_CLI\Utils\format_items( $format, $results, $fields );
	}
}
