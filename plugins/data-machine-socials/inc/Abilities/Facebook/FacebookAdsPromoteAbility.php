<?php
/**
 * Facebook Ads Promote Ability
 *
 * datamachine/facebook-ads-promote (#281): validates a promotion against
 * the hard limits, creates a PAUSED campaign / ad set / creative / ad,
 * and stages it for human approval. It never activates spend; see
 * FacebookPromotionApproval for the gate.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Facebook
 */

namespace DataMachineSocials\Abilities\Facebook;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Engine\AI\Actions\PendingActionHelper;
use DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility;
use DataMachineSocials\Abilities\AbstractSocialAbility;
use DataMachineSocials\Handlers\Facebook\FacebookGraphClient;

defined( 'ABSPATH' ) || exit;

class FacebookAdsPromoteAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	/** Approval window; unapproved promotions are deleted when it lapses. */
	private const APPROVAL_TTL = 2 * DAY_IN_SECONDS;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
		FacebookPromotionApproval::register();
	}

	private function registerCallback(): callable {
		return function () {
			$string = array( 'type' => 'string' );

			wp_register_ability(
				'datamachine/facebook-ads-promote',
				array(
					'label'               => __( 'Stage Facebook Promotion', 'data-machine-socials' ),
					'description'         => __( 'Create a PAUSED, budget-capped Meta link-click promotion and stage it for human approval. Never activates spend.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'name'               => $string,
							'budget'             => array(
								'type'        => 'number',
								'description' => 'Lifetime budget in the ad account currency (major units). Capped by the hard limit.',
							),
							'start_time'         => $string,
							'end_time'           => $string,
							'latitude'           => array( 'type' => 'number' ),
							'longitude'          => array( 'type' => 'number' ),
							'radius_miles'       => array( 'type' => 'integer' ),
							'age_min'            => array( 'type' => 'integer' ),
							'age_max'            => array( 'type' => 'integer' ),
							'interests'          => array(
								'type'  => 'array',
								'items' => $string,
							),
							'link'               => array(
								'type'        => 'string',
								'description' => 'Destination URL (e.g. the event RSVP page).',
							),
							'post_id'            => array(
								'type'        => 'string',
								'description' => 'Existing Page post to promote. Omit to build a link ad from message + image.',
							),
							'instagram_media_id' => array(
								'type'        => 'string',
								'description' => 'Existing Instagram post (numeric media ID) to boost. Defaults placements to Instagram only.',
							),
							'platforms'          => array(
								'type'        => 'array',
								'items'       => array(
									'type' => 'string',
									'enum' => FacebookAdsPolicy::PLATFORMS,
								),
								'description' => 'Placements: facebook and/or instagram. Default both (Instagram only when boosting an Instagram post).',
							),
							'message'            => $string,
							'headline'           => $string,
							'image_path'         => $string,
							'image_url'          => $string,
						),
						'required'   => array( 'budget', 'end_time', 'latitude', 'longitude', 'radius_miles', 'link' ),
					),
					'output_schema'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						// Staging-only, but approved spend is real money: keep it off the
						// generic REST runner; CLI and in-process agents use it.
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
		if ( ! $auth ) {
			return new \WP_Error( 'missing_auth', 'Facebook auth provider not available', array( 'status' => 401 ) );
		}

		$promotion = FacebookAdsPolicy::validate( $input, FacebookAdsPolicy::limits(), $auth->get_ad_account_id(), time() );
		if ( is_wp_error( $promotion ) ) {
			return $promotion;
		}

		$graph = FacebookGraphClient::for_ads();
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}

		$page_id = (string) $auth->get_page_id();
		if ( '' === $page_id ) {
			return new \WP_Error( 'missing_page', 'No Facebook Page connected.' );
		}

		$act     = '/' . $promotion['ad_account_id'];
		$account = $graph->get( $act, array( 'fields' => 'currency,account_status' ) );
		if ( is_wp_error( $account ) ) {
			return $account;
		}
		if ( 1 !== (int) ( $account['account_status'] ?? 0 ) ) {
			return new \WP_Error( 'ad_account_inactive', 'The selected ad account is not active.' );
		}
		$currency = (string) ( $account['currency'] ?? 'USD' );
		$offset   = FacebookAdsPolicy::currency_offset( $currency );

		$interests = FacebookAdsReadAbility::resolve_interests( $graph, $promotion['interests'] );
		if ( is_wp_error( $interests ) ) {
			return $interests;
		}

		$instagram_id = self::instagramUserId();
		if ( '' === $instagram_id && ( in_array( 'instagram', $promotion['platforms'], true ) || '' !== $promotion['ig_media_id'] ) ) {
			return new \WP_Error( 'instagram_not_connected', 'Instagram placements need a connected Instagram business account.' );
		}
		$ids = array();

		$campaign = $graph->post(
			$act . '/campaigns',
			array(
				'name'                            => $promotion['name'],
				'objective'                       => 'OUTCOME_TRAFFIC',
				'status'                          => 'PAUSED',
				'special_ad_categories'           => array(),
				'buying_type'                     => 'AUCTION',
				'is_adset_budget_sharing_enabled' => false,
			)
		);
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}
		$ids['campaign_id'] = (string) $campaign['id'];

		$steps = array(
			'adset_id'    => fn() => $graph->post(
				$act . '/adsets',
				array(
					'name'              => $promotion['name'] . ' - audience',
					'campaign_id'       => $ids['campaign_id'],
					'lifetime_budget'   => (int) round( $promotion['budget'] * $offset ),
					'start_time'        => gmdate( 'c', $promotion['start_time'] ),
					'end_time'          => gmdate( 'c', $promotion['end_time'] ),
					'billing_event'     => 'IMPRESSIONS',
					'optimization_goal' => 'LINK_CLICKS',
					'bid_strategy'      => 'LOWEST_COST_WITHOUT_CAP',
					'destination_type'  => 'WEBSITE',
					'targeting'         => FacebookAdsPolicy::targeting( $promotion, $interests ),
					'status'            => 'PAUSED',
				)
			),
			'creative_id' => function () use ( $graph, $act, $promotion, $page_id, $instagram_id ) {
				$spec = $this->creativeSpec( $graph, $promotion, $page_id, $instagram_id );
				return is_wp_error( $spec ) ? $spec : $graph->post( $act . '/adcreatives', $spec );
			},
			// By reference: adset_id / creative_id are filled in by earlier steps.
			'ad_id'       => function () use ( $graph, $act, $promotion, &$ids ) {
				return $graph->post(
					$act . '/ads',
					array(
						'name'     => $promotion['name'] . ' - ad',
						'adset_id' => $ids['adset_id'],
						'creative' => array( 'creative_id' => $ids['creative_id'] ),
						'status'   => 'PAUSED',
					)
				);
			},
		);
		foreach ( $steps as $key => $step ) {
			$res = $step();
			if ( is_wp_error( $res ) ) {
				FacebookPromotionApproval::delete_campaign( $graph, $ids['campaign_id'] );
				return $res;
			}
			$ids[ $key ] = (string) $res['id'];
		}

		$preview = FacebookAdsPolicy::preview( $promotion, $interests, $currency );
		$staged  = PendingActionHelper::stage(
			array(
				'kind'            => FacebookPromotionApproval::KIND,
				'summary'         => sprintf( 'Spend up to %s %s promoting "%s" to %s', number_format( $promotion['budget'], 2 ), strtoupper( $currency ), $promotion['name'], $preview['audience'] ),
				'apply_input'     => $ids + array(
					'ad_account_id'   => $promotion['ad_account_id'],
					'currency_offset' => $offset,
				),
				'preview_data'    => $preview,
				'ttl'             => self::APPROVAL_TTL,
				'resolver_grants' => FacebookPromotionApproval::resolver_grants(),
			)
		);
		if ( isset( $staged['staged'] ) && false === $staged['staged'] ) {
			FacebookPromotionApproval::delete_campaign( $graph, $ids['campaign_id'] );
			return new \WP_Error( 'stage_failed', (string) ( $staged['error'] ?? 'Failed to stage approval.' ) );
		}

		$action_id = (string) ( $staged['payload']['action_id'] ?? ( $staged['action_id'] ?? '' ) );

		return array(
			'success'   => true,
			'status'    => 'paused_pending_approval',
			'action_id' => $action_id,
			'ids'       => $ids,
			'preview'   => $preview,
			'links'     => self::approvalLinks( $action_id ),
			'approval'  => $staged,
		);
	}

	/**
	 * Signed approve/reject links for delivery to a human.
	 *
	 * @param string $action_id Pending action ID.
	 */
	public static function approvalLinks( string $action_id ): array {
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

	/**
	 * Ad creative spec: an existing Instagram post, an existing Page post, or a link ad.
	 *
	 * @return array|\WP_Error
	 */
	private function creativeSpec( FacebookGraphClient $graph, array $promotion, string $page_id, string $instagram_id ) {
		$ig = '' !== $instagram_id ? array( 'instagram_user_id' => $instagram_id ) : array();

		if ( '' !== $promotion['ig_media_id'] ) {
			// Boost the existing Instagram post itself, sending clicks to the destination.
			return array(
				'name'                      => $promotion['name'] . ' - creative',
				'object_id'                 => $page_id,
				'instagram_user_id'         => $instagram_id,
				'source_instagram_media_id' => $promotion['ig_media_id'],
				'call_to_action'            => array(
					'type'  => 'LEARN_MORE',
					'value' => array( 'link' => $promotion['link'] ),
				),
			);
		}

		if ( '' !== $promotion['post_id'] ) {
			return array(
				'name'            => $promotion['name'] . ' - creative',
				'object_story_id' => str_contains( $promotion['post_id'], '_' ) ? $promotion['post_id'] : $page_id . '_' . $promotion['post_id'],
			) + $ig;
		}

		$link_data = array(
			'link'           => $promotion['link'],
			'message'        => $promotion['message'],
			'call_to_action' => array(
				'type'  => 'LEARN_MORE',
				'value' => array( 'link' => $promotion['link'] ),
			),
		);
		if ( '' !== $promotion['headline'] ) {
			$link_data['name'] = $promotion['headline'];
		}

		if ( '' !== $promotion['image_path'] ) {
			$hash = $this->uploadImage( $graph, $promotion['ad_account_id'], $promotion['image_path'] );
			if ( is_wp_error( $hash ) ) {
				return $hash;
			}
			$link_data['image_hash'] = $hash;
		} else {
			$link_data['picture'] = $promotion['image_url'];
		}

		return array(
			'name'              => $promotion['name'] . ' - creative',
			'object_story_spec' => array(
				'page_id'   => $page_id,
				'link_data' => $link_data,
			) + $ig,
		);
	}

	/**
	 * Upload a local image to the ad account library.
	 *
	 * @return string|\WP_Error Image hash.
	 */
	private function uploadImage( FacebookGraphClient $graph, string $ad_account_id, string $path ) {
		if ( ! is_readable( $path ) ) {
			return new \WP_Error( 'invalid_creative', 'image_path is not readable.' );
		}
		$upload = $graph->post(
			'/' . $ad_account_id . '/adimages',
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Graph adimages accepts base64 bytes of a local file.
			array( 'bytes' => base64_encode( (string) file_get_contents( $path ) ) )
		);
		if ( is_wp_error( $upload ) ) {
			return $upload;
		}
		$image = is_array( $upload['images'] ?? null ) ? reset( $upload['images'] ) : null;
		return empty( $image['hash'] ) ? new \WP_Error( 'image_upload_failed', 'Meta did not return an image hash.' ) : (string) $image['hash'];
	}

	private static function instagramUserId(): string {
		if ( ! class_exists( '\DataMachine\Abilities\AuthAbilities' ) ) {
			return '';
		}
		$ig = ( new \DataMachine\Abilities\AuthAbilities() )->getProvider( 'instagram' );
		return ( $ig && method_exists( $ig, 'get_user_id' ) ) ? (string) $ig->get_user_id() : '';
	}
}
