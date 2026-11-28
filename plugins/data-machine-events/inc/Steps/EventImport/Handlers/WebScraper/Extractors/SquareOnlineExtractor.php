<?php
/**
 * Square Online structured data extractor.
 *
 * Extracts event flyer images from Square Online sites (*.square.site) by parsing
 * the embedded __BOOTSTRAP_STATE__ JSON that contains page content including images.
 *
 * Square Online embeds page content in window.__BOOTSTRAP_STATE__ JSON rather than
 * standard HTML <img> tags. This extractor finds image URLs in the JSON structure
 * for processing by VisionExtractionProcessor.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 * @since   0.9.19
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SquareOnlineExtractor extends BaseExtractor {

	private const MIN_IMAGE_WIDTH  = 400;
	private const MIN_IMAGE_HEIGHT = 300;

	/**
	 * Check if this extractor can handle the given HTML content.
	 *
	 * Detects Square Online sites by presence of __BOOTSTRAP_STATE__ and
	 * square.site or editmysite.com references in the page.
	 *
	 * @param string $html HTML content to check
	 * @return bool True if this is a Square Online page with bootstrap data
	 */
	public function canExtract( string $html ): bool {
		if ( strpos( $html, '__BOOTSTRAP_STATE__' ) === false ) {
			return false;
		}

		return strpos( $html, 'square.site' ) !== false
			|| strpos( $html, 'editmysite.com' ) !== false
			|| strpos( $html, 'squareup.com' ) !== false;
	}

	/**
	 * Extract returns empty - actual processing requires VisionExtractionProcessor.
	 *
	 * This extractor only finds image candidates; actual event extraction
	 * happens via AI vision processing.
	 *
	 * @param string $html HTML content
	 * @param string $source_url Source URL for context
	 * @return array Always returns empty array
	 */
	public function extract( string $html, string $source_url ): array {
		return array();
	}

	/**
	 * Get the extraction method identifier.
	 *
	 * @return string Method identifier
	 */
	public function getMethod(): string {
		return 'square_online_vision';
	}

	/**
	 * Get image candidates from Square Online __BOOTSTRAP_STATE__ JSON.
	 *
	 * Parses the embedded JSON to find image URLs in userContent blocks,
	 * filtering for images that meet size requirements for flyer analysis.
	 *
	 * @param string $html HTML content containing __BOOTSTRAP_STATE__
	 * @param string $source_url Source URL for resolving relative paths
	 * @return array Array of image candidates with url, width, height, score
	 */
	public function getImageCandidates( string $html, string $source_url ): array {
		$bootstrap_data = $this->extractBootstrapJson( $html );
		if ( null === $bootstrap_data ) {
			return array();
		}

		$images   = $this->findImagesInBootstrap( $bootstrap_data );
		$base_url = $this->getBaseUrl( $source_url, $bootstrap_data );

		$candidates = array();
		foreach ( $images as $image ) {
			$width  = $image['width'] ?? 0;
			$height = $image['height'] ?? 0;

			if ( $width < self::MIN_IMAGE_WIDTH || $height < self::MIN_IMAGE_HEIGHT ) {
				continue;
			}

			$url = $this->resolveImageUrl( $image['source'], $base_url );
			if ( empty( $url ) ) {
				continue;
			}

			$candidates[] = array(
				'url'    => $url,
				'width'  => $width,
				'height' => $height,
				'score'  => $this->scoreImage( $image, $width, $height ),
				'alt'    => $image['alt'] ?? '',
			);
		}

		usort(
			$candidates,
			function ( $a, $b ) {
				return $b['score'] - $a['score'];
			}
		);

		return array_slice( $candidates, 0, 5 );
	}

	/**
	 * Extract __BOOTSTRAP_STATE__ JSON from HTML.
	 *
	 * @param string $html HTML content
	 * @return array|null Parsed JSON data or null on failure
	 */
	private function extractBootstrapJson( string $html ): ?array {
		if ( ! preg_match( '/__BOOTSTRAP_STATE__\s*=\s*(\{.+?\});?\s*(?:<\/script>|window\.)/s', $html, $matches ) ) {
			return null;
		}

		$json = $matches[1];
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}

		return $data;
	}

	/**
	 * Find all images in the bootstrap data structure.
	 *
	 * Recursively searches for figure.source patterns in the JSON.
	 *
	 * @param array $data Bootstrap data
	 * @return array Array of image data with source, width, height
	 */
	private function findImagesInBootstrap( array $data ): array {
		$images = array();
		$this->searchForImages( $data, $images );
		return $images;
	}

	/**
	 * Recursively search for image data in nested structure.
	 *
	 * Looks for:
	 * - figure.source paths (primary Square Online image format)
	 * - image URLs in repeatables
	 * - background images in style properties
	 *
	 * @param mixed $data Data to search
	 * @param array &$images Array to collect found images
	 */
	private function searchForImages( $data, array &$images ): void {
		if ( ! is_array( $data ) ) {
			return;
		}

		if ( isset( $data['figure'] ) && isset( $data['figure']['source'] ) ) {
			$images[] = array(
				'source' => $data['figure']['source'],
				'width'  => $data['figure']['width'] ?? 0,
				'height' => $data['figure']['height'] ?? 0,
				'alt'    => $data['figure']['alt'] ?? '',
			);
		}

		if ( isset( $data['image'] ) && isset( $data['image']['figure'] ) && isset( $data['image']['figure']['source'] ) ) {
			$figure   = $data['image']['figure'];
			$images[] = array(
				'source' => $figure['source'],
				'width'  => $figure['width'] ?? 0,
				'height' => $figure['height'] ?? 0,
				'alt'    => $figure['alt'] ?? '',
			);
		}

		if ( isset( $data['backgroundImage'] ) && isset( $data['backgroundImage']['source'] ) ) {
			$bg       = $data['backgroundImage'];
			$images[] = array(
				'source' => $bg['source'],
				'width'  => $bg['width'] ?? 0,
				'height' => $bg['height'] ?? 0,
				'alt'    => '',
			);
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$this->searchForImages( $value, $images );
			}
		}
	}

	/**
	 * Get base URL for resolving image paths.
	 *
	 * Tries to extract the site URL from bootstrap data, falls back to source URL.
	 *
	 * @param string $source_url Source URL
	 * @param array  $bootstrap_data Bootstrap JSON data
	 * @return string Base URL for image resolution
	 */
	private function getBaseUrl( string $source_url, array $bootstrap_data ): string {
		if ( isset( $bootstrap_data['siteData']['business']['baseUri'] ) ) {
			return rtrim( $bootstrap_data['siteData']['business']['baseUri'], '/' );
		}

		if ( isset( $bootstrap_data['siteData']['meta']['canonical'] ) ) {
			$canonical = $bootstrap_data['siteData']['meta']['canonical'];
			$parsed    = wp_parse_url( $canonical );
			if ( $parsed && isset( $parsed['host'] ) ) {
				$scheme = $parsed['scheme'] ?? 'https';
				return $scheme . '://' . $parsed['host'];
			}
		}

		$parsed = wp_parse_url( $source_url );
		if ( $parsed && isset( $parsed['host'] ) ) {
			$scheme = $parsed['scheme'] ?? 'https';
			return $scheme . '://' . $parsed['host'];
		}

		return '';
	}

	/**
	 * Resolve image source path to absolute URL.
	 *
	 * @param string $source Image source path
	 * @param string $base_url Base URL for resolution
	 * @return string Absolute URL or empty string on failure
	 */
	private function resolveImageUrl( string $source, string $base_url ): string {
		$source = trim( $source );

		if ( empty( $source ) ) {
			return '';
		}

		if ( filter_var( $source, FILTER_VALIDATE_URL ) ) {
			return $source;
		}

		if ( strpos( $source, '//' ) === 0 ) {
			return 'https:' . $source;
		}

		if ( strpos( $source, '/' ) === 0 ) {
			return $base_url . $source;
		}

		return $base_url . '/' . $source;
	}

	/**
	 * Score an image based on flyer likelihood.
	 *
	 * @param array $image Image data
	 * @param int   $width Image width
	 * @param int   $height Image height
	 * @return int Score value (higher = more likely a flyer)
	 */
	private function scoreImage( array $image, int $width, int $height ): int {
		$score = 30;

		if ( $width >= 1000 && $height >= 1000 ) {
			$score += 30;
		} elseif ( $width >= 600 && $height >= 600 ) {
			$score += 20;
		}

		$ratio = $height > 0 ? $width / $height : 1;
		if ( $ratio >= 0.5 && $ratio <= 0.9 ) {
			$score += 15;
		}

		$source = strtolower( $image['source'] ?? '' );
		$alt    = strtolower( $image['alt'] ?? '' );

		$positive_keywords = array( 'poster', 'flyer', 'event', 'show', 'concert', 'live', 'music', 'calendar' );
		foreach ( $positive_keywords as $keyword ) {
			if ( strpos( $source, $keyword ) !== false || strpos( $alt, $keyword ) !== false ) {
				$score += 20;
				break;
			}
		}

		$negative_keywords = array( 'logo', 'icon', 'avatar', 'profile', 'thumb', 'menu', 'nav', 'button' );
		foreach ( $negative_keywords as $keyword ) {
			if ( strpos( $source, $keyword ) !== false || strpos( $alt, $keyword ) !== false ) {
				$score -= 40;
				break;
			}
		}

		return max( 0, $score );
	}
}
