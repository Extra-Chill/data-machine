<?php
/**
 * Facebook Ads Policy
 *
 * Pure validation and payload building for paid Meta promotions (#281).
 * Holds the hard spend limits every promotion must satisfy — at staging
 * time and again at approval time — so the rules are testable without
 * WordPress or the Graph API.
 *
 * Product guardrails (Extra Chill doctrine): scoped promotion of a real
 * post or event to a local audience. Geo-radius + age + interest targeting
 * only — no custom/lookalike audiences, no retargeting, no always-on spend.
 *
 * @package    DataMachineSocials
 * @subpackage Abilities\Facebook
 */

namespace DataMachineSocials\Abilities\Facebook;

defined( 'ABSPATH' ) || exit;

final class FacebookAdsPolicy {

	/** Site option holding the hard spend limits. */
	public const OPTION = 'datamachine_socials_facebook_ads_limits';

	/** Default hard cap on one promotion's lifetime budget, in account major units. */
	public const DEFAULT_MAX_LIFETIME_BUDGET = 100;

	/** Default hard cap on one promotion's duration, in days. */
	public const DEFAULT_MAX_DURATION_DAYS = 14;

	/** Placements a promotion may use. */
	public const PLATFORMS = array( 'facebook', 'instagram' );

	private const MAX_RADIUS_MILES = 50;
	private const MIN_RADIUS_MILES = 1;
	private const MAX_INTERESTS    = 10;
	private const MIN_AGE          = 18;
	private const MAX_AGE          = 65;

	/** Currencies Meta bills without a minor unit (offset 1 instead of 100). */
	private const ZERO_DECIMAL_CURRENCIES = array( 'CLP', 'COP', 'CRC', 'HUF', 'ISK', 'IDR', 'JPY', 'KRW', 'PYG', 'TWD', 'VND' );

	/**
	 * Stored hard limits merged over defaults.
	 *
	 * @return array{max_lifetime_budget: float, max_duration_days: int}
	 */
	public static function limits(): array {
		$stored = get_site_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'max_lifetime_budget' => (float) ( $stored['max_lifetime_budget'] ?? self::DEFAULT_MAX_LIFETIME_BUDGET ),
			'max_duration_days'   => (int) ( $stored['max_duration_days'] ?? self::DEFAULT_MAX_DURATION_DAYS ),
		);
	}

	/**
	 * Persist hard limits (partial updates allowed).
	 *
	 * @param array $changes Keys: max_lifetime_budget, max_duration_days.
	 * @return array|\WP_Error Saved limits.
	 */
	public static function save_limits( array $changes ) {
		$limits = self::limits();

		if ( array_key_exists( 'max_lifetime_budget', $changes ) ) {
			$budget = (float) $changes['max_lifetime_budget'];
			if ( $budget <= 0 ) {
				return new \WP_Error( 'invalid_limit', 'max_lifetime_budget must be greater than 0.' );
			}
			$limits['max_lifetime_budget'] = $budget;
		}
		if ( array_key_exists( 'max_duration_days', $changes ) ) {
			$days = (int) $changes['max_duration_days'];
			if ( $days < 1 ) {
				return new \WP_Error( 'invalid_limit', 'max_duration_days must be at least 1.' );
			}
			$limits['max_duration_days'] = $days;
		}

		update_site_option( self::OPTION, $limits );
		return $limits;
	}

	/**
	 * Minor units per major unit for a Meta account currency.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	public static function currency_offset( string $currency ): int {
		return in_array( strtoupper( $currency ), self::ZERO_DECIMAL_CURRENCIES, true ) ? 1 : 100;
	}

	/**
	 * Validate and normalize a promotion request against hard limits.
	 *
	 * @param array  $input      Promotion input (see FacebookAdsPromoteAbility schema).
	 * @param array  $limits     Output of limits().
	 * @param string $ad_account Configured ad account (act_<id>), "" if unset.
	 * @param int    $now        Current Unix time.
	 * @return array|\WP_Error Normalized promotion.
	 */
	public static function validate( array $input, array $limits, string $ad_account, int $now ) {
		if ( '' === $ad_account ) {
			return new \WP_Error( 'ads_not_configured', 'No ad account selected. Run `wp datamachine-socials facebook ads-config --account=act_<id>` first.' );
		}

		$budget = (float) ( $input['budget'] ?? 0 );
		if ( $budget <= 0 ) {
			return new \WP_Error( 'invalid_budget', 'budget must be greater than 0.' );
		}
		if ( $budget > (float) $limits['max_lifetime_budget'] ) {
			return new \WP_Error( 'budget_over_limit', sprintf( 'budget %s exceeds the hard limit of %s per promotion.', self::money( $budget ), self::money( (float) $limits['max_lifetime_budget'] ) ) );
		}

		$start = self::to_time( $input['start_time'] ?? '', $now + 15 * MINUTE_IN_SECONDS );
		$end   = self::to_time( $input['end_time'] ?? '', 0 );
		if ( null === $start || null === $end || 0 === $end ) {
			return new \WP_Error( 'invalid_schedule', 'start_time (optional) and end_time (required) must be valid dates.' );
		}
		if ( $start < $now - 5 * MINUTE_IN_SECONDS ) {
			return new \WP_Error( 'invalid_schedule', 'start_time is in the past.' );
		}
		if ( $end <= $start ) {
			return new \WP_Error( 'invalid_schedule', 'end_time must be after start_time.' );
		}
		$max_seconds = (int) $limits['max_duration_days'] * DAY_IN_SECONDS;
		if ( $end - $start > $max_seconds ) {
			return new \WP_Error( 'duration_over_limit', sprintf( 'Promotion runs longer than the hard limit of %d days.', (int) $limits['max_duration_days'] ) );
		}

		$lat    = (float) ( $input['latitude'] ?? 0 );
		$lng    = (float) ( $input['longitude'] ?? 0 );
		$radius = (int) ( $input['radius_miles'] ?? 0 );
		if ( ! isset( $input['latitude'], $input['longitude'] ) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return new \WP_Error( 'invalid_location', 'latitude and longitude are required.' );
		}
		if ( $radius < self::MIN_RADIUS_MILES || $radius > self::MAX_RADIUS_MILES ) {
			return new \WP_Error( 'invalid_location', sprintf( 'radius_miles must be between %d and %d.', self::MIN_RADIUS_MILES, self::MAX_RADIUS_MILES ) );
		}

		$age_min = (int) ( $input['age_min'] ?? 21 );
		$age_max = (int) ( $input['age_max'] ?? self::MAX_AGE );
		if ( $age_min < self::MIN_AGE || $age_max > self::MAX_AGE || $age_min > $age_max ) {
			return new \WP_Error( 'invalid_age', sprintf( 'Ages must be within %d-%d with age_min <= age_max.', self::MIN_AGE, self::MAX_AGE ) );
		}

		$interests = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) ( $input['interests'] ?? array() ) ) ) ) );
		if ( count( $interests ) > self::MAX_INTERESTS ) {
			return new \WP_Error( 'too_many_interests', sprintf( 'At most %d interests.', self::MAX_INTERESTS ) );
		}

		$link = (string) ( $input['link'] ?? '' );
		if ( ! preg_match( '#^https://#i', $link ) || false === filter_var( $link, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_link', 'link must be an https URL (the destination people land on).' );
		}

		$post_id  = trim( (string) ( $input['post_id'] ?? '' ) );
		$ig_media = trim( (string) ( $input['instagram_media_id'] ?? '' ) );
		$image    = trim( (string) ( $input['image_path'] ?? '' ) );
		$image_u  = trim( (string) ( $input['image_url'] ?? '' ) );
		$message  = trim( (string) ( $input['message'] ?? '' ) );
		if ( '' !== $post_id && '' !== $ig_media ) {
			return new \WP_Error( 'invalid_creative', 'Promote either a Page post (post_id) or an Instagram post (instagram_media_id), not both.' );
		}
		if ( '' !== $ig_media && ! ctype_digit( $ig_media ) ) {
			return new \WP_Error( 'invalid_creative', 'instagram_media_id must be the numeric Instagram media ID.' );
		}
		if ( '' === $post_id && '' === $ig_media ) {
			if ( '' === $message ) {
				return new \WP_Error( 'invalid_creative', 'message is required when not promoting an existing post.' );
			}
			if ( '' === $image && '' === $image_u ) {
				return new \WP_Error( 'invalid_creative', 'image_path or image_url is required when not promoting an existing post.' );
			}
		}

		// Boosting an Instagram post defaults to Instagram placements only.
		$platforms = self::platforms( $input['platforms'] ?? array(), '' !== $ig_media ? array( 'instagram' ) : self::PLATFORMS );
		if ( is_wp_error( $platforms ) ) {
			return $platforms;
		}

		return array(
			'ad_account_id' => $ad_account,
			'name'          => trim( (string) ( $input['name'] ?? '' ) ) ? trim( (string) $input['name'] ) : 'Promotion ' . gmdate( 'Y-m-d', $start ),
			'budget'        => $budget,
			'start_time'    => $start,
			'end_time'      => $end,
			'latitude'      => $lat,
			'longitude'     => $lng,
			'radius_miles'  => $radius,
			'age_min'       => $age_min,
			'age_max'       => $age_max,
			'interests'     => $interests,
			'link'          => $link,
			'post_id'       => $post_id,
			'ig_media_id'   => $ig_media,
			'platforms'     => $platforms,
			'image_path'    => $image,
			'image_url'     => $image_u,
			'message'       => $message,
			'headline'      => trim( (string) ( $input['headline'] ?? '' ) ),
		);
	}

	/**
	 * Normalize requested placements.
	 *
	 * @param mixed    $requested Array or comma list; empty -> $default.
	 * @param string[] $default   Default placements.
	 * @return string[]|\WP_Error
	 */
	public static function platforms( $requested, array $default ) {
		$list = is_array( $requested ) ? $requested : explode( ',', (string) $requested );
		$list = array_values( array_unique( array_filter( array_map( static fn( $p ) => strtolower( trim( (string) $p ) ), $list ) ) ) );
		if ( empty( $list ) ) {
			return $default;
		}
		$unknown = array_diff( $list, self::PLATFORMS );
		if ( $unknown ) {
			return new \WP_Error( 'invalid_platforms', 'Unknown platform(s): ' . implode( ', ', $unknown ) . '. Use facebook and/or instagram.' );
		}
		return $list;
	}

	/**
	 * Re-check a live ad set against current hard limits before activation.
	 *
	 * @param int   $lifetime_budget_minor Ad set lifetime_budget as returned by Graph (minor units).
	 * @param int   $offset                Currency offset.
	 * @param int   $start                 Ad set start (Unix).
	 * @param int   $end                   Ad set end (Unix).
	 * @param array $limits                Output of limits().
	 * @return true|\WP_Error
	 */
	public static function recheck( int $lifetime_budget_minor, int $offset, int $start, int $end, array $limits ) {
		$budget = $lifetime_budget_minor / max( 1, $offset );
		if ( $budget > (float) $limits['max_lifetime_budget'] ) {
			return new \WP_Error( 'budget_over_limit', sprintf( 'Live ad set budget %s exceeds the hard limit of %s; refusing to activate.', self::money( $budget ), self::money( (float) $limits['max_lifetime_budget'] ) ) );
		}
		if ( $end - $start > (int) $limits['max_duration_days'] * DAY_IN_SECONDS ) {
			return new \WP_Error( 'duration_over_limit', 'Live ad set runs longer than the hard limit; refusing to activate.' );
		}
		return true;
	}

	/**
	 * Graph `targeting` spec. Interests are resolved {id,name} pairs.
	 *
	 * @param array $promotion Output of validate().
	 * @param array $interests Resolved interests.
	 */
	public static function targeting( array $promotion, array $interests ): array {
		$targeting = array(
			'geo_locations'        => array(
				'custom_locations' => array(
					array(
						'latitude'      => $promotion['latitude'],
						'longitude'     => $promotion['longitude'],
						'radius'        => $promotion['radius_miles'],
						'distance_unit' => 'mile',
					),
				),
			),
			'age_min'              => $promotion['age_min'],
			'age_max'              => $promotion['age_max'],
			'publisher_platforms'  => $promotion['platforms'],
			// Keep Meta from widening the audience beyond what a human approved.
			'targeting_automation' => array( 'advantage_audience' => 0 ),
		);

		if ( ! empty( $interests ) ) {
			$targeting['flexible_spec'] = array( array( 'interests' => array_values( $interests ) ) );
		}

		return $targeting;
	}

	/**
	 * Plain-language preview a human approves.
	 *
	 * @param array  $promotion Output of validate().
	 * @param array  $interests Resolved interests.
	 * @param string $currency  Account currency.
	 */
	public static function preview( array $promotion, array $interests, string $currency ): array {
		return array(
			'ad_account_id' => $promotion['ad_account_id'],
			'budget'        => self::money( $promotion['budget'] ) . ' ' . strtoupper( $currency ) . ' lifetime (hard cap)',
			'schedule'      => gmdate( 'Y-m-d H:i', $promotion['start_time'] ) . ' to ' . gmdate( 'Y-m-d H:i', $promotion['end_time'] ) . ' UTC',
			'audience'      => sprintf( 'Ages %d-%d within %d miles of %.4f,%.4f', $promotion['age_min'], $promotion['age_max'], $promotion['radius_miles'], $promotion['latitude'], $promotion['longitude'] ),
			'interests'     => array_map( static fn( $i ) => (string) ( $i['name'] ?? '' ), $interests ),
			'destination'   => $promotion['link'],
			'platforms'     => implode( ' + ', $promotion['platforms'] ),
			'creative'      => '' !== $promotion['ig_media_id'] ? 'Existing Instagram post ' . $promotion['ig_media_id'] : ( '' !== $promotion['post_id'] ? 'Existing Page post ' . $promotion['post_id'] : array(
				'message'  => $promotion['message'],
				'headline' => $promotion['headline'],
				'image'    => '' !== $promotion['image_path'] ? basename( $promotion['image_path'] ) : $promotion['image_url'],
			) ),
			'objective'     => 'Link clicks (OUTCOME_TRAFFIC)',
		);
	}

	/**
	 * Parse a date input; empty -> $default; invalid -> null.
	 *
	 * @param mixed $value   Date string or Unix time.
	 * @param int   $fallback Fallback when empty.
	 */
	private static function to_time( $value, int $fallback ): ?int {
		if ( '' === $value || null === $value ) {
			return $fallback;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		$ts = strtotime( (string) $value );
		return false === $ts ? null : $ts;
	}

	private static function money( float $amount ): string {
		return number_format( $amount, 2, '.', '' );
	}
}
