<?php
/**
 * Broken Image Reference Abilities
 *
 * Read-only diagnostic that scans published post content for image references
 * whose local files are missing. Multisite-aware: a reference URL is resolved
 * to its owning site's uploads base directory by matching the upload base URL
 * prefix, which is correct for both subdomain and subdirectory installs and
 * supports cross-site references (a post on one blog referencing an upload
 * hosted by another network blog).
 *
 * The diagnostic never mutates posts or files.
 *
 * @package DataMachine\Abilities\Media
 * @since 0.161.6
 */

namespace DataMachine\Abilities\Media;

use DataMachine\Abilities\AbilityRegistration;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class BrokenImageReferenceAbilities {

	/**
	 * Image URL-bearing attributes recognized in <img> tags.
	 *
	 * `srcset`/`data-srcset`/`data-lazy-srcset` carry comma-separated candidate
	 * lists that must be split into individual URLs.
	 *
	 * @var array<int, string>
	 */
	private const SRCSET_ATTRIBUTES = array( 'srcset', 'data-srcset', 'data-lazy-srcset' );

	/**
	 * Single-URL lazy-load / background image attributes.
	 *
	 * @var array<int, string>
	 */
	private const SINGLE_URL_ATTRIBUTES = array(
		'src',
		'data-src',
		'data-lazy-src',
		'data-original',
		'data-bg',
	);

	/**
	 * Attributes whose value is a full CSS background-image source set, e.g.
	 * `data-bgset="url(...) , url(...)"`. Extracted via the url(...) extractor.
	 *
	 * @var array<int, string>
	 */
	private const BGSET_ATTRIBUTES = array( 'data-bgset' );

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/diagnose-broken-image-references',
				array(
					'label'               => 'Diagnose Broken Image References',
					'description'         => 'Scan published post content for image references whose local files are missing. Multisite-aware (host -> upload directory) and cross-site safe. Read-only; never mutates posts or files.',
					'category'            => 'datamachine-media',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array(
								'type'        => 'string',
								'description' => 'Post type to scan. Default: post.',
								'default'     => 'post',
							),
							'post_id'   => array(
								'type'        => 'integer',
								'description' => 'Limit the scan to a single post ID on the current site.',
							),
							'limit'     => array(
								'type'        => 'integer',
								'description' => 'Maximum number of posts to scan. 0 for no limit. Default: 0.',
								'default'     => 0,
							),
							'network'   => array(
								'type'        => 'boolean',
								'description' => 'Scan every site on the network (multisite only). Default: false (current site only).',
								'default'     => false,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'           => array( 'type' => 'boolean' ),
							'posts_scanned'     => array( 'type' => 'integer' ),
							'references_found'  => array( 'type' => 'integer' ),
							'broken_count'      => array( 'type' => 'integer' ),
							'broken_references' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'message'           => array( 'type' => 'string' ),
							'error'             => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'diagnoseBrokenImageReferences' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Scan published post content for image references that point at missing local files.
	 *
	 * Resolution is multisite-aware: each image URL is matched against the upload
	 * base URL of every network site so that a post on one blog can legitimately
	 * reference an upload hosted by another blog. External hosts (not part of the
	 * network) are reported separately and never verified by default.
	 *
	 * @since 0.161.6
	 *
	 * @param array             $input        Ability input.
	 * @param callable|null     $file_checker Optional file-existence checker. Defaults
	 *                                        to native file_exists(). Test seam only.
	 * @return array Diagnostic results.
	 */
	public static function diagnoseBrokenImageReferences( array $input = array(), ?callable $file_checker = null ): array {
		$post_type = sanitize_text_field( $input['post_type'] ?? 'post' );
		$post_id   = absint( $input['post_id'] ?? 0 );
		$limit     = absint( $input['limit'] ?? 0 );
		$network   = ! empty( $input['network'] );

		$file_checker = is_callable( $file_checker ) ? $file_checker : 'file_exists';

		$blog_uploads = self::build_blog_uploads_map( $network );

		$blogs_to_scan = array_keys( $blog_uploads );
		if ( ! $network ) {
			$blogs_to_scan = array( get_current_blog_id() );
		}

		$broken      = array();
		$posts_total = 0;
		$refs_total  = 0;

		foreach ( $blogs_to_scan as $scan_blog_id ) {
			$switched = self::maybe_switch_to_blog( $scan_blog_id );

			try {
				$posts = self::fetch_posts_for_scan( $post_type, $post_id, $limit );

				$current_home    = home_url();
				$current_origin  = self::origin_from_url( $current_home );
				$current_blog_id = get_current_blog_id();

				foreach ( $posts as $post ) {
					++$posts_total;
					$content = is_object( $post ) ? (string) $post->post_content : '';
					if ( '' === $content ) {
						continue;
					}

					$raw_refs = self::extract_image_urls_from_html( $content );

					$seen_urls = array();
					foreach ( $raw_refs as $ref ) {
						$normalized = self::normalize_image_url( $ref['url'], $current_origin );
						if ( '' === $normalized ) {
							continue;
						}

						$dedupe_key = $normalized;
						if ( isset( $seen_urls[ $dedupe_key ] ) ) {
							continue;
						}
						$seen_urls[ $dedupe_key ] = true;
						++$refs_total;

						$resolved = self::resolve_url_to_local( $normalized, $blog_uploads, $current_blog_id, $current_origin );

						if ( 'local' !== $resolved['kind'] ) {
							continue;
						}

						$path = $resolved['path'] ?? '';
						if ( '' === $path ) {
							continue;
						}

						if ( ! $file_checker( $path ) ) {
							$broken[] = array(
								'blog_id'    => (int) $current_blog_id,
								'post_id'    => (int) ( is_object( $post ) ? $post->ID : 0 ),
								'post_title' => is_object( $post ) ? (string) $post->post_title : '',
								'url'        => $normalized,
								'attribute'  => $ref['attribute'],
								'host_blog'  => isset( $resolved['blog_id'] ) ? (int) $resolved['blog_id'] : 0,
								'path'       => $path,
								'reason'     => 'file_missing',
							);
						}
					}
				}
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}

		return array(
			'success'           => true,
			'posts_scanned'     => $posts_total,
			'references_found'  => $refs_total,
			'broken_count'      => count( $broken ),
			'broken_references' => $broken,
			'message'           => sprintf(
				'Scanned %1$d post(s), %2$d image reference(s), found %3$d missing local file(s).',
				$posts_total,
				$refs_total,
				count( $broken )
			),
		);
	}

	/**
	 * Build the blog_id => upload dir map for every site to be considered.
	 *
	 * For network scans this iterates every site; otherwise it only contains the
	 * current site. Each entry carries the base URL (for matching) and base
	 * directory (for building the filesystem path).
	 *
	 * @since 0.161.6
	 *
	 * @param bool $network Whether to include every network site.
	 * @return array<int, array{baseurl: string, basedir: string}>
	 */
	private static function build_blog_uploads_map( bool $network ): array {
		$site_ids = array( get_current_blog_id() );

		if ( $network && is_multisite() ) {
			$site_ids = array_map(
				'intval',
				get_sites(
					array(
						'fields' => 'ids',
						'number' => '',
					)
				)
			);
			if ( empty( $site_ids ) ) {
				$site_ids = array( get_current_blog_id() );
			}
		}

		$map = array();
		foreach ( $site_ids as $site_id ) {
			$switched = self::maybe_switch_to_blog( $site_id );
			try {
				$uploads         = wp_get_upload_dir();
				$map[ $site_id ] = array(
					'baseurl' => isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '',
					'basedir' => isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '',
				);
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}

		return $map;
	}

	/**
	 * Fetch published posts (id + title + content) for the current blog.
	 *
	 * Streams content in bounded chunks keyed by ascending ID so peak memory
	 * scales with the chunk size rather than the full content set, mirroring the
	 * pattern established by InternalLinkingAbilities::buildLinkGraph().
	 *
	 * @since 0.161.6
	 *
	 * @param string $post_type Post type to scan.
	 * @param int    $post_id   Specific post ID (0 = scan all published).
	 * @param int    $limit     Maximum posts (0 = no limit).
	 * @return array<int, object>
	 */
	private static function fetch_posts_for_scan( string $post_type, int $post_id, int $limit ): array {
		global $wpdb;

		if ( $post_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$post = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT ID, post_title, post_content FROM {$wpdb->posts}
					WHERE ID = %d AND post_status = %s",
					$post_id,
					'publish'
				)
			);

			return is_object( $post ) ? array( $post ) : array();
		}

		$posts = array();
		$chunk = 100;
		$last  = 0;
		$cap   = $limit > 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_content FROM {$wpdb->posts}
					WHERE post_type = %s AND post_status = %s AND ID > %d
					ORDER BY ID ASC LIMIT %d",
					$post_type,
					'publish',
					$last,
					$chunk
				)
			);
			if ( ! is_array( $rows ) || empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$posts[] = $row;
				$last    = max( $last, (int) $row->ID );

				if ( $cap && count( $posts ) >= $limit ) {
					return $posts;
				}
			}
			$rows_returned = count( $rows );
		} while ( $rows_returned === $chunk );

		return $posts;
	}

	/**
	 * Extract image reference URLs from post HTML.
	 *
	 * Parses <img> tags via libxml (when available) with a regex fallback, then
	 * collects every candidate URL from `src`, `srcset` candidates, and the
	 * common lazy-load / responsive attributes. Pure: no WordPress dependencies.
	 *
	 * @since 0.161.6
	 *
	 * @param string $html Raw post content.
	 * @return array<int, array{url: string, attribute: string}> Reference list in document order.
	 */
	public static function extract_image_urls_from_html( string $html ): array {
		$img_tags = self::parse_img_tags( $html );

		$refs = array();
		foreach ( $img_tags as $attrs ) {
			foreach ( self::SINGLE_URL_ATTRIBUTES as $attr ) {
				$value = self::attr( $attrs, $attr );
				if ( '' !== $value ) {
					$refs[] = array(
						'url'       => $value,
						'attribute' => $attr,
					);
				}
			}

			foreach ( self::SRCSET_ATTRIBUTES as $attr ) {
				$value = self::attr( $attrs, $attr );
				if ( '' === $value ) {
					continue;
				}
				foreach ( self::parse_srcset( $value ) as $candidate ) {
					$refs[] = array(
						'url'       => $candidate,
						'attribute' => $attr,
					);
				}
			}

			foreach ( self::BGSET_ATTRIBUTES as $attr ) {
				$value = self::attr( $attrs, $attr );
				if ( '' === $value ) {
					continue;
				}
				foreach ( self::parse_css_url_list( $value ) as $candidate ) {
					$refs[] = array(
						'url'       => $candidate,
						'attribute' => $attr,
					);
				}
			}
		}

		return $refs;
	}

	/**
	 * Parse all <img ...> tags from HTML into attribute maps.
	 *
	 * Uses DOMDocument when libxml is available; otherwise falls back to a
	 * tolerant regex so the diagnostic degrades gracefully on hosts without the
	 * extension. Attribute keys are lowercased.
	 *
	 * @since 0.161.6
	 *
	 * @param string $html Raw HTML.
	 * @return array<int, array<string, string>>
	 */
	private static function parse_img_tags( string $html ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}

		$parsed = self::parse_img_tags_dom( $html );
		if ( null !== $parsed ) {
			return $parsed;
		}

		return self::parse_img_tags_regex( $html );
	}

	/**
	 * DOMDocument-based <img> parser. Returns null when libxml is unavailable.
	 *
	 * @since 0.161.6
	 *
	 * @param string $html Raw HTML.
	 * @return array<int, array<string, string>>|null
	 */
	private static function parse_img_tags_dom( string $html ): ?array {
		if ( ! class_exists( 'DOMDocument' ) || ! function_exists( 'libxml_use_internal_errors' ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		try {
			$dom = new \DOMDocument();
			// Suppress encoding sniffing issues by prefixing an encoding declaration.
			$loaded = @$dom->loadHTML( '<?xml encoding="UTF-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- libxml emits unactionable HTML warnings on real-world markup.
			if ( ! $loaded ) {
				return null;
			}

			$tags = $dom->getElementsByTagName( 'img' );
			$out  = array();
			foreach ( $tags as $node ) {
				/** @var \DOMElement $node */
				if ( ! $node->hasAttributes() ) {
					continue;
				}
				$map = array();
				foreach ( $node->attributes as $attribute ) {
					$name         = strtolower( $attribute->nodeName );
					$map[ $name ] = trim( $attribute->nodeValue ?? '' );
				}
				$out[] = $map;
			}

			return $out;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	/**
	 * Regex fallback <img> parser for environments without libxml.
	 *
	 * @since 0.161.6
	 *
	 * @param string $html Raw HTML.
	 * @return array<int, array<string, string>>
	 */
	private static function parse_img_tags_regex( string $html ): array {
		$out = array();
		if ( false === preg_match_all( '/<img\b[^>]*>/i', $html, $matches ) ) {
			return $out;
		}

		foreach ( $matches[0] as $tag ) {
			if ( false === preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'`=<>]+))/', $tag, $attr_matches, PREG_SET_ORDER ) ) {
				continue;
			}

			$map = array();
			foreach ( $attr_matches as $m ) {
				$name         = strtolower( $m[1] );
				$value        = isset( $m[2] ) && '' !== $m[2] ? $m[2] : ( isset( $m[3] ) && '' !== $m[3] ? $m[3] : ( $m[4] ?? '' ) );
				$map[ $name ] = trim( $value );
			}
			$out[] = $map;
		}

		return $out;
	}

	/**
	 * Parse a srcset string into its component image URLs.
	 *
	 * A srcset is a comma-separated list of `URL descriptor` tokens where the
	 * descriptor is a width (`1x`, `480w`) or pixel density. This strips the
	 * descriptor and returns only URLs. Pure.
	 *
	 * @since 0.161.6
	 *
	 * @param string $srcset Raw srcset attribute value.
	 * @return array<int, string>
	 */
	public static function parse_srcset( string $srcset ): array {
		if ( '' === trim( $srcset ) ) {
			return array();
		}

		$urls = array();
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			// A descriptor is a trailing token like "480w" or "2x" preceded by space.
			$parts = preg_split( '/\s+/', $candidate );
			if ( ! is_array( $parts ) || empty( $parts ) ) {
				continue;
			}
			$url = trim( $parts[0] );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Extract url(...) references from a CSS background-style value.
	 *
	 * Handles data-bgset / data-bg values like `url(a.jpg) 1x, url(b.jpg) 2x`.
	 * Pure.
	 *
	 * @since 0.161.6
	 *
	 * @param string $value Raw attribute value.
	 * @return array<int, string>
	 */
	public static function parse_css_url_list( string $value ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}

		$urls = array();
		if ( false === preg_match_all( '/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $value, $matches, PREG_SET_ORDER ) ) {
			return $urls;
		}

		foreach ( $matches as $m ) {
			$url = trim( $m[1] );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Normalize a raw image URL to an absolute URL.
	 *
	 * Resolves root-relative (`/wp-content/...`) and protocol-relative
	 * (`//host/...`) URLs against the current site origin. Data URIs, mailto,
	 * and other non-fetchable schemes yield an empty string. Pure.
	 *
	 * @since 0.161.6
	 *
	 * @param string $url            Raw URL from markup.
	 * @param string $current_origin Current site origin, e.g. `https://extrachill.com`.
	 * @return string Absolute URL, or '' for unusable schemes.
	 */
	public static function normalize_image_url( string $url, string $current_origin ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$lower = strtolower( $url );
		foreach ( array( 'data:', 'mailto:', 'tel:', 'javascript:', 'blob:' ) as $skip ) {
			if ( str_starts_with( $lower, $skip ) ) {
				return '';
			}
		}

		// Protocol-relative.
		if ( str_starts_with( $url, '//' ) ) {
			$scheme = self::scheme_from_origin( $current_origin );

			return '' !== $scheme ? $scheme . ':' . $url : '';
		}

		// Root-relative or relative without a scheme.
		if ( ! preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $url ) ) {
			$origin = rtrim( $current_origin, '/' );
			$lead   = str_starts_with( $url, '/' ) ? '' : '/';

			return $origin . $lead . $url;
		}

		return $url;
	}

	/**
	 * Resolve an absolute image URL to a local filesystem path.
	 *
	 * Matches the URL against each site's upload base URL prefix (longest-prefix
	 * wins), which is correct for both subdomain and subdirectory multisite and
	 * for cross-site references. URLs whose host never matches a network site are
	 * classified `external`. Pure given the maps.
	 *
	 * The URL remainder below the matched upload base is URL-decoded exactly once
	 * (the URL-to-filename boundary) before joining to the uploads base directory.
	 * See {@see build_contained_upload_path()} for the decode boundary and
	 * containment rules.
	 *
	 * @since 0.161.6
	 * @since 0.162.1 Decode encoded upload paths once and contain them under basedir.
	 *
	 * @param string                                                        $url              Absolute URL.
	 * @param array<int, array{baseurl: string, basedir: string}>           $blog_uploads     Blog upload maps.
	 * @param int                                                           $current_blog_id  Current blog ID (unused but reserved for context).
	 * @param string                                                        $current_origin   Current site origin.
	 * @return array{kind: string, path: string|null, blog_id: int|null}
	 */
	public static function resolve_url_to_local( string $url, array $blog_uploads, int $current_blog_id, string $current_origin ): array {
		unset( $current_blog_id, $current_origin ); // Reserved for future heuristics; intentionally unused now.

		$url = trim( $url );
		if ( '' === $url ) {
			return array(
				'kind'    => 'unresolvable',
				'path'    => null,
				'blog_id' => null,
			);
		}

		$url_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		$best_blog_id = null;
		$best_baseurl = '';
		foreach ( $blog_uploads as $blog_id => $uploads ) {
			$baseurl = $uploads['baseurl'] ?? '';
			if ( '' === $baseurl ) {
				continue;
			}

			$baseurl_host = strtolower( (string) wp_parse_url( $baseurl, PHP_URL_HOST ) );
			if ( '' !== $url_host && '' !== $baseurl_host && $url_host !== $baseurl_host ) {
				continue;
			}

			if ( ! self::url_starts_with( $url, $baseurl ) ) {
				continue;
			}

			if ( strlen( $baseurl ) > strlen( $best_baseurl ) ) {
				$best_baseurl = $baseurl;
				$best_blog_id = $blog_id;
			}
		}

		if ( null === $best_blog_id ) {
			// No upload base matched; classify by whether the host is on-network.
			if ( '' !== $url_host && self::host_is_network_host( $url_host, $blog_uploads ) ) {
				return array(
					'kind'    => 'unresolvable',
					'path'    => null,
					'blog_id' => null,
				);
			}

			return array(
				'kind'    => 'external',
				'path'    => null,
				'blog_id' => null,
			);
		}

		$basedir  = $blog_uploads[ $best_blog_id ]['basedir'] ?? '';
		$relative = substr( $url, strlen( $best_baseurl ) );
		$fs_path  = self::build_contained_upload_path( $basedir, $relative );

		if ( null === $fs_path ) {
			// Empty remainder or a decoded traversal/separator escape attempt.
			return array(
				'kind'    => 'unresolvable',
				'path'    => null,
				'blog_id' => null,
			);
		}

		return array(
			'kind'    => 'local',
			'path'    => $fs_path,
			'blog_id' => $best_blog_id,
		);
	}

	/**
	 * Build a contained filesystem path for the URL remainder below an upload base.
	 *
	 * The remainder (the raw URL substring after the matched upload base URL) is
	 * URL-decoded exactly once before being joined to the uploads base directory.
	 * `rawurldecode()` is the correct path-component decoder: it maps `%20` to a
	 * space, `%2B` to a literal `+`, and — for Blogger migration URLs — `%2528`
	 * to the literal filename fragment `%28`. The result is never decoded again,
	 * so a double-encoded sequence like `%252e%252e%252f` stays an inert literal
	 * filename (`%2e%2e%2f`) rather than a traversal.
	 *
	 * Containment is enforced lexically: `realpath()` is unusable here because
	 * this diagnostic verifies files that are expected to be *missing*. Any path
	 * segment equal to `..` is rejected outright, which also catches decoded
	 * separator escapes (e.g. `%2f..%2f` decodes to `/../`). Query strings and
	 * fragments are dropped before lookup since they are not part of the filename.
	 *
	 * @since 0.162.1
	 *
	 * @param string $basedir   Absolute uploads base directory (no trailing slash expected).
	 * @param string $remainder Raw URL remainder after the upload base URL (starts with `/`, `?`, or empty).
	 * @return string|null Contained filesystem path under basedir, or null when the
	 *                     remainder is empty or attempts to escape the base.
	 */
	private static function build_contained_upload_path( string $basedir, string $remainder ): ?string {
		if ( '' === $basedir ) {
			return null;
		}

		// Query strings and fragments are not part of the filesystem name.
		$split     = preg_split( '/[?#]/', $remainder, 2 );
		$path_part = is_array( $split ) ? (string) $split[0] : $remainder;

		// Decode exactly once: URL representation -> literal filesystem name.
		// A single pass maps `%2528` to `%28`; further decoding is intentionally
		// avoided so double-encoded traversal stays an inert literal.
		$decoded = rawurldecode( $path_part );

		$segments = explode( '/', $decoded );
		$clean    = array();
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				// Collapse empty (leading/duplicate separators) and current-dir segments.
				continue;
			}

			if ( '..' === $segment || false !== strpos( $segment, "\0" ) ) {
				// Reject decoded traversal (`..`, including `%2e%2e` variants) and
				// null-byte injection outright; a contained upload never needs them.
				return null;
			}

			$clean[] = $segment;
		}

		if ( empty( $clean ) ) {
			// The URL pointed at the upload root itself (or carried only a query).
			return null;
		}

		$fs_path = $basedir . '/' . implode( '/', $clean );

		// Normalize directory separators for the platform.
		return str_replace( '/', DIRECTORY_SEPARATOR, $fs_path );
	}

	/**
	 * Whether a host matches any site in the upload map.
	 *
	 * @since 0.161.6
	 *
	 * @param string                                                        $host          Lowercased host.
	 * @param array<int, array{baseurl: string, basedir: string}>           $blog_uploads  Blog upload maps.
	 * @return bool
	 */
	private static function host_is_network_host( string $host, array $blog_uploads ): bool {
		foreach ( $blog_uploads as $uploads ) {
			$baseurl_host = strtolower( (string) wp_parse_url( $uploads['baseurl'] ?? '', PHP_URL_HOST ) );
			if ( $host === $baseurl_host ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Case-insensitive, slash-sensitive URL prefix check.
	 *
	 * Ensures the candidate base is a real directory prefix — e.g. so that
	 * `https://x/uploads` does not match a URL under `https://x/uploads-other`.
	 *
	 * @since 0.161.6
	 *
	 * @param string $url     Candidate URL.
	 * @param string $baseurl Base URL to test against.
	 * @return bool
	 */
	private static function url_starts_with( string $url, string $baseurl ): bool {
		if ( '' === $baseurl ) {
			return false;
		}

		$url_lower  = strtolower( $url );
		$base_lower = strtolower( $baseurl );

		if ( ! str_starts_with( $url_lower, $base_lower ) ) {
			return false;
		}

		$next = substr( $url_lower, strlen( $base_lower ) );

		// A trailing path separator (or query) marks a genuine child path.
		return '' === $next || '/' === $next[0] || '?' === $next[0];
	}

	/**
	 * Extract the scheme from an origin URL.
	 *
	 * @since 0.161.6
	 *
	 * @param string $origin Origin URL.
	 * @return string
	 */
	private static function scheme_from_origin( string $origin ): string {
		$scheme = strtolower( (string) wp_parse_url( $origin, PHP_URL_SCHEME ) );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? $scheme : 'https';
	}

	/**
	 * Extract the origin (scheme://host[:port]) from a URL.
	 *
	 * @since 0.161.6
	 *
	 * @param string $url Full URL.
	 * @return string Origin or '' if unparseable.
	 */
	public static function origin_from_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}

	/**
	 * Case-insensitive attribute fetch with default.
	 *
	 * @since 0.161.6
	 *
	 * @param array  $attrs Attribute map (lowercased keys expected).
	 * @param string $name  Attribute name.
	 * @return string
	 */
	private static function attr( array $attrs, string $name ): string {
		$name = strtolower( $name );
		foreach ( $attrs as $key => $value ) {
			if ( strtolower( (string) $key ) === $name ) {
				$value = is_string( $value ) ? $value : (string) $value;

				return trim( $value );
			}
		}

		return '';
	}

	/**
	 * Switch to a blog unless already on it.
	 *
	 * @since 0.161.6
	 *
	 * @param int $blog_id Target blog ID.
	 * @return bool Whether a switch occurred (caller must restore).
	 */
	private static function maybe_switch_to_blog( int $blog_id ): bool {
		if ( ! is_multisite() ) {
			return false;
		}
		if ( get_current_blog_id() === $blog_id ) {
			return false;
		}

		switch_to_blog( $blog_id );

		return true;
	}
}
