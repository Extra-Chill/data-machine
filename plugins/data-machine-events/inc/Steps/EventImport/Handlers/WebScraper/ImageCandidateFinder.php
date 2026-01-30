<?php
/**
 * Image Candidate Finder
 *
 * Identifies potential event flyer images in HTML content using heuristics.
 * Scores images based on positive/negative signals to find likely flyers.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 * @since   0.9.18
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ImageCandidateFinder {

	private const MIN_SCORE_THRESHOLD = 20;
	private const MAX_CANDIDATES      = 5;

	/**
	 * Find and score image candidates from HTML content.
	 *
	 * @param string $html HTML content to analyze
	 * @param string $page_url Source URL for resolving relative paths
	 * @return array Array of scored candidates sorted by score descending
	 */
	public function findCandidates( string $html, string $page_url ): array {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<meta charset="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath      = new \DOMXPath( $dom );
		$candidates = array();

		$images = $xpath->query( '//img[@src]' );
		if ( false === $images ) {
			return array();
		}

		foreach ( $images as $img ) {
			if ( ! ( $img instanceof \DOMElement ) ) {
				continue;
			}

			$src = $img->getAttribute( 'src' );
			if ( empty( $src ) ) {
				continue;
			}

			$absolute_url = $this->resolveUrl( $src, $page_url );
			if ( empty( $absolute_url ) ) {
				continue;
			}

			$score = $this->scoreImage( $img, $xpath, $absolute_url );

			if ( $score >= self::MIN_SCORE_THRESHOLD ) {
				$candidates[] = array(
					'url'    => $absolute_url,
					'score'  => $score,
					'alt'    => $img->getAttribute( 'alt' ),
					'width'  => $this->extractDimension( $img, 'width' ),
					'height' => $this->extractDimension( $img, 'height' ),
				);
			}
		}

		usort(
			$candidates,
			function ( $a, $b ) {
				return $b['score'] - $a['score'];
			}
		);

		return array_slice( $candidates, 0, self::MAX_CANDIDATES );
	}

	/**
	 * Check if HTML content contains potential flyer images.
	 *
	 * @param string $html HTML content
	 * @param string $page_url Source URL
	 * @return bool True if viable candidates exist
	 */
	public function hasViableCandidates( string $html, string $page_url ): bool {
		$candidates = $this->findCandidates( $html, $page_url );
		return ! empty( $candidates );
	}

	/**
	 * Score an image element based on flyer likelihood.
	 *
	 * @param \DOMElement $img Image element
	 * @param \DOMXPath   $xpath XPath instance
	 * @param string      $url Resolved image URL
	 * @return int Score value (higher = more likely a flyer)
	 */
	private function scoreImage( \DOMElement $img, \DOMXPath $xpath, string $url ): int {
		$score = 0;

		$alt       = strtolower( $img->getAttribute( 'alt' ) ?? '' );
		$css_class = strtolower( $img->getAttribute( 'class' ) ?? '' );
		$src       = strtolower( $url );

		// Positive signals
		$score += $this->scorePositiveKeywords( $alt, $src, $css_class );
		$score += $this->scoreDimensions( $img );
		$score += $this->scoreContext( $img, $xpath );
		$score += $this->scoreLinked( $img, $xpath );

		// Negative signals
		$score += $this->scoreNegativeKeywords( $alt, $src, $css_class );
		$score += $this->scoreNegativeLocation( $img, $xpath );

		return max( 0, $score );
	}

	/**
	 * Score positive keywords in alt, src, and class attributes.
	 */
	private function scorePositiveKeywords( string $alt, string $src, string $css_class ): int {
		$score             = 0;
		$flyer_keywords    = array( 'flyer', 'poster', 'flier' );
		$event_keywords    = array( 'event', 'show', 'concert', 'live', 'music', 'gig' );
		$calendar_keywords = array( 'calendar', 'schedule' );

		foreach ( $flyer_keywords as $keyword ) {
			if ( strpos( $alt, $keyword ) !== false ) {
				$score += 30;
				break;
			}
			if ( strpos( $src, $keyword ) !== false ) {
				$score += 25;
				break;
			}
		}

		foreach ( $event_keywords as $keyword ) {
			if ( strpos( $alt, $keyword ) !== false ) {
				$score += 20;
				break;
			}
			if ( strpos( $css_class, $keyword ) !== false ) {
				$score += 15;
				break;
			}
		}

		foreach ( $calendar_keywords as $keyword ) {
			if ( strpos( $alt, $keyword ) !== false || strpos( $src, $keyword ) !== false ) {
				$score += 25;
				break;
			}
		}

		return $score;
	}

	/**
	 * Score based on image dimensions.
	 */
	private function scoreDimensions( \DOMElement $img ): int {
		$width  = $this->extractDimension( $img, 'width' );
		$height = $this->extractDimension( $img, 'height' );

		if ( 0 === $width || 0 === $height ) {
			return 0;
		}

		// Large images (>= 600x400) are likely flyers
		if ( $width >= 600 && $height >= 400 ) {
			return 25;
		}

		// Medium images (>= 400x300) might be flyers
		if ( $width >= 400 && $height >= 300 ) {
			return 10;
		}

		// Small images (< 300x200) are unlikely flyers
		if ( $width < 300 || $height < 200 ) {
			return -40;
		}

		return 0;
	}

	/**
	 * Score based on surrounding context (event containers, date text).
	 */
	private function scoreContext( \DOMElement $img, \DOMXPath $xpath ): int {
		$score = 0;

		// Check if image is in an event-related container
		$event_containers = $xpath->query(
			'ancestor::*[contains(@class, "event") or contains(@class, "show") or contains(@class, "concert") or contains(@id, "event") or contains(@id, "show")]',
			$img
		);

		if ( $event_containers && $event_containers->length > 0 ) {
			$score += 20;
		}

		// Check for date text nearby
		$parent = $img->parentNode;
		if ( $parent instanceof \DOMElement ) {
			$sibling_text = $parent->textContent ?? '';
			if ( preg_match( '/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+\d{1,2}/i', $sibling_text ) ) {
				$score += 15;
			}
			if ( preg_match( '/\d{1,2}\s*(am|pm)/i', $sibling_text ) ) {
				$score += 10;
			}
		}

		return $score;
	}

	/**
	 * Score based on whether the image is linked.
	 */
	private function scoreLinked( \DOMElement $img, \DOMXPath $xpath ): int {
		$parent_link = $xpath->query( 'ancestor::a[1]', $img );
		if ( $parent_link && $parent_link->length > 0 ) {
			$link = $parent_link->item( 0 );
			if ( $link instanceof \DOMElement ) {
				$href = strtolower( $link->getAttribute( 'href' ) ?? '' );
				if ( strpos( $href, 'event' ) !== false || strpos( $href, 'ticket' ) !== false ) {
					return 15;
				}
				return 5;
			}
		}
		return 0;
	}

	/**
	 * Score negative keywords that indicate non-flyer images.
	 */
	private function scoreNegativeKeywords( string $alt, string $src, string $css_class ): int {
		$score = 0;

		$logo_keywords    = array( 'logo', 'brand', 'icon', 'avatar', 'profile', 'sponsor' );
		$ui_keywords      = array( 'button', 'arrow', 'chevron', 'nav', 'menu', 'close', 'search' );
		$content_keywords = array( 'thumbnail', 'thumb', 'avatar', 'gravatar', 'author' );

		foreach ( $logo_keywords as $keyword ) {
			if ( strpos( $src, $keyword ) !== false ) {
				$score -= 50;
				break;
			}
			if ( strpos( $css_class, $keyword ) !== false ) {
				$score -= 40;
				break;
			}
		}

		foreach ( $ui_keywords as $keyword ) {
			if ( strpos( $css_class, $keyword ) !== false || strpos( $src, $keyword ) !== false ) {
				$score -= 60;
				break;
			}
		}

		foreach ( $content_keywords as $keyword ) {
			if ( strpos( $css_class, $keyword ) !== false || strpos( $src, $keyword ) !== false ) {
				$score -= 30;
				break;
			}
		}

		return $score;
	}

	/**
	 * Score negative locations (header, footer, nav).
	 */
	private function scoreNegativeLocation( \DOMElement $img, \DOMXPath $xpath ): int {
		$score = 0;

		$header_footer = $xpath->query( 'ancestor::header | ancestor::footer | ancestor::nav', $img );
		if ( $header_footer && $header_footer->length > 0 ) {
			$score -= 30;
		}

		$aside = $xpath->query( 'ancestor::aside', $img );
		if ( $aside && $aside->length > 0 ) {
			$score -= 20;
		}

		return $score;
	}

	/**
	 * Extract dimension from element attributes or style.
	 *
	 * @param \DOMElement $img Image element
	 * @param string      $dimension 'width' or 'height'
	 * @return int Dimension in pixels or 0 if unknown
	 */
	private function extractDimension( \DOMElement $img, string $dimension ): int {
		$value = $img->getAttribute( $dimension );
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		$style = $img->getAttribute( 'style' );
		if ( ! empty( $style ) && preg_match( '/' . $dimension . '\s*:\s*(\d+)/', $style, $matches ) ) {
			return (int) $matches[1];
		}

		return 0;
	}

	/**
	 * Resolve relative URL to absolute.
	 *
	 * @param string $url URL to resolve
	 * @param string $base_url Base URL for resolution
	 * @return string Absolute URL or empty string on failure
	 */
	private function resolveUrl( string $url, string $base_url ): string {
		$url = trim( $url );

		if ( empty( $url ) || strpos( $url, 'data:' ) === 0 ) {
			return '';
		}

		if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return $url;
		}

		$parsed = wp_parse_url( $base_url );
		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return '';
		}

		$scheme = $parsed['scheme'] ?? 'https';
		$host   = $parsed['host'];
		$base   = $scheme . '://' . $host;

		if ( strpos( $url, '//' ) === 0 ) {
			return $scheme . ':' . $url;
		}

		if ( strpos( $url, '/' ) === 0 ) {
			return $base . $url;
		}

		$path = $parsed['path'] ?? '/';
		$dir  = dirname( $path );
		if ( '.' === $dir || '/' === $dir ) {
			$dir = '';
		}

		return $base . $dir . '/' . $url;
	}
}
