<?php
/**
 * Google Search ability.
 *
 * Exposes Google Custom Search as datamachine/google-search so REST and MCP
 * agents can run read-only web searches with the site's configured key. The
 * google_search chat/pipeline tool delegates here, so both share one
 * implementation and one not-configured error.
 *
 * @package DataMachineBusiness\Abilities\Search
 */

namespace DataMachineBusiness\Abilities\Search;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineBusiness\Tools\GoogleSearch;

defined( 'ABSPATH' ) || exit;

class GoogleSearchAbility {

	public const ENDPOINT    = 'https://www.googleapis.com/customsearch/v1';
	public const MAX_RESULTS = 10;

	private static bool $registered = false;

	/**
	 * HTTP GET override for tests: callable( string $url, array $options ): array.
	 *
	 * @var callable|null
	 */
	public static $http_get = null;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( array( $this, 'register' ) );
		self::$registered = true;
	}

	/**
	 * Register datamachine/google-search.
	 */
	public function register(): void {
		wp_register_ability(
			'datamachine/google-search',
			array(
				'label'               => __( 'Google Search', 'data-machine-business' ),
				'description'         => __( 'Search the web with Google Custom Search and get up to 10 results (title, link, snippet). Use it to find an artist\'s site, a show listing or press when you do not have a URL yet; read a result with datamachine/web-fetch. Returns google_search_not_configured if the site has no API key and engine ID.', 'data-machine-business' ),
				'category'            => 'datamachine-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'query' ),
					'properties'           => array(
						'query'         => array(
							'type'        => 'string',
							'description' => 'Search query.',
						),
						'site_restrict' => array(
							'type'        => 'string',
							'description' => 'Optional domain to restrict results to, e.g. "bandcamp.com".',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'query'           => array( 'type' => 'string' ),
						'results_count'   => array( 'type' => 'integer' ),
						'total_available' => array( 'type' => 'string' ),
						'search_time'     => array( 'type' => 'number' ),
						'results'         => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'title'       => array( 'type' => 'string' ),
									'link'        => array( 'type' => 'string' ),
									'snippet'     => array( 'type' => 'string' ),
									'displayLink' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => array( self::class, 'can_search' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}

	/**
	 * Read-only web search with the site key: editor/author/team level.
	 *
	 * @return bool
	 */
	public static function can_search(): bool {
		return PermissionHelper::can_manage() || current_user_can( 'edit_posts' );
	}

	/**
	 * Ability execute callback.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function execute( array $input ) {
		return self::search( (string) ( $input['query'] ?? '' ), (string) ( $input['site_restrict'] ?? '' ) );
	}

	/**
	 * Run a Google Custom Search query.
	 *
	 * @param string $query         Search query.
	 * @param string $site_restrict Optional domain restriction.
	 * @return array|\WP_Error
	 */
	public static function search( string $query, string $site_restrict = '' ) {
		$query = sanitize_text_field( $query );
		if ( '' === $query ) {
			return new \WP_Error( 'google_search_query_required', 'A search query is required.', array( 'status' => 400 ) );
		}

		$config = GoogleSearch::get_config();
		if ( empty( $config['api_key'] ) || empty( $config['search_engine_id'] ) ) {
			return new \WP_Error(
				'google_search_not_configured',
				'Google Search is not configured on this site. An administrator needs to add a Google Custom Search API key and Search Engine ID (cx) under Data Machine > Agent > Tools > Google Search, or through the datamachine/save-tool-config ability with tool_id "google_search".',
				array( 'status' => 503 )
			);
		}

		$params = array(
			'key'  => $config['api_key'],
			'cx'   => $config['search_engine_id'],
			'q'    => $query,
			'num'  => self::MAX_RESULTS,
			'safe' => 'active',
		);

		$site_restrict = sanitize_text_field( $site_restrict );
		if ( '' !== $site_restrict ) {
			$params['siteSearch'] = $site_restrict;
		}

		$result = self::http_get(
			add_query_arg( $params, self::ENDPOINT ),
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
				'context' => 'Google Search',
			)
		);

		if ( empty( $result['success'] ) ) {
			return new \WP_Error( 'google_search_failed', 'Google Search request failed: ' . (string) ( $result['error'] ?? 'unknown error' ), array( 'status' => 502 ) );
		}

		$data = json_decode( (string) ( $result['data'] ?? '' ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'google_search_failed', 'Google Search returned an unreadable response.', array( 'status' => 502 ) );
		}

		$results = array();
		foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
			$results[] = array(
				'title'       => (string) ( $item['title'] ?? '' ),
				'link'        => (string) ( $item['link'] ?? '' ),
				'snippet'     => (string) ( $item['snippet'] ?? '' ),
				'displayLink' => (string) ( $item['displayLink'] ?? '' ),
			);
		}

		$info = $data['searchInformation'] ?? array();

		return array(
			'query'           => $query,
			'results_count'   => count( $results ),
			'total_available' => (string) ( $info['totalResults'] ?? '0' ),
			'search_time'     => (float) ( $info['searchTime'] ?? 0 ),
			'results'         => $results,
		);
	}

	/**
	 * Perform the GET.
	 *
	 * @param string $url     URL.
	 * @param array  $options HttpClient options.
	 * @return array
	 */
	private static function http_get( string $url, array $options ): array {
		if ( is_callable( self::$http_get ) ) {
			return (array) call_user_func( self::$http_get, $url, $options );
		}

		return HttpClient::get( $url, $options );
	}
}
