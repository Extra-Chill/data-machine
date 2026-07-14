<?php
/**
 * Google Analytics (GA4) Abilities
 *
 * Primitive ability for Google Analytics Data API (GA4).
 * All GA4 data — tools, CLI, REST, chat — flows through this ability.
 *
 * Uses the GA4 Data API v1beta to fetch visitor analytics:
 * page performance, traffic sources, daily trends, real-time data,
 * top events, and user demographics.
 *
 * Authentication uses the same service account JWT flow as Google Search Console.
 *
 * @package DataMachineBusiness\Abilities\Analytics
 * @since 0.31.0
 */

namespace DataMachineBusiness\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

class GoogleAnalyticsAbilities {

	/**
	 * Option key for storing GA configuration.
	 *
	 * @var string
	 */
	const CONFIG_OPTION = 'datamachine_ga_config';

	/**
	 * Transient key for cached access token.
	 *
	 * @var string
	 */
	const TOKEN_TRANSIENT = 'datamachine_ga_access_token';

	/**
	 * GA4 Data API base URL.
	 *
	 * @var string
	 */
	const API_BASE = 'https://analyticsdata.googleapis.com/v1beta/properties/';

	/**
	 * GA4 Data API v1alpha base URL.
	 *
	 * The funnel report surface (runFunnelReport) — the only Data API method that
	 * expresses ordered, session-scoped steps — is alpha-only. See path_sequence.
	 *
	 * @var string
	 */
	const API_BASE_ALPHA = 'https://analyticsdata.googleapis.com/v1alpha/properties/';

	/**
	 * Default number of hostnames to fan out into path_sequence funnels.
	 *
	 * path_sequence runs one runFunnelReport per ORDERED host pair, so cost grows
	 * ~N*(N-1). The default keeps a full run inside a normal CLI/HTTP timeout
	 * while still covering a typical small multisite network; callers can raise
	 * it with the `limit` input (clamped to PATH_SEQUENCE_MAX_HOSTS).
	 *
	 * @var int
	 */
	const PATH_SEQUENCE_DEFAULT_HOSTS = 6;

	/**
	 * Hard ceiling on path_sequence host fan-out.
	 *
	 * Bounds worst-case API usage regardless of the `limit` input — N hosts cost
	 * up to N*(N-1) funnel calls, so this caps the blast radius.
	 *
	 * @var int
	 */
	const PATH_SEQUENCE_MAX_HOSTS = 12;

	/**
	 * Default result limit.
	 *
	 * @var int
	 */
	const DEFAULT_LIMIT = 25;

	/**
	 * Maximum result limit.
	 *
	 * @var int
	 */
	const MAX_LIMIT = 10000;

	/**
	 * Action-to-report configuration mapping.
	 *
	 * Each action defines the dimensions and metrics for its GA4 report request.
	 *
	 * @var array
	 */
	const ACTION_REPORTS = array(
		'page_stats'        => array(
			// hostName is prepended (not replacing pagePath) so existing pagePath-keyed
			// consumers keep working while cross-site rows become distinguishable — on a
			// multisite GA4 property two sites otherwise both collapse to pagePath "/".
			'dimensions' => array( 'hostName', 'pagePath', 'pageTitle' ),
			'metrics'    => array( 'screenPageViews', 'sessions', 'bounceRate', 'averageSessionDuration', 'activeUsers' ),
		),
		'network_density'   => array(
			// Cross-site journey proxy: current host x previous URL. Bucket pageReferrer's
			// host into in-network vs external to compute "% of sessions per site whose
			// referrer was another EC site". Approximation only — see action description.
			'dimensions' => array( 'hostName', 'pageReferrer' ),
			'metrics'    => array( 'sessions', 'activeUsers', 'screenPageViews' ),
		),
		'traffic_sources'   => array(
			'dimensions' => array( 'sessionSource', 'sessionMedium' ),
			'metrics'    => array( 'sessions', 'activeUsers', 'screenPageViews', 'bounceRate' ),
		),
		'date_stats'        => array(
			'dimensions' => array( 'date' ),
			'metrics'    => array( 'sessions', 'screenPageViews', 'activeUsers', 'bounceRate', 'averageSessionDuration' ),
		),
		'top_events'        => array(
			'dimensions' => array( 'eventName' ),
			'metrics'    => array( 'eventCount', 'eventCountPerUser' ),
		),
		'user_demographics' => array(
			'dimensions' => array( 'country', 'deviceCategory' ),
			'metrics'    => array( 'sessions', 'activeUsers', 'screenPageViews' ),
		),
		'landing_pages'     => array(
			'dimensions' => array( 'landingPage' ),
			'metrics'    => array( 'sessions', 'activeUsers', 'bounceRate', 'averageSessionDuration', 'engagementRate' ),
		),
		'engagement'        => array(
			'dimensions' => array( 'pagePath', 'pageTitle' ),
			'metrics'    => array( 'engagementRate', 'averageSessionDuration', 'engagedSessions', 'sessionsPerUser', 'screenPageViewsPerSession', 'userEngagementDuration' ),
		),
		'new_vs_returning'  => array(
			'dimensions' => array( 'newVsReturning' ),
			'metrics'    => array( 'sessions', 'activeUsers', 'engagementRate', 'screenPageViewsPerSession', 'averageSessionDuration' ),
		),
	);

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/google-analytics',
				array(
					'label'               => 'Google Analytics',
					'description'         => 'Fetch visitor analytics data from Google Analytics (GA4) Data API',
					'category'            => 'datamachine-analytics',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'action' ),
						'properties' => array(
							'action'      => array(
								'type'        => 'string',
								'description' => 'Action to perform: page_stats (per-page metrics, includes hostName for multisite), traffic_sources, date_stats, realtime, top_events, user_demographics, landing_pages, engagement, new_vs_returning, network_density (cross-site journey proxy: hostName x pageReferrer; approximation only — pageReferrer is the immediately-preceding URL, not an ordered session path, and is subject to referrer-policy stripping, sampling, and high-cardinality "(other)" bucketing), path_sequence (true ordered cross-host journeys via the v1alpha funnel report — discovers hosts then runs a 2-step closed funnel per ordered host pair, returning each host\'s entry_users, ordered next-host transitions (next_host with activeUsers), and onward_users, so a consumer can compute "% of each host\'s users reaching >=1 other site" and rank top ordered cross-site paths; in-network bucketing is the consumer\'s job. DATA SOURCE: GA4 Data API v1alpha funnel report. Caveats: v1alpha (may change), USER-scoped metric (activeUsers, not sessions), subject to sampling, returns ordered 2-hop transitions per host pair (compose deeper chains from the matrix), capped to the top hosts. The fully-accurate long-term source is a BigQuery export tap, not yet configured).',
							),
							'property_id' => array(
								'type'        => 'string',
								'description' => 'GA4 property ID (numeric). Defaults to configured property ID.',
							),
							'start_date'  => array(
								'type'        => 'string',
								'description' => 'Start date in YYYY-MM-DD format (defaults to 28 days ago). Not used for realtime.',
							),
							'end_date'    => array(
								'type'        => 'string',
								'description' => 'End date in YYYY-MM-DD format (defaults to yesterday). Not used for realtime.',
							),
							'limit'       => array(
								'type'        => 'integer',
								'description' => 'Row limit (default: 25, max: 10000). For path_sequence this instead selects how many top hosts (by sessions) to pair, default 6, clamped to 12 — fan-out grows ~N*(N-1) funnel calls.',
							),
							'page_filter' => array(
								'type'        => 'string',
								'description' => 'Filter results to pages with paths containing this string.',
							),
							'hostname'    => array(
								'type'        => 'string',
								'description' => 'Filter to pages on this hostname (for multisite GA4 properties).',
							),
							'sort_by'     => array(
								'type'        => 'string',
								'description' => 'Sort results by this metric or dimension field name.',
							),
							'order'       => array(
								'type'        => 'string',
								'description' => 'Sort direction: asc or desc (default: desc).',
							),
							'compare'     => array(
								'type'        => 'boolean',
								'description' => 'Compare against the previous period of equal length. Adds delta columns.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'action'        => array( 'type' => 'string' ),
							'results_count' => array( 'type' => 'integer' ),
							'results'       => array( 'type' => 'array' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'fetchStats' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Fetch stats from Google Analytics Data API.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function fetchStats( array $input ): array {
		$action = sanitize_text_field( $input['action'] ?? '' );

		$valid_actions = array_merge( array_keys( self::ACTION_REPORTS ), array( 'realtime', 'path_sequence' ) );
		if ( empty( $action ) || ! in_array( $action, $valid_actions, true ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid action. Must be one of: ' . implode( ', ', $valid_actions ),
			);
		}

		$config = self::get_config();

		if ( empty( $config['service_account_json'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Google Analytics not configured. Add service account JSON in Settings.',
			);
		}

		$service_account = json_decode( $config['service_account_json'], true );

		if ( json_last_error() !== JSON_ERROR_NONE || empty( $service_account['client_email'] ) || empty( $service_account['private_key'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid service account JSON. Ensure it contains client_email and private_key.',
			);
		}

		$access_token = self::get_access_token( $service_account );

		if ( is_wp_error( $access_token ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to authenticate: ' . $access_token->get_error_message(),
			);
		}

		$property_id = ! empty( $input['property_id'] ) ? sanitize_text_field( $input['property_id'] ) : ( $config['property_id'] ?? '' );

		if ( empty( $property_id ) ) {
			return array(
				'success' => false,
				'error'   => 'No GA4 property ID configured or provided.',
			);
		}

		// Route to realtime handler.
		if ( 'realtime' === $action ) {
			return self::fetchRealtime( $access_token, $property_id );
		}

		// Route to path_sequence handler (ordered, session-scoped host journeys
		// via the v1alpha funnel report — a different endpoint and shape).
		if ( 'path_sequence' === $action ) {
			return self::fetchPathSequence( $input, $access_token, $property_id );
		}

		return self::fetchReport( $input, $action, $access_token, $property_id );
	}

	/**
	 * Build the GA4 runReport request body from ability input.
	 *
	 * Public for unit testing — request body construction is the testable surface
	 * for filter / sort / pagination behavior without needing an HTTP round-trip.
	 *
	 * @param array  $input  Ability input.
	 * @param string $action Report action (must be a key in self::ACTION_REPORTS).
	 * @return array Request body for the GA4 runReport endpoint.
	 */
	public static function buildReportRequestBody( array $input, string $action ): array {
		$report_config = self::ACTION_REPORTS[ $action ];

		$start_date = ! empty( $input['start_date'] ) ? sanitize_text_field( $input['start_date'] ) : gmdate( 'Y-m-d', strtotime( '-28 days' ) );
		$end_date   = ! empty( $input['end_date'] ) ? sanitize_text_field( $input['end_date'] ) : gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$limit      = ! empty( $input['limit'] ) ? min( (int) $input['limit'], self::MAX_LIMIT ) : self::DEFAULT_LIMIT;
		$compare    = ! empty( $input['compare'] );

		$dimensions = array_map(
			function ( $dim ) {
				return array( 'name' => $dim );
			},
			$report_config['dimensions']
		);

		$metrics = array_map(
			function ( $met ) {
				return array( 'name' => $met );
			},
			$report_config['metrics']
		);

		// Build date ranges — add comparison period if requested.
		$date_ranges = array(
			array(
				'startDate' => $start_date,
				'endDate'   => $end_date,
			),
		);

		if ( $compare ) {
			$period_length    = (int) ( ( strtotime( $end_date ) - strtotime( $start_date ) ) / 86400 );
			$compare_end_ts   = strtotime( $start_date ) - 86400;
			$compare_start_ts = $compare_end_ts - ( $period_length * 86400 );
			$date_ranges[]    = array(
				'startDate' => gmdate( 'Y-m-d', $compare_start_ts ),
				'endDate'   => gmdate( 'Y-m-d', $compare_end_ts ),
			);
		}

		$request_body = array(
			'dateRanges' => $date_ranges,
			'dimensions' => $dimensions,
			'metrics'    => $metrics,
			// Comparison rows are reconciled after the API response. Fetch the full
			// supported key set so a prior row outside the final display limit is
			// not incorrectly classified as new.
			'limit'      => $compare ? self::MAX_LIMIT : $limit,
		);

		// Build dimension filters.
		$filters = array();

		// Page path filter. GA4 supports pagePath/landingPage as filter dimensions
		// even when they aren't part of the report's dimensions array, so the filter
		// applies to every action — not just those that group by page.
		if ( ! empty( $input['page_filter'] ) ) {
			// Prefer landingPage when the report groups by it (so the filter matches
			// the dimension being returned); otherwise filter by pagePath, which
			// scopes any action (date_stats, traffic_sources, top_events, etc.) to
			// hits/sessions that touched matching paths.
			$path_dim = in_array( 'landingPage', $report_config['dimensions'], true )
				? 'landingPage'
				: 'pagePath';

			$filters[] = array(
				'filter' => array(
					'fieldName'    => $path_dim,
					'stringFilter' => array(
						'matchType' => 'CONTAINS',
						'value'     => sanitize_text_field( $input['page_filter'] ),
					),
				),
			);
		}

		// Hostname filter for multisite properties.
		if ( ! empty( $input['hostname'] ) ) {
			$filters[] = array(
				'filter' => array(
					'fieldName'    => 'hostName',
					'stringFilter' => array(
						'matchType' => 'EXACT',
						'value'     => sanitize_text_field( $input['hostname'] ),
					),
				),
			);
		}

		// In-network referrer filter for network_density.
		//
		// network_density groups by hostName x pageReferrer. pageReferrer is a
		// near-unbounded, high-cardinality dimension, so the single-page GA4 row
		// cap fills with the largest external/"(other)" referrer buckets and the
		// small in-network referrer rows fall off the end — silently
		// under-counting in-network referrals. Constrain pageReferrer to the
		// configured in-network hosts server-side so GA4 only returns in-network
		// rows BEFORE the cap applies, keeping it one cheap API call (there are
		// far fewer than the row cap of distinct in-network referrer buckets) and
		// making the result volume-independent.
		if ( 'network_density' === $action ) {
			/**
			 * Filter the set of in-network hosts used to constrain the
			 * network_density referrer query.
			 *
			 * Generic Data Machine layers ship with no site baked in. A consumer
			 * plugin registers its own network's hostnames here. With no consumer
			 * configured this defaults to an empty list and no referrer
			 * constraint is applied.
			 *
			 * @param array $network_hosts List of in-network hostnames. Empty by default.
			 */
			$network_hosts = apply_filters(
				'datamachine_network_density_hosts',
				array()
			);

			$host_expressions = array();
			foreach ( (array) $network_hosts as $host ) {
				$host = sanitize_text_field( $host );
				if ( '' === $host ) {
					continue;
				}
				// CONTAINS covers the apex host and every subdomain
				// (e.g. "example.com" matches both "example.com" and
				// "sub.example.com"), so wildcard subdomains are
				// implicit and do not need separate patterns.
				$host_expressions[] = array(
					'filter' => array(
						'fieldName'    => 'pageReferrer',
						'stringFilter' => array(
							'matchType' => 'CONTAINS',
							'value'     => $host,
						),
					),
				);
			}

			if ( ! empty( $host_expressions ) ) {
				// A single OR group of pageReferrer CONTAINS expressions is one
				// filter expression, so it composes with any hostname filter via
				// the existing 1-vs-andGroup path below.
				$filters[] = count( $host_expressions ) === 1
					? $host_expressions[0]
					: array(
						'orGroup' => array(
							'expressions' => $host_expressions,
						),
					);
			}
		}

		if ( count( $filters ) === 1 ) {
			$request_body['dimensionFilter'] = $filters[0];
		} elseif ( count( $filters ) > 1 ) {
			$request_body['dimensionFilter'] = array(
				'andGroup' => array(
					'expressions' => $filters,
				),
			);
		}

		// Sort order.
		if ( ! empty( $input['sort_by'] ) ) {
			$sort_field  = sanitize_text_field( $input['sort_by'] );
			$sort_order  = 'asc' === strtolower( $input['order'] ?? 'desc' ) ? 'ASCENDING' : 'DESCENDING';
			$all_metrics = $report_config['metrics'];
			$all_dims    = $report_config['dimensions'];

			if ( in_array( $sort_field, $all_metrics, true ) ) {
				$request_body['orderBys'] = array(
					array(
						'metric' => array( 'metricName' => $sort_field ),
						'desc'   => 'DESCENDING' === $sort_order,
					),
				);
			} elseif ( in_array( $sort_field, $all_dims, true ) ) {
				$request_body['orderBys'] = array(
					array(
						'dimension' => array(
							'dimensionName' => $sort_field,
							'orderType'     => 'ALPHANUMERIC',
						),
						'desc'      => 'DESCENDING' === $sort_order,
					),
				);
			}
		}

		return $request_body;
	}

	/**
	 * Fetch a standard GA4 report.
	 *
	 * @param array  $input        Ability input.
	 * @param string $action       Report action.
	 * @param string $access_token OAuth2 access token.
	 * @param string $property_id  GA4 property ID.
	 * @return array
	 */
	private static function fetchReport( array $input, string $action, string $access_token, string $property_id ): array {
		$request_body = self::buildReportRequestBody( $input, $action );

		// Pull values needed for response shaping back out of the request body
		// so we don't have to recompute defaults / comparison logic in two places.
		$compare     = ! empty( $input['compare'] );
		$date_ranges = $request_body['dateRanges'];
		$start_date  = $date_ranges[0]['startDate'];
		$end_date    = $date_ranges[0]['endDate'];

		$api_url = self::API_BASE . $property_id . ':runReport';

		$result = HttpClient::post(
			$api_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'context' => 'Google Analytics Data API',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Failed to connect to Google Analytics API: ' . ( $result['error'] ?? 'Unknown error' ),
			);
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse Google Analytics API response.',
			);
		}

		if ( ! empty( $data['error'] ) ) {
			$error_message = $data['error']['message'] ?? 'Unknown API error';
			return array(
				'success' => false,
				'error'   => 'GA4 API error: ' . $error_message,
			);
		}

		$limit = ! empty( $input['limit'] ) ? min( (int) $input['limit'], self::MAX_LIMIT ) : self::DEFAULT_LIMIT;
		$rows  = $compare
			? self::formatComparisonRows( $data, $limit )
			: self::formatReportRows( $data);

		$response = array(
			'success'       => true,
			'action'        => $action,
			'date_range'    => array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
			),
			'results_count' => count( $rows ),
			'results'       => $rows,
		);

		if ( $compare ) {
			$response['compare_date_range'] = array(
				'start_date' => $date_ranges[1]['startDate'],
				'end_date'   => $date_ranges[1]['endDate'],
			);
		}

		return $response;
	}

	/**
	 * Fetch real-time analytics data.
	 *
	 * @param string $access_token OAuth2 access token.
	 * @param string $property_id  GA4 property ID.
	 * @return array
	 */
	private static function fetchRealtime( string $access_token, string $property_id ): array {
		$request_body = array(
			'dimensions' => array(
				array( 'name' => 'unifiedScreenName' ),
			),
			'metrics'    => array(
				array( 'name' => 'activeUsers' ),
				array( 'name' => 'screenPageViews' ),
			),
			'limit'      => 25,
		);

		$api_url = self::API_BASE . $property_id . ':runRealtimeReport';

		$result = HttpClient::post(
			$api_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'context' => 'Google Analytics Realtime API',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Failed to connect to Google Analytics Realtime API: ' . ( $result['error'] ?? 'Unknown error' ),
			);
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse Google Analytics Realtime response.',
			);
		}

		if ( ! empty( $data['error'] ) ) {
			return array(
				'success' => false,
				'error'   => 'GA4 Realtime API error: ' . ( $data['error']['message'] ?? 'Unknown' ),
			);
		}

		$dimension_headers = wp_list_pluck( $data['dimensionHeaders'] ?? array(), 'name' );
		$metric_headers    = wp_list_pluck( $data['metricHeaders'] ?? array(), 'name' );

		$total_active_users = 0;
		$total_page_views   = 0;
		$pages              = array();

		foreach ( ( $data['rows'] ?? array() ) as $row ) {
			$dim_values    = wp_list_pluck( $row['dimensionValues'] ?? array(), 'value' );
			$metric_values = wp_list_pluck( $row['metricValues'] ?? array(), 'value' );

			$page_data = array();
			foreach ( $dimension_headers as $i => $name ) {
				$page_data[ $name ] = $dim_values[ $i ] ?? '';
			}
			foreach ( $metric_headers as $i => $name ) {
				$page_data[ $name ] = (int) ( $metric_values[ $i ] ?? 0 );
			}

			$total_active_users += $page_data['activeUsers'] ?? 0;
			$total_page_views   += $page_data['screenPageViews'] ?? 0;

			$pages[] = $page_data;
		}

		return array(
			'success'            => true,
			'action'             => 'realtime',
			'total_active_users' => $total_active_users,
			'total_page_views'   => $total_page_views,
			'results_count'      => count( $pages ),
			'results'            => $pages,
		);
	}

	/**
	 * Fetch ordered, within-journey cross-host path sequences.
	 *
	 * DATA SOURCE: GA4 Data API v1alpha funnel report (runFunnelReport). This is
	 * the ONLY Data API surface that expresses ORDERED, sequential steps — the
	 * standard v1beta runReport cannot emit intra-session/journey ordering at all
	 * (its sole cross-site signal is pageReferrer, the immediately-preceding URL:
	 * a single hop, not an ordered path, and subject to referrer-policy stripping
	 * and high-cardinality "(other)" bucketing — that is the network_density
	 * proxy, deliberately distinct from this action).
	 *
	 * HOW IT WORKS (generic, no hostnames hardcoded):
	 *   1. Discover the hosts present in the property/date range via a hostName
	 *      runReport (top PATH_SEQUENCE_MAX_HOSTS by sessions).
	 *   2. For each ORDERED pair of distinct hosts (A, B), run a 2-step CLOSED
	 *      funnel: step 1 = "hostName EXACT A", step 2 = "hostName EXACT B".
	 *      In a closed funnel users must enter at step 1, so step 2's activeUsers
	 *      = users who reached B AFTER A in order — a true ordered A -> B
	 *      transition, not a referrer guess. (funnelNextAction cannot be used
	 *      here: GA4 restricts nextActionDimension to eventName / page / screen
	 *      dimensions and rejects hostName, so explicit ordered step pairs are
	 *      the correct construction.)
	 *   3. Aggregate per entry host: entry_users (step-1 activeUsers), the ranked
	 *      ordered next-host transitions (A -> B with users), and onward_users
	 *      (the max single-hop B, a lower bound on users who left A for another
	 *      host). A consumer can then compute "% of each host's users reaching
	 *      >=1 other site" and rank the top ordered cross-site paths.
	 *
	 * IN-NETWORK BUCKETING IS THE CONSUMER'S JOB: this action stays generic and
	 * does not know which hosts belong to a given network. It returns every
	 * ordered host-to-host transition; the calling agent decides which hosts are
	 * "in network" and computes density from the raw transitions.
	 *
	 * CAVEATS (remaining, even on the funnel surface):
	 *   - v1alpha: runFunnelReport is an alpha API and may change.
	 *   - USER-SCOPED metric: funnels count activeUsers, not sessions (the funnel
	 *     surface exposes no sessions metric). "Ordered" means the user reached B
	 *     after A; without withinDurationFromPriorStep it may span sessions.
	 *   - SAMPLING: funnel reports are subject to GA4 sampling on large ranges.
	 *   - 2-HOP transitions: each pair funnel yields an ordered A -> B hop. Deeper
	 *     chains (A -> B -> C) are composed by the consumer from the matrix.
	 *   - HOST CAP: only the top PATH_SEQUENCE_MAX_HOSTS hosts are paired, so the
	 *     fan-out is bounded (N hosts => up to N*(N-1) funnel calls).
	 *
	 * LONG-TERM TARGET (fully accurate): a BigQuery export tap. With the GA4
	 * property's events exported to BigQuery, a session-level query (events
	 * nested per session, ordered by event_timestamp, hostname per hit) yields
	 * exact, unsampled, arbitrary-depth ordered host paths. That is the only
	 * fully-accurate source of truth and is the intended replacement for this
	 * Data-API approximation once BigQuery credentials/config exist (none are
	 * configured today, so this action implements the best Data-API option).
	 *
	 * @param array  $input        Ability input.
	 * @param string $access_token OAuth2 access token.
	 * @param string $property_id  GA4 property ID.
	 * @return array
	 */
	private static function fetchPathSequence( array $input, string $access_token, string $property_id ): array {
		$start_date = ! empty( $input['start_date'] ) ? sanitize_text_field( $input['start_date'] ) : gmdate( 'Y-m-d', strtotime( '-28 days' ) );
		$end_date   = ! empty( $input['end_date'] ) ? sanitize_text_field( $input['end_date'] ) : gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		// `limit` selects how many top hosts to pair. Default keeps a full run
		// inside a normal timeout; clamp to the hard ceiling to bound fan-out.
		$max_hosts = ! empty( $input['limit'] )
			? max( 2, min( (int) $input['limit'], self::PATH_SEQUENCE_MAX_HOSTS ) )
			: self::PATH_SEQUENCE_DEFAULT_HOSTS;

		$hosts = self::discoverHosts( $access_token, $property_id, $start_date, $end_date, $max_hosts );

		if ( is_wp_error( $hosts ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to discover hosts for path sequence: ' . $hosts->get_error_message(),
			);
		}

		$base_response = array(
			'success'     => true,
			'action'      => 'path_sequence',
			'data_source' => 'ga4_data_api_v1alpha_funnel',
			'metric'      => 'activeUsers',
			'date_range'  => array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
			),
		);

		if ( count( $hosts ) < 2 ) {
			// Need at least two hosts to have any cross-host transition.
			return array_merge(
				$base_response,
				array(
					'results_count' => 0,
					'results'       => array(),
				)
			);
		}

		$host_names = wp_list_pluck( $hosts, 'hostName' );

		// Accumulate transitions keyed by entry host. We iterate unordered host
		// PAIRS (i < j) and probe A -> B first; if A -> B has zero users then no
		// user reached both hosts, so the reverse B -> A is also zero and we skip
		// that funnel call. Only when A -> B is non-zero do we probe B -> A.
		$transitions_by_host = array();
		$entry_users_by_host = array();
		foreach ( $host_names as $host_name ) {
			$transitions_by_host[ $host_name ] = array();
			$entry_users_by_host[ $host_name ] = null;
		}

		$count = count( $host_names );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$a = $host_names[ $i ];
				$b = $host_names[ $j ];

				$ab = self::fetchOrderedPairTransition( $access_token, $property_id, $start_date, $end_date, $a, $b );
				if ( is_wp_error( $ab ) ) {
					return array(
						'success' => false,
						'error'   => 'Failed to fetch path sequence for ' . $a . ' -> ' . $b . ': ' . $ab->get_error_message(),
					);
				}

				if ( null === $entry_users_by_host[ $a ] ) {
					$entry_users_by_host[ $a ] = $ab['entry_users'];
				}

				if ( $ab['next_users'] > 0 ) {
					$transitions_by_host[ $a ][] = array(
						'next_host' => $b,
						'users'     => $ab['next_users'],
					);

					// Only probe the reverse direction when at least one user
					// shared both hosts — otherwise B -> A is necessarily zero.
					$ba = self::fetchOrderedPairTransition( $access_token, $property_id, $start_date, $end_date, $b, $a );
					if ( is_wp_error( $ba ) ) {
						return array(
							'success' => false,
							'error'   => 'Failed to fetch path sequence for ' . $b . ' -> ' . $a . ': ' . $ba->get_error_message(),
						);
					}

					if ( null === $entry_users_by_host[ $b ] ) {
						$entry_users_by_host[ $b ] = $ba['entry_users'];
					}

					if ( $ba['next_users'] > 0 ) {
						$transitions_by_host[ $b ][] = array(
							'next_host' => $a,
							'users'     => $ba['next_users'],
						);
					}
				}
			}
		}

		$results = array();
		foreach ( $host_names as $entry_host ) {
			$transitions = $transitions_by_host[ $entry_host ];

			usort(
				$transitions,
				static function ( $a, $b ) {
					return $b['users'] <=> $a['users'];
				}
			);

			$results[] = array(
				'hostName'     => $entry_host,
				// Users whose journey touched this host (funnel step-1 activeUsers).
				'entry_users'  => (int) ( $entry_users_by_host[ $entry_host ] ?? 0 ),
				// Lower bound on users who, after this host, went on to another
				// host: the largest single ordered next-hop. (Per-destination
				// funnels can't be summed without double-counting users who
				// reached multiple other hosts, so the max is the safe floor for
				// "% reaching >=1 other site".)
				'onward_users' => empty( $transitions ) ? 0 : (int) $transitions[0]['users'],
				// Ordered next-host transitions (this host -> next_host), with
				// activeUsers, descending. Raw material for top cross-site paths.
				'next_hosts'   => $transitions,
			);
		}

		return array_merge(
			$base_response,
			array(
				'results_count' => count( $results ),
				'results'       => $results,
			)
		);
	}

	/**
	 * Discover the hostnames present in the property for a date range.
	 *
	 * Uses the standard runReport (hostName x sessions) so path_sequence stays
	 * property-agnostic — it learns the hosts from the data instead of having
	 * any host list baked in.
	 *
	 * @param string $access_token OAuth2 access token.
	 * @param string $property_id  GA4 property ID.
	 * @param string $start_date   Start date (YYYY-MM-DD).
	 * @param string $end_date     End date (YYYY-MM-DD).
	 * @param int    $max_hosts    Max hosts to return (top by sessions).
	 * @return array|\WP_Error Array of array{hostName,sessions} or error.
	 */
	private static function discoverHosts( string $access_token, string $property_id, string $start_date, string $end_date, int $max_hosts ) {
		$request_body = array(
			'dateRanges' => array(
				array(
					'startDate' => $start_date,
					'endDate'   => $end_date,
				),
			),
			'dimensions' => array( array( 'name' => 'hostName' ) ),
			'metrics'    => array( array( 'name' => 'sessions' ) ),
			'orderBys'   => array(
				array(
					'metric' => array( 'metricName' => 'sessions' ),
					'desc'   => true,
				),
			),
			'limit'      => $max_hosts,
		);

		$result = HttpClient::post(
			self::API_BASE . $property_id . ':runReport',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'context' => 'Google Analytics Data API (path_sequence host discovery)',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'ga_path_sequence_hosts_failed', $result['error'] ?? 'Unknown error' );
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'ga_path_sequence_hosts_parse', 'Failed to parse host discovery response.' );
		}

		if ( ! empty( $data['error'] ) ) {
			return new \WP_Error( 'ga_path_sequence_hosts_api', $data['error']['message'] ?? 'Unknown API error' );
		}

		$hosts = array();
		foreach ( ( $data['rows'] ?? array() ) as $row ) {
			$host = $row['dimensionValues'][0]['value'] ?? '';
			if ( '' === $host || '(not set)' === $host ) {
				continue;
			}
			$hosts[] = array(
				'hostName' => $host,
				'sessions' => (int) ( $row['metricValues'][0]['value'] ?? 0 ),
			);
		}

		return $hosts;
	}

	/**
	 * Fetch one ordered host transition (entry_host -> next_host).
	 *
	 * Runs a 2-step CLOSED funnel: step 1 = hostName EXACT entry_host, step 2 =
	 * hostName EXACT next_host. Returns the step-1 activeUsers (entry_users) and
	 * step-2 activeUsers (next_users = users who reached next_host after
	 * entry_host, in order).
	 *
	 * @param string $access_token OAuth2 access token.
	 * @param string $property_id  GA4 property ID.
	 * @param string $start_date   Start date (YYYY-MM-DD).
	 * @param string $end_date     End date (YYYY-MM-DD).
	 * @param string $entry_host   First-step hostname.
	 * @param string $next_host    Second-step hostname.
	 * @return array|\WP_Error array{entry_users:int,next_users:int} or error.
	 */
	private static function fetchOrderedPairTransition( string $access_token, string $property_id, string $start_date, string $end_date, string $entry_host, string $next_host ) {
		$request_body = array(
			'dateRanges' => array(
				array(
					'startDate' => $start_date,
					'endDate'   => $end_date,
				),
			),
			'funnel'     => array(
				// Closed funnel: users must enter at step 1, so step 2 counts only
				// users who reached next_host AFTER entry_host (ordered).
				'isOpenFunnel' => false,
				'steps'        => array(
					self::buildHostFunnelStep( 'entry', $entry_host ),
					self::buildHostFunnelStep( 'next', $next_host ),
				),
			),
		);

		$result = HttpClient::post(
			self::API_BASE_ALPHA . $property_id . ':runFunnelReport',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'context' => 'Google Analytics Data API (path_sequence funnel)',
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'ga_path_sequence_funnel_failed', $result['error'] ?? 'Unknown error' );
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'ga_path_sequence_funnel_parse', 'Failed to parse funnel response.' );
		}

		if ( ! empty( $data['error'] ) ) {
			return new \WP_Error( 'ga_path_sequence_funnel_api', $data['error']['message'] ?? 'Unknown API error' );
		}

		return self::extractFunnelStepUsers( $data );
	}

	/**
	 * Build a single hostName-EXACT funnel step.
	 *
	 * Public-by-extraction so the request shape is unit-testable without an HTTP
	 * round-trip (mirrors buildReportRequestBody's testing rationale).
	 *
	 * @param string $name Funnel step name.
	 * @param string $host Exact hostname to match.
	 * @return array Funnel step definition.
	 */
	public static function buildHostFunnelStep( string $name, string $host ): array {
		return array(
			'name'             => $name,
			'filterExpression' => array(
				'funnelFieldFilter' => array(
					'fieldName'    => 'hostName',
					'stringFilter' => array(
						'matchType' => 'EXACT',
						'value'     => $host,
					),
				),
			),
		);
	}

	/**
	 * Pull step-1 and step-2 activeUsers out of a 2-step funnelTable response.
	 *
	 * The funnelTable returns one row per step (dimension funnelStepName like
	 * "1. entry", "2. next") and an activeUsers metric column. We read step 1 and
	 * step 2 activeUsers by their ordinal step prefix, robust to extra metric
	 * columns (completionRate, abandonments, abandonmentRate).
	 *
	 * @param array $data Raw runFunnelReport response.
	 * @return array array{entry_users:int,next_users:int}
	 */
	private static function extractFunnelStepUsers( array $data ): array {
		$table          = $data['funnelTable'] ?? array();
		$metric_headers = wp_list_pluck( $table['metricHeaders'] ?? array(), 'name' );

		$users_col = array_search( 'activeUsers', $metric_headers, true );
		if ( false === $users_col ) {
			$users_col = 0;
		}

		$entry_users = 0;
		$next_users  = 0;

		foreach ( ( $table['rows'] ?? array() ) as $row ) {
			$step  = $row['dimensionValues'][0]['value'] ?? '';
			$value = (int) ( $row['metricValues'][ $users_col ]['value'] ?? 0 );

			// Step rows are prefixed with their ordinal: "1. entry", "2. next".
			if ( 0 === strpos( $step, '1.' ) ) {
				$entry_users = $value;
			} elseif ( 0 === strpos( $step, '2.' ) ) {
				$next_users = $value;
			}
		}

		return array(
			'entry_users' => $entry_users,
			'next_users'  => $next_users,
		);
	}

	/**
	 * Format GA4 report rows into a flat, readable structure.
	 *
	 * @param array $data          Raw GA4 API response.
	 * @param array $report_config Report configuration with dimension/metric names.
	 * @return array Formatted rows.
	 */
	private static function formatReportRows( array $data): array {
		$dimension_headers = wp_list_pluck( $data['dimensionHeaders'] ?? array(), 'name' );
		$metric_headers    = wp_list_pluck( $data['metricHeaders'] ?? array(), 'name' );

		$rows = array();

		foreach ( ( $data['rows'] ?? array() ) as $row ) {
			$dim_values    = wp_list_pluck( $row['dimensionValues'] ?? array(), 'value' );
			$metric_values = wp_list_pluck( $row['metricValues'] ?? array(), 'value' );

			$formatted = array();
			foreach ( $dimension_headers as $i => $name ) {
				$formatted[ $name ] = $dim_values[ $i ] ?? '';
			}
			foreach ( $metric_headers as $i => $name ) {
				$value = $metric_values[ $i ] ?? '0';
				// Cast numeric strings to appropriate types.
				if ( is_numeric( $value ) ) {
					$formatted[ $name ] = strpos( $value, '.' ) !== false ? (float) $value : (int) $value;
				} else {
					$formatted[ $name ] = $value;
				}
			}

			$rows[] = $formatted;
		}

		return $rows;
	}

	/**
	 * Format GA4 comparison rows with delta columns.
	 *
	 * GA returns a separate row for each date range and adds a synthetic
	 * dateRange dimension. Reconcile those rows by the complete report dimension
	 * tuple, preserving current-period order and omitting prior-only rows.
	 *
	 * @param array $data  Raw GA4 API response.
	 * @param int   $limit Final reconciled row limit.
	 * @return array Formatted rows with delta columns.
	 */
	private static function formatComparisonRows( array $data, int $limit ): array {
		$dimension_headers = wp_list_pluck( $data['dimensionHeaders'] ?? array(), 'name' );
		$metric_headers    = wp_list_pluck( $data['metricHeaders'] ?? array(), 'name' );
		$date_range_index  = array_search( 'dateRange', $dimension_headers, true );
		$current_rows      = array();
		$previous_rows     = array();

		foreach ( ( $data['rows'] ?? array() ) as $row ) {
			$dim_values    = wp_list_pluck( $row['dimensionValues'] ?? array(), 'value' );
			$metric_values = wp_list_pluck( $row['metricValues'] ?? array(), 'value' );
			$dimensions    = array();
			foreach ( $dimension_headers as $i => $name ) {
				if ( 'dateRange' === $name ) {
					continue;
				}
				$dimensions[ $name ] = $dim_values[ $i ] ?? '';
			}

			$key         = wp_json_encode( array_values( $dimensions ) );
			$range       = false !== $date_range_index ? ( $dim_values[ $date_range_index ] ?? 'date_range_0' ) : 'date_range_0';
			$formatted   = $dimensions;
			$raw_metrics = array();
			foreach ( $metric_headers as $i => $name ) {
				$value                = $metric_values[ $i ] ?? '0';
				$raw_metrics[ $name ] = $value;
				$formatted[ $name ]   = is_numeric( $value )
					? ( strpos( $value, '.' ) !== false ? (float) $value : (int) $value )
					: $value;
			}

			$entry = array(
				'formatted' => $formatted,
				'metrics'   => $raw_metrics,
			);

			if ( 'date_range_1' === $range ) {
				$previous_rows[ $key ] = $entry;
			} else {
				$current_rows[ $key ] = $entry;
			}
		}

		$rows = array();
		foreach ( $current_rows as $key => $current_row ) {
			$formatted    = $current_row['formatted'];
			$has_previous = isset( $previous_rows[ $key ] );

			foreach ( $metric_headers as $name ) {
				$current      = $current_row['metrics'][ $name ] ?? '0';
				$current_num  = is_numeric( $current ) ? (float) $current : 0;
				$delta_column = "\xCE\x94 " . $name;

				if ( ! $has_previous ) {
					$formatted[ $delta_column ] = 'new';
					continue;
				}

				$previous     = $previous_rows[ $key ]['metrics'][ $name ] ?? '0';
				$previous_num = is_numeric( $previous ) ? (float) $previous : 0;
				if ( 0.0 !== $previous_num ) {
					$delta                      = ( ( $current_num - $previous_num ) / $previous_num ) * 100;
					$sign                       = $delta >= 0 ? '+' : '';
					$formatted[ $delta_column ] = $sign . round( $delta, 1 ) . '%';
				} else {
					$formatted[ $delta_column ] = '-';
				}
			}

			$rows[] = $formatted;
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Get an OAuth2 access token using service account JWT flow.
	 *
	 * @param array $service_account Parsed service account JSON.
	 * @return string|\WP_Error Access token or error.
	 */
	private static function get_access_token( array $service_account ) {
		$cached = get_transient( self::TOKEN_TRANSIENT );

		if ( ! empty( $cached ) ) {
			return $cached;
		}

		$header = self::base64url_encode( wp_json_encode( array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		) ) );
		$now    = time();
		$claims = self::base64url_encode( wp_json_encode( array(
			'iss'   => $service_account['client_email'],
			'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'iat'   => $now,
			'exp'   => $now + 3600,
		) ) );

		$unsigned = $header . '.' . $claims;

		$sign_result = openssl_sign( $unsigned, $signature, $service_account['private_key'], 'SHA256' );

		if ( ! $sign_result ) {
			return new \WP_Error( 'ga_jwt_sign_failed', 'Failed to sign JWT. Check private key in service account JSON.' );
		}

		$jwt = $unsigned . '.' . self::base64url_encode( $signature );

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			$error_desc = $body['error_description'] ?? ( $body['error'] ?? 'Unknown token error' );
			return new \WP_Error( 'ga_token_failed', 'Failed to get access token: ' . $error_desc );
		}

		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], 3500 );

		return $body['access_token'];
	}

	/**
	 * Base64url encode (RFC 7515).
	 *
	 * @param string $data Data to encode.
	 * @return string Base64url encoded string.
	 */
	private static function base64url_encode( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for API authentication, not obfuscation.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Check if Google Analytics is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$config = self::get_config();
		return ! empty( $config['service_account_json'] ) && ! empty( $config['property_id'] );
	}

	/**
	 * Get stored configuration.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		return get_site_option( self::CONFIG_OPTION, array() );
	}
}
