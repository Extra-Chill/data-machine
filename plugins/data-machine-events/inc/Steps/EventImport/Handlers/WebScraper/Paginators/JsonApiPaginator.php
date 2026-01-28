<?php
/**
 * JSON API Paginator for WordPress REST endpoints.
 *
 * Handles pagination for Tribe Events REST API and other WordPress REST endpoints
 * that include page/total_pages metadata in their JSON responses.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Paginators
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Paginators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JsonApiPaginator implements PaginatorInterface {

	/**
	 * Check if this paginator can handle the given URL/content combination.
	 *
	 * Requires a REST API URL and valid JSON with pagination metadata.
	 *
	 * @param string $url     Current page URL.
	 * @param string $content Page content.
	 * @return bool True if this is a paginated JSON API response.
	 */
	public function canPaginate( string $url, string $content ): bool {
		if ( ! preg_match( '/wp-json\//i', $url ) ) {
			return false;
		}

		$data = json_decode( trim( $content ), true );

		return is_array( $data ) && isset( $data['total_pages'] );
	}

	/**
	 * Get the next page URL from JSON pagination metadata.
	 *
	 * @param string $current_url Current page URL.
	 * @param string $content     JSON response content.
	 * @return string|null Next page URL, or null if no more pages.
	 */
	public function getNextPageUrl( string $current_url, string $content ): ?string {
		$data = json_decode( trim( $content ), true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$current_page = (int) ( $data['page'] ?? 1 );
		$total_pages  = (int) ( $data['total_pages'] ?? 1 );

		if ( $current_page >= $total_pages ) {
			return null;
		}

		return $this->buildPaginatedUrl( $current_url, $current_page + 1 );
	}

	/**
	 * Get paginator identifier for logging.
	 *
	 * @return string Method identifier.
	 */
	public function getMethod(): string {
		return 'json_api';
	}

	/**
	 * Build a paginated URL by adding or updating the page parameter.
	 *
	 * @param string $base_url Base URL to paginate.
	 * @param int    $page     Page number to set.
	 * @return string URL with page parameter.
	 */
	private function buildPaginatedUrl( string $base_url, int $page ): string {
		$parsed = wp_parse_url( $base_url );
		$query  = array();

		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query );
		}

		$query['page'] = $page;

		$url = ( $parsed['scheme'] ?? 'https' ) . '://' . $parsed['host'];
		if ( ! empty( $parsed['port'] ) ) {
			$url .= ':' . $parsed['port'];
		}
		if ( ! empty( $parsed['path'] ) ) {
			$url .= $parsed['path'];
		}
		$url .= '?' . http_build_query( $query );

		return $url;
	}
}
