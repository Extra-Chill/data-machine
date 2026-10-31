<?php
/**
 * Facebook Promotion Approval
 *
 * The spend gate for paid Meta promotions (#281), wired into Data
 * Machine's pending-action resolver. A staged promotion is PAUSED in the
 * ad account; this class is the only code that ever activates one.
 *
 * - can_resolve: signed approve/reject link, or a site administrator.
 *   Agents never get here: no agent grant is staged.
 * - apply: consume the one-time receipt, re-read the live ad set, re-check
 *   it against the hard limits, then activate ad -> ad set -> campaign.
 *   Out-of-policy at approval time deletes the campaign.
 * - rejected / expired: delete the paused campaign.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Facebook
 */

namespace DataMachineSocials\Abilities\Facebook;

use DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt;
use DataMachineSocials\Handlers\Facebook\FacebookGraphClient;

defined( 'ABSPATH' ) || exit;

final class FacebookPromotionApproval {

	/** Pending-action kind: activate a newly staged promotion. */
	public const KIND = 'socials_facebook_promotion';

	/** Pending-action kind: apply an edit to a live promotion. */
	public const UPDATE_KIND = 'socials_facebook_promotion_update';

	private static bool $registered = false;

	/** Hook into the pending-action resolver once. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		add_filter( 'datamachine_pending_action_handlers', array( self::class, 'handlers' ) );
		add_action( 'datamachine_pending_action_resolved', array( self::class, 'on_resolved' ), 10, 2 );
		add_action( 'datamachine_pending_action_expired', array( self::class, 'on_expired' ) );
		self::$registered = true;
	}

	/**
	 * Resolver grants staged with every promotion: a CLI operator may
	 * resolve this kind. No agent grant.
	 */
	public static function resolver_grants(): array {
		return array(
			array(
				'type'  => 'operator',
				'kinds' => array( self::KIND, self::UPDATE_KIND ),
			),
		);
	}

	/**
	 * @param mixed $handlers Existing handler map.
	 * @return array
	 */
	public static function handlers( $handlers ): array {
		$handlers                      = is_array( $handlers ) ? $handlers : array();
		$handlers[ self::KIND ]        = array(
			'apply'       => array( self::class, 'apply' ),
			'can_resolve' => array( self::class, 'can_resolve' ),
		);
		$handlers[ self::UPDATE_KIND ] = array(
			'apply'       => array( self::class, 'apply_update' ),
			'can_resolve' => array( self::class, 'can_resolve' ),
		);
		return $handlers;
	}

	/**
	 * Who may approve or reject spend.
	 *
	 * @param mixed $payload  Pending action payload.
	 * @param mixed $decision accepted|rejected.
	 * @param mixed $user_id  Current user during resolution.
	 * @param mixed $context  Resolver context.
	 * @return true|\WP_Error
	 */
	public static function can_resolve( $payload, $decision, $user_id, $context = array() ) {
		unset( $payload, $decision );
		if ( is_array( $context ) && 'signed_url' === ( $context['resolution_transport'] ?? '' ) ) {
			return true;
		}
		$user_id = (int) $user_id;
		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error( 'spend_requires_admin', 'Only a site administrator can approve paid promotion spend.', array( 'status' => 403 ) );
	}

	/**
	 * Activate an approved promotion.
	 *
	 * Three parameters are required by the resolver's apply contract.
	 *
	 * @param array $input   Stored apply_input.
	 * @param array $payload Stored pending action payload.
	 * @param array $receipt One-time authorization receipt.
	 * @return array|\WP_Error
	 */
	public static function apply( array $input, array $payload, array $receipt ) {
		// Consume first: an approval can never be replayed into a second activation.
		$subject    = (string) ( $payload['agent'] ?? $payload['creator'] ?? '' );
		$workspace  = isset( $payload['workspace'] ) && is_array( $payload['workspace'] ) ? $payload['workspace'] : array();
		$authorized = PendingActionAuthorizationReceipt::consume( $receipt, self::KIND, self::KIND, $input, $input, $subject, $workspace );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$graph = FacebookGraphClient::for_ads();
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}

		$adset = $graph->get( '/' . rawurlencode( (string) ( $input['adset_id'] ?? '' ) ), array( 'fields' => 'lifetime_budget,start_time,end_time' ) );
		if ( is_wp_error( $adset ) ) {
			return $adset;
		}
		$ok = FacebookAdsPolicy::recheck(
			(int) ( $adset['lifetime_budget'] ?? PHP_INT_MAX ),
			(int) ( $input['currency_offset'] ?? 100 ),
			(int) strtotime( (string) ( $adset['start_time'] ?? '' ) ),
			(int) strtotime( (string) ( $adset['end_time'] ?? '' ) ),
			FacebookAdsPolicy::limits()
		);
		if ( is_wp_error( $ok ) ) {
			self::delete_campaign( $graph, (string) ( $input['campaign_id'] ?? '' ) );
			return $ok;
		}

		// Campaign last: nothing can deliver until every layer is active.
		foreach ( array( 'ad_id', 'adset_id', 'campaign_id' ) as $key ) {
			$res = $graph->post( '/' . rawurlencode( (string) ( $input[ $key ] ?? '' ) ), array( 'status' => 'ACTIVE' ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		return array(
			'success'     => true,
			'status'      => 'active',
			'campaign_id' => (string) ( $input['campaign_id'] ?? '' ),
		);
	}

	/**
	 * Apply an approved edit to a live promotion's ad set.
	 *
	 * Re-reads the ad set and re-checks the resulting budget and schedule
	 * against the current hard limits before writing. A rejected or
	 * expired edit changes nothing; the promotion keeps running as it was.
	 *
	 * @param array $input   Stored apply_input: adset_id, fields, currency_offset.
	 * @param array $payload Stored pending action payload.
	 * @param array $receipt One-time authorization receipt.
	 * @return array|\WP_Error
	 */
	public static function apply_update( array $input, array $payload, array $receipt ) {
		$subject    = (string) ( $payload['agent'] ?? $payload['creator'] ?? '' );
		$workspace  = isset( $payload['workspace'] ) && is_array( $payload['workspace'] ) ? $payload['workspace'] : array();
		$authorized = PendingActionAuthorizationReceipt::consume( $receipt, self::UPDATE_KIND, self::UPDATE_KIND, $input, $input, $subject, $workspace );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$graph = FacebookGraphClient::for_ads();
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}

		$adset_id = (string) ( $input['adset_id'] ?? '' );
		$fields   = is_array( $input['fields'] ?? null ) ? $input['fields'] : array();
		$adset    = $graph->get( '/' . rawurlencode( $adset_id ), array( 'fields' => 'lifetime_budget,start_time,end_time' ) );
		if ( is_wp_error( $adset ) ) {
			return $adset;
		}

		$ok = FacebookAdsPolicy::recheck(
			(int) ( $fields['lifetime_budget'] ?? $adset['lifetime_budget'] ?? PHP_INT_MAX ),
			(int) ( $input['currency_offset'] ?? 100 ),
			(int) strtotime( (string) ( $adset['start_time'] ?? '' ) ),
			(int) strtotime( (string) ( $fields['end_time'] ?? $adset['end_time'] ?? '' ) ),
			FacebookAdsPolicy::limits()
		);
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		if ( isset( $fields['targeting'] ) && is_array( $fields['targeting'] ) ) {
			$fields['targeting']['targeting_automation'] = array( 'advantage_audience' => 0 );
		}

		$res = $graph->post( '/' . rawurlencode( $adset_id ), $fields );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return array(
			'success'  => true,
			'status'   => 'updated',
			'adset_id' => $adset_id,
			'changed'  => array_keys( $fields ),
		);
	}

	/**
	 * @param mixed $action   WP_Agent_Pending_Action.
	 * @param mixed $decision WP_Agent_Approval_Decision.
	 */
	public static function on_resolved( $action, $decision ): void {
		if ( is_object( $decision ) && method_exists( $decision, 'is_rejected' ) && $decision->is_rejected() ) {
			self::cleanup( $action );
		}
	}

	/**
	 * @param mixed $action WP_Agent_Pending_Action.
	 */
	public static function on_expired( $action ): void {
		self::cleanup( $action );
	}

	/**
	 * Delete a campaign (cascades to its ad sets and ads).
	 *
	 * @param FacebookGraphClient $graph       Authorized client.
	 * @param string              $campaign_id Campaign ID.
	 */
	public static function delete_campaign( FacebookGraphClient $graph, string $campaign_id ): void {
		if ( '' !== $campaign_id ) {
			$graph->delete( '/' . rawurlencode( $campaign_id ) );
		}
	}

	/**
	 * @param mixed $action WP_Agent_Pending_Action.
	 */
	private static function cleanup( $action ): void {
		if ( ! is_object( $action ) || ! method_exists( $action, 'get_kind' ) || self::KIND !== $action->get_kind() ) {
			return;
		}
		$graph = FacebookGraphClient::for_ads();
		if ( ! is_wp_error( $graph ) ) {
			self::delete_campaign( $graph, (string) ( ( (array) $action->get_apply_input() )['campaign_id'] ?? '' ) );
		}
	}
}
