<?php
/**
 * Read-only Stripe reporting CLI.
 *
 * @package DataMachineBusiness\Cli
 */
namespace DataMachineBusiness\Cli;

use DataMachine\Cli\BaseCommand;
use DataMachineBusiness\Abilities\Analytics\StripeReportsAbilities;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class StripeCommand extends BaseCommand {
	/**
	 * Query Stripe or configure a read-only restricted key.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : subscriptions, mrr, renewals, past_due, charges, payouts, or config.
	 *
	 * [--limit=<limit>]
	 * : Source records per collection (default 25, clamped to 1–100).
	 *
	 * [--days=<days>]
	 * : Renewal window (default 7, clamped to 1–365).
	 *
	 * [--api-key=<key>]
	 * : For config only: read-only restricted rk_live_ or rk_test_ key.
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
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';
		if ( 'config' === $action ) {
			$key = (string) ( $assoc_args['api-key'] ?? '' );
			$valid = StripeReportsAbilities::validateKey( $key );
			if ( is_wp_error( $valid ) ) {
				WP_CLI::error( $valid->get_error_message() );
				return;
			}
			update_option( StripeReportsAbilities::CONFIG_OPTION, array( 'api_key' => $key ), false );
			WP_CLI::success( 'Stripe restricted key saved. Ensure its dashboard permissions are read-only.' );
			return;
		}
		$format = $assoc_args['format'] ?? 'table';
		if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) {
			WP_CLI::error( 'Format must be table, json, or csv.' );
			return;
		}
		$ability = wp_get_ability( 'datamachine/stripe-reports' );
		if ( ! $ability ) {
			WP_CLI::error( 'Ability datamachine/stripe-reports is not registered. Ensure Data Machine Business is active.' );
			return;
		}
		$input = array( 'action' => $action );
		foreach ( array( 'limit', 'days' ) as $flag ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$input[ $flag ] = (int) $assoc_args[ $flag ];
			}
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION ) );
			return;
		}
		if ( ! $result['provenance']['complete'] ) {
			WP_CLI::warning( 'Bounded sample only: more Stripe records exist. MRR is not an account-wide total.' );
		}
		if ( empty( $result['results'] ) ) {
			WP_CLI::success( 'No matching rows in the bounded sample. Use --format=json for provenance.' );
			return;
		}
		$fields = self::tableFields( $action );
		$rows = array_map( static fn( $row ) => array_merge( array_fill_keys( $fields, null ), $row ), $result['results'] );
		$this->format_items( $rows, $fields, $assoc_args );
	}

	/** Stable columns, including both delinquency row types. */
	public static function tableFields( string $action ): array {
		$subscription = array( 'type', 'id', 'item_id', 'customer', 'customer_email', 'customer_name', 'amount', 'currency', 'interval', 'interval_count', 'status', 'current_period_end', 'cancel_at_period_end' );
		return match ( $action ) {
			'mrr' => array( 'currency', 'mrr' ),
			'charges' => array( 'id', 'amount', 'currency', 'status', 'paid', 'refunded', 'customer', 'created' ),
			'payouts' => array( 'id', 'amount', 'currency', 'status', 'arrival_date' ),
			'past_due' => array_merge( $subscription, array( 'due_date' ) ),
			default => $subscription,
		};
	}
}
