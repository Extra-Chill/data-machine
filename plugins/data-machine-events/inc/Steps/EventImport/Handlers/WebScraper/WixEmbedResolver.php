<?php
/**
 * Resolve Wix custom HTML components for any event extractor.
 *
 * @package DataMachineEvents
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use DataMachine\Core\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WixEmbedResolver {

	/**
	 * Fetch unique filesusr.com embeds referenced by Thunderbolt component props.
	 *
	 * Resolution is deliberately one hop: embed HTML is never resolved recursively.
	 *
	 * @param string $html Original page HTML.
	 * @return string[] Embedded HTML documents.
	 */
	public function resolve( string $html ): array {
		if ( strpos( $html, 'staticHTMLComponentUrl' ) === false
			|| ! preg_match_all( '/"(comp-[a-z0-9_-]+)"\s*:\s*"HtmlComponent"/', $html, $components )
			|| ! preg_match_all( '#https://siteassets\.parastorage\.com/pages/pages/thunderbolt\?[^"\'<>\s]+#', $html, $urls ) ) {
			return array();
		}

		$embeds = array();
		$seen   = array();
		foreach ( array_unique( $urls[0] ) as $raw_url ) {
			$url = html_entity_decode( $raw_url, ENT_QUOTES | ENT_HTML5 );
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			if ( ( $query['module'] ?? '' ) !== 'thunderbolt-features' ) {
				continue;
			}

			$features = json_decode( $this->fetch( $url, 20 ) ?? '', true );
			$props    = $features['props']['render']['compProps'] ?? array();
			foreach ( array_unique( $components[1] ) as $id ) {
				$embed_url = $props[ $id ]['url'] ?? null;
				if ( ! is_string( $embed_url ) || isset( $seen[ $embed_url ] ) ) {
					continue;
				}
				$host = strtolower( (string) wp_parse_url( $embed_url, PHP_URL_HOST ) );
				if ( 'https' !== wp_parse_url( $embed_url, PHP_URL_SCHEME )
					|| ( 'filesusr.com' !== $host && ! str_ends_with( $host, '.filesusr.com' ) ) ) {
					continue;
				}
				$seen[ $embed_url ] = true;
				$content            = $this->fetch( $embed_url, 15 );
				if ( ! empty( $content ) ) {
					$embeds[] = $content;
				}
			}
		}

		return $embeds;
	}

	/** Fetch through the shared HTTP client with no off-host redirects. */
	private function fetch( string $url, int $timeout ): ?string {
		// HttpClient does not forward the redirection option in all versions.
		$no_redirects = static function ( array $args, string $request_url ) use ( $url ): array {
			if ( $request_url === $url ) {
				$args['redirection'] = 0;
			}
			return $args;
		};
		add_filter( 'http_request_args', $no_redirects, 10, 2 );
		try {
			$result = HttpClient::get( $url, array( 'timeout' => $timeout ) );
		} finally {
			remove_filter( 'http_request_args', $no_redirects, 10 );
		}
		return ! empty( $result['success'] ) ? ( $result['data'] ?? null ) : null;
	}
}
