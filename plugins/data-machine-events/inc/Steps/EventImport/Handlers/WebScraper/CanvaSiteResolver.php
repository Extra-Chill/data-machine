<?php
/**
 * Resolve Canva Sites into plain HTML for any event extractor.
 *
 * Canva Websites (`*.my.canva.site` and custom domains) render client-side:
 * the server HTML carries no visible text, and every text block lives in the
 * document JSON passed to `JSON.parse('...')`. This resolver decodes that JSON
 * and emits simple HTML (one block per text run, `<a href>` for links) in
 * document order, so the normal structured extraction and HTML-section/AI
 * fallback path can read the page.
 *
 * @package DataMachineEvents
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CanvaSiteResolver {

	/** Attribute marking one decoded Canva page section. */
	public const SECTION_ATTRIBUTE = 'data-canva-section';

	/** Canva runtime globals present on every Canva Site, including custom domains. */
	private const RUNTIME_MARKERS = array( '__canva_website_bootstrap__', '__canva_public_path__', 'canva_loadFooterHtml' );

	/**
	 * Decode a Canva Site into simple HTML.
	 *
	 * @param string $html Original page HTML.
	 * @param string $url  Original page URL (used for host detection only).
	 * @return string|null Decoded HTML, or null when the page is not Canva or nothing decodes.
	 */
	public function resolve( string $html, string $url = '' ): ?string {
		if ( ! $this->isCanva( $html, $url ) ) {
			return null;
		}

		foreach ( $this->jsonParseLiterals( $html ) as $literal ) {
			$json = $this->decodeJsString( $literal );
			if ( null === $json || ! preg_match( '/"C"\s*:\s*\{\s*"A"\s*:\s*\[/', $json ) ) {
				continue;
			}
			$data = json_decode( $json, true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			$blocks = array();
			$this->collect( $data, array(), $blocks );
			$blocks = array_values( array_filter( $blocks, static fn( array $block ): bool => '' !== $block['text'] || null !== $block['href'] ) );
			if ( ! empty( $blocks ) ) {
				return $this->render( $blocks, $this->title( $html ) );
			}
		}

		return null;
	}

	/** Detect Canva by hosted domain or by runtime markers (custom domains). */
	public function isCanva( string $html, string $url = '' ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'my.canva.site' === $host || str_ends_with( $host, '.my.canva.site' ) ) {
			return true;
		}
		foreach ( self::RUNTIME_MARKERS as $marker ) {
			if ( str_contains( $html, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Raw (still JS-escaped) bodies of every `JSON.parse('...')` string literal.
	 *
	 * @return string[]
	 */
	private function jsonParseLiterals( string $html ): array {
		$literals = array();
		$offset   = 0;
		$length   = strlen( $html );
		while ( preg_match( '/JSON\.parse\(\s*([\'"])/', $html, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			$quote = $match[1][0];
			$start = $match[0][1] + strlen( $match[0][0] );
			$i     = $start;
			while ( $i < $length ) {
				$i += strcspn( $html, '\\' . $quote, $i );
				if ( $i >= $length || $html[ $i ] === $quote ) {
					break;
				}
				$i += 2; // Skip the escaped character.
			}
			if ( $i >= $length ) {
				break;
			}
			$literals[] = substr( $html, $start, $i - $start );
			$offset     = $i + 1;
		}
		return $literals;
	}

	/**
	 * Decode a JS string literal body by rewriting it as a JSON string literal.
	 *
	 * JSON decoding then handles `\uXXXX` (including surrogate pairs), `\n`,
	 * and the other standard escapes.
	 */
	private function decodeJsString( string $literal ): ?string {
		$json = preg_replace_callback(
			'/\\\\(u\{[0-9a-fA-F]{1,6}\}|u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|\r\n|[\s\S])|"|[\x00-\x1f]/',
			static function ( array $m ): string {
				if ( ! isset( $m[1] ) ) {
					return '"' === $m[0] ? '\\"' : sprintf( '\\u%04x', ord( $m[0] ) );
				}
				$escape = $m[1];
				if ( 'u{' === substr( $escape, 0, 2 ) ) {
					$code = (int) hexdec( substr( $escape, 2, -1 ) );
					if ( $code <= 0xFFFF ) {
						return sprintf( '\\u%04x', $code );
					}
					$code -= 0x10000;
					return sprintf( '\\u%04x\\u%04x', 0xD800 | ( $code >> 10 ), 0xDC00 | ( $code & 0x3FF ) );
				}
				if ( 'u' === $escape[0] && 5 === strlen( $escape ) ) {
					return '\\' . $escape;
				}
				if ( 'x' === $escape[0] && 3 === strlen( $escape ) ) {
					return '\\u00' . substr( $escape, 1 );
				}
				switch ( $escape ) {
					case 'n':
					case 'r':
					case 't':
					case 'b':
					case 'f':
					case '"':
					case '\\':
					case '/':
						return '\\' . $escape;
					case 'v':
						return '\\u000b';
					case '0':
						return '\\u0000';
					case "\n":
					case "\r":
					case "\r\n":
						return ''; // Line continuation.
				}
				return ord( $escape ) < 0x20 ? sprintf( '\\u%04x', ord( $escape ) ) : $escape;
			},
			$literal
		);
		if ( null === $json ) {
			return null;
		}
		$decoded = json_decode( '"' . $json . '"' );
		return is_string( $decoded ) ? $decoded : null;
	}

	/**
	 * Walk the document in order, collecting text runs and links with their paths.
	 *
	 * A text run is `"C":{"A":["line\n", ...],"B":[length, ...]}`; a link is
	 * `{"A?":"A","A":"https://..."}`. A link attached to the same element as
	 * the text run just emitted wraps that text; otherwise it stands alone.
	 *
	 * @param mixed                                                       $node   JSON node.
	 * @param array<int, int|string>                                      $path   Path to the node.
	 * @param array<int, array{text: string, href: ?string, path: array}> $blocks Collected blocks.
	 */
	private function collect( $node, array $path, array &$blocks ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		$start = count( $blocks );
		foreach ( $node as $key => $child ) {
			if ( 'C' === $key && $this->isTextRun( $child ) ) {
				$blocks[] = array(
					'text' => $this->runText( $child['A'] ),
					'href' => null,
					'path' => array_merge( $path, array( $key ) ),
				);
				continue;
			}

			$href = $this->linkUrl( $child );
			if ( null !== $href ) {
				$last = count( $blocks ) - 1;
				if ( $last >= $start && null === $blocks[ $last ]['href'] && '' !== $blocks[ $last ]['text'] ) {
					$blocks[ $last ]['href'] = $href;
				} else {
					$blocks[] = array(
						'text' => '',
						'href' => $href,
						'path' => array_merge( $path, array( $key ) ),
					);
				}
				continue;
			}

			$this->collect( $child, array_merge( $path, array( $key ) ), $blocks );
		}
	}

	/** @param mixed $value */
	private function isTextRun( $value ): bool {
		if ( ! is_array( $value ) || empty( $value['A'] ) || ! is_array( $value['A'] ) || ! isset( $value['B'] ) || ! is_array( $value['B'] ) ) {
			return false;
		}
		foreach ( $value['A'] as $line ) {
			if ( ! is_string( $line ) ) {
				return false;
			}
		}
		foreach ( $value['B'] as $length ) {
			if ( ! is_int( $length ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param mixed $value */
	private function linkUrl( $value ): ?string {
		if ( ! is_array( $value ) || ( $value['A?'] ?? null ) !== 'A' || ! is_string( $value['A'] ?? null ) ) {
			return null;
		}
		$url = trim( $value['A'] );
		return preg_match( '#^https?://[^\s]+$#i', $url ) ? $url : null;
	}

	/** @param string[] $lines */
	private function runText( array $lines ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", implode( '', $lines ) );
		$text = (string) preg_replace( '/[ \t]*\n\s*/', "\n", $text );
		return trim( $text );
	}

	/** Original page title, kept for context. */
	private function title( string $html ): string {
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			return trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		return '';
	}

	/**
	 * Render blocks as HTML, one section per top-level branch the runs diverge at.
	 *
	 * @param array<int, array{text: string, href: ?string, path: array}> $blocks Blocks in document order.
	 */
	private function render( array $blocks, string $title ): string {
		$prefix = $blocks[0]['path'];
		foreach ( $blocks as $block ) {
			$common = 0;
			$max    = min( count( $prefix ), count( $block['path'] ) );
			while ( $common < $max && $prefix[ $common ] === $block['path'][ $common ] ) {
				++$common;
			}
			$prefix = array_slice( $prefix, 0, $common );
		}
		$depth = count( $prefix );

		$sections = array();
		$current  = null;
		foreach ( $blocks as $block ) {
			$key = count( $block['path'] ) > $depth ? (string) $block['path'][ $depth ] : '';
			if ( null === $current || $current !== $key ) {
				$sections[] = array();
				$current    = $key;
			}
			$sections[ count( $sections ) - 1 ][] = $this->renderBlock( $block );
		}

		$body = '';
		foreach ( $sections as $index => $section ) {
			$body .= '<section ' . self::SECTION_ATTRIBUTE . '="' . ( $index + 1 ) . '">' . "\n" . implode( "\n", $section ) . "\n</section>\n";
		}

		return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html( $title ) . "</title></head><body>\n" . $body . "</body></html>\n";
	}

	/** @param array{text: string, href: ?string, path: array} $block */
	private function renderBlock( array $block ): string {
		$text = '' === $block['text'] ? (string) $block['href'] : $block['text'];
		$text = implode( '<br>', array_map( 'esc_html', explode( "\n", $text ) ) );
		if ( null !== $block['href'] ) {
			$text = '<a href="' . esc_url( $block['href'] ) . '">' . $text . '</a>';
		}
		return '<p>' . $text . '</p>';
	}
}
