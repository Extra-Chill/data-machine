<?php
/**
 * Read-only Impact (impact.com) affiliate reports.
 *
 * Reads Media Partner Actions, aggregates them into day-grain commission rows
 * per campaign, and optionally stores them in the generic revenue ledger.
 *
 * @package DataMachineBusiness\Abilities\Analytics
 */

namespace DataMachineBusiness\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineBusiness\Abilities\Revenue\RevenueDecimal;
use DataMachineBusiness\Abilities\Revenue\RevenueLedgerAbilities;

defined( 'ABSPATH' ) || exit;

class ImpactReportsAbilities {
	const CONFIG_OPTION = 'datamachine_impact_config';
	const API_HOST      = 'https://api.impact.com';
	/** Impact rejects Actions date ranges longer than 45 days. */
	const MAX_WINDOW_DAYS = 45;
	/** Impact's maximum Actions page size. */
	const PAGE_SIZE = 2000;
	/** Pages per 45-day chunk before the read is refused as incomplete. */
	const MAX_PAGES = 25;
	/** Whole-request bound: about one year of chunks per call. */
	const MAX_RANGE_DAYS = 400;
	const LEDGER_SOURCE  = 'impact';
	const LEDGER_KIND    = 'affiliate_commission';
	/** Reversed actions are recorded in metrics but earn nothing. */
	const EARNING_STATES = array( 'APPROVED', 'PENDING' );

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}
		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( static function () {
			wp_register_ability( 'datamachine/impact-reports', array(
				'label'               => 'Impact Affiliate Reports',
				'description'         => 'Read-only Impact (impact.com) Media Partner commission report: actions aggregated by UTC event day and campaign. Opt-in store ingests the window into the revenue ledger as source=impact, kind=affiliate_commission. Credentials remain server-side.',
				'category'            => 'datamachine-analytics',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'start_date', 'end_date' ),
					'properties' => array(
						'start_date' => array(
							'type'        => 'string',
							'description' => 'Window start (YYYY-MM-DD, UTC).',
						),
						'end_date'   => array(
							'type'        => 'string',
							'description' => 'Window end (YYYY-MM-DD, UTC, inclusive). At most ' . self::MAX_RANGE_DAYS . ' days after start_date; read in 45-day chunks.',
						),
						'store'      => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Opt-in: ingest the aggregated window into the revenue ledger via datamachine/revenue-ingest, replacing that window.',
						),
						'dry_run'    => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'With store: report what the ledger would replace and insert without writing.',
						),
					),
				),
				'output_schema'       => self::outputSchema(),
				'execute_callback'    => array( self::class, 'fetch' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => false ),
			) );
		} );
		self::$registered = true;
	}

	public static function outputSchema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'success', 'results', 'results_count', 'totals', 'counts', 'provenance' ),
			'properties' => array(
				'success'       => array( 'type' => 'boolean' ),
				'results'       => array(
					'type'        => 'array',
					'description' => 'Ledger-shaped day/campaign rows (kind, amount, currency, period_start, period_end, grain, object_ref, metrics).',
					'items'       => array( 'type' => 'object' ),
				),
				'results_count' => array( 'type' => 'integer' ),
				'totals'        => array(
					'type'        => 'array',
					'description' => 'Earning payout per currency (APPROVED + PENDING).',
					'items'       => array( 'type' => 'object' ),
				),
				'counts'        => array( 'type' => 'object' ),
				'provenance'    => array( 'type' => 'object' ),
				'ledger'        => array(
					'type'        => 'object',
					'description' => 'Present only for store=true: the datamachine/revenue-ingest result.',
				),
			),
		);
	}

	public static function get_config(): array {
		$config = get_option( self::CONFIG_OPTION, array() );
		return is_array( $config ) ? $config : array();
	}

	/** @return array|\WP_Error */
	public static function fetch( array $input ) {
		$config = self::get_config();
		$sid    = trim( (string) ( $config['account_sid'] ?? '' ) );
		$token  = trim( (string) ( $config['auth_token'] ?? '' ) );
		if ( '' === $sid || '' === $token ) {
			return new \WP_Error( 'impact_not_configured', 'Impact is not configured: set account_sid and auth_token in the ' . self::CONFIG_OPTION . ' option.' );
		}
		$start = (string) ( $input['start_date'] ?? '' );
		$end   = (string) ( $input['end_date'] ?? '' );
		$range = self::validateRange( $start, $end );
		if ( is_wp_error( $range ) ) {
			return $range;
		}

		$actions    = array();
		$provenance = array();
		foreach ( self::chunkWindows( $start, $end ) as $chunk ) {
			$read = self::readActions( $sid, $token, $chunk['start'], $chunk['end'] );
			if ( is_wp_error( $read ) ) {
				return self::redact( $read, $sid, $token );
			}
			$actions      = array_merge( $actions, $read['actions'] );
			$provenance[] = array(
				'start_date' => $chunk['start'],
				'end_date'   => $chunk['end'],
				'pages'      => $read['pages'],
				'records'    => count( $read['actions'] ),
			);
		}

		$aggregate = self::aggregateActions( $actions, $start, $end );
		$result    = array(
			'success'       => true,
			'results'       => $aggregate['rows'],
			'results_count' => count( $aggregate['rows'] ),
			'totals'        => $aggregate['totals'],
			'counts'        => array(
				'source_records' => count( $actions ),
				'aggregated'     => $aggregate['aggregated'],
				'outside_window' => $aggregate['outside_window'],
				'invalid'        => $aggregate['invalid'],
				'rows'           => count( $aggregate['rows'] ),
			),
			'provenance'    => array(
				'source'       => array(
					'ability' => 'datamachine/impact-reports',
					'api'     => self::API_HOST . '/Mediapartners/{AccountSID}/Actions',
					'method'  => 'GET',
				),
				'window'       => array(
					'start_date' => $start,
					'end_date'   => $end,
				),
				'chunks'       => $provenance,
				'date_filter'  => 'ActionDateStart/ActionDateEnd (UTC), bucketed by EventDate in UTC',
				'amount_field' => 'Payout',
				'amount_units' => 'major',
				'complete'     => true,
			),
		);

		if ( ! empty( $input['store'] ) ) {
			$ledger = RevenueLedgerAbilities::ingest( array(
				'source'     => self::LEDGER_SOURCE,
				'account'    => $sid,
				'start_date' => $start,
				'end_date'   => $end,
				'kinds'      => array( self::LEDGER_KIND ),
				'rows'       => $aggregate['rows'],
				'dry_run'    => ! empty( $input['dry_run'] ),
			) );
			if ( is_wp_error( $ledger ) ) {
				return self::redact( $ledger, $sid, $token );
			}
			$result['ledger']              = $ledger;
			$result['provenance']['store'] = true;
		}

		return self::redact( $result, $sid, $token );
	}

	/** @return true|\WP_Error */
	public static function validateRange( string $start, string $end ) {
		$from = self::day( $start );
		$to   = self::day( $end );
		if ( null === $from || null === $to || $from > $to ) {
			return new \WP_Error( 'impact_invalid_window', 'start_date and end_date must be YYYY-MM-DD dates with start_date <= end_date.' );
		}
		if ( ( $to - $from ) / 86400 + 1 > self::MAX_RANGE_DAYS ) {
			return new \WP_Error( 'impact_window_too_long', 'At most ' . self::MAX_RANGE_DAYS . ' days per request; split the range.' );
		}
		return true;
	}

	/**
	 * Split an inclusive UTC day range into contiguous inclusive chunks of at
	 * most MAX_WINDOW_DAYS days.
	 *
	 * @return array<int, array{start: string, end: string}>
	 */
	public static function chunkWindows( string $start, string $end ): array {
		$from = self::day( $start );
		$to   = self::day( $end );
		if ( null === $from || null === $to || $from > $to ) {
			return array();
		}
		$chunks = array();
		for ( $cursor = $from; $cursor <= $to; $cursor += self::MAX_WINDOW_DAYS * 86400 ) {
			$chunk_end = min( $cursor + ( self::MAX_WINDOW_DAYS - 1 ) * 86400, $to );
			$chunks[]  = array(
				'start' => gmdate( 'Y-m-d', $cursor ),
				'end'   => gmdate( 'Y-m-d', $chunk_end ),
			);
		}
		return $chunks;
	}

	/**
	 * Aggregate Impact actions into ledger rows: one row per UTC event day,
	 * campaign and currency. The amount is the APPROVED + PENDING payout;
	 * REVERSED actions are counted in metrics only.
	 *
	 * @param array<int, mixed> $actions Impact Action objects (non-arrays are counted as invalid).
	 * @return array{rows: array[], totals: array[], aggregated: int, outside_window: int, invalid: int}
	 */
	public static function aggregateActions( array $actions, string $start, string $end ): array {
		/** @var array<string, array{day: string, campaign: string, campaign_id: string, currency: string, earning: int, actions: int, sale_amount: int}> $buckets */
		$buckets = array();
		/** @var array<string, array<string, array{actions: int, micros: int}>> $state_totals */
		$state_totals   = array();
		$seen           = array();
		$outside_window = 0;
		$invalid        = 0;
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				++$invalid;
				continue;
			}
			$id = (string) ( $action['Id'] ?? '' );
			if ( '' !== $id ) {
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
			}
			$timestamp = strtotime( (string) ( $action['EventDate'] ?? '' ) );
			$micros    = RevenueDecimal::to_micros( (string) ( $action['Payout'] ?? '0' ) );
			$currency  = strtoupper( trim( (string) ( $action['Currency'] ?? '' ) ) );
			$currency  = '' === $currency ? 'USD' : $currency;
			if ( false === $timestamp || null === $micros || ! preg_match( '/^[A-Z]{3}$/D', $currency ) ) {
				++$invalid;
				continue;
			}
			$day = gmdate( 'Y-m-d', $timestamp );
			if ( $day < $start || $day > $end ) {
				++$outside_window;
				continue;
			}
			$campaign = trim( (string) ( $action['CampaignName'] ?? '' ) );
			$campaign = '' === $campaign ? 'campaign-' . (string) ( $action['CampaignId'] ?? 'unknown' ) : $campaign;
			$state    = strtoupper( (string) ( $action['State'] ?? 'UNKNOWN' ) );
			$key      = $day . '|' . $campaign . '|' . $currency;
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array(
					'day'         => $day,
					'campaign'    => $campaign,
					'campaign_id' => (string) ( $action['CampaignId'] ?? '' ),
					'currency'    => $currency,
					'earning'     => 0,
					'actions'     => 0,
					'sale_amount' => 0,
				);
			}
			$previous                       = $state_totals[ $key ][ $state ] ?? array(
				'actions' => 0,
				'micros'  => 0,
			);
			$state_totals[ $key ][ $state ] = array(
				'actions' => $previous['actions'] + 1,
				'micros'  => $previous['micros'] + $micros,
			);
			++$buckets[ $key ]['actions'];
			if ( in_array( $state, self::EARNING_STATES, true ) ) {
				$buckets[ $key ]['earning']     += $micros;
				$buckets[ $key ]['sale_amount'] += RevenueDecimal::to_micros( (string) ( $action['Amount'] ?? '0' ) ) ?? 0;
			}
		}

		ksort( $buckets );
		$rows   = array();
		$totals = array();
		foreach ( $buckets as $key => $bucket ) {
			$states        = array();
			$bucket_states = $state_totals[ $key ] ?? array();
			ksort( $bucket_states );
			foreach ( $bucket_states as $state => $values ) {
				$states[ strtolower( $state ) ] = array(
					'actions' => $values['actions'],
					'payout'  => RevenueDecimal::from_micros( $values['micros'] ),
				);
			}
			$rows[] = array(
				'kind'         => self::LEDGER_KIND,
				'amount'       => RevenueDecimal::from_micros( $bucket['earning'] ),
				'currency'     => $bucket['currency'],
				'period_start' => $bucket['day'],
				'period_end'   => $bucket['day'],
				'grain'        => 'day',
				'object_ref'   => $bucket['campaign'],
				'metrics'      => array(
					'campaign_id' => $bucket['campaign_id'],
					'actions'     => $bucket['actions'],
					'sale_amount' => RevenueDecimal::from_micros( $bucket['sale_amount'] ),
					'states'      => $states,
				),
			);

			$totals[ $bucket['currency'] ] = ( $totals[ $bucket['currency'] ] ?? 0 ) + $bucket['earning'];
		}
		ksort( $totals );
		$total_rows = array();
		foreach ( $totals as $currency => $micros ) {
			$total_rows[] = array(
				'currency' => $currency,
				'amount'   => RevenueDecimal::from_micros( $micros ),
			);
		}

		return array(
			'rows'           => $rows,
			'totals'         => $total_rows,
			'aggregated'     => count( $seen ),
			'outside_window' => $outside_window,
			'invalid'        => $invalid,
		);
	}

	/**
	 * Read every Actions page for one chunk. GET only; never propagates raw
	 * response bodies or credentials.
	 *
	 * @return array{actions: array[], pages: int}|\WP_Error
	 */
	private static function readActions( string $sid, string $token, string $start, string $end ) {
		$query   = http_build_query( array(
			'ActionDateStart' => $start . 'T00:00:00Z',
			'ActionDateEnd'   => gmdate( 'Y-m-d', (int) self::day( $end ) + 86400 ) . 'T00:00:00Z',
			'PageSize'        => self::PAGE_SIZE,
		) );
		$url     = self::API_HOST . '/Mediapartners/' . rawurlencode( $sid ) . '/Actions?' . $query;
		$actions = array();
		$visited = array();
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$visited[ $url ] = true;
			$response        = HttpClient::get( $url, array(
				'headers'                   => array(
					'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
					'Accept'        => 'application/json',
				),
				'timeout'                   => 30,
				'context'                   => 'Impact reporting',
				'log_response_body_preview' => false,
			) );
			$data            = json_decode( (string) ( $response['data'] ?? '' ), true );
			if ( empty( $response['success'] ) ) {
				$status = (int) ( $response['status_code'] ?? 0 );
				$code   = match ( $status ) {
					401 => 'impact_authentication', 403 => 'impact_permission', 429 => 'impact_rate_limit', default => 'impact_api_error' };
				$detail = is_array( $data ) && isset( $data['Message'] ) ? ' ' . substr( (string) $data['Message'], 0, 200 ) : '';
				return new \WP_Error( $code, 'Impact Actions read failed (HTTP ' . $status . ').' . $detail );
			}
			if ( ! is_array( $data ) || ! isset( $data['Actions'] ) || ! is_array( $data['Actions'] ) ) {
				return new \WP_Error( 'impact_invalid_response', 'Impact returned an invalid Actions response.' );
			}
			$actions = array_merge( $actions, $data['Actions'] );
			$next    = (string) ( $data['@nextpageuri'] ?? '' );
			if ( '' === $next ) {
				return array(
					'actions' => $actions,
					'pages'   => $page,
				);
			}
			$url = str_starts_with( $next, 'http' ) ? $next : self::API_HOST . $next;
			if ( ! str_starts_with( $url, self::API_HOST . '/' ) || isset( $visited[ $url ] ) ) {
				return new \WP_Error( 'impact_invalid_cursor', 'Impact pagination did not advance.' );
			}
		}
		return new \WP_Error( 'impact_incomplete', 'Impact Actions exceed ' . self::MAX_PAGES . ' pages for ' . $start . '..' . $end . '; refusing an incomplete read.' );
	}

	private static function day( string $date ): ?int {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) ) {
			return null;
		}
		$timestamp = strtotime( $date . ' 00:00:00 UTC' );
		return false === $timestamp || gmdate( 'Y-m-d', $timestamp ) !== $date ? null : $timestamp;
	}

	private static function redact( $value, string $sid, string $token ) {
		if ( $value instanceof \WP_Error ) {
			return new \WP_Error( $value->get_error_code(), (string) self::redact( $value->get_error_message(), $sid, $token ) );
		}
		if ( is_array( $value ) ) {
			return array_map( static fn( $entry ) => self::redact( $entry, $sid, $token ), $value );
		}
		return is_string( $value ) ? str_replace( $token, '[redacted]', $value ) : $value;
	}
}
