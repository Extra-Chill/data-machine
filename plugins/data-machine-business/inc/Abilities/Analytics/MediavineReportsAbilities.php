<?php
/**
 * Mediavine Reports Abilities
 *
 * Primitive ability for fetching per-period, per-URL Mediavine ad revenue
 * directly from Mediavine's publisher API. All Mediavine revenue data — CLI,
 * REST, chat, the revenue arc — flows through this ability.
 *
 * Mediavine ships no documented public API, but its dashboard is backed by a
 * clean HTTP API that PHP reaches DIRECTLY from the server (tested 2026-06-21:
 * a plain wp_remote_get of the pages CSV returned HTTP 200) — no browser, no
 * Playwright, no cross-host glue. Two surfaces are used:
 *
 *   1. GraphQL login mutation (unidashSignIn) -> a Bearer access token, cached
 *      in a transient until it expires.
 *   2. The pagesSummary GraphQL report (slug,views,revenue,rpm,cpm,viewability,
 *      fillRate,impressionsPerPageview) for a date range — the rows a consumer
 *      CLI plugin imports into its revenue store.
 *
 * A bonus `summary` action calls the metricsSummary GraphQL query for
 * site-level totals (request variables use ISO timestamps).
 *
 * Structurally this mirrors GoogleAnalyticsAbilities: a CONFIG_OPTION read via
 * get_config(), a token cached in a transient, authenticate -> fetch -> return
 * structured rows, registered on wp_abilities_api_init with
 * PermissionHelper::can_manage under the datamachine-analytics category,
 * show_in_rest => false, instantiated in data-machine-business.php.
 *
 * Credentials ({email, password}) live in the datamachine_mediavine_config
 * option (server-side), never in code. This automates OUR OWN dashboard login
 * to export OUR OWN revenue — keep it low-frequency (backfill once, monthly).
 *
 * @package DataMachineBusiness\Abilities\Analytics
 * @since 0.32.0
 */

namespace DataMachineBusiness\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

class MediavineReportsAbilities {

	/**
	 * Option key for storing Mediavine configuration ({email, password}).
	 *
	 * @var string
	 */
	const CONFIG_OPTION = 'datamachine_mediavine_config';

	/**
	 * Transient key for the cached access token.
	 *
	 * @var string
	 */
	const TOKEN_TRANSIENT = 'datamachine_mediavine_access_token';

	/**
	 * Mediavine publisher API base URL.
	 *
	 * @var string
	 */
	const API_BASE = 'https://api-publishers.mediavine.com';

	/**
	 * Per-page row cap requested from Mediavine.
	 *
	 * @var int
	 */
	const PAGES_PER_PAGE = 100000;

	/**
	 * Whether the upstream page-report schema exposes row-level host attribution.
	 *
	 * Proven by introspection of the Mediavine publisher GraphQL API
	 * (api-publishers.mediavine.com): the `pagesSummary` query returns rows of
	 * type `PageReport`, which exposes `path` (a URL path) plus a per-row
	 * `siteId`/`date` that come back null for aggregate (date-range) queries,
	 * but NO hostname, canonical URL, or domain field. Downstream attribution
	 * that relies on a host must therefore treat every page row as
	 * host-unattributed and resolve ownership through its own out-of-band
	 * mapping rather than guessing from the path.
	 *
	 * @var bool
	 */
	const HOST_ATTRIBUTION_AVAILABLE = false;

	/**
	 * Human-readable page-report reason for HOST_ATTRIBUTION_AVAILABLE.
	 *
	 * References the actual upstream type (`PageReport`) so the limitation is
	 * traceable to the source schema rather than an Extra Chill assumption.
	 *
	 * @var string
	 */
	const PAGES_HOST_ATTRIBUTION_REASON = 'Mediavine PageReport exposes path only; hostname, canonical URL, and domain are not part of the upstream pagesSummary GraphQL schema.';

	/**
	 * Human-readable host attribution reason for aggregate summary reports.
	 *
	 * @var string
	 */
	const SUMMARY_HOST_ATTRIBUTION_REASON = 'Mediavine metricsSummary returns site-level aggregate metrics without row-level URL or host dimensions.';

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
				'datamachine/mediavine-reports',
				array(
					'label'               => 'Mediavine Reports',
					'description'         => 'Fetch per-period, per-URL Mediavine ad revenue directly from the Mediavine publisher GraphQL API. Actions: pages (per-period per-URL revenue rows: slug,views,revenue,rpm,cpm,viewability,fillRate,impressionsPerPageview,period), summary (site-level aggregate totals: earnings, pageviews, sessions, rpm), backfill (iterate a list of periods, returning pages rows per period for a full revenue-arc backfill). Every result batch and each backfill period summary carries a `provenance` block: the requested Mediavine site id, the requested and canonical report period, the source action/query identity, and an explicit host_attribution.available=false flag (the upstream PageReport type exposes path only, never hostname/domain). Credentials live server-side in the datamachine_mediavine_config option.',
					'category'            => 'datamachine-analytics',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'action' ),
						'properties' => array(
							'action'     => array(
								'type'        => 'string',
								'description' => 'Action: pages (per-period per-URL rows), summary (aggregate totals), backfill (iterate periods).',
							),
							'start_date' => array(
								'type'        => 'string',
								'description' => 'Window start in YYYY-MM-DD (used by pages/summary when periods is not supplied).',
							),
							'end_date'   => array(
								'type'        => 'string',
								'description' => 'Window end in YYYY-MM-DD (used by pages/summary when periods is not supplied).',
							),
							'period'     => array(
								'type'        => 'string',
								'description' => 'Optional period label (e.g. 2026-05) stamped on the returned rows so the revenue arc can group by it.',
							),
							'periods'    => array(
								'type'        => 'array',
								'description' => 'For backfill: list of {period, start_date, end_date} objects (YYYY-MM-DD dates). Each yields its own set of pages rows stamped with that period.',
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'period'     => array( 'type' => 'string' ),
										'start_date' => array( 'type' => 'string' ),
										'end_date'   => array( 'type' => 'string' ),
									),
								),
							),
							'site_id'    => array(
								'type'        => 'string',
								'description' => 'Mediavine GraphQL global InternalSite ID. A numeric internal site id is also accepted and encoded. Legacy site slugs are not accepted by the reporting API.',
							),
						),
					),
					'output_schema'       => self::outputSchema(),
					'execute_callback'    => array( self::class, 'fetch' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Return the complete public output contract for every action.
	 */
	public static function outputSchema(): array {
		$result_properties = array(
			'slug'                   => array( 'type' => 'string' ),
			'views'                  => array( 'type' => 'integer' ),
			'revenue'                => array( 'type' => 'number' ),
			'rpm'                    => array( 'type' => 'number' ),
			'cpm'                    => array( 'type' => 'number' ),
			'viewability'            => array( 'type' => 'number' ),
			'fillRate'               => array( 'type' => 'number' ),
			'impressionsPerPageview' => array( 'type' => 'number' ),
			'period'                 => array( 'type' => 'string' ),
			'earnings'               => array( 'type' => 'number' ),
			'pageviews'              => array( 'type' => 'integer' ),
			'sessions'               => array( 'type' => 'integer' ),
			'sessionRpm'             => array( 'type' => 'number' ),
			'pageRpm'                => array( 'type' => 'number' ),
			'paidImpressions'        => array( 'type' => 'integer' ),
		);

		return array(
			'type'       => 'object',
			'required'   => array( 'success' ),
			'properties' => array(
				'success'       => array( 'type' => 'boolean' ),
				'action'        => array(
					'type' => 'string',
					'enum' => array( 'pages', 'summary', 'backfill' ),
				),
				'site_id'       => array(
					'type'        => 'string',
					'description' => 'Normalized Relay global InternalSite ID sent to Mediavine.',
				),
				'period'        => array( 'type' => 'string' ),
				'date_range'    => array(
					'type'       => 'object',
					'required'   => array( 'start_date', 'end_date' ),
					'properties' => array(
						'start_date' => array( 'type' => 'string' ),
						'end_date'   => array( 'type' => 'string' ),
					),
				),
				'results_count' => array( 'type' => 'integer' ),
				'results'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => $result_properties,
					),
				),
				'periods'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'required'   => array( 'period', 'start_date', 'end_date', 'rows', 'provenance' ),
						'properties' => array(
							'period'     => array( 'type' => 'string' ),
							'start_date' => array( 'type' => 'string' ),
							'end_date'   => array( 'type' => 'string' ),
							'rows'       => array( 'type' => 'integer' ),
							'provenance' => self::provenanceSchema(),
							'error'      => array( 'type' => 'string' ),
						),
					),
				),
				'provenance'    => self::provenanceSchema(),
				'error'         => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Return the source provenance output contract.
	 */
	private static function provenanceSchema(): array {
		$date_pair = array(
			'type'       => 'object',
			'required'   => array( 'start', 'end' ),
			'properties' => array(
				'start' => array( 'type' => array( 'string', 'null' ) ),
				'end'   => array( 'type' => array( 'string', 'null' ) ),
			),
		);

		return array(
			'type'        => 'object',
			'description' => 'Source provenance for the requested site, normalized report identity, period boundaries, and host attribution support.',
			'required'    => array( 'source', 'site', 'period', 'host_attribution' ),
			'properties'  => array(
				'source'           => array(
					'type'       => 'object',
					'required'   => array( 'ability', 'action', 'operation', 'api' ),
					'properties' => array(
						'ability'   => array( 'type' => 'string' ),
						'action'    => array(
							'type' => 'string',
							'enum' => array( 'pages', 'summary', 'backfill' ),
						),
						'operation' => array( 'type' => 'string' ),
						'api'       => array( 'type' => 'string' ),
					),
				),
				'site'             => array(
					'type'       => 'object',
					'required'   => array( 'requested_id', 'relay_id', 'internal_id' ),
					'properties' => array(
						'requested_id' => array( 'type' => 'string' ),
						'relay_id'     => array( 'type' => 'string' ),
						'internal_id'  => array( 'type' => array( 'string', 'null' ) ),
					),
				),
				'period'           => array(
					'type'       => 'object',
					'required'   => array( 'requested', 'canonical', 'row_count' ),
					'properties' => array(
						'requested' => $date_pair,
						'canonical' => $date_pair,
						'row_count' => array( 'type' => array( 'integer', 'null' ) ),
					),
				),
				'host_attribution' => array(
					'type'       => 'object',
					'required'   => array( 'available', 'reason' ),
					'properties' => array(
						'available' => array( 'type' => 'boolean' ),
						'reason'    => array( 'type' => 'string' ),
					),
				),
			),
		);
	}

	/**
	 * Ability entry point — route to the requested action.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function fetch( array $input ): array {
		$action = sanitize_text_field( $input['action'] ?? '' );

		$valid_actions = array( 'pages', 'summary', 'backfill' );
		if ( empty( $action ) || ! in_array( $action, $valid_actions, true ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid action. Must be one of: ' . implode( ', ', $valid_actions ),
			);
		}

		$config = self::get_config();

		if ( empty( $config['email'] ) || empty( $config['password'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Mediavine not configured. Set {email, password} in the datamachine_mediavine_config option.',
			);
		}

		$access_token = self::get_access_token( $config );

		if ( is_wp_error( $access_token ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to authenticate with Mediavine: ' . $access_token->get_error_message(),
			);
		}

		$requested_site_id = self::resolve_site_id( $input, $config );

		if ( '' === $requested_site_id ) {
			return array(
				'success' => false,
				'error'   => 'A Mediavine site id is required. Pass the "site_id" input, set site_id in the datamachine_mediavine_config option, or register a default via the datamachine_mediavine_default_site_id filter.',
			);
		}

		$site_id = self::normalizeReportSiteId( $requested_site_id );
		if ( is_wp_error( $site_id ) ) {
			return array(
				'success' => false,
				'error'   => $site_id->get_error_message(),
			);
		}

		if ( 'summary' === $action ) {
			return self::fetchSummary( $input, $access_token, $requested_site_id, $site_id );
		}

		if ( 'backfill' === $action ) {
			return self::fetchBackfill( $input, $access_token, $requested_site_id, $site_id );
		}

		return self::fetchPages( $input, $access_token, $requested_site_id, $site_id );
	}

	/**
	 * Fetch per-page revenue rows for a single date range.
	 *
	 * @param array  $input        Ability input.
	 * @param string $access_token Bearer token.
	 * @param string $requested_site_id Original site id supplied by the caller or configuration.
	 * @param string $site_id      Normalized Relay global Mediavine site id.
	 * @return array
	 */
	private static function fetchPages( array $input, string $access_token, string $requested_site_id, string $site_id ): array {
		$start_date = self::resolveDate( $input['start_date'] ?? '', '-28 days' );
		$end_date   = self::resolveDate( $input['end_date'] ?? '', '-1 day' );
		$period     = sanitize_text_field( $input['period'] ?? '' );

		$parsed = self::fetchPagesRows( $access_token, $site_id, $start_date, $end_date, $period );

		if ( is_wp_error( $parsed ) ) {
			return array(
				'success' => false,
				'error'   => $parsed->get_error_message(),
			);
		}

		return self::buildPagesResult( $requested_site_id, $site_id, $start_date, $end_date, $period, $parsed );
	}

	/**
	 * Assemble the `pages` action response, including source provenance.
	 *
	 * Pure (no HTTP) so the batch-level shape — rows plus provenance — is
	 * unit-testable without a network round-trip.
	 *
	 * @param string $requested_site_id Original site id supplied by the caller or configuration.
	 * @param string $site_id     Normalized Relay global Mediavine site id.
	 * @param string $start_date  Requested window start (Y-m-d).
	 * @param string $end_date    Requested window end (Y-m-d).
	 * @param string $period      Period label stamped on rows.
	 * @param array  $parsed      Parsed payload from parsePagesPayload() {rows, meta}.
	 * @return array
	 */
	public static function buildPagesResult( string $requested_site_id, string $site_id, string $start_date, string $end_date, string $period, array $parsed ): array {
		$rows = $parsed['rows'] ?? array();
		$meta = $parsed['meta'] ?? array();

		return array(
			'success'       => true,
			'action'        => 'pages',
			'site_id'       => $site_id,
			'period'        => $period,
			'date_range'    => array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
			),
			'results_count' => count( $rows ),
			'results'       => $rows,
			'provenance'    => self::buildProvenance( 'pages', 'PagesSummaryQuery', $requested_site_id, $site_id, $start_date, $end_date, $meta ),
		);
	}

	/**
	 * Iterate a list of periods, fetching per-page rows for each.
	 *
	 * The list shape is [{period, start_date, end_date}, ...]. Each period that
	 * fetches successfully contributes its rows; per-period failures are
	 * reported in the period summary rather than aborting the whole backfill, so
	 * a single bad month never loses the rest of a multi-year backfill.
	 *
	 * @param array  $input        Ability input.
	 * @param string $access_token Bearer token.
	 * @param string $requested_site_id Original site id supplied by the caller or configuration.
	 * @param string $site_id      Normalized Relay global Mediavine site id.
	 * @return array
	 */
	private static function fetchBackfill( array $input, string $access_token, string $requested_site_id, string $site_id ): array {
		$periods = $input['periods'] ?? array();

		if ( ! is_array( $periods ) || empty( $periods ) ) {
			return array(
				'success' => false,
				'error'   => 'backfill requires a non-empty "periods" list of {period, start_date, end_date} objects.',
			);
		}

		$all_rows         = array();
		$period_summaries = array();

		foreach ( $periods as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$period     = sanitize_text_field( $entry['period'] ?? '' );
			$start_date = self::resolveDate( $entry['start_date'] ?? '', '-28 days' );
			$end_date   = self::resolveDate( $entry['end_date'] ?? '', '-1 day' );

			$parsed = self::fetchPagesRows( $access_token, $site_id, $start_date, $end_date, $period );

			if ( is_wp_error( $parsed ) ) {
				$period_summaries[] = self::buildBackfillPeriodSummary( $requested_site_id, $site_id, $period, $start_date, $end_date, 0, array(), $parsed->get_error_message() );
				continue;
			}

			$rows               = $parsed['rows'] ?? array();
			$all_rows           = array_merge( $all_rows, $rows );
			$period_summaries[] = self::buildBackfillPeriodSummary( $requested_site_id, $site_id, $period, $start_date, $end_date, count( $rows ), $parsed['meta'] ?? array() );
		}

		return self::buildBackfillResult( $requested_site_id, $site_id, $period_summaries, $all_rows );
	}

	/**
	 * Assemble a single backfill period summary, including source provenance.
	 *
	 * Provenance is carried per-period (not only at the top level) so a
	 * downstream consumer can attribute each batch independently, including a
	 * period that failed to fetch (provenance still records what was requested).
	 *
	 * @param string $requested_site_id Original site id supplied by the caller or configuration.
	 * @param string $site_id     Normalized Relay global Mediavine site id.
	 * @param string $period      Period label.
	 * @param string $start_date  Requested window start (Y-m-d).
	 * @param string $end_date    Requested window end (Y-m-d).
	 * @param int    $row_count   Rows fetched for this period.
	 * @param array  $meta        Canonical meta from upstream (reportStart/reportEnd/totalCount).
	 * @param string|null $error  Optional per-period error message.
	 * @return array
	 */
	public static function buildBackfillPeriodSummary( string $requested_site_id, string $site_id, string $period, string $start_date, string $end_date, int $row_count, array $meta = array(), ?string $error = null ): array {
		$summary = array(
			'period'     => $period,
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'rows'       => $row_count,
			'provenance' => self::buildProvenance( 'backfill', 'PagesSummaryQuery', $requested_site_id, $site_id, $start_date, $end_date, $meta ),
		);

		if ( null !== $error ) {
			$summary['error'] = $error;
		}

		return $summary;
	}

	/**
	 * Assemble the top-level backfill response, including source provenance.
	 *
	 * The top-level provenance spans the full requested range (min start to
	 * max end across periods) so consumers know the overall window without
	 * re-deriving it from the period list.
	 *
	 * @param string $requested_site_id Original site id supplied by the caller or configuration.
	 * @param string $site_id          Normalized Relay global Mediavine site id.
	 * @param array  $period_summaries Per-period summaries (each carries its own provenance).
	 * @param array  $all_rows         Flattened rows across all periods.
	 * @return array
	 */
	public static function buildBackfillResult( string $requested_site_id, string $site_id, array $period_summaries, array $all_rows ): array {
		$span_start = '';
		$span_end   = '';
		foreach ( $period_summaries as $ps ) {
			$s = isset( $ps['start_date'] ) ? (string) $ps['start_date'] : '';
			$e = isset( $ps['end_date'] ) ? (string) $ps['end_date'] : '';
			if ( '' !== $s && ( '' === $span_start || $s < $span_start ) ) {
				$span_start = $s;
			}
			if ( '' !== $e && ( '' === $span_end || $e > $span_end ) ) {
				$span_end = $e;
			}
		}

		return array(
			'success'       => true,
			'action'        => 'backfill',
			'site_id'       => $site_id,
			'periods'       => $period_summaries,
			'results_count' => count( $all_rows ),
			'results'       => $all_rows,
			'provenance'    => self::buildProvenance( 'backfill', 'PagesSummaryQuery', $requested_site_id, $site_id, $span_start, $span_end, array() ),
		);
	}

	/**
	 * Fetch the current pagesSummary GraphQL report and normalize its payload.
	 *
	 * GraphQL fields are normalized to slug,views,revenue,rpm,cpm,viewability,
	 * fillRate,impressionsPerPageview — exactly the established public row shape.
	 * Each parsed row also carries the supplied period for revenue-arc grouping.
	 * The upstream `meta` block (totalCount/reportStart/reportEnd) is preserved
	 * so the canonical report period survives into the provenance block.
	 *
	 * @param string $access_token Bearer token.
	 * @param string $site_id      Mediavine site id.
	 * @param string $start_date   Window start (Y-m-d).
	 * @param string $end_date     Window end (Y-m-d).
	 * @param string $period       Period label to stamp on each row.
	 * @return array|\WP_Error Parsed payload {rows, meta} or error.
	 */
	private static function fetchPagesRows( string $access_token, string $site_id, string $start_date, string $end_date, string $period ) {
		$result = HttpClient::post(
			self::API_BASE . '/graphql',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( self::buildPagesRequestBody( $site_id, $start_date, $end_date ) ),
				'context' => 'Mediavine pagesSummary',
			)
		);

		if ( empty( $result['success'] ) ) {
			return new \WP_Error( 'mediavine_pages_transport', 'Mediavine pages report transport or authorization failure: ' . ( $result['error'] ?? 'Unknown error' ) );
		}

		$data = json_decode( (string) ( $result['data'] ?? '' ), true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'mediavine_pages_parse', 'Mediavine pages report returned invalid JSON.' );
		}

		$error = self::graphqlError( $data );
		if ( null !== $error ) {
			return new \WP_Error( 'mediavine_pages_schema', 'Mediavine pages report schema or authorization error: ' . $error );
		}

		return self::parsePagesPayload( $data, $period );
	}

	/**
	 * Build the current pagesSummary request contract.
	 */
	public static function buildPagesRequestBody( string $site_id, string $start_date, string $end_date ): array {
		return array(
			'query'         => 'query PagesSummaryQuery($data: GetPagesSummaryInput!){ pagesSummary(data:$data){ meta{ totalCount reportStart reportEnd } pages{ path pageviews pageRevenue rpm cpm viewability fillrate impressionsPerPageView } } }',
			'operationName' => 'PagesSummaryQuery',
			'variables'     => array(
				'data' => array(
					'siteId'    => $site_id,
					'startDate' => self::toIso( $start_date, false ),
					'endDate'   => self::toIso( $end_date, true ),
					'page'      => 1,
					'perPage'   => self::PAGES_PER_PAGE,
					'sort'      => 'pageRevenue',
					'direction' => 'desc',
				),
			),
		);
	}

	/**
	 * Normalize a pagesSummary response into the established ability row shape.
	 *
	 * Backward-compatible wrapper: returns only the parsed rows (or WP_Error).
	 * New callers should use parsePagesPayload() to also receive the canonical
	 * upstream meta block (reportStart/reportEnd/totalCount).
	 *
	 * @return array|\WP_Error
	 */
	public static function parsePagesResponse( array $data, string $period ) {
		$parsed = self::parsePagesPayload( $data, $period );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return $parsed['rows'];
	}

	/**
	 * Normalize a pagesSummary response into rows plus the canonical meta block.
	 *
	 * The established row shape is preserved exactly
	 * (slug,views,revenue,rpm,cpm,viewability,fillRate,impressionsPerPageview,period)
	 * so existing consumers are unaffected. The upstream `meta`
	 * (totalCount/reportStart/reportEnd) is captured separately so the canonical
	 * report period can be propagated into source provenance. Note: the upstream
	 * `PageReport` type exposes `path` (a URL path) but no hostname/domain, so
	 * no host field is read here — host attribution is reported as unavailable
	 * in the provenance block rather than synthesized.
	 *
	 * @return array|\WP_Error {rows: array, meta: array} or error.
	 */
	public static function parsePagesPayload( array $data, string $period ) {
		$payload = $data['data']['pagesSummary'] ?? null;
		if ( ! is_array( $payload ) || ! isset( $payload['pages'] ) || ! is_array( $payload['pages'] ) ) {
			return new \WP_Error( 'mediavine_pages_contract', 'Mediavine pages report response is missing pagesSummary.pages.' );
		}

		$raw_meta = is_array( $payload['meta'] ?? null ) ? $payload['meta'] : array();

		if ( empty( $payload['pages'] ) ) {
			return new \WP_Error( 'mediavine_pages_empty', 'Mediavine pages report returned no page-level rows for the requested date range.' );
		}

		$rows = array();
		foreach ( $payload['pages'] as $page ) {
			if ( ! is_array( $page ) || empty( $page['path'] ) ) {
				continue;
			}

			$rows[] = array(
				'slug'                   => (string) $page['path'],
				'views'                  => (int) ( $page['pageviews'] ?? 0 ),
				'revenue'                => (float) ( $page['pageRevenue'] ?? 0 ),
				'rpm'                    => (float) ( $page['rpm'] ?? 0 ),
				'cpm'                    => (float) ( $page['cpm'] ?? 0 ),
				'viewability'            => (float) ( $page['viewability'] ?? 0 ),
				'fillRate'               => (float) ( $page['fillrate'] ?? 0 ),
				'impressionsPerPageview' => (float) ( $page['impressionsPerPageView'] ?? 0 ),
				'period'                 => $period,
			);
		}

		if ( empty( $rows ) ) {
			return new \WP_Error( 'mediavine_pages_empty', 'Mediavine pages report contained no usable page-level rows.' );
		}

		return array(
			'rows' => $rows,
			'meta' => self::normalizeReportMeta( $raw_meta ),
		);
	}

	/**
	 * Normalize the upstream `meta` (ReportMeta) block into a typed array.
	 *
	 * reportStart/reportEnd arrive as YYYY/MM/DD strings (e.g. "2026/06/01");
	 * they are preserved verbatim as the canonical period rather than
	 * re-formatted, so consumers can see exactly what the upstream reported.
	 *
	 * @param array $meta Raw meta block.
	 * @return array
	 */
	public static function normalizeReportMeta( array $meta ): array {
		return array(
			'totalCount'  => isset( $meta['totalCount'] ) ? (int) $meta['totalCount'] : null,
			'reportStart' => isset( $meta['reportStart'] ) ? (string) $meta['reportStart'] : null,
			'reportEnd'   => isset( $meta['reportEnd'] ) ? (string) $meta['reportEnd'] : null,
		);
	}

	/**
	 * Build the source provenance block for a result batch.
	 *
	 * Provenance preserves exactly what the upstream source can truthfully
	 * provide:
	 *   - the requested Mediavine site id (relay-encoded) plus its decoded
	 *     numeric internal id when derivable;
	 *   - the requested report window (Y-m-d, what the caller asked for) and
	 *     the canonical report period (the upstream meta reportStart/reportEnd);
	 *   - the source action and GraphQL operation identity;
	 *   - an explicit host_attribution.available flag. This is always false for
	 *     pagesSummary because the upstream `PageReport` type exposes `path`
	 *     only — proven by schema introspection, not assumed. Consumers must
	 *     not infer a host from the row path.
	 *
	 * @param string $action            Ability action (pages|summary|backfill).
	 * @param string $operation         GraphQL operation name.
	 * @param string $requested_site_id Original site id supplied by input, config, or filter.
	 * @param string $relay_site_id     Normalized Relay global site id used in the request.
	 * @param string $start_date        Requested window start (Y-m-d).
	 * @param string $end_date          Requested window end (Y-m-d).
	 * @param array  $meta              Normalized canonical meta block.
	 * @return array
	 */
	public static function buildProvenance( string $action, string $operation, string $requested_site_id, string $relay_site_id, string $start_date, string $end_date, array $meta = array() ): array {
		$host_reason = 'summary' === $action ? self::SUMMARY_HOST_ATTRIBUTION_REASON : self::PAGES_HOST_ATTRIBUTION_REASON;

		return array(
			'source'           => array(
				'ability'   => 'datamachine/mediavine-reports',
				'action'    => $action,
				'operation' => $operation,
				'api'       => self::API_BASE . '/graphql',
			),
			'site'             => array(
				'requested_id' => $requested_site_id,
				'relay_id'     => $relay_site_id,
				'internal_id'  => self::decodeInternalSiteId( $relay_site_id ),
			),
			'period'           => array(
				'requested' => array(
					'start' => $start_date,
					'end'   => $end_date,
				),
				'canonical' => array(
					'start' => $meta['reportStart'] ?? null,
					'end'   => $meta['reportEnd'] ?? null,
				),
				'row_count' => array_key_exists( 'totalCount', $meta ) ? $meta['totalCount'] : null,
			),
			'host_attribution' => array(
				'available' => self::HOST_ATTRIBUTION_AVAILABLE,
				'reason'    => $host_reason,
			),
		);
	}

	/**
	 * Decode a numeric internal site id from a relay-encoded or raw site id.
	 *
	 * Reporting requests use a Relay global id ("Base64(InternalSite:<n>)").
	 * A plain numeric id is passed through. Returns null when the id cannot be
	 * resolved to a numeric internal id (e.g. a legacy slug).
	 *
	 * @param string $requested_site_id Site id used in the request.
	 * @return string|null Numeric internal id, or null.
	 */
	public static function decodeInternalSiteId( string $requested_site_id ): ?string {
		if ( '' === $requested_site_id ) {
			return null;
		}

		if ( ctype_digit( $requested_site_id ) ) {
			return $requested_site_id;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Read a Relay global ID type prefix.
		$decoded = base64_decode( $requested_site_id, true );
		if ( false !== $decoded && str_starts_with( $decoded, 'InternalSite:' ) ) {
			$numeric = substr( $decoded, strlen( 'InternalSite:' ) );
			return ctype_digit( $numeric ) ? $numeric : null;
		}

		return null;
	}

	/**
	 * Parse the per-page revenue CSV body into structured rows.
	 *
	 * Public-by-extraction so CSV parsing is unit-testable without an HTTP
	 * round-trip. Header names are matched loosely (lowercased, non-alphanumeric
	 * stripped) so minor dashboard renames still map. Numbers are returned typed
	 * (views int, money/ratios float) and each row carries the period.
	 *
	 * @param string $csv    Raw CSV body.
	 * @param string $period Period label to stamp on each row.
	 * @return array Parsed rows.
	 */
	public static function parsePagesCsv( string $csv, string $period ): array {
		$csv = trim( $csv );
		if ( '' === $csv ) {
			return array();
		}

		$lines = preg_split( '/\r\n|\r|\n/', $csv );
		if ( empty( $lines ) ) {
			return array();
		}

		$header_cells = str_getcsv( array_shift( $lines ) );
		$column_index = array();
		foreach ( $header_cells as $i => $raw ) {
			$key = preg_replace( '/[^a-z0-9]/', '', strtolower( trim( (string) $raw ) ) );
			if ( '' !== $key ) {
				$column_index[ $key ] = $i;
			}
		}

		// Canonical token => output field. Mirrors the importer's loose matching
		// so the rows this ability emits drop straight into the revenue store.
		$field_map = array(
			'slug'                   => array( 'slug', 'string' ),
			'url'                    => array( 'slug', 'string' ),
			'page'                   => array( 'slug', 'string' ),
			'views'                  => array( 'views', 'int' ),
			'pageviews'              => array( 'views', 'int' ),
			'revenue'                => array( 'revenue', 'float' ),
			'earnings'               => array( 'revenue', 'float' ),
			'rpm'                    => array( 'rpm', 'float' ),
			'cpm'                    => array( 'cpm', 'float' ),
			'viewability'            => array( 'viewability', 'float' ),
			'fillrate'               => array( 'fillRate', 'float' ),
			'impressionsperpageview' => array( 'impressionsPerPageview', 'float' ),
		);

		$rows = array();
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			$cells = str_getcsv( $line );
			$row   = array();

			foreach ( $field_map as $token => $spec ) {
				if ( ! isset( $column_index[ $token ] ) ) {
					continue;
				}
				$idx = $column_index[ $token ];
				$raw = isset( $cells[ $idx ] ) ? (string) $cells[ $idx ] : '';

				list( $field, $type ) = $spec;

				if ( 'string' === $type ) {
					// Only the first matching slug-token wins (slug before url/page).
					if ( ! isset( $row[ $field ] ) ) {
						$row[ $field ] = trim( $raw );
					}
				} else {
					$number = self::parseNumber( $raw );
					$value  = 'int' === $type ? (int) $number : $number;
					if ( ! isset( $row[ $field ] ) ) {
						$row[ $field ] = $value;
					}
				}
			}

			if ( empty( $row['slug'] ) ) {
				continue;
			}

			$row['period'] = $period;
			$rows[]        = $row;
		}

		return $rows;
	}

	/**
	 * Fetch the site-level metricsSummary aggregate.
	 *
	 * NOTE: metricsSummary request variables use ISO 8601 timestamps (e.g.
	 * 2023-01-01T05:00:00.000Z). ReportMeta response boundaries use YYYY/MM/DD.
	 * The Y-m-d input window is converted to start/end-of-day ISO instants here.
	 *
	 * @param array  $input        Ability input.
	 * @param string $access_token Bearer token.
	 * @param string $requested_site_id Original site id supplied by the caller or configuration.
	 * @param string $site_id      Normalized Relay global Mediavine site id.
	 * @return array
	 */
	private static function fetchSummary( array $input, string $access_token, string $requested_site_id, string $site_id ): array {
		$start_date = self::resolveDate( $input['start_date'] ?? '', '-28 days' );
		$end_date   = self::resolveDate( $input['end_date'] ?? '', '-1 day' );

		$query = 'query MetricsSummaryQuery($data: GetMetricsSummaryInput!){ metricsSummary(data:$data){ meta{ totalCount reportStart reportEnd } summary{ earnings pageviews sessions cpm sessionRpm pageRpm paidImpressions } } }';

		$body = array(
			'query'         => $query,
			'operationName' => 'MetricsSummaryQuery',
			'variables'     => array(
				'data' => array(
					'siteId'    => $site_id,
					'startDate' => self::toIso( $start_date, false ),
					'endDate'   => self::toIso( $end_date, true ),
				),
			),
		);

		$result = HttpClient::post(
			self::API_BASE . '/graphql',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'context' => 'Mediavine metricsSummary',
			)
		);

		if ( empty( $result['success'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to fetch Mediavine summary: ' . ( $result['error'] ?? 'Unknown error' ),
			);
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse Mediavine summary response.',
			);
		}

		$error = self::graphqlError( $data );
		if ( null !== $error ) {
			return array(
				'success' => false,
				'error'   => 'Mediavine summary API error: ' . $error,
			);
		}

		$payload = $data['data']['metricsSummary'] ?? array();
		$summary = $payload['summary'] ?? array();
		if ( empty( $summary ) ) {
			return array(
				'success' => false,
				'error'   => 'Mediavine summary report returned no summary data for the requested date range.',
			);
		}

		$row = array(
			'period'          => sanitize_text_field( $input['period'] ?? '' ),
			'earnings'        => (float) ( $summary['earnings'] ?? 0 ),
			'pageviews'       => (int) ( $summary['pageviews'] ?? 0 ),
			'sessions'        => (int) ( $summary['sessions'] ?? 0 ),
			'cpm'             => (float) ( $summary['cpm'] ?? 0 ),
			'sessionRpm'      => (float) ( $summary['sessionRpm'] ?? 0 ),
			'pageRpm'         => (float) ( $summary['pageRpm'] ?? 0 ),
			'paidImpressions' => (int) ( $summary['paidImpressions'] ?? 0 ),
		);

		$meta = self::normalizeReportMeta( is_array( $payload['meta'] ?? null ) ? $payload['meta'] : array() );

		return self::buildSummaryResult( $requested_site_id, $site_id, $start_date, $end_date, $row, $meta );
	}

	/**
	 * Assemble the `summary` action response, including source provenance.
	 *
	 * Pure (no HTTP) so the summary batch shape — including provenance and the
	 * canonical period — is unit-testable without a network round-trip.
	 *
	 * @param string $requested_site_id Original site id supplied by the caller or configuration.
	 * @param string $site_id     Normalized Relay global Mediavine site id.
	 * @param string $start_date  Requested window start (Y-m-d).
	 * @param string $end_date    Requested window end (Y-m-d).
	 * @param array  $row         Normalized summary row.
	 * @param array  $meta        Normalized canonical meta block.
	 * @return array
	 */
	public static function buildSummaryResult( string $requested_site_id, string $site_id, string $start_date, string $end_date, array $row, array $meta = array() ): array {
		return array(
			'success'       => true,
			'action'        => 'summary',
			'site_id'       => $site_id,
			'date_range'    => array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
			),
			'results_count' => 1,
			'results'       => array( $row ),
			'provenance'    => self::buildProvenance( 'summary', 'MetricsSummaryQuery', $requested_site_id, $site_id, $start_date, $end_date, $meta ),
		);
	}

	/**
	 * Get a Bearer access token, logging in via GraphQL if not cached.
	 *
	 * The unidashSignIn mutation returns accessToken + expiresIn. The token is
	 * cached in a transient until shortly before expiresIn so repeat calls in a
	 * backfill reuse one login. If twoFactorRequired is true the account has 2FA
	 * enabled, which this flow cannot satisfy — a clear error is returned.
	 *
	 * @param array $config Stored config ({email, password}).
	 * @return string|\WP_Error Access token or error.
	 */
	private static function get_access_token( array $config ) {
		$cached = get_transient( self::TOKEN_TRANSIENT );

		if ( ! empty( $cached ) ) {
			return $cached;
		}

		$query = 'mutation LoginFormMutation($data: UnidashSignInInput!){ unidashSignIn(data:$data){ accessToken expiresIn refreshToken tokenType twoFactorRequired userId } }';

		$body = array(
			'query'         => $query,
			'operationName' => 'LoginFormMutation',
			'variables'     => array(
				'data' => array(
					'email'    => (string) $config['email'],
					'password' => (string) $config['password'],
				),
			),
		);

		$result = HttpClient::post(
			self::API_BASE . '/graphql',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'context' => 'Mediavine login',
			)
		);

		if ( empty( $result['success'] ) ) {
			return new \WP_Error( 'mediavine_login_failed', $result['error'] ?? 'Unknown error' );
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'mediavine_login_parse', 'Failed to parse Mediavine login response.' );
		}

		$error = self::graphqlError( $data );
		if ( null !== $error ) {
			return new \WP_Error( 'mediavine_login_api', $error );
		}

		$sign_in = $data['data']['unidashSignIn'] ?? array();

		if ( ! empty( $sign_in['twoFactorRequired'] ) ) {
			return new \WP_Error( 'mediavine_2fa_required', 'Mediavine login requires two-factor authentication, which is not supported by this ability.' );
		}

		$access_token = $sign_in['accessToken'] ?? '';

		if ( empty( $access_token ) ) {
			return new \WP_Error( 'mediavine_no_token', 'Mediavine login did not return an access token. Check the configured email/password.' );
		}

		// Cache until shortly before expiry. expiresIn is in seconds; default to
		// ~50 minutes if absent, and always keep a 60s safety margin.
		$expires_in = (int) ( $sign_in['expiresIn'] ?? 3000 );
		$ttl        = max( 60, $expires_in - 60 );

		set_transient( self::TOKEN_TRANSIENT, $access_token, $ttl );

		return $access_token;
	}

	/**
	 * Extract a human-readable GraphQL error message, if any.
	 *
	 * @param mixed $data Decoded GraphQL response.
	 * @return string|null Error message or null when there is no error.
	 */
	private static function graphqlError( $data ): ?string {
		if ( ! is_array( $data ) ) {
			return 'Empty or non-JSON response.';
		}

		if ( empty( $data['errors'] ) || ! is_array( $data['errors'] ) ) {
			return null;
		}

		$messages = array();
		foreach ( $data['errors'] as $err ) {
			if ( is_array( $err ) && ! empty( $err['message'] ) ) {
				$messages[] = (string) $err['message'];
			}
		}

		return empty( $messages ) ? 'Unknown GraphQL error.' : implode( '; ', $messages );
	}

	/**
	 * Resolve a date input to Y-m-d, falling back to a relative default.
	 *
	 * @param string $value   Raw date input.
	 * @param string $fallback Relative fallback (e.g. "-28 days").
	 * @return string Y-m-d date.
	 */
	private static function resolveDate( string $value, string $fallback ): string {
		$value = sanitize_text_field( $value );
		$ts    = '' !== $value ? strtotime( $value ) : false;
		if ( false === $ts ) {
			$ts = strtotime( $fallback );
		}
		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Normalize the configured identifier for current report operations.
	 *
	 * Current reports require a Relay global InternalSite ID. Numeric internal
	 * ids are accepted as a convenience; legacy dashboard slugs cannot be
	 * resolved with publisher credentials and therefore fail explicitly.
	 *
	 * @return string|\WP_Error
	 */
	private static function normalizeReportSiteId( string $site_id ) {
		if ( ctype_digit( $site_id ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Relay global ID encoding, not obfuscation.
			return base64_encode( 'InternalSite:' . $site_id );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Validate the Relay global ID type.
		$decoded = base64_decode( $site_id, true );
		if ( false !== $decoded && str_starts_with( $decoded, 'InternalSite:' ) ) {
			return $site_id;
		}

		return new \WP_Error( 'mediavine_site_id_contract', 'Mediavine reporting requires a GraphQL global InternalSite ID (or numeric internal site id); legacy site slugs are no longer accepted.' );
	}

	/**
	 * Convert a Y-m-d date to an ISO 8601 instant for metricsSummary.
	 *
	 * The summary GraphQL query uses ISO timestamps. Start dates map to the start
	 * of the day, end dates to the end of the day, both in UTC.
	 *
	 * @param string $date     Y-m-d date.
	 * @param bool   $end_of_day Whether this is an end date (end of day).
	 * @return string ISO 8601 timestamp.
	 */
	private static function toIso( string $date, bool $end_of_day ): string {
		$ts = strtotime( $date );
		if ( false === $ts ) {
			$ts = time();
		}
		$time = $end_of_day ? '23:59:59.999' : '00:00:00.000';
		return gmdate( 'Y-m-d', $ts ) . 'T' . $time . 'Z';
	}

	/**
	 * Parse a money/number cell into a float (strips $, commas, %).
	 *
	 * @param string $value Raw cell.
	 * @return float
	 */
	private static function parseNumber( string $value ): float {
		$value = preg_replace( '/[^0-9.\-]/', '', $value );
		return '' === $value ? 0.0 : (float) $value;
	}

	/**
	 * Check if Mediavine is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$config = self::get_config();
		return ! empty( $config['email'] ) && ! empty( $config['password'] );
	}

	/**
	 * Resolve the Mediavine site id to query.
	 *
	 * Resolution order: explicit input -> the configured `site_id` in the
	 * datamachine_mediavine_config option -> the
	 * `datamachine_mediavine_default_site_id` filter -> empty. This generic
	 * layer ships with no site id baked in; when nothing resolves, the caller
	 * must require the input.
	 *
	 * @param array $input  Ability input.
	 * @param array $config Stored Mediavine config.
	 * @return string Mediavine site id, or '' when nothing supplies one.
	 */
	private static function resolve_site_id( array $input, array $config ): string {
		if ( ! empty( $input['site_id'] ) ) {
			return sanitize_text_field( $input['site_id'] );
		}

		if ( ! empty( $config['site_id'] ) ) {
			return (string) $config['site_id'];
		}

		/**
		 * Filter the default Mediavine site id.
		 *
		 * Generic Data Machine layers ship with no site id baked in. A consumer
		 * plugin registers its own Mediavine site id here so the revenue
		 * abilities can run without an explicit `site_id` input on every call.
		 *
		 * @param string $site_id Default Mediavine site id. Empty string by default.
		 */
		return sanitize_text_field( (string) apply_filters( 'datamachine_mediavine_default_site_id', '' ) );
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
