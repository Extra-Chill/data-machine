<?php
/**
 * HTML Link Paginator for standard HTML pagination.
 *
 * Discovers next page URLs from HTML pagination patterns including:
 * - rel="next" links (standard HTML5)
 * - <link rel="next"> elements (SEO pagination)
 * - .pagination containers
 * - Text-based navigation ("Next", ">", ">>", etc.)
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Paginators
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Paginators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HtmlLinkPaginator implements PaginatorInterface {

	/**
	 * Check if this paginator can handle the given URL/content combination.
	 *
	 * HTML paginator is the default fallback, so it can handle any HTML content.
	 *
	 * @param string $url     Current page URL.
	 * @param string $content Page content.
	 * @return bool True if content appears to be HTML.
	 */
	public function canPaginate( string $url, string $content ): bool {
		return strpos( $content, '<' ) !== false && strpos( $content, '>' ) !== false;
	}

	/**
	 * Get the next page URL from HTML pagination links.
	 *
	 * @param string $current_url Current page URL.
	 * @param string $content     HTML content.
	 * @return string|null Next page URL, or null if no more pages.
	 */
	public function getNextPageUrl( string $current_url, string $content ): ?string {
		$current_host = wp_parse_url( $current_url, PHP_URL_HOST );
		if ( empty( $current_host ) ) {
			return null;
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $dom );

		// Priority 1: Standard HTML5 rel="next" on links
		$next_links = $xpath->query( '//a[@rel="next"]' );
		if ( $next_links->length > 0 ) {
			$href = $this->extractValidHref( $next_links->item( 0 ), $current_url, $current_host );
			if ( $href ) {
				return $href;
			}
		}

		// Priority 2: Link element with rel="next" (SEO pagination)
		$link_next = $xpath->query( '//link[@rel="next"]' );
		if ( $link_next->length > 0 ) {
			$node = $link_next->item( 0 );
			if ( $node instanceof \DOMElement ) {
				$href     = $node->getAttribute( 'href' );
				$resolved = $this->resolveUrl( $href, $current_url, $current_host );
				if ( $resolved ) {
					return $resolved;
				}
			}
		}

		// Priority 3: Links with "next" in class name
		$next_class_patterns = array(
			'//a[contains(@class, "next")]',
			'//a[contains(@class, "pagination-next")]',
			'//a[contains(@class, "page-next")]',
		);

		foreach ( $next_class_patterns as $pattern ) {
			$nodes = $xpath->query( $pattern );
			foreach ( $nodes as $node ) {
				$href = $this->extractValidHref( $node, $current_url, $current_host );
				if ( $href ) {
					return $href;
				}
			}
		}

		// Priority 4: Links within pagination containers
		$pagination_containers = array(
			'//*[contains(@class, "pagination")]//a',
			'//*[contains(@class, "pager")]//a',
			'//nav[@aria-label="pagination"]//a',
			'//*[@role="navigation"]//a',
		);

		foreach ( $pagination_containers as $container_pattern ) {
			$nodes = $xpath->query( $container_pattern );
			foreach ( $nodes as $node ) {
				if ( ! ( $node instanceof \DOMElement ) ) {
					continue;
				}

				$text       = strtolower( trim( $node->textContent ) );
				$class      = strtolower( $node->getAttribute( 'class' ) );
				$aria_label = strtolower( $node->getAttribute( 'aria-label' ) );

				if (
					strpos( $text, 'next' ) !== false ||
					strpos( $class, 'next' ) !== false ||
					strpos( $aria_label, 'next' ) !== false ||
					'>' === $text ||
					'>>' === $text ||
					"\u{2192}" === $text // Right arrow (→)
				) {
					$href = $this->extractValidHref( $node, $current_url, $current_host );
					if ( $href ) {
						return $href;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Get paginator identifier for logging.
	 *
	 * @return string Method identifier.
	 */
	public function getMethod(): string {
		return 'html_link';
	}

	/**
	 * Extract and validate href from DOM node.
	 *
	 * @param \DOMNode $node         DOM node to extract href from.
	 * @param string   $current_url  Current page URL for resolution.
	 * @param string   $current_host Current host for validation.
	 * @return string|null Valid resolved URL, or null if invalid.
	 */
	private function extractValidHref( \DOMNode $node, string $current_url, string $current_host ): ?string {
		if ( ! ( $node instanceof \DOMElement ) ) {
			return null;
		}

		$href = $node->getAttribute( 'href' );
		if ( empty( $href ) || '#' === $href ) {
			return null;
		}

		return $this->resolveUrl( $href, $current_url, $current_host );
	}

	/**
	 * Resolve relative URL and validate same-domain.
	 *
	 * @param string $href         URL or path to resolve.
	 * @param string $current_url  Current page URL for base resolution.
	 * @param string $current_host Current host for validation.
	 * @return string|null Fully resolved URL, or null if invalid or cross-domain.
	 */
	private function resolveUrl( string $href, string $current_url, string $current_host ): ?string {
		if ( strpos( $href, 'javascript:' ) === 0 ) {
			return null;
		}

		// Handle protocol-relative URLs
		if ( strpos( $href, '//' ) === 0 ) {
			$href = 'https:' . $href;
		}

		// Handle root-relative URLs
		if ( strpos( $href, '/' ) === 0 ) {
			$scheme = wp_parse_url( $current_url, PHP_URL_SCHEME ) ?? 'https';
			$href   = $scheme . '://' . $current_host . $href;
		} elseif ( ! preg_match( '/^https?:\/\//i', $href ) ) {
			// Handle relative URLs
			$base_path = dirname( wp_parse_url( $current_url, PHP_URL_PATH ) ?: '/' );
			$scheme    = wp_parse_url( $current_url, PHP_URL_SCHEME ) ?? 'https';
			$href      = $scheme . '://' . $current_host . $base_path . '/' . $href;
		}

		// Validate same domain
		$href_host = wp_parse_url( $href, PHP_URL_HOST );
		if ( $href_host !== $current_host ) {
			return null;
		}

		// Skip if it's the same as current URL
		$current_normalized = strtok( $current_url, '#' );
		$href_normalized    = strtok( $href, '#' );
		if ( $current_normalized === $href_normalized ) {
			return null;
		}

		return $href;
	}
}
