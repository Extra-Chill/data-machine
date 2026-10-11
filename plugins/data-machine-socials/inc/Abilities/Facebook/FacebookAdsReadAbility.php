<?php
/**
 * Facebook Ads Read Ability
 *
 * Read-only paid-promotion abilities (#281):
 *
 * - datamachine/facebook-ad-accounts    Ad accounts the connected user can see.
 * - datamachine/facebook-ads-interests  Resolve an interest keyword to Meta IDs.
 * - datamachine/facebook-ads-report     Spend, reach, impressions, link clicks.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Facebook
 */

namespace DataMachineSocials\Abilities\Facebook;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\Abilities\AbstractSocialAbility;
use DataMachineSocials\Handlers\Facebook\FacebookGraphClient;

defined( 'ABSPATH' ) || exit;

class FacebookAdsReadAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	private const INSIGHT_FIELDS = 'spend,reach,impressions,inline_link_clicks,cpc';

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			$base = array(
				'category'            => 'datamachine-socials',
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			);

			wp_register_ability(
				'datamachine/facebook-ad-accounts',
				$base + array(
					'label'            => __( 'List Facebook Ad Accounts', 'data-machine-socials' ),
					'description'      => __( 'List Meta ad accounts available to the connected Facebook user.', 'data-machine-socials' ),
					'input_schema'     => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'execute_callback' => array( $this, 'listAdAccounts' ),
				)
			);

			wp_register_ability(
				'datamachine/facebook-ads-interests',
				$base + array(
					'label'            => __( 'Search Facebook Ad Interests', 'data-machine-socials' ),
					'description'      => __( 'Resolve an interest keyword to Meta ad targeting interests.', 'data-machine-socials' ),
					'input_schema'     => array(
						'type'       => 'object',
						'properties' => array(
							'query' => array( 'type' => 'string' ),
							'limit' => array( 'type' => 'integer' ),
						),
						'required'   => array( 'query' ),
					),
					'execute_callback' => array( $this, 'searchInterests' ),
				)
			);

			wp_register_ability(
				'datamachine/facebook-ads-report',
				$base + array(
					'label'            => __( 'Facebook Ads Report', 'data-machine-socials' ),
					'description'      => __( 'Spend, reach, impressions and link clicks for promotions in the configured ad account.', 'data-machine-socials' ),
					'input_schema'     => array(
						'type'       => 'object',
						'properties' => array(
							'campaign_id' => array( 'type' => 'string' ),
						),
					),
					'execute_callback' => array( $this, 'report' ),
				)
			);
		};
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	/** @return array|\WP_Error */
	public function listAdAccounts( array $input = array() ) {
		unset( $input );
		$graph = FacebookGraphClient::for_ads();
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}
		$res = $graph->get( '/me/adaccounts', array( 'fields' => 'id,name,account_status,currency' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$auth = FacebookGraphClient::auth();
		return array(
			'success'  => true,
			'accounts' => $res['data'] ?? array(),
			'selected' => $auth ? $auth->get_ad_account_id() : '',
		);
	}

	/** @return array|\WP_Error */
	public function searchInterests( array $input ) {
		$graph = FacebookGraphClient::for_ads();
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}
		return self::interests( $graph, (string) ( $input['query'] ?? '' ), (int) ( $input['limit'] ?? 10 ) );
	}

	/**
	 * Interest search shared with the promote ability.
	 *
	 * @param FacebookGraphClient $graph Authorized client.
	 * @param string              $query Keyword.
	 * @param int                 $limit Max results (1-25).
	 * @return array|\WP_Error
	 */
	public static function interests( FacebookGraphClient $graph, string $query, int $limit ) {
		$query = trim( $query );
		if ( '' === $query ) {
			return new \WP_Error( 'missing_param', 'query is required', array( 'status' => 400 ) );
		}
		$res = $graph->get(
			'/search',
			array(
				'type'  => 'adinterest',
				'q'     => $query,
				'limit' => max( 1, min( 25, $limit ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'success'   => true,
			'interests' => array_map(
				static fn( $i ) => array(
					'id'            => (string) ( $i['id'] ?? '' ),
					'name'          => (string) ( $i['name'] ?? '' ),
					'audience_size' => $i['audience_size_upper_bound'] ?? null,
				),
				(array) ( $res['data'] ?? array() )
			),
		);
	}

	/** @return array|\WP_Error */
	public function report( array $input = array() ) {
		$graph = FacebookGraphClient::for_ads();
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}
		$fields   = 'id,name,status,effective_status,insights.date_preset(maximum){' . self::INSIGHT_FIELDS . '}';
		$campaign = trim( (string) ( $input['campaign_id'] ?? '' ) );

		if ( '' !== $campaign ) {
			$res = $graph->get( '/' . rawurlencode( $campaign ), array( 'fields' => $fields ) );
			return is_wp_error( $res ) ? $res : array(
				'success'   => true,
				'campaigns' => array( $res ),
			);
		}

		$auth    = FacebookGraphClient::auth();
		$account = $auth ? $auth->get_ad_account_id() : '';
		if ( '' === $account ) {
			return new \WP_Error( 'ads_not_configured', 'No ad account selected.' );
		}
		$res = $graph->get(
			'/' . $account . '/campaigns',
			array(
				'fields' => $fields,
				'limit'  => 25,
			)
		);
		return is_wp_error( $res ) ? $res : array(
			'success'   => true,
			'campaigns' => $res['data'] ?? array(),
		);
	}
}
