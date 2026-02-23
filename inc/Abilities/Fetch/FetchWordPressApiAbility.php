<?php
/**
 * Fetch WordPress API Ability
 *
 * Abilities API primitive for fetching from WordPress REST API endpoints.
 * Centralizes REST API fetching, flexible field extraction, deduplication,
 * timeframe filtering, and keyword search.
 *
 * @package DataMachine\Abilities\Fetch
 */

namespace DataMachine\Abilities\Fetch;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class FetchWordPressApiAbility {

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
				'datamachine/fetch-wordpress-api',
				array(
					'label'               => __( 'Fetch WordPress REST API', 'data-machine' ),
					'description'         => __( 'Fetch posts from external WordPress sites via REST API', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'endpoint_url' ),
						'properties' => array(
							'endpoint_url'    => array(
								'type'        => 'string',
								'description' => __( 'Complete REST API endpoint URL (e.g., https://example.com/wp-json/wp/v2/posts)', 'data-machine' ),
							),
							'timeframe_limit' => array(
								'type'        => 'string',
								'default'     => 'all_time',
								'description' => __( 'Timeframe filter (all_time, 24_hours, 7_days, 30_days, 90_days, 6_months, 1_year)', 'data-machine' ),
							),
							'search'          => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Search term to filter items', 'data-machine' ),
							),
							'processed_items' => array(
								'type'        => 'array',
								'default'     => array(),
								'description' => __( 'Array of already processed item IDs to skip', 'data-machine' ),
							),
							'download_images' => array(
								'type'        => 'boolean',
								'default'     => true,
								'description' => __( 'Whether to download featured images', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
							'logs'    => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Permission callback for ability.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute WordPress API fetch ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with fetched data or error.
	 */
	public function execute( array $input ): array {
		$logs   = array();
		$config = $this->normalizeConfig( $input );

		$endpoint_url    = $config['endpoint_url'];
		$timeframe_limit = $config['timeframe_limit'];
		$search          = $config['search'];
		$processed_items = $config['processed_items'];
		$download_images = $config['download_images'];

		// Validate endpoint URL
		if ( empty( $endpoint_url ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'WordPressAPI: Endpoint URL is required.',
			);
			return array(
				'success' => false,
				'error'   => 'Endpoint URL is required',
				'logs'    => $logs,
			);
		}

		if ( ! filter_var( $endpoint_url, FILTER_VALIDATE_URL ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'WordPressAPI: Invalid endpoint URL format.',
				'data'    => array( 'endpoint_url' => $endpoint_url ),
			);
			return array(
				'success' => false,
				'error'   => 'Invalid endpoint URL format',
				'logs'    => $logs,
			);
		}

		// For WordPress REST APIs, add server-side search parameter
		$modified_url = $endpoint_url;
		if ( strpos( $endpoint_url, '/wp-json/' ) !== false && ! empty( $search ) ) {
			$modified_url = add_query_arg( 'search', $search, $endpoint_url );
			$logs[]       = array(
				'level'   => 'debug',
				'message' => 'WordPressAPI: Added server-side search parameter to endpoint',
				'data'    => array(
					'search_term'  => $search,
					'modified_url' => $modified_url,
				),
			);
		}

		$result = $this->httpGet( $modified_url, array( 'context' => 'REST API' ) );

		if ( ! $result['success'] ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'WordPressAPI: Failed to fetch from endpoint.',
				'data'    => array(
					'error'        => $result['error'],
					'endpoint_url' => $modified_url,
				),
			);
			return array(
				'success' => false,
				'error'   => $result['error'],
				'logs'    => $logs,
			);
		}

		$response_data = $result['data'];
		if ( empty( $response_data ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'WordPressAPI: Empty response from endpoint.',
				'data'    => array( 'endpoint_url' => $modified_url ),
			);
			return array(
				'success' => false,
				'error'   => 'Empty response from endpoint',
				'logs'    => $logs,
			);
		}

		// Parse JSON response
		$items = json_decode( $response_data, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'WordPressAPI: Invalid JSON response.',
				'data'    => array(
					'json_error'   => json_last_error_msg(),
					'endpoint_url' => $modified_url,
				),
			);
			return array(
				'success' => false,
				'error'   => 'Invalid JSON response: ' . json_last_error_msg(),
				'logs'    => $logs,
			);
		}

		if ( ! is_array( $items ) || empty( $items ) ) {
			$logs[] = array(
				'level'   => 'debug',
				'message' => 'WordPressAPI: No items found in response.',
			);
			return array(
				'success' => true,
				'data'    => array(),
				'logs'    => $logs,
			);
		}

		$total_checked = 0;
		$site_name     = $this->extractSiteNameFromUrl( $endpoint_url );

		foreach ( $items as $item ) {
			++$total_checked;

			$item_id = $item['id'] ?? 0;
			if ( empty( $item_id ) ) {
				continue;
			}

			$unique_id = md5( $endpoint_url . '_' . $item_id );

			if ( in_array( $unique_id, $processed_items, true ) ) {
				$logs[] = array(
					'level'   => 'debug',
					'message' => 'WordPressAPI: Skipping item (already processed).',
					'data'    => array( 'item_id' => $unique_id ),
				);
				continue;
			}

			// Extract item data flexibly
			$title       = $this->extractTitle( $item );
			$content     = $this->extractContent( $item );
			$excerpt     = $this->extractExcerpt( $item );
			$source_link = $this->extractSourceLink( $item );
			$item_date   = $this->extractDate( $item );

			// Apply timeframe filtering
			if ( $item_date ) {
				$item_timestamp = strtotime( $item_date );
				if ( $item_timestamp && ! $this->applyTimeframeFilter( $item_timestamp, $timeframe_limit ) ) {
					$logs[] = array(
						'level'   => 'debug',
						'message' => 'WordPressAPI: Skipping item outside timeframe.',
						'data'    => array(
							'item_id'   => $unique_id,
							'item_date' => $item_date,
						),
					);
					continue;
				}
			}

			// Apply keyword search filter
			$search_text = $title . ' ' . wp_strip_all_tags( $content . ' ' . $excerpt );
			if ( ! $this->applyKeywordSearch( $search_text, $search ) ) {
				$logs[] = array(
					'level'   => 'debug',
					'message' => 'WordPressAPI: Skipping item (search filter).',
					'data'    => array( 'item_id' => $unique_id ),
				);
				continue;
			}

			// Extract image URL
			$image_url  = $this->extractImageUrl( $item );
			$image_info = null;

			if ( $download_images && ! empty( $image_url ) ) {
				$file_check = wp_check_filetype( $image_url );
				$mime_type  = $file_check['type'] ? $file_check['type'] : 'application/octet-stream';

				if ( strpos( $mime_type, 'image/' ) === 0 && in_array( $mime_type, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
					$image_info = array(
						'url'       => $image_url,
						'mime_type' => $mime_type,
					);
				}
			}

			// Prepare raw data
			$raw_data = array(
				'title'    => $title,
				'content'  => wp_strip_all_tags( $content ),
				'metadata' => array(
					'source_type'            => 'rest_api',
					'item_identifier_to_log' => $unique_id,
					'original_id'            => $item_id,
					'original_title'         => $title,
					'original_date_gmt'      => $item_date,
					'site_name'              => $site_name,
				),
			);

			// Add excerpt if present
			if ( ! empty( $excerpt ) ) {
				$raw_data['content'] .= "\n\nExcerpt: " . wp_strip_all_tags( $excerpt );
			}

			// Add image info if present
			if ( $image_info ) {
				$raw_data['file_info'] = array(
					'url'       => $image_info['url'],
					'mime_type' => $image_info['mime_type'],
				);
			}

			$logs[] = array(
				'level'   => 'debug',
				'message' => 'WordPressAPI: Successfully fetched item.',
				'data'    => array(
					'item_id'   => $unique_id,
					'title'     => $title,
					'has_image' => ! empty( $image_info ),
				),
			);

			return array(
				'success'    => true,
				'data'       => $raw_data,
				'item_id'    => $unique_id,
				'source_url' => $source_link,
				'logs'       => $logs,
			);
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'WordPressAPI: No eligible items found.',
			'data'    => array( 'total_checked' => $total_checked ),
		);

		return array(
			'success' => true,
			'data'    => array(),
			'logs'    => $logs,
		);
	}

	/**
	 * Normalize input configuration with defaults.
	 */
	private function normalizeConfig( array $input ): array {
		$defaults = array(
			'endpoint_url'    => '',
			'timeframe_limit' => 'all_time',
			'search'          => '',
			'processed_items' => array(),
			'download_images' => true,
		);

		return array_merge( $defaults, $input );
	}

	/**
	 * Make HTTP GET request.
	 */
	private function httpGet( string $url, array $options ): array {
		$args = array(
			'timeout' => $options['timeout'] ?? 30,
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		return array(
			'success'     => $status_code >= 200 && $status_code < 300,
			'status_code' => $status_code,
			'data'        => $body,
		);
	}

	/**
	 * Extract title from item data with flexible field checking.
	 *
	 * @param array $item Item data from REST API.
	 * @return string Title or 'N/A' if none found.
	 */
	private function extractTitle( array $item ): string {
		// WordPress format
		if ( isset( $item['title']['rendered'] ) ) {
			return $item['title']['rendered'];
		}

		// Direct title field
		if ( isset( $item['title'] ) && is_string( $item['title'] ) ) {
			return $item['title'];
		}

		// Other common fields
		if ( isset( $item['name'] ) ) {
			return $item['name'];
		}

		if ( isset( $item['subject'] ) ) {
			return $item['subject'];
		}

		return 'N/A';
	}

	/**
	 * Extract content from item data with flexible field checking.
	 *
	 * @param array $item Item data from REST API.
	 * @return string Content or empty string if none found.
	 */
	private function extractContent( array $item ): string {
		// WordPress format
		if ( isset( $item['content']['rendered'] ) ) {
			return $item['content']['rendered'];
		}

		// Direct content field
		if ( isset( $item['content'] ) && is_string( $item['content'] ) ) {
			return $item['content'];
		}

		// Other common fields
		if ( isset( $item['body'] ) ) {
			return $item['body'];
		}

		if ( isset( $item['description'] ) ) {
			return $item['description'];
		}

		if ( isset( $item['text'] ) ) {
			return $item['text'];
		}

		return '';
	}

	/**
	 * Extract excerpt from item data with flexible field checking.
	 *
	 * @param array $item Item data from REST API.
	 * @return string Excerpt or empty string if none found.
	 */
	private function extractExcerpt( array $item ): string {
		// WordPress format
		if ( isset( $item['excerpt']['rendered'] ) ) {
			return $item['excerpt']['rendered'];
		}

		// Direct excerpt field
		if ( isset( $item['excerpt'] ) && is_string( $item['excerpt'] ) ) {
			return $item['excerpt'];
		}

		// Other common fields
		if ( isset( $item['summary'] ) ) {
			return $item['summary'];
		}

		if ( isset( $item['description'] ) && strlen( $item['description'] ) < 300 ) {
			return $item['description'];
		}

		return '';
	}

	/**
	 * Extract source link from item data with flexible field checking.
	 *
	 * @param array $item Item data from REST API.
	 * @return string Source link or empty string if none found.
	 */
	private function extractSourceLink( array $item ): string {
		// WordPress format
		if ( isset( $item['link'] ) ) {
			return $item['link'];
		}

		// Other common fields
		if ( isset( $item['url'] ) ) {
			return $item['url'];
		}

		if ( isset( $item['permalink'] ) ) {
			return $item['permalink'];
		}

		if ( isset( $item['href'] ) ) {
			return $item['href'];
		}

		return '';
	}

	/**
	 * Extract date from item data with flexible field checking.
	 *
	 * @param array $item Item data from REST API.
	 * @return string|null Date in GMT format or null if none found.
	 */
	private function extractDate( array $item ): ?string {
		// WordPress format
		if ( isset( $item['date_gmt'] ) ) {
			return $item['date_gmt'];
		}

		if ( isset( $item['date'] ) ) {
			return $item['date'];
		}

		// Other common fields
		if ( isset( $item['created_at'] ) ) {
			return $item['created_at'];
		}

		if ( isset( $item['published_at'] ) ) {
			return $item['published_at'];
		}

		if ( isset( $item['timestamp'] ) ) {
			return $item['timestamp'];
		}

		return null;
	}

	/**
	 * Extract image URL from item data with flexible field checking.
	 *
	 * @param array $item Item data from REST API.
	 * @return string|null Image URL or null if none found.
	 */
	private function extractImageUrl( array $item ): ?string {
		// WordPress embedded media
		if ( isset( $item['_embedded']['wp:featuredmedia'][0]['source_url'] ) ) {
			return $item['_embedded']['wp:featuredmedia'][0]['source_url'];
		}

		// Direct image fields
		if ( isset( $item['featured_image'] ) ) {
			return $item['featured_image'];
		}

		if ( isset( $item['image'] ) ) {
			return is_string( $item['image'] ) ? $item['image'] : ( $item['image']['url'] ?? null );
		}

		if ( isset( $item['thumbnail'] ) ) {
			return is_string( $item['thumbnail'] ) ? $item['thumbnail'] : ( $item['thumbnail']['url'] ?? null );
		}

		if ( isset( $item['featured_media'] ) && is_string( $item['featured_media'] ) ) {
			return $item['featured_media'];
		}

		return null;
	}

	/**
	 * Extract site name from endpoint URL.
	 *
	 * @param string $endpoint_url Complete endpoint URL.
	 * @return string Site name extracted from URL.
	 */
	private function extractSiteNameFromUrl( string $endpoint_url ): string {
		$parsed_url = wp_parse_url( $endpoint_url );
		if ( isset( $parsed_url['host'] ) ) {
			return $parsed_url['host'];
		}

		return $endpoint_url;
	}

	/**
	 * Apply timeframe filter to item timestamp.
	 */
	private function applyTimeframeFilter( int $item_timestamp, string $timeframe_limit ): bool {
		if ( 'all_time' === $timeframe_limit ) {
			return true;
		}

		$now    = time();
		$cutoff = 0;

		switch ( $timeframe_limit ) {
			case '24_hours':
				$cutoff = $now - DAY_IN_SECONDS;
				break;
			case '72_hours':
				$cutoff = $now - ( 3 * DAY_IN_SECONDS );
				break;
			case '7_days':
				$cutoff = $now - ( 7 * DAY_IN_SECONDS );
				break;
			case '30_days':
				$cutoff = $now - ( 30 * DAY_IN_SECONDS );
				break;
			case '90_days':
				$cutoff = $now - ( 90 * DAY_IN_SECONDS );
				break;
			case '6_months':
				$cutoff = $now - ( 180 * DAY_IN_SECONDS );
				break;
			case '1_year':
				$cutoff = $now - YEAR_IN_SECONDS;
				break;
		}

		return $item_timestamp >= $cutoff;
	}

	/**
	 * Apply keyword search filter.
	 */
	private function applyKeywordSearch( string $text, string $search_term ): bool {
		if ( empty( $search_term ) ) {
			return true;
		}

		$terms      = array_map( 'trim', explode( ',', $search_term ) );
		$text_lower = strtolower( $text );

		foreach ( $terms as $term ) {
			if ( empty( $term ) ) {
				continue;
			}
			if ( strpos( $text_lower, strtolower( $term ) ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
