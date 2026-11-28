<?php
/**
 * Exa web search ability shared by REST, MCP and agent tools.
 *
 * @package DataMachineBusiness\Abilities\Search
 */

namespace DataMachineBusiness\Abilities\Search;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineBusiness\Tools\ExaSearch;

defined( 'ABSPATH' ) || exit;

class ExaSearchAbility {

	public const ENDPOINT    = 'https://api.exa.ai/search';
	public const MAX_RESULTS = 25;

	private static bool $registered = false;

	/** @var callable|null HTTP POST override: callable( string $url, array $options ): array. */
	public static $http_post = null;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}
		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( array( $this, 'register' ) );
		self::$registered = true;
	}

	/** Shared ability and tool input schema. */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'query' ),
			'properties'           => array(
				'query'                => array(
					'type'        => 'string',
					'description' => 'Search query.',
				),
				'num_results'          => array(
					'type'        => 'integer',
					'default'     => 5,
					'description' => 'Number of results, clamped to 1-25.',
				),
				'type'                 => array(
					'type'    => 'string',
					'enum'    => array( 'auto', 'neural', 'keyword', 'fast' ),
					'default' => 'auto',
				),
				'include_domains'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'exclude_domains'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'start_published_date' => array(
					'type'        => 'string',
					'description' => 'Earliest publication date (ISO date).',
				),
				'include_text'         => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function register(): void {
		wp_register_ability(
			'datamachine/exa-search',
			array(
				'label'               => __( 'Exa Search', 'data-machine-business' ),
				'description'         => __( 'Search the web with Exa for titles, URLs, publication dates, authors and optional text. Returns exa_search_not_configured if no API key is configured.', 'data-machine-business' ),
				'category'            => 'datamachine-content',
				'input_schema'        => self::input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'query'   => array( 'type' => 'string' ),
						'results' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'title'          => array( 'type' => 'string' ),
									'url'            => array( 'type' => 'string' ),
									'published_date' => array( 'type' => 'string' ),
									'author'         => array( 'type' => 'string' ),
									'text'           => array( 'type' => 'string' ),
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

	public static function can_search(): bool {
		return PermissionHelper::can_manage() || current_user_can( 'edit_posts' );
	}

	/**
	 * Search with the site's Exa key.
	 *
	 * @param array $input Search parameters.
	 * @return array|\WP_Error
	 */
	public static function execute( array $input ) {
		$query = sanitize_text_field( (string) ( $input['query'] ?? '' ) );
		if ( '' === $query ) {
			return new \WP_Error( 'exa_search_query_required', 'A search query is required.', array( 'status' => 400 ) );
		}

		$config = ExaSearch::get_config();
		if ( empty( $config['api_key'] ) ) {
			return new \WP_Error(
				'exa_search_not_configured',
				'Exa Search is not configured on this site. An administrator needs to add an Exa API key under Data Machine > Agent > Tools > Exa Search, or via datamachine/save-tool-config with tool_id "exa_search".',
				array( 'status' => 503 )
			);
		}

		$type = (string) ( $input['type'] ?? 'auto' );
		if ( ! in_array( $type, array( 'auto', 'neural', 'keyword', 'fast' ), true ) ) {
			return new \WP_Error( 'exa_search_invalid_type', 'Invalid Exa search type.', array( 'status' => 400 ) );
		}
		$include_text = (bool) ( $input['include_text'] ?? true );
		$body         = array(
			'query'      => $query,
			'numResults' => max( 1, min( self::MAX_RESULTS, (int) ( $input['num_results'] ?? 5 ) ) ),
			'type'       => $type,
		);
		foreach ( array(
			'include_domains' => 'includeDomains',
			'exclude_domains' => 'excludeDomains',
		) as $key => $api_key ) {
			if ( ! empty( $input[ $key ] ) ) {
				$body[ $api_key ] = array_values( array_map( 'sanitize_text_field', (array) $input[ $key ] ) );
			}
		}
		if ( ! empty( $input['start_published_date'] ) ) {
			$body['startPublishedDate'] = sanitize_text_field( $input['start_published_date'] );
		}
		if ( $include_text ) {
			$body['contents'] = array( 'text' => array( 'maxCharacters' => 2000 ) );
		}

		$options = array(
			'timeout' => 30,
			'headers' => array(
				'x-api-key'    => $config['api_key'],
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'context' => 'Exa Search',
		);
		$result  = is_callable( self::$http_post )
			? (array) call_user_func( self::$http_post, self::ENDPOINT, $options )
			: HttpClient::post( self::ENDPOINT, $options );

		$data   = json_decode( (string) ( $result['data'] ?? '' ), true );
		$status = (int) ( $result['status_code'] ?? 200 );
		if ( empty( $result['success'] ) || $status < 200 || $status >= 300 ) {
			$message = $data['error'] ?? $data['message'] ?? $result['error'] ?? 'unknown error';
			if ( is_array( $message ) ) {
				$message = $message['message'] ?? wp_json_encode( $message );
			}
			return new \WP_Error( 'exa_search_failed', 'Exa Search request failed: ' . (string) $message, array( 'status' => 502 ) );
		}
		if ( ! is_array( $data ) || ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return new \WP_Error( 'exa_search_failed', 'Exa Search returned an unreadable response.', array( 'status' => 502 ) );
		}

		$results = array();
		foreach ( $data['results'] as $item ) {
			$row = array(
				'title'          => (string) ( $item['title'] ?? '' ),
				'url'            => (string) ( $item['url'] ?? '' ),
				'published_date' => (string) ( $item['publishedDate'] ?? '' ),
				'author'         => (string) ( $item['author'] ?? '' ),
			);
			if ( $include_text && isset( $item['text'] ) ) {
				$row['text'] = (string) $item['text'];
			}
			$results[] = $row;
		}
		return array(
			'success' => true,
			'query'   => $query,
			'results' => $results,
		);
	}
}
