<?php
/**
 * Google Search Console Abilities
 *
 * Primitive ability for Google Search Console Search Analytics API.
 * All GSC data — tools, CLI, REST, chat — flows through this ability.
 *
 * @package DataMachine\Abilities\Analytics
 * @since 0.25.0
 */

namespace DataMachine\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

class GoogleSearchConsoleAbilities {

	/**
	 * Option key for storing GSC configuration.
	 *
	 * @var string
	 */
	const CONFIG_OPTION = 'datamachine_gsc_config';

	/**
	 * Transient key for cached access token.
	 *
	 * @var string
	 */
	const TOKEN_TRANSIENT = 'datamachine_gsc_access_token';

	/**
	 * Action-to-dimensions mapping.
	 *
	 * @var array
	 */
	const ACTION_DIMENSIONS = array(
		'query_stats'      => array( 'query' ),
		'page_stats'       => array( 'page' ),
		'query_page_stats' => array( 'query', 'page' ),
		'date_stats'       => array( 'date' ),
	);

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
	const MAX_LIMIT = 25000;

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/google-search-console',
				array(
					'label'               => 'Google Search Console',
					'description'         => 'Fetch search analytics data from Google Search Console API',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'action' ),
						'properties' => array(
							'action'       => array(
								'type'        => 'string',
								'description' => 'Action to perform: query_stats, page_stats, query_page_stats, date_stats, inspect_url, list_sitemaps, get_sitemap, submit_sitemap.',
							),
							'url'          => array(
								'type'        => 'string',
								'description' => 'Full URL to inspect (required for inspect_url action).',
							),
							'sitemap_url'  => array(
								'type'        => 'string',
								'description' => 'Sitemap URL (required for get_sitemap and submit_sitemap actions).',
							),
							'site_url'     => array(
								'type'        => 'string',
								'description' => 'Site URL (sc-domain: or https://). Defaults to configured site URL.',
							),
							'start_date'   => array(
								'type'        => 'string',
								'description' => 'Start date in YYYY-MM-DD format (defaults to 28 days ago).',
							),
							'end_date'     => array(
								'type'        => 'string',
								'description' => 'End date in YYYY-MM-DD format (defaults to 3 days ago for final data).',
							),
							'limit'        => array(
								'type'        => 'integer',
								'description' => 'Row limit (default: 25, max: 25000).',
							),
							'url_filter'   => array(
								'type'        => 'string',
								'description' => 'Filter results to URLs containing this string.',
							),
							'query_filter' => array(
								'type'        => 'string',
								'description' => 'Filter results to queries containing this string.',
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Fetch stats from Google Search Console API.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function fetchStats( array $input ): array {
		$action = sanitize_text_field( $input['action'] ?? '' );

		$valid_actions = array_merge(
			array_keys( self::ACTION_DIMENSIONS ),
			array( 'inspect_url', 'list_sitemaps', 'get_sitemap', 'submit_sitemap' )
		);
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
				'error'   => 'Google Search Console not configured. Add service account JSON in Settings.',
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

		$site_url = ! empty( $input['site_url'] ) ? sanitize_text_field( $input['site_url'] ) : ( $config['site_url'] ?? '' );

		// Route to specialized handlers for non-analytics actions.
		if ( 'inspect_url' === $action ) {
			return self::inspectUrl( $input, $access_token, $site_url );
		}
		if ( 'list_sitemaps' === $action ) {
			return self::listSitemaps( $access_token, $site_url );
		}
		if ( 'get_sitemap' === $action ) {
			return self::getSitemap( $input, $access_token, $site_url );
		}
		if ( 'submit_sitemap' === $action ) {
			return self::submitSitemap( $input, $access_token, $site_url );
		}

		$start_date = ! empty( $input['start_date'] ) ? sanitize_text_field( $input['start_date'] ) : gmdate( 'Y-m-d', strtotime( '-28 days' ) );
		$end_date   = ! empty( $input['end_date'] ) ? sanitize_text_field( $input['end_date'] ) : gmdate( 'Y-m-d', strtotime( '-3 days' ) );
		$limit      = ! empty( $input['limit'] ) ? min( (int) $input['limit'], self::MAX_LIMIT ) : self::DEFAULT_LIMIT;
		$dimensions = self::ACTION_DIMENSIONS[ $action ];

		if ( empty( $site_url ) ) {
			return array(
				'success' => false,
				'error'   => 'No site URL configured or provided.',
			);
		}

		$request_body = array(
			'startDate'  => $start_date,
			'endDate'    => $end_date,
			'dimensions' => $dimensions,
			'rowLimit'   => $limit,
			'dataState'  => 'final',
		);

		// Build dimension filter groups if filters provided.
		$filters = array();

		if ( ! empty( $input['url_filter'] ) ) {
			$filters[] = array(
				'dimension'  => 'page',
				'operator'   => 'contains',
				'expression' => sanitize_text_field( $input['url_filter'] ),
			);
		}

		if ( ! empty( $input['query_filter'] ) ) {
			$filters[] = array(
				'dimension'  => 'query',
				'operator'   => 'contains',
				'expression' => sanitize_text_field( $input['query_filter'] ),
			);
		}

		if ( ! empty( $filters ) ) {
			$request_body['dimensionFilterGroups'] = array(
				array(
					'groupType' => 'and',
					'filters'   => $filters,
				),
			);
		}

		$encoded_site_url = rawurlencode( $site_url );
		$api_url          = "https://www.googleapis.com/webmasters/v3/sites/{$encoded_site_url}/searchAnalytics/query";

		$result = HttpClient::post(
			$api_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'context' => 'Google Search Console Ability',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Failed to connect to Google Search Console API: ' . ( $result['error'] ?? 'Unknown error' ),
			);
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse Google Search Console API response.',
			);
		}

		if ( ! empty( $data['error'] ) ) {
			$error_message = $data['error']['message'] ?? 'Unknown API error';
			return array(
				'success' => false,
				'error'   => 'GSC API error: ' . $error_message,
			);
		}

		$rows = $data['rows'] ?? array();

		return array(
			'success'       => true,
			'action'        => $action,
			'results_count' => count( $rows ),
			'results'       => $rows,
		);
	}

	/**
	 * Inspect a URL via the URL Inspection API.
	 *
	 * @param array  $input        Ability input containing 'url'.
	 * @param string $access_token OAuth2 access token.
	 * @param string $site_url     GSC property URL.
	 * @return array
	 */
	private static function inspectUrl( array $input, string $access_token, string $site_url ): array {
		$url = sanitize_text_field( $input['url'] ?? '' );
		if ( empty( $url ) ) {
			return array(
				'success' => false,
				'error'   => 'URL is required for inspect_url action.',
			);
		}

		$api_url = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

		$result = HttpClient::post(
			$api_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'inspectionUrl' => $url,
					'siteUrl'       => $site_url,
					'languageCode'  => 'en-US',
				) ),
				'context' => 'Google Search Console URL Inspection',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'URL Inspection API failed: ' . ( $result['error'] ?? 'Unknown error' ),
			);
		}

		$data = json_decode( $result['data'], true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse URL Inspection response.',
			);
		}

		if ( ! empty( $data['error'] ) ) {
			return array(
				'success' => false,
				'error'   => 'GSC API error: ' . ( $data['error']['message'] ?? 'Unknown' ),
			);
		}

		$inspection   = $data['inspectionResult'] ?? array();
		$index_status = $inspection['indexStatusResult'] ?? array();
		$mobile       = $inspection['mobileUsabilityResult'] ?? array();
		$rich_results = $inspection['richResultsResult'] ?? array();

		return array(
			'success'          => true,
			'action'           => 'inspect_url',
			'url'              => $url,
			'index_status'     => array(
				'verdict'          => $index_status['verdict'] ?? 'UNKNOWN',
				'coverage_state'   => $index_status['coverageState'] ?? '',
				'indexing_state'   => $index_status['indexingState'] ?? '',
				'last_crawl_time'  => $index_status['lastCrawlTime'] ?? '',
				'page_fetch_state' => $index_status['pageFetchState'] ?? '',
				'google_canonical' => $index_status['googleCanonical'] ?? '',
				'user_canonical'   => $index_status['userCanonical'] ?? '',
				'crawled_as'       => $index_status['crawledAs'] ?? '',
				'robots_txt_state' => $index_status['robotsTxtState'] ?? '',
				'referring_urls'   => $index_status['referringUrls'] ?? array(),
				'sitemap'          => $index_status['sitemap'] ?? array(),
			),
			'mobile_usability' => array(
				'verdict' => $mobile['verdict'] ?? 'UNKNOWN',
				'issues'  => $mobile['issues'] ?? array(),
			),
			'rich_results'     => array(
				'verdict'        => $rich_results['verdict'] ?? 'UNKNOWN',
				'detected_items' => $rich_results['detectedItems'] ?? array(),
			),
		);
	}

	/**
	 * List all sitemaps for the configured site.
	 *
	 * @param string $access_token OAuth2 access token.
	 * @param string $site_url     GSC property URL.
	 * @return array
	 */
	private static function listSitemaps( string $access_token, string $site_url ): array {
		$encoded = rawurlencode( $site_url );
		$api_url = "https://www.googleapis.com/webmasters/v3/sites/{$encoded}/sitemaps";

		$result = HttpClient::get(
			$api_url,
			array(
				'timeout' => 30,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'context' => 'Google Search Console Sitemaps List',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Sitemaps API failed: ' . ( $result['error'] ?? 'Unknown error' ),
			);
		}

		$data = json_decode( $result['data'], true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse Sitemaps response.',
			);
		}

		if ( ! empty( $data['error'] ) ) {
			return array(
				'success' => false,
				'error'   => 'GSC API error: ' . ( $data['error']['message'] ?? 'Unknown' ),
			);
		}

		$sitemaps = array();
		foreach ( ( $data['sitemap'] ?? array() ) as $sm ) {
			$sitemaps[] = array(
				'path'            => $sm['path'] ?? '',
				'last_submitted'  => $sm['lastSubmitted'] ?? '',
				'last_downloaded' => $sm['lastDownloaded'] ?? '',
				'is_pending'      => $sm['isPending'] ?? false,
				'warnings'        => $sm['warnings'] ?? 0,
				'errors'          => $sm['errors'] ?? 0,
				'contents'        => $sm['contents'] ?? array(),
			);
		}

		return array(
			'success'        => true,
			'action'         => 'list_sitemaps',
			'sitemaps_count' => count( $sitemaps ),
			'sitemaps'       => $sitemaps,
		);
	}

	/**
	 * Get details for a specific sitemap.
	 *
	 * @param array  $input        Ability input containing 'sitemap_url'.
	 * @param string $access_token OAuth2 access token.
	 * @param string $site_url     GSC property URL.
	 * @return array
	 */
	private static function getSitemap( array $input, string $access_token, string $site_url ): array {
		$sitemap_url = sanitize_text_field( $input['sitemap_url'] ?? '' );
		if ( empty( $sitemap_url ) ) {
			return array(
				'success' => false,
				'error'   => 'sitemap_url is required for get_sitemap action.',
			);
		}

		$encoded_site    = rawurlencode( $site_url );
		$encoded_sitemap = rawurlencode( $sitemap_url );
		$api_url         = "https://www.googleapis.com/webmasters/v3/sites/{$encoded_site}/sitemaps/{$encoded_sitemap}";

		$result = HttpClient::get(
			$api_url,
			array(
				'timeout' => 30,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'context' => 'Google Search Console Sitemap Detail',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Sitemap API failed: ' . ( $result['error'] ?? 'Unknown error' ),
			);
		}

		$data = json_decode( $result['data'], true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse Sitemap response.',
			);
		}

		if ( ! empty( $data['error'] ) ) {
			return array(
				'success' => false,
				'error'   => 'GSC API error: ' . ( $data['error']['message'] ?? 'Unknown' ),
			);
		}

		return array(
			'success'         => true,
			'action'          => 'get_sitemap',
			'path'            => $data['path'] ?? '',
			'last_submitted'  => $data['lastSubmitted'] ?? '',
			'last_downloaded' => $data['lastDownloaded'] ?? '',
			'is_pending'      => $data['isPending'] ?? false,
			'warnings'        => $data['warnings'] ?? 0,
			'errors'          => $data['errors'] ?? 0,
			'contents'        => $data['contents'] ?? array(),
		);
	}

	/**
	 * Submit a sitemap to Google Search Console.
	 *
	 * @param array  $input        Ability input containing 'sitemap_url'.
	 * @param string $access_token OAuth2 access token.
	 * @param string $site_url     GSC property URL.
	 * @return array
	 */
	private static function submitSitemap( array $input, string $access_token, string $site_url ): array {
		$sitemap_url = sanitize_text_field( $input['sitemap_url'] ?? '' );
		if ( empty( $sitemap_url ) ) {
			return array(
				'success' => false,
				'error'   => 'sitemap_url is required for submit_sitemap action.',
			);
		}

		$encoded_site    = rawurlencode( $site_url );
		$encoded_sitemap = rawurlencode( $sitemap_url );
		$api_url         = "https://www.googleapis.com/webmasters/v3/sites/{$encoded_site}/sitemaps/{$encoded_sitemap}";

		// Submit uses PUT with empty body.
		$response = wp_remote_request(
			$api_url,
			array(
				'method'  => 'PUT',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => '',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => 'Sitemap submit failed: ' . $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return array(
				'success' => false,
				'error'   => 'Sitemap submit returned HTTP ' . $code . ': ' . ( $body['error']['message'] ?? '' ),
			);
		}

		return array(
			'success'     => true,
			'action'      => 'submit_sitemap',
			'sitemap_url' => $sitemap_url,
			'message'     => 'Sitemap submitted successfully.',
		);
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
			'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'iat'   => $now,
			'exp'   => $now + 3600,
		) ) );

		$unsigned = $header . '.' . $claims;

		$sign_result = openssl_sign( $unsigned, $signature, $service_account['private_key'], 'SHA256' );

		if ( ! $sign_result ) {
			return new \WP_Error( 'gsc_jwt_sign_failed', 'Failed to sign JWT. Check private key in service account JSON.' );
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
			return new \WP_Error( 'gsc_token_failed', 'Failed to get access token: ' . $error_desc );
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
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Check if Google Search Console is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$config = self::get_config();
		return ! empty( $config['service_account_json'] );
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
