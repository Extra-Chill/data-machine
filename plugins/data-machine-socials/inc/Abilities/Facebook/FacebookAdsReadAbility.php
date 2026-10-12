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

	private const INSIGHT_FIELDS = 'spend,reach,impressions,inline_link_clicks,cpc,actions';

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

	/**
	 * Resolve interest keywords to Meta interests, exactly (#285).
	 *
	 * Each entry is either a numeric Meta interest ID or a name. Names must
	 * match a search result exactly (case-insensitive); otherwise this
	 * refuses and lists the candidates instead of guessing.
	 *
	 * @param FacebookGraphClient $graph    Authorized client.
	 * @param string[]            $keywords Interest names or IDs.
	 * @return array<int, array{id: string, name: string}>|\WP_Error
	 */
	public static function resolve_interests( FacebookGraphClient $graph, array $keywords ) {
		$resolved = array();
		$ids      = array();

		foreach ( $keywords as $keyword ) {
			$keyword = trim( (string) $keyword );
			if ( '' === $keyword ) {
				continue;
			}
			if ( ctype_digit( $keyword ) ) {
				$ids[] = $keyword;
				continue;
			}

			$found = self::interests( $graph, $keyword, 10 );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			$match = self::exact_match( $keyword, $found['interests'] );
			if ( null === $match ) {
				$names = array_map( static fn( $i ) => $i['name'] . ' (' . $i['id'] . ')', array_slice( $found['interests'], 0, 5 ) );
				return new \WP_Error(
					'interest_not_exact',
					sprintf( 'No interest named exactly "%s". Candidates: %s. Pass the exact name or its numeric ID.', $keyword, $names ? implode( ', ', $names ) : 'none' )
				);
			}
			$resolved[ $match['id'] ] = array(
				'id'   => $match['id'],
				'name' => $match['name'],
			);
		}

		if ( $ids ) {
			$valid = $graph->get(
				'/search',
				array(
					'type'               => 'adinterestvalid',
					'interest_fbid_list' => $ids,
				)
			);
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$known = array();
			foreach ( (array) ( $valid['data'] ?? array() ) as $row ) {
				if ( ! empty( $row['valid'] ) && isset( $row['id'] ) ) {
					$known[ (string) $row['id'] ] = (string) ( $row['name'] ?? '' );
				}
			}
			foreach ( $ids as $id ) {
				if ( ! isset( $known[ $id ] ) ) {
					return new \WP_Error( 'interest_invalid', sprintf( 'Interest ID %s is not a valid Meta targeting interest.', $id ) );
				}
				$resolved[ $id ] = array(
					'id'   => $id,
					'name' => $known[ $id ],
				);
			}
		}

		return array_values( $resolved );
	}

	/**
	 * Exact (case-insensitive) name match among search results.
	 *
	 * @param string $name       Requested name.
	 * @param array  $candidates Search results ({id,name}).
	 * @return array{id: string, name: string}|null
	 */
	public static function exact_match( string $name, array $candidates ): ?array {
		$needle = mb_strtolower( trim( $name ) );
		foreach ( $candidates as $candidate ) {
			if ( mb_strtolower( trim( (string) ( $candidate['name'] ?? '' ) ) ) === $needle && '' !== (string) ( $candidate['id'] ?? '' ) ) {
				return array(
					'id'   => (string) $candidate['id'],
					'name' => (string) $candidate['name'],
				);
			}
		}
		return null;
	}

	/**
	 * Add derived detail to a campaign read: remaining budget, a targeting
	 * summary per ad set, and each ad's destination URL and source post.
	 *
	 * Meta returns ad set budgets in the account currency's minor units
	 * (cents for USD) and insight spend in major units, so budgets are
	 * converted before comparing. Interests are collected from every
	 * flexible_spec group.
	 *
	 * @param array $campaign Campaign node as returned by the Graph API.
	 * @return array
	 */
	public static function summarize_campaign( array $campaign ): array {
		$spend = (float) ( $campaign['insights']['data'][0]['spend'] ?? 0 );

		$adsets = (array) ( $campaign['adsets']['data'] ?? array() );
		foreach ( $adsets as $i => $adset ) {
			$lifetime = (float) ( $adset['lifetime_budget'] ?? 0 );
			$daily    = (float) ( $adset['daily_budget'] ?? 0 );
			$budget   = ( $lifetime > 0 ? $lifetime : $daily ) / 100;

			$targeting = (array) ( $adset['targeting'] ?? array() );
			$interests = array();
			foreach ( (array) ( $targeting['flexible_spec'] ?? array() ) as $group ) {
				foreach ( (array) ( $group['interests'] ?? array() ) as $interest ) {
					$name = (string) ( $interest['name'] ?? '' );
					if ( '' !== $name ) {
						$interests[] = $name;
					}
				}
			}

			$adsets[ $i ]['budget']            = $budget;
			$adsets[ $i ]['budget_type']       = $lifetime > 0 ? 'lifetime' : 'daily';
			$adsets[ $i ]['remaining_budget']  = $lifetime > 0 ? max( 0.0, round( $budget - $spend, 2 ) ) : null;
			$adsets[ $i ]['targeting_summary'] = array(
				'geo_locations' => $targeting['geo_locations'] ?? array(),
				'age_min'       => $targeting['age_min'] ?? null,
				'age_max'       => $targeting['age_max'] ?? null,
				'interests'     => array_values( array_unique( $interests ) ),
			);
		}
		if ( isset( $campaign['adsets'] ) ) {
			$campaign['adsets']['data'] = $adsets;
		}

		$ads = (array) ( $campaign['ads']['data'] ?? array() );
		foreach ( $ads as $i => $ad ) {
			$creative                     = (array) ( $ad['creative'] ?? array() );
			$ads[ $i ]['destination_url'] = $creative['call_to_action']['value']['link'] ?? null;
			$ads[ $i ]['source_post']     = array(
				'instagram_media_id'      => $creative['source_instagram_media_id'] ?? null,
				'instagram_permalink_url' => $creative['instagram_permalink_url'] ?? null,
			);
		}
		if ( isset( $campaign['ads'] ) ) {
			$campaign['ads']['data'] = $ads;
		}

		return $campaign;
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
			$detail_fields = 'id,name,status,effective_status,objective,start_time,stop_time,insights.date_preset(maximum){' . self::INSIGHT_FIELDS . '},adsets{optimization_goal,lifetime_budget,daily_budget,end_time,destination_type,targeting{geo_locations,age_min,age_max,flexible_spec}},ads{creative{call_to_action,source_instagram_media_id,instagram_permalink_url}}';
			$res           = $graph->get( '/' . rawurlencode( $campaign ), array( 'fields' => $detail_fields ) );
			if ( ! is_wp_error( $res ) ) {
				$res = self::summarize_campaign( $res );
			}
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
