//! extractInternalLinks — extracted from InternalLinkingAbilities.php.


	/**
	 * Build the internal link graph by scanning post content.
	 *
	 * Shared logic used by audit, get-orphaned-posts, and check-broken-links.
	 * Returns the full graph data structure suitable for caching.
	 *
	 * @since 0.32.0
	 *
	 * @param string $post_type    Post type to scan.
	 * @param string $category     Category slug to filter by.
	 * @param array  $specific_ids Specific post IDs to scan.
	 * @return array Graph data structure.
	 */
	private static function buildLinkGraph( string $post_type, string $category, array $specific_ids ): array {
		global $wpdb;

		$home_url  = home_url();
		$home_host = wp_parse_url( $home_url, PHP_URL_HOST );

		// Build the query for posts to scan.
		if ( ! empty( $specific_ids ) ) {
			$id_placeholders = implode( ',', array_fill( 0, count( $specific_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
					"SELECT ID, post_title, post_content FROM {$wpdb->posts}
					WHERE ID IN ($id_placeholders) AND post_status = %s",
					array_merge( $specific_ids, array( 'publish' ) )
				)
			);
					// phpcs:enable WordPress.DB.PreparedSQL
		} elseif ( ! empty( $category ) ) {
			$term = get_term_by( 'slug', $category, 'category' );
			if ( ! $term ) {
				return array(
					'success' => false,
					'error'   => "Category '{$category}' not found.",
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_content
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					WHERE p.post_type = %s AND p.post_status = %s
					AND tt.taxonomy = %s AND tt.term_id = %d",
					$post_type,
					'publish',
					'category',
					$term->term_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_content FROM {$wpdb->posts}
					WHERE post_type = %s AND post_status = %s",
					$post_type,
					'publish'
				)
			);
		}

		if ( empty( $posts ) ) {
			return array(
				'success'        => true,
				'post_type'      => $post_type,
				'total_scanned'  => 0,
				'total_links'    => 0,
				'orphaned_count' => 0,
				'avg_outbound'   => 0,
				'avg_inbound'    => 0,
				'orphaned_posts' => array(),
				'top_linked'     => array(),
				'_all_links'     => array(),
				'_id_to_title'   => array(),
			);
		}

		// Build a lookup of all scanned post URLs -> IDs.
		$url_to_id   = array();
		$id_to_url   = array();
		$id_to_title = array();

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post->ID );
			if ( $permalink ) {
				$url_to_id[ untrailingslashit( $permalink ) ] = $post->ID;
				$url_to_id[ trailingslashit( $permalink ) ]   = $post->ID;
				$id_to_url[ $post->ID ]                       = $permalink;
			}
			$id_to_title[ $post->ID ] = $post->post_title;
		}

		// Scan each post's content for internal links.
		$outbound    = array(); // post_id => array of target post_ids.
		$inbound     = array(); // post_id => count of inbound links.
		$all_links   = array(); // all discovered internal link entries.
		$total_links = 0;

		// Initialize inbound counts.
		foreach ( $posts as $post ) {
			$inbound[ $post->ID ]  = 0;
			$outbound[ $post->ID ] = array();
		}

		$all_external_links = array();

		foreach ( $posts as $post ) {
			$content = $post->post_content;
			if ( empty( $content ) ) {
				continue;
			}

			// Internal links.
			$links = self::extractInternalLinks( $content, $home_host );

			foreach ( $links as $link_url ) {
				++$total_links;
				$normalized = untrailingslashit( $link_url );

				// Resolve to a post ID if possible.
				$target_id = $url_to_id[ $normalized ] ?? $url_to_id[ trailingslashit( $link_url ) ] ?? null;

				if ( null === $target_id ) {
					// Try url_to_postid as fallback for non-standard URLs.
					$target_id = url_to_postid( $link_url );
					if ( 0 === $target_id ) {
						$target_id = null;
					}
				}

				if ( null !== $target_id && $target_id !== $post->ID ) {
					$outbound[ $post->ID ][] = $target_id;

					if ( isset( $inbound[ $target_id ] ) ) {
						++$inbound[ $target_id ];
					}
				}

				$all_links[] = array(
					'source_id'  => $post->ID,
					'target_url' => $link_url,
					'target_id'  => $target_id,
					'resolved'   => null !== $target_id,
				);
			}

			// External links.
			$external = self::extractExternalLinks( $content, $home_host );
			foreach ( $external as $ext_link ) {
				$all_external_links[] = array(
					'source_id'   => $post->ID,
					'target_url'  => $ext_link['url'],
					'anchor_text' => $ext_link['anchor_text'],
					'domain'      => $ext_link['domain'],
				);
			}
		}

		// Identify orphaned posts (zero inbound links from other scanned posts).
		$orphaned = array();
		foreach ( $inbound as $post_id => $count ) {
			if ( 0 === $count ) {
				$orphaned[] = array(
					'post_id'   => $post_id,
					'title'     => $id_to_title[ $post_id ] ?? '',
					'permalink' => $id_to_url[ $post_id ] ?? '',
					'outbound'  => count( $outbound[ $post_id ] ?? array() ),
				);
			}
		}

		// Top linked posts (most inbound).
		arsort( $inbound );
		$top_linked = array();
		$top_count  = 0;
		foreach ( $inbound as $post_id => $count ) {
			if ( 0 === $count || $top_count >= 20 ) {
				break;
			}
			$top_linked[] = array(
				'post_id'   => $post_id,
				'title'     => $id_to_title[ $post_id ] ?? '',
				'permalink' => $id_to_url[ $post_id ] ?? '',
				'inbound'   => $count,
				'outbound'  => count( $outbound[ $post_id ] ?? array() ),
			);
			++$top_count;
		}

		$total_scanned  = count( $posts );
		$outbound_total = array_sum( array_map( 'count', $outbound ) );
		$inbound_total  = array_sum( $inbound );

		return array(
			'success'             => true,
			'post_type'           => $post_type,
			'total_scanned'       => $total_scanned,
			'total_links'         => $total_links,
			'orphaned_count'      => count( $orphaned ),
			'avg_outbound'        => $total_scanned > 0 ? round( $outbound_total / $total_scanned, 2 ) : 0,
			'avg_inbound'         => $total_scanned > 0 ? round( $inbound_total / $total_scanned, 2 ) : 0,
			'orphaned_posts'      => $orphaned,
			'top_linked'          => $top_linked,
			// Internal data for broken link checker (not exposed in REST).
			'_all_links'          => $all_links,
			'_all_external_links' => $all_external_links,
			'_id_to_title'        => $id_to_title,
		);
	}

	/**
	 * Extract internal link URLs from HTML content.
	 *
	 * Uses regex to find all <a href="..."> tags where the href points to
	 * the same host as the site. Ignores anchors, mailto, tel, and external links.
	 *
	 * @since 0.32.0
	 *
	 * @param string $html      HTML content to parse.
	 * @param string $home_host Site hostname for comparison.
	 * @return array Array of internal link URLs.
	 */
	private static function extractInternalLinks( string $html, string $home_host ): array {
		$links = array();

		// Match all href attributes in anchor tags.
		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\'#]+)["\'][^>]*>/i', $html, $matches ) ) {
			return $links;
		}

		foreach ( $matches[1] as $url ) {
			// Skip non-http URLs.
			if ( preg_match( '/^(mailto:|tel:|javascript:|data:)/i', $url ) ) {
				continue;
			}

			// Handle relative URLs.
			if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
				$url = home_url( $url );
			}

			// Parse and check host.
			$parsed = wp_parse_url( $url );
			$host   = $parsed['host'] ?? '';

			if ( empty( $host ) || strcasecmp( $host, $home_host ) !== 0 ) {
				continue;
			}

			// Strip query string and fragment for normalization.
			$clean_url = $parsed['scheme'] . '://' . $parsed['host'];
			if ( ! empty( $parsed['path'] ) ) {
				$clean_url .= $parsed['path'];
			}

			$links[] = $clean_url;
		}

		return array_unique( $links );
	}

	/**
	 * Extract external link URLs and anchor text from HTML content.
	 *
	 * Inverse of extractInternalLinks — keeps links pointing to hosts
	 * other than the site. Returns both URL and anchor text for reporting.
	 *
	 * @since 0.42.0
	 *
	 * @param string $html      HTML content to parse.
	 * @param string $home_host Site hostname for comparison.
	 * @return array Array of arrays with 'url' and 'anchor_text' keys.
	 */
	private static function extractExternalLinks( string $html, string $home_host ): array {
		$links = array();

		// Match href AND capture the full tag + inner text for anchor extraction.
		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\'#]+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			return $links;
		}

		$seen = array();

		foreach ( $matches as $match ) {
			$url         = $match[1];
			$anchor_text = wp_strip_all_tags( $match[2] );

			// Skip non-http URLs.
			if ( preg_match( '/^(mailto:|tel:|javascript:|data:)/i', $url ) ) {
				continue;
			}

			// Skip relative URLs (they're internal).
			if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
				continue;
			}

			// Parse and check host — keep only external.
			$parsed = wp_parse_url( $url );
			$host   = $parsed['host'] ?? '';

			if ( empty( $host ) || strcasecmp( $host, $home_host ) === 0 ) {
				continue;
			}

			// Normalize URL (keep query string for external — different pages).
			$clean_url = ( $parsed['scheme'] ?? 'https' ) . '://' . $parsed['host'];
			if ( ! empty( $parsed['path'] ) ) {
				$clean_url .= $parsed['path'];
			}
			if ( ! empty( $parsed['query'] ) ) {
				$clean_url .= '?' . $parsed['query'];
			}

			// Deduplicate by URL within a single post.
			if ( isset( $seen[ $clean_url ] ) ) {
				continue;
			}
			$seen[ $clean_url ] = true;

			$links[] = array(
				'url'         => $clean_url,
				'anchor_text' => trim( $anchor_text ),
				'domain'      => $host,
			);
		}

		return $links;
	}
