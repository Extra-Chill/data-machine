<?php
/**
 * Facebook paid promotion tests (#281).
 *
 * Covers the hard-limit policy, the scope split between posting and spend,
 * the resolver contract of the approval handler, and who may approve.
 * End-to-end behaviour against the pending-action resolver (stage, signed
 * approve/reject, CLI accept, agent denial, live-budget recheck) was
 * verified on the production host with the Graph API intercepted; see #281.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Facebook
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Facebook;

use DataMachineSocials\Abilities\Facebook\FacebookAdsReadAbility;
use DataMachineSocials\Abilities\Facebook\FacebookAdsPromoteAbility;
use DataMachineSocials\Abilities\Facebook\FacebookPromotionApproval;
use DataMachineSocials\Abilities\Facebook\FacebookAdsPolicy;
use DataMachineSocials\Handlers\Facebook\FacebookAuth;
use WP_UnitTestCase;

class FacebookAdsTest extends WP_UnitTestCase {

	private const NOW = 1790000000;

	private function limits(): array {
		return array(
			'max_lifetime_budget' => 75.0,
			'max_duration_days'   => 10,
		);
	}

	private function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'budget'       => 50,
				'end_time'     => self::NOW + 5 * DAY_IN_SECONDS,
				'latitude'     => 32.8335,
				'longitude'    => -79.9567,
				'radius_miles' => 15,
				'link'         => 'https://events.extrachill.com/events/x',
				'message'      => 'Free beer.',
				'image_url'    => 'https://extrachill.com/poster.png',
			),
			$overrides
		);
	}

	public function tear_down(): void {
		delete_site_option( FacebookAdsPolicy::OPTION );
		delete_site_option( 'datamachine_auth_data' );
		parent::tear_down();
	}

	public function test_valid_promotion_normalizes(): void {
		$p = FacebookAdsPolicy::validate( $this->input(), $this->limits(), 'act_123', self::NOW );

		$this->assertIsArray( $p );
		$this->assertSame( 'act_123', $p['ad_account_id'] );
		$this->assertSame( self::NOW + 15 * MINUTE_IN_SECONDS, $p['start_time'] );
		$this->assertSame( 21, $p['age_min'] );
	}

	/**
	 * @dataProvider refusals
	 */
	public function test_hard_limits_refuse( array $overrides, string $code ): void {
		$result = FacebookAdsPolicy::validate( $this->input( $overrides ), $this->limits(), 'act_123', self::NOW );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public static function refusals(): array {
		return array(
			'over budget'      => array( array( 'budget' => 75.01 ), 'budget_over_limit' ),
			'zero budget'      => array( array( 'budget' => 0 ), 'invalid_budget' ),
			'over duration'    => array( array( 'end_time' => self::NOW + 11 * DAY_IN_SECONDS ), 'duration_over_limit' ),
			'past start'       => array( array( 'start_time' => self::NOW - HOUR_IN_SECONDS ), 'invalid_schedule' ),
			'end before start' => array( array( 'start_time' => self::NOW + DAY_IN_SECONDS, 'end_time' => self::NOW + HOUR_IN_SECONDS ), 'invalid_schedule' ),
			'radius too big'   => array( array( 'radius_miles' => 51 ), 'invalid_location' ),
			'under 18'         => array( array( 'age_min' => 13 ), 'invalid_age' ),
			'http link'        => array( array( 'link' => 'http://example.com' ), 'invalid_link' ),
			'no creative'      => array( array( 'image_url' => '' ), 'invalid_creative' ),
			'too many interests' => array( array( 'interests' => array_fill( 0, 11, 'music' ) ), 'too_many_interests' ),
			'unknown platform' => array( array( 'platforms' => array( 'tiktok' ) ), 'invalid_platforms' ),
			'two source posts' => array( array( 'post_id' => '1', 'instagram_media_id' => '2' ), 'invalid_creative' ),
			'bad ig media id'  => array( array( 'instagram_media_id' => 'abc' ), 'invalid_creative' ),
		);
	}

	public function test_placements_default_and_instagram_post_boost(): void {
		$link = FacebookAdsPolicy::validate( $this->input(), $this->limits(), 'act_123', self::NOW );
		$this->assertSame( FacebookAdsPolicy::PLATFORMS, $link['platforms'] );

		$boost = FacebookAdsPolicy::validate(
			$this->input(
				array(
					'instagram_media_id' => '17899513059669654',
					'message'            => '',
					'image_url'          => '',
				)
			),
			$this->limits(),
			'act_123',
			self::NOW
		);
		$this->assertSame( array( 'instagram' ), $boost['platforms'], 'Boosting an Instagram post defaults to Instagram only' );
		$this->assertSame( '17899513059669654', $boost['ig_media_id'] );
		$this->assertSame( 'Existing Instagram post 17899513059669654', FacebookAdsPolicy::preview( $boost, array(), 'USD' )['creative'] );

		$both = FacebookAdsPolicy::validate( $this->input( array( 'platforms' => 'Instagram, facebook' ) ), $this->limits(), 'act_123', self::NOW );
		$this->assertSame( array( 'instagram', 'facebook' ), $both['platforms'] );
		$this->assertSame( array( 'instagram', 'facebook' ), FacebookAdsPolicy::targeting( $both, array() )['publisher_platforms'] );
	}

	public function test_requires_a_configured_ad_account(): void {
		$result = FacebookAdsPolicy::validate( $this->input(), $this->limits(), '', self::NOW );

		$this->assertSame( 'ads_not_configured', $result->get_error_code() );
	}

	public function test_recheck_refuses_live_budget_above_limit(): void {
		$this->assertTrue( FacebookAdsPolicy::recheck( 7500, 100, self::NOW, self::NOW + DAY_IN_SECONDS, $this->limits() ) );
		$this->assertWPError( FacebookAdsPolicy::recheck( 9000, 100, self::NOW, self::NOW + DAY_IN_SECONDS, $this->limits() ) );
		$this->assertWPError( FacebookAdsPolicy::recheck( 1000, 100, self::NOW, self::NOW + 11 * DAY_IN_SECONDS, $this->limits() ) );
	}

	public function test_targeting_is_local_and_locks_advantage_audience(): void {
		$p = FacebookAdsPolicy::validate( $this->input( array( 'platforms' => array( 'facebook' ) ) ), $this->limits(), 'act_123', self::NOW );
		$t = FacebookAdsPolicy::targeting( $p, array( array( 'id' => '1', 'name' => 'Live music' ) ) );

		$this->assertSame( 15, $t['geo_locations']['custom_locations'][0]['radius'] );
		$this->assertSame( 'mile', $t['geo_locations']['custom_locations'][0]['distance_unit'] );
		$this->assertSame( 0, $t['targeting_automation']['advantage_audience'] );
		$this->assertSame( array( 'facebook' ), $t['publisher_platforms'] );
		$this->assertArrayNotHasKey( 'custom_audiences', $t );
	}

	public function test_currency_offset(): void {
		$this->assertSame( 100, FacebookAdsPolicy::currency_offset( 'USD' ) );
		$this->assertSame( 1, FacebookAdsPolicy::currency_offset( 'JPY' ) );
	}

	public function test_limits_round_trip_and_reject_bad_values(): void {
		FacebookAdsPolicy::save_limits( array( 'max_lifetime_budget' => 40 ) );

		$this->assertSame( 40.0, FacebookAdsPolicy::limits()['max_lifetime_budget'] );
		$this->assertSame( FacebookAdsPolicy::DEFAULT_MAX_DURATION_DAYS, FacebookAdsPolicy::limits()['max_duration_days'] );
		$this->assertWPError( FacebookAdsPolicy::save_limits( array( 'max_lifetime_budget' => 0 ) ) );
	}

	public function test_ad_account_lives_in_facebook_config_without_clobbering_it(): void {
		$auth = new FacebookAuth();
		$auth->save_config(
			array(
				'app_id'     => 'id_1',
				'app_secret' => 'secret_1',
			)
		);

		$this->assertTrue( $auth->set_ad_account_id( '987' ) );
		$this->assertSame( 'act_987', $auth->get_ad_account_id() );
		$this->assertSame( 'id_1', $auth->get_config()['app_id'] );
		$this->assertFalse( $auth->set_ad_account_id( 'act_abc' ) );
		$this->assertSame( 'act_987', $auth->get_ad_account_id() );
		// Declared so the admin settings form round-trips it instead of dropping it.
		$this->assertArrayHasKey( 'ad_account_id', $auth->get_config_fields() );
		$this->assertFalse( $auth->get_config_fields()['ad_account_id']['required'] );
	}

	public function test_posting_connection_never_requests_ads_scopes(): void {
		$this->assertStringNotContainsString( 'ads_management', FacebookAuth::SCOPES );
		$this->assertStringContainsString( 'ads_management', FacebookAuth::ADS_SCOPES );
	}

	public function test_apply_handler_matches_resolver_contract(): void {
		// ResolvePendingActionAbility::isApplyHandler() requires >= 3 params
		// (input, payload, receipt); fewer is treated as "no handler".
		$reflection = new \ReflectionMethod( FacebookPromotionApproval::class, 'apply' );

		$this->assertGreaterThanOrEqual( 3, $reflection->getNumberOfParameters() );
	}

	public function test_only_admins_or_signed_links_may_resolve(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}

		$this->assertTrue( FacebookPromotionApproval::can_resolve( array(), 'accepted', $admin ) );
		$this->assertWPError( FacebookPromotionApproval::can_resolve( array(), 'accepted', $editor ) );
		$this->assertWPError( FacebookPromotionApproval::can_resolve( array(), 'accepted', 0 ) );
		$this->assertTrue( FacebookPromotionApproval::can_resolve( array(), 'accepted', 0, array( 'resolution_transport' => 'signed_url' ) ) );
	}

	private function live_adset(): array {
		return array(
			'lifetime_budget' => 5000,
			'start_time'      => gmdate( 'c', self::NOW ),
			'end_time'        => gmdate( 'c', self::NOW + 9 * DAY_IN_SECONDS ),
			'targeting'       => array(
				'geo_locations'        => array(
					'custom_locations' => array(
						array(
							'latitude'      => 32.8335,
							'longitude'     => -79.9567,
							'radius'        => 15,
							'distance_unit' => 'mile',
						),
					),
				),
				'age_min'              => 21,
				'age_max'              => 65,
				'publisher_platforms'  => array( 'instagram' ),
				'targeting_automation' => array( 'advantage_audience' => 0 ),
				'flexible_spec'        => array( array( 'interests' => array( array( 'id' => '1', 'name' => 'Beer' ) ) ) ),
			),
		);
	}

	public function test_plan_update_edits_only_what_was_asked(): void {
		$plan = FacebookAdsPolicy::plan_update( $this->live_adset(), array( 'budget' => 70 ), null, $this->limits(), 100, 10.0, self::NOW );

		$this->assertSame( array( 'lifetime_budget' => 7000 ), $plan['fields'] );
		$this->assertSame( '50.00 lifetime', $plan['before']['budget'] );
		$this->assertSame( '70.00 lifetime', $plan['after']['budget'] );
	}

	public function test_plan_update_replaces_interests_and_keeps_audience_locked(): void {
		$adset = $this->live_adset();
		unset( $adset['targeting']['targeting_automation'] );

		$plan = FacebookAdsPolicy::plan_update( $adset, array( 'radius_miles' => 20 ), array( array( 'id' => '2', 'name' => 'GitHub' ) ), $this->limits(), 100, 0.0, self::NOW );

		$t = $plan['fields']['targeting'];
		$this->assertSame( 20, $t['geo_locations']['custom_locations'][0]['radius'] );
		$this->assertSame( 'GitHub', $t['flexible_spec'][0]['interests'][0]['name'] );
		$this->assertSame( array( 'instagram' ), $t['publisher_platforms'] );
		$this->assertSame( 0, $t['targeting_automation']['advantage_audience'] );
	}

	/**
	 * @dataProvider update_refusals
	 */
	public function test_plan_update_refuses( array $changes, ?array $interests, float $spent, string $code ): void {
		$result = FacebookAdsPolicy::plan_update( $this->live_adset(), $changes, $interests, $this->limits(), 100, $spent, self::NOW );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public static function update_refusals(): array {
		return array(
			'over budget cap'   => array( array( 'budget' => 76 ), null, 0.0, 'budget_over_limit' ),
			'below spend'       => array( array( 'budget' => 20 ), null, 25.0, 'budget_below_spend' ),
			'over duration cap' => array( array( 'end_time' => self::NOW + 11 * DAY_IN_SECONDS ), null, 0.0, 'duration_over_limit' ),
			'end in past'       => array( array( 'end_time' => self::NOW - DAY_IN_SECONDS ), null, 0.0, 'invalid_schedule' ),
			'radius too big'    => array( array( 'radius_miles' => 80 ), null, 0.0, 'invalid_location' ),
			'too young'         => array( array( 'age_min' => 13 ), null, 0.0, 'invalid_age' ),
			'too many'          => array( array(), array_fill( 0, 11, array( 'id' => '9', 'name' => 'x' ) ), 0.0, 'too_many_interests' ),
			'no changes'        => array( array(), null, 0.0, 'nothing_to_update' ),
		);
	}

	public function test_exact_interest_match_never_guesses(): void {
		$candidates = array(
			array( 'id' => '1', 'name' => 'App Store (iOS)' ),
			array( 'id' => '2', 'name' => 'Open-source software' ),
		);

		$this->assertNull( FacebookAdsReadAbility::exact_match( 'open source', $candidates ) );
		$this->assertSame( '2', FacebookAdsReadAbility::exact_match( 'open-source SOFTWARE', $candidates )['id'] );
	}

	/**
	 * Shape taken from the real Graph read of campaign 52568045328948
	 * (Lo-Fi meetup boost): lifetime_budget is in cents, spend in dollars.
	 */
	public function test_summarize_campaign_exposes_budget_targeting_and_destination(): void {
		$campaign = FacebookAdsReadAbility::summarize_campaign(
			array(
				'id'        => '52568045328948',
				'objective' => 'OUTCOME_TRAFFIC',
				'insights'  => array( 'data' => array( array( 'spend' => '0.85' ) ) ),
				'adsets'    => array(
					'data' => array(
						array(
							'optimization_goal' => 'LINK_CLICKS',
							'lifetime_budget'   => '5000',
							'daily_budget'      => '0',
							'destination_type'  => 'WEBSITE',
							'targeting'         => array(
								'age_min'       => 21,
								'age_max'       => 65,
								'flexible_spec' => array(
									array( 'interests' => array( array( 'name' => 'WordPress' ), array( 'name' => 'Beer' ) ) ),
									array( 'interests' => array( array( 'name' => 'Photography' ) ) ),
								),
							),
						),
					),
				),
				'ads'       => array(
					'data' => array(
						array(
							'id'       => '52568045352948',
							'creative' => array(
								'call_to_action'            => array( 'value' => array( 'link' => 'https://events.extrachill.com/events/x' ) ),
								'source_instagram_media_id' => '18335726839277941',
								'instagram_permalink_url'   => 'https://www.instagram.com/p/DeXyHQUA-VQ/',
							),
						),
					),
				),
			)
		);

		$adset = $campaign['adsets']['data'][0];
		$this->assertSame( 50.0, $adset['budget'] );
		$this->assertSame( 'lifetime', $adset['budget_type'] );
		$this->assertSame( 49.15, $adset['remaining_budget'] );
		$this->assertSame( array( 'WordPress', 'Beer', 'Photography' ), $adset['targeting_summary']['interests'] );
		$this->assertSame( 21, $adset['targeting_summary']['age_min'] );

		$ad = $campaign['ads']['data'][0];
		$this->assertSame( 'https://events.extrachill.com/events/x', $ad['destination_url'] );
		$this->assertSame( '18335726839277941', $ad['source_post']['instagram_media_id'] );
	}

	public function test_summarize_campaign_daily_budget_has_no_remaining(): void {
		$campaign = FacebookAdsReadAbility::summarize_campaign(
			array(
				'adsets' => array( 'data' => array( array( 'daily_budget' => '1000' ) ) ),
			)
		);

		$this->assertSame( 10.0, $campaign['adsets']['data'][0]['budget'] );
		$this->assertSame( 'daily', $campaign['adsets']['data'][0]['budget_type'] );
		$this->assertNull( $campaign['adsets']['data'][0]['remaining_budget'] );
	}

	public function test_default_campaign_url_tags_use_a_slug(): void {
		$this->assertSame( 'utm_source=meta&utm_medium=paid_social&utm_campaign=october-meetup-boost', FacebookAdsPromoteAbility::defaultUrlTags( 'October Meetup Boost!' ) );
	}

	public function test_operator_grant_covers_create_and_update_only(): void {
		$grant = FacebookPromotionApproval::resolver_grants()[0];

		$this->assertSame( 'operator', $grant['type'] );
		$this->assertSame( array( FacebookPromotionApproval::KIND, FacebookPromotionApproval::UPDATE_KIND ), $grant['kinds'] );
		$this->assertGreaterThanOrEqual( 3, ( new \ReflectionMethod( FacebookPromotionApproval::class, 'apply_update' ) )->getNumberOfParameters() );
	}
}
