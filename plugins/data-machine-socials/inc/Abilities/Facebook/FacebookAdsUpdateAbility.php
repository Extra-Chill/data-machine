<?php
/**
 * Facebook Ads Update Ability
 *
 * datamachine/facebook-ads-update: edit a live promotion's budget, end
 * date, location, ages or interests. Like creation, it never changes
 * anything directly — it plans the edit against the hard limits, shows a
 * before/after preview, and stages it for human approval. The approval
 * handler (FacebookPromotionApproval::apply_update) re-checks the limits
 * and writes the ad set.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Facebook
 */

namespace DataMachineSocials\Abilities\Facebook;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Engine\AI\Actions\PendingActionHelper;
use DataMachineSocials\Abilities\AbstractSocialAbility;
use DataMachineSocials\Handlers\Facebook\FacebookGraphClient;

defined( 'ABSPATH' ) || exit;

class FacebookAdsUpdateAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	/** Approval window for an edit. */
	private const APPROVAL_TTL = 2 * DAY_IN_SECONDS;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
		FacebookPromotionApproval::register();
	}

	private function registerCallback(): callable {
		return function () {
			$list = array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			);

			wp_register_ability(
				'datamachine/facebook-ads-update',
				array(
					'label'               => __( 'Stage Facebook Promotion Edit', 'data-machine-socials' ),
					'description'         => __( 'Plan an edit to a live promotion (budget, end date, location, ages, interests) and stage it for human approval. Never changes the ad directly.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'campaign_id'      => array( 'type' => 'string' ),
							'budget'           => array( 'type' => 'number' ),
							'end_time'         => array( 'type' => 'string' ),
							'radius_miles'     => array( 'type' => 'integer' ),
							'latitude'         => array( 'type' => 'number' ),
							'longitude'        => array( 'type' => 'number' ),
							'age_min'          => array( 'type' => 'integer' ),
							'age_max'          => array( 'type' => 'integer' ),
							'interests'        => $list + array( 'description' => 'Replace all interests (exact names or numeric IDs).' ),
							'add_interests'    => $list + array( 'description' => 'Add interests (exact names or numeric IDs).' ),
							'remove_interests' => $list + array( 'description' => 'Remove interests by exact name or ID.' ),
						),
						'required'   => array( 'campaign_id' ),
					),
					'output_schema'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => false,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
						),
					),
				)
			);
		};
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	/** @return array|\WP_Error */
	public function execute( array $input ) {
		$auth = FacebookGraphClient::auth();
		$act  = $auth ? $auth->get_ad_account_id() : '';
		if ( '' === $act ) {
			return new \WP_Error( 'ads_not_configured', 'No ad account selected.' );
		}

		$graph = FacebookGraphClient::for_ads();
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}

		$campaign_id = trim( (string) ( $input['campaign_id'] ?? '' ) );
		if ( '' === $campaign_id || ! ctype_digit( $campaign_id ) ) {
			return new \WP_Error( 'missing_param', 'campaign_id is required.', array( 'status' => 400 ) );
		}

		$campaign = $graph->get( '/' . $campaign_id, array( 'fields' => 'id,name,account_id,effective_status' ) );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}
		if ( 'act_' . (string) ( $campaign['account_id'] ?? '' ) !== $act ) {
			return new \WP_Error( 'foreign_campaign', 'That campaign is not in the configured ad account.' );
		}

		$adsets = $graph->get(
			'/' . $campaign_id . '/adsets',
			array(
				'fields' => 'id,lifetime_budget,start_time,end_time,targeting',
				'limit'  => 2,
			)
		);
		if ( is_wp_error( $adsets ) ) {
			return $adsets;
		}
		$rows = (array) ( $adsets['data'] ?? array() );
		if ( 1 !== count( $rows ) ) {
			return new \WP_Error( 'unsupported_campaign', 'Only campaigns with exactly one ad set can be edited here.' );
		}
		$adset = $rows[0];
		if ( empty( $adset['lifetime_budget'] ) ) {
			return new \WP_Error( 'unsupported_campaign', 'Only lifetime-budget ad sets (as created by promote) can be edited here.' );
		}

		$interests = $this->nextInterests( $graph, (array) ( $adset['targeting'] ?? array() ), $input );
		if ( is_wp_error( $interests ) ) {
			return $interests;
		}

		$account  = $graph->get( '/' . $act, array( 'fields' => 'currency' ) );
		$currency = is_wp_error( $account ) ? 'USD' : (string) ( $account['currency'] ?? 'USD' );
		$offset   = FacebookAdsPolicy::currency_offset( $currency );

		$insights = $graph->get( '/' . $campaign_id . '/insights', array( 'fields' => 'spend' ) );
		$spent    = is_wp_error( $insights ) ? 0.0 : (float) ( $insights['data'][0]['spend'] ?? 0 );

		$plan = FacebookAdsPolicy::plan_update( $adset, $input, $interests, FacebookAdsPolicy::limits(), $offset, $spent, time() );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$preview = array(
			'campaign' => (string) ( $campaign['name'] ?? $campaign_id ),
			'spent'    => number_format( $spent, 2 ) . ' ' . strtoupper( $currency ),
			'before'   => $plan['before'],
			'after'    => $plan['after'],
		);

		$staged = PendingActionHelper::stage(
			array(
				'kind'            => FacebookPromotionApproval::UPDATE_KIND,
				'summary'         => sprintf( 'Edit promotion "%s": %s', $preview['campaign'], implode( ', ', array_keys( $plan['fields'] ) ) ),
				'apply_input'     => array(
					'campaign_id'     => $campaign_id,
					'adset_id'        => (string) $adset['id'],
					'fields'          => $plan['fields'],
					'currency_offset' => $offset,
				),
				'preview_data'    => $preview,
				'ttl'             => self::APPROVAL_TTL,
				'resolver_grants' => FacebookPromotionApproval::resolver_grants(),
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
			'preview'   => $preview,
			'links'     => FacebookAdsPromoteAbility::approvalLinks( $action_id ),
		);
	}

	/**
	 * Interest set after the requested edit, or null when unchanged.
	 *
	 * @return array|null|\WP_Error
	 */
	private function nextInterests( FacebookGraphClient $graph, array $targeting, array $input ) {
		$replace = array_filter( (array) ( $input['interests'] ?? array() ) );
		$add     = array_filter( (array) ( $input['add_interests'] ?? array() ) );
		$remove  = array_filter( (array) ( $input['remove_interests'] ?? array() ) );

		if ( ! $replace && ! $add && ! $remove ) {
			return null;
		}
		if ( $replace && ( $add || $remove ) ) {
			return new \WP_Error( 'invalid_interests', 'Use interests (replace) or add/remove, not both.' );
		}

		if ( $replace ) {
			return FacebookAdsReadAbility::resolve_interests( $graph, $replace );
		}

		$current = array();
		foreach ( FacebookAdsPolicy::current_interests( $targeting ) as $interest ) {
			$current[ $interest['id'] ] = $interest;
		}

		if ( $add ) {
			$added = FacebookAdsReadAbility::resolve_interests( $graph, $add );
			if ( is_wp_error( $added ) ) {
				return $added;
			}
			foreach ( $added as $interest ) {
				$current[ $interest['id'] ] = $interest;
			}
		}

		foreach ( $remove as $needle ) {
			$needle = mb_strtolower( trim( (string) $needle ) );
			$hit    = false;
			foreach ( $current as $id => $interest ) {
				if ( (string) $id === $needle || mb_strtolower( $interest['name'] ) === $needle ) {
					unset( $current[ $id ] );
					$hit = true;
				}
			}
			if ( ! $hit ) {
				return new \WP_Error( 'interest_not_targeted', sprintf( '"%s" is not one of this promotion\'s interests.', $needle ) );
			}
		}

		return array_values( $current );
	}
}
