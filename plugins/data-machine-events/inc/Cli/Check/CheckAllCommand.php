<?php
/**
 * Run all data quality checks and display a summary.
 *
 * Delegates to each individual check subcommand and aggregates results.
 *
 * Usage:
 *   wp datamachine-events check all
 *   wp datamachine-events check all --scope=all
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.14.0
 */

namespace DataMachineEvents\Cli\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckAllCommand {

	/**
	 * Run all data quality checks.
	 *
	 * Runs times, venues, encoding, meta-sync, duration, and duplicates
	 * checks sequentially and displays results.
	 *
	 * ## OPTIONS
	 *
	 * [--scope=<scope>]
	 * : Which events to scan.
	 * ---
	 * default: upcoming
	 * options:
	 *   - upcoming
	 *   - past
	 *   - all
	 * ---
	 *
	 * [--days-ahead=<days>]
	 * : Days to look ahead for upcoming scope.
	 * ---
	 * default: 90
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Max items to show per category.
	 * ---
	 * default: 10
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-events check all
	 *     wp datamachine-events check all --scope=all --limit=5
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$scope      = $assoc_args['scope'] ?? 'upcoming';
		$days_ahead = $assoc_args['days-ahead'] ?? '90';
		$limit      = $assoc_args['limit'] ?? '10';

		$checks = array(
			'times'      => 'Time Issues',
			'venues'     => 'Venue Issues',
			'encoding'   => 'Encoding Issues',
			'meta-sync'  => 'Meta Sync Issues',
			'duration'   => 'Duration Issues',
			'duplicates' => 'Duplicate Events',
		);

		\WP_CLI::log( '=====================================' );
		\WP_CLI::log( '  Data Machine Events — Full Audit' );
		\WP_CLI::log( '=====================================' );
		\WP_CLI::log( sprintf( 'Scope: %s | Limit: %s per check', $scope, $limit ) );
		\WP_CLI::log( '' );

		foreach ( $checks as $subcommand => $label ) {
			\WP_CLI::log( "========== {$label} ==========" );
			\WP_CLI::log( '' );

			$run_args = array(
				'scope'      => $scope,
				'days-ahead' => $days_ahead,
				'limit'      => $limit,
			);

			// meta-sync only uses --limit
			if ( 'meta-sync' === $subcommand ) {
				$run_args = array( 'limit' => $limit );
			}

			// duration uses --max-days and --scope, not --days-ahead or --limit
			if ( 'duration' === $subcommand ) {
				$run_args = array( 'scope' => $scope );
			}

			try {
				\WP_CLI::runcommand(
					"datamachine-events check {$subcommand} " . $this->build_flag_string( $run_args ),
					array(
						'return'     => false,
						'launch'     => false,
						'exit_error' => false,
					)
				);
			} catch ( \Exception $e ) {
				\WP_CLI::warning( "Check '{$subcommand}' failed: " . $e->getMessage() );
			}

			\WP_CLI::log( '' );
		}

		\WP_CLI::log( '=====================================' );
		\WP_CLI::success( 'Full audit complete.' );
	}

	/**
	 * Build CLI flag string from assoc args.
	 *
	 * @param array $args Associative arguments.
	 * @return string Flag string.
	 */
	private function build_flag_string( array $args ): string {
		$flags = array();

		foreach ( $args as $key => $value ) {
			$flags[] = "--{$key}={$value}";
		}

		return implode( ' ', $flags );
	}
}
