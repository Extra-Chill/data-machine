<?php
/**
 * Stage a Stripe Payment Link for human approval.
 *
 * datamachine/stripe-stage-payment-link validates a one-time or recurring
 * Payment Link against the hard limits and stages it as a pending action.
 * It makes no Stripe calls; StripePaymentLinkApproval creates the link only
 * after a human approves it.
 *
 * @package DataMachineBusiness\Abilities\Stripe
 */

namespace DataMachineBusiness\Abilities\Stripe;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Engine\AI\Actions\PendingActionHelper;
use DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility;

defined( 'ABSPATH' ) || exit;

class StripePaymentLinkAbility {

	/** Approval window; an unapproved request simply expires. */
	public const APPROVAL_TTL = 2 * DAY_IN_SECONDS;

	private static bool $registered = false;

	public function __construct() {
		StripePaymentLinkApproval::register();
		if ( self::$registered ) {
			return;
		}
		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init(
			static function () {
				wp_register_ability(
					'datamachine/stripe-stage-payment-link',
					array(
						'label'               => 'Stage Stripe Payment Link',
						'description'         => 'Stage a one-time or recurring Stripe Payment Link for human approval. Nothing is created in Stripe until a human approves; amounts are capped by hard limits.',
						'category'            => 'datamachine-publishing',
						'input_schema'        => array(
							'type'                 => 'object',
							'required'             => array( 'name', 'amount' ),
							'additionalProperties' => false,
							'properties'           => array(
								'name'           => array(
									'type'        => 'string',
									'description' => 'Product name shown on the Stripe checkout page.',
								),
								'description'    => array( 'type' => 'string' ),
								'amount'         => array(
									'type'        => 'number',
									'description' => 'Charge per payment in major units, e.g. 50 for $50.00. Capped by the hard limit.',
								),
								'currency'       => array(
									'type'    => 'string',
									'default' => 'usd',
								),
								'interval'       => array(
									'type'    => 'string',
									'enum'    => StripePaymentLinkPolicy::INTERVALS,
									'default' => 'once',
								),
								'interval_count' => array(
									'type'    => 'integer',
									'default' => 1,
								),
							),
						),
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => array( self::class, 'stage' ),
						'permission_callback' => fn() => PermissionHelper::can_manage(),
						'meta'                => array( 'show_in_rest' => false ),
					)
				);
			}
		);
		self::$registered = true;
	}

	/**
	 * Validate and stage. Never calls Stripe.
	 *
	 * @param array $input Payment Link request.
	 * @return array|\WP_Error
	 */
	public static function stage( array $input ) {
		$link = StripePaymentLinkPolicy::normalize( $input, StripePaymentLinkPolicy::limits() );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		// Fail fast: an approval that cannot run is noise for the approver.
		$key = StripePaymentLinkApproval::api_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$link['request_id'] = wp_generate_uuid4();
		$summary            = StripePaymentLinkPolicy::summary( $link );
		$staged             = PendingActionHelper::stage(
			array(
				'kind'            => StripePaymentLinkApproval::KIND,
				'summary'         => $summary,
				'apply_input'     => $link,
				'preview_data'    => $link + array( 'summary' => $summary ),
				'ttl'             => self::APPROVAL_TTL,
				'resolver_grants' => StripePaymentLinkApproval::resolver_grants(),
			)
		);
		if ( isset( $staged['staged'] ) && false === $staged['staged'] ) {
			return new \WP_Error( 'stage_failed', (string) ( $staged['error'] ?? 'Failed to stage approval.' ) );
		}
		$action_id = (string) ( $staged['payload']['action_id'] ?? ( $staged['action_id'] ?? '' ) );

		return array(
			'success'   => true,
			'status'    => 'pending_approval',
			'action_id' => $action_id,
			'summary'   => $summary,
			'request'   => $link,
			'links'     => self::approval_links( $action_id ),
		);
	}

	/**
	 * Signed approve/reject links for delivery to a human.
	 *
	 * @param string $action_id Pending action ID.
	 */
	public static function approval_links( string $action_id ): array {
		if ( '' === $action_id || ! class_exists( SignPendingActionResolutionAbility::class ) ) {
			return array();
		}
		$signed = SignPendingActionResolutionAbility::execute(
			array(
				'action_id' => $action_id,
				'lifetime'  => self::APPROVAL_TTL,
			)
		);
		return empty( $signed['success'] ) ? array() : array(
			'approve_url' => (string) $signed['approve_url'],
			'reject_url'  => (string) $signed['reject_url'],
			'expires_at'  => (string) $signed['expires_at'],
		);
	}
}
