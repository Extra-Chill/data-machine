<?php
/**
 * Stripe Payment Link CLI: stage links for approval and configure the write key.
 *
 * @package DataMachineBusiness\Cli
 */

namespace DataMachineBusiness\Cli;

use DataMachine\Cli\BaseCommand;
use DataMachineBusiness\Abilities\Analytics\StripeReportsAbilities;
use DataMachineBusiness\Abilities\Stripe\StripePaymentLinkApproval;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class StripePaymentLinkCommand extends BaseCommand {

	/**
	 * Stage a Stripe Payment Link for human approval. Nothing is created in Stripe until it is approved.
	 *
	 * ## OPTIONS
	 *
	 * --name=<name>
	 * : Product name shown at checkout.
	 *
	 * --amount=<amount>
	 * : Charge per payment in major units, e.g. 50 for $50.00.
	 *
	 * [--currency=<currency>]
	 * : ISO currency code. Must be allowed by the Payment Link limits.
	 * ---
	 * default: usd
	 * ---
	 *
	 * [--interval=<interval>]
	 * : once, day, week, month, or year.
	 * ---
	 * default: once
	 * ---
	 *
	 * [--interval-count=<count>]
	 * : Bill every N intervals.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--description=<description>]
	 * : Optional product description.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine stripe payment-link stage --name="Hosting + support" --amount=50 --interval=month
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function stage( array $args, array $assoc_args ): void {
		unset( $args );
		$ability = wp_get_ability( 'datamachine/stripe-stage-payment-link' );
		if ( ! $ability ) {
			WP_CLI::error( 'Ability datamachine/stripe-stage-payment-link is not registered. Ensure Data Machine Business is active.' );
			return;
		}
		$input = array(
			'name'           => (string) ( $assoc_args['name'] ?? '' ),
			'amount'         => (float) ( $assoc_args['amount'] ?? 0 ),
			'currency'       => (string) ( $assoc_args['currency'] ?? 'usd' ),
			'interval'       => (string) ( $assoc_args['interval'] ?? 'once' ),
			'interval_count' => (int) ( $assoc_args['interval-count'] ?? 1 ),
		);
		if ( isset( $assoc_args['description'] ) ) {
			$input['description'] = (string) $assoc_args['description'];
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION ) );
			return;
		}
		WP_CLI::success( $result['summary'] . ' — staged for approval.' );
		WP_CLI::line( 'Action ID: ' . $result['action_id'] );
		if ( ! empty( $result['links']['approve_url'] ) ) {
			WP_CLI::line( 'Approve: ' . $result['links']['approve_url'] );
			WP_CLI::line( 'Reject:  ' . $result['links']['reject_url'] );
			WP_CLI::line( 'Expires: ' . $result['links']['expires_at'] );
		}
	}

	/**
	 * Store the restricted write key used only after approval.
	 *
	 * Create it in Stripe with write access to Products, Prices and Payment
	 * Links and nothing else. It is stored apart from the read-only reporting key.
	 *
	 * ## OPTIONS
	 *
	 * --api-key=<key>
	 * : Restricted rk_live_ or rk_test_ key.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function config( array $args, array $assoc_args ): void {
		unset( $args );
		$key   = (string) ( $assoc_args['api-key'] ?? '' );
		$valid = StripeReportsAbilities::validateKey( $key );
		if ( is_wp_error( $valid ) ) {
			WP_CLI::error( 'Create a restricted Stripe key (rk_live_ or rk_test_) with write access to Products, Prices and Payment Links only. Secret keys (sk_) are not accepted.' );
			return;
		}
		update_option( StripePaymentLinkApproval::CONFIG_OPTION, array( 'api_key' => $key ), false );
		WP_CLI::success( 'Stripe Payment Link key saved. Grant it Products, Prices and Payment Links write only.' );
	}
}
