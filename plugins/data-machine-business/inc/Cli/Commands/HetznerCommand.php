<?php
/**
 * Hetzner Cloud WP-CLI command (read-only).
 *
 * Thin wrapper around the datamachine/hetzner-* abilities. All business logic
 * (config resolution, pagination, error mapping, backup freshness) lives in
 * HetznerAbilities; this command only handles CLI args and output formatting.
 *
 * @package DataMachineBusiness\Cli\Commands
 */

namespace DataMachineBusiness\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class HetznerCommand extends BaseCommand {

	/**
	 * List Hetzner Cloud servers, or show one by ID.
	 *
	 * ## OPTIONS
	 *
	 * [<server_id>]
	 * : Optional Hetzner server ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine hetzner list-servers
	 *     wp datamachine hetzner list-servers 12345678 --format=json
	 *
	 * @subcommand list-servers
	 * @when after_wp_load
	 */
	public function list_servers( array $args, array $assoc_args ): void {
		$input = array();
		if ( ! empty( $args[0] ) ) {
			$input['server_id'] = (int) $args[0];
		}

		$result = $this->execute_ability( 'datamachine/hetzner-list-servers', $input );
		if ( $this->maybe_json( $result, $assoc_args ) ) {
			return;
		}

		$this->output_rows(
			$result['servers'] ?? array(),
			array( 'id', 'name', 'status', 'server_type', 'location', 'public_ipv4', 'backup_window', 'backups_enabled', 'delete_protection', 'rebuild_protection' ),
			$assoc_args,
			'No servers found.'
		);
	}

	/**
	 * Show Hetzner Cloud backup freshness per server.
	 *
	 * Flags a server as stale when its newest completed backup is older than
	 * the threshold (default 26h, filterable via
	 * datamachine_hetzner_backup_stale_hours), and as disabled when the server
	 * has no backup_window.
	 *
	 * ## OPTIONS
	 *
	 * [<server_id>]
	 * : Optional Hetzner server ID. Defaults to every server.
	 *
	 * [--stale-hours=<hours>]
	 * : Override the staleness threshold in hours.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine hetzner backup-status
	 *     wp datamachine hetzner backup-status --stale-hours=30 --format=json
	 *
	 * @subcommand backup-status
	 * @when after_wp_load
	 */
	public function backup_status( array $args, array $assoc_args ): void {
		$input = array();
		if ( ! empty( $args[0] ) ) {
			$input['server_id'] = (int) $args[0];
		}
		if ( isset( $assoc_args['stale-hours'] ) ) {
			$input['stale_hours'] = (float) $assoc_args['stale-hours'];
		}

		$result = $this->execute_ability( 'datamachine/hetzner-backup-status', $input );
		if ( $this->maybe_json( $result, $assoc_args ) ) {
			return;
		}

		$this->output_rows(
			$result['servers'] ?? array(),
			array( 'server_id', 'server_name', 'state', 'last_backup_at', 'backup_age_hours', 'stale', 'count', 'backup_window', 'backups_disabled' ),
			$assoc_args,
			'No servers found.'
		);

		if ( ! empty( $result['healthy'] ) ) {
			WP_CLI::success( (string) ( $result['message'] ?? 'Backups are fresh.' ) );
			return;
		}

		foreach ( (array) ( $result['issues'] ?? array() ) as $issue ) {
			WP_CLI::warning( (string) $issue );
		}
	}

	/**
	 * List Hetzner Cloud volumes.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine hetzner list-volumes
	 *
	 * @subcommand list-volumes
	 * @when after_wp_load
	 */
	public function list_volumes( array $args, array $assoc_args ): void {
		unset( $args );
		$result = $this->execute_ability( 'datamachine/hetzner-list-volumes', array() );
		if ( $this->maybe_json( $result, $assoc_args ) ) {
			return;
		}

		$this->output_rows(
			$result['volumes'] ?? array(),
			array( 'id', 'name', 'size_gb', 'server_id', 'location', 'status', 'format', 'linux_device', 'delete_protection' ),
			$assoc_args,
			'No volumes found.'
		);
	}

	/**
	 * Show Hetzner Cloud CPU / disk / network metrics for a server.
	 *
	 * ## OPTIONS
	 *
	 * <server_id>
	 * : Hetzner server ID.
	 *
	 * [--type=<types>]
	 * : Comma-separated metric types: cpu, disk, network. Default: all.
	 *
	 * [--hours=<hours>]
	 * : Window length in hours ending now (default 1). Ignored when --start is set.
	 *
	 * [--start=<iso8601>]
	 * : Window start.
	 *
	 * [--end=<iso8601>]
	 * : Window end (default now).
	 *
	 * [--step=<seconds>]
	 * : Resolution in seconds.
	 *
	 * [--format=<format>]
	 * : Output format. Table shows per-series latest/avg/max; json includes raw time series.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine hetzner server-metrics 12345678 --hours=24
	 *     wp datamachine hetzner server-metrics 12345678 --type=cpu --format=json
	 *
	 * @subcommand server-metrics
	 * @when after_wp_load
	 */
	public function server_metrics( array $args, array $assoc_args ): void {
		$input = array( 'server_id' => (int) ( $args[0] ?? 0 ) );
		foreach ( array( 'type', 'start', 'end' ) as $flag ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$input[ $flag ] = (string) $assoc_args[ $flag ];
			}
		}
		if ( isset( $assoc_args['hours'] ) ) {
			$input['hours'] = (float) $assoc_args['hours'];
		}
		if ( isset( $assoc_args['step'] ) ) {
			$input['step'] = (int) $assoc_args['step'];
		}

		$result = $this->execute_ability( 'datamachine/hetzner-server-metrics', $input );
		if ( $this->maybe_json( $result, $assoc_args ) ) {
			return;
		}

		$rows = array();
		foreach ( (array) ( $result['summary'] ?? array() ) as $series => $stats ) {
			$rows[] = array_merge( array( 'series' => $series ), (array) $stats );
		}

		WP_CLI::log( sprintf( 'Window: %s → %s', $result['start'] ?? '', $result['end'] ?? '' ) );
		$this->output_rows( $rows, array( 'series', 'points', 'latest', 'avg', 'max' ), $assoc_args, 'No metrics returned.' );
	}

	/**
	 * Print JSON when requested.
	 *
	 * @param array<string,mixed> $result     Ability result.
	 * @param array<string,mixed> $assoc_args CLI flags.
	 * @return bool Whether JSON was printed.
	 */
	private function maybe_json( array $result, array $assoc_args ): bool {
		if ( 'json' !== ( $assoc_args['format'] ?? 'table' ) ) {
			return false;
		}

		WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		return true;
	}

	/**
	 * Render rows with fixed columns.
	 *
	 * @param mixed               $rows          Row list.
	 * @param string[]            $fields        Columns.
	 * @param array<string,mixed> $assoc_args    CLI flags.
	 * @param string              $empty_message Message when there are no rows.
	 */
	private function output_rows( $rows, array $fields, array $assoc_args, string $empty_message ): void {
		$rows = is_array( $rows ) ? $rows : array();
		if ( empty( $rows ) ) {
			WP_CLI::success( $empty_message );
			return;
		}

		$flat = array();
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $fields as $field ) {
				$value          = $row[ $field ] ?? null;
				$line[ $field ] = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : ( is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : $value );
			}
			$flat[] = $line;
		}

		$this->format_items( $flat, $fields, $assoc_args );
	}

	/**
	 * Execute an ability and error out on failure.
	 *
	 * @param string               $slug  Ability slug.
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private function execute_ability( string $slug, array $input ): array {
		$ability = wp_get_ability( $slug );

		if ( ! $ability ) {
			WP_CLI::error( "Ability '{$slug}' not registered. Ensure Data Machine Business is active and WordPress 6.9+." );
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
}
