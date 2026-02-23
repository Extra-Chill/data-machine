<?php
/**
 * Fetch RSS Ability
 *
 * Abilities API primitive for fetching RSS/Atom feeds.
 * Centralizes feed fetching, XML parsing, and item extraction.
 *
 * @package DataMachine\Abilities\Fetch
 */

namespace DataMachine\Abilities\Fetch;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class FetchRssAbility {

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
				'datamachine/fetch-rss',
				array(
					'label'               => __( 'Fetch RSS Feed', 'data-machine' ),
					'description'         => __( 'Fetch and parse RSS/Atom feeds with filtering', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'feed_url' ),
						'properties' => array(
							'feed_url'        => array(
								'type'        => 'string',
								'description' => __( 'URL of the RSS/Atom feed', 'data-machine' ),
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
								'description' => __( 'Array of already processed item GUIDs to skip', 'data-machine' ),
							),
							'download_images' => array(
								'type'        => 'boolean',
								'default'     => true,
								'description' => __( 'Whether to download enclosure images', 'data-machine' ),
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
	 * Execute RSS fetch ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with fetched data or error.
	 */
	public function execute( array $input ): array {
		$logs   = array();
		$config = $this->normalizeConfig( $input );

		$feed_url        = $config['feed_url'];
		$timeframe_limit = $config['timeframe_limit'];
		$search          = $config['search'];
		$processed_items = $config['processed_items'];
		$download_images = $config['download_images'];

		$result = $this->httpGet( $feed_url, array( 'context' => 'RSS Feed' ) );

		if ( ! $result['success'] ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Rss: Failed to fetch RSS feed.',
				'data'    => array(
					'error'    => $result['error'],
					'feed_url' => $feed_url,
				),
			);
			return array(
				'success' => false,
				'error'   => $result['error'],
				'logs'    => $logs,
			);
		}

		$feed_content = $result['data'];
		if ( empty( $feed_content ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Rss: RSS feed content is empty.',
				'data'    => array( 'feed_url' => $feed_url ),
			);
			return array(
				'success' => false,
				'error'   => 'RSS feed content is empty',
				'logs'    => $logs,
			);
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $feed_content );
		if ( false === $xml ) {
			$errors         = libxml_get_errors();
			$error_messages = array_map(
				function ( $error ) {
					return trim( $error->message );
				},
				$errors
			);

			$logs[] = array(
				'level'   => 'error',
				'message' => 'Rss: Failed to parse RSS feed XML.',
				'data'    => array(
					'feed_url'   => $feed_url,
					'xml_errors' => implode( ', ', $error_messages ),
				),
			);
			return array(
				'success' => false,
				'error'   => 'Failed to parse RSS feed XML: ' . implode( ', ', $error_messages ),
				'logs'    => $logs,
			);
		}

		$items = array();

		if ( isset( $xml->channel->item ) ) {
			$items = $xml->channel->item;
		} elseif ( isset( $xml->item ) ) {
			$items = $xml->item;
		} elseif ( isset( $xml->entry ) ) {
			$items = $xml->entry;
		} else {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Rss: Unsupported feed format or no items found in feed.',
				'data'    => array( 'feed_url' => $feed_url ),
			);
			return array(
				'success' => false,
				'error'   => 'Unsupported feed format or no items found',
				'logs'    => $logs,
			);
		}

		if ( empty( $items ) ) {
			return array(
				'success' => true,
				'data'    => array(),
				'logs'    => $logs,
			);
		}

		$total_checked = 0;

		foreach ( $items as $item ) {
			++$total_checked;

			$title       = $this->extractItemTitle( $item );
			$description = $this->extractItemDescription( $item );
			$link        = $this->extractItemLink( $item );
			$pub_date    = $this->extractItemDate( $item );
			$guid        = $this->extractItemGuid( $item, $link );

			if ( empty( $guid ) ) {
				$logs[] = array(
					'level'   => 'warning',
					'message' => 'Rss: Skipping item without GUID.',
					'data'    => array( 'title' => $title ),
				);
				continue;
			}

			if ( in_array( $guid, $processed_items, true ) ) {
				continue;
			}

			if ( $pub_date ) {
				$item_timestamp = strtotime( $pub_date );
				if ( false !== $item_timestamp && ! $this->applyTimeframeFilter( $item_timestamp, $timeframe_limit ) ) {
					$logs[] = array(
						'level'   => 'debug',
						'message' => 'Rss: Skipping item outside timeframe.',
						'data'    => array(
							'guid'     => $guid,
							'pub_date' => $pub_date,
						),
					);
					continue;
				}
			}

			$search_text = $title . ' ' . wp_strip_all_tags( $description );
			if ( ! $this->applyKeywordSearch( $search_text, $search ) ) {
				continue;
			}

			$author        = $this->extractItemAuthor( $item );
			$categories    = $this->extractItemCategories( $item );
			$enclosure_url = $this->extractItemEnclosure( $item );

			$content_data = array(
				'title'   => $title,
				'content' => $description,
			);

			$metadata = array(
				'source_type'            => 'rss',
				'item_identifier_to_log' => $guid,
				'original_id'            => $guid,
				'original_title'         => $title,
				'original_date_gmt'      => $pub_date ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $pub_date ) ) : null,
				'author'                 => $author,
				'categories'             => $categories,
			);

			$file_info = null;
			if ( $download_images && ! empty( $enclosure_url ) ) {
				$file_check = wp_check_filetype( $enclosure_url );
				$mime_type  = $file_check['type'] ? $file_check['type'] : 'application/octet-stream';

				if ( strpos( $mime_type, 'image/' ) === 0 && in_array( $mime_type, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
					$file_info = array(
						'type'      => $mime_type,
						'mime_type' => $mime_type,
						'url'       => $enclosure_url,
					);
				} else {
					$file_info = array(
						'type'      => $mime_type,
						'mime_type' => $mime_type,
					);
				}
			}

			$raw_data = array(
				'title'    => $content_data['title'],
				'content'  => $content_data['content'],
				'metadata' => $metadata,
			);

			if ( $file_info ) {
				$raw_data['file_info'] = $file_info;
			}

			$logs[] = array(
				'level'   => 'debug',
				'message' => 'Rss: Successfully parsed item.',
				'data'    => array(
					'guid'      => $guid,
					'title'     => $title,
					'has_image' => ! empty( $file_info ) && isset( $file_info['url'] ),
				),
			);

			return array(
				'success'    => true,
				'data'       => $raw_data,
				'guid'       => $guid,
				'source_url' => $link ? $link : '',
				'logs'       => $logs,
			);
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Rss: No eligible items found in RSS feed.',
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
			'feed_url'        => '',
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
	 * Extract title from RSS item.
	 */
	private function extractItemTitle( $item ): string {
		if ( isset( $item->title ) ) {
			return (string) $item->title;
		}
		return 'Untitled';
	}

	/**
	 * Extract description/content from RSS item.
	 */
	private function extractItemDescription( $item ): string {
		if ( isset( $item->description ) ) {
			return wp_strip_all_tags( (string) $item->description );
		}
		if ( isset( $item->summary ) ) {
			return wp_strip_all_tags( (string) $item->summary );
		}
		if ( isset( $item->content ) ) {
			return wp_strip_all_tags( (string) $item->content );
		}
		$content_ns = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
		if ( isset( $content_ns->encoded ) ) {
			return wp_strip_all_tags( (string) $content_ns->encoded );
		}
		return '';
	}

	/**
	 * Extract link URL from RSS item.
	 */
	private function extractItemLink( $item ): string {
		if ( isset( $item->link ) ) {
			$link = $item->link;
			if ( is_object( $link ) && isset( $link['href'] ) ) {
				return (string) $link['href'];
			}
			return (string) $link;
		}
		return '';
	}

	/**
	 * Extract publication date from RSS item.
	 */
	private function extractItemDate( $item ): ?string {
		if ( isset( $item->pubDate ) ) {
			return (string) $item->pubDate;
		}
		if ( isset( $item->published ) ) {
			return (string) $item->published;
		}
		if ( isset( $item->updated ) ) {
			return (string) $item->updated;
		}
		$dc_ns = $item->children( 'http://purl.org/dc/elements/1.1/' );
		if ( isset( $dc_ns->date ) ) {
			return (string) $dc_ns->date;
		}
		return null;
	}

	/**
	 * Extract GUID/unique identifier from RSS item.
	 */
	private function extractItemGuid( $item, string $item_link ): string {
		if ( isset( $item->guid ) ) {
			return (string) $item->guid;
		}
		if ( isset( $item->id ) ) {
			return (string) $item->id;
		}
		return $item_link;
	}

	/**
	 * Extract author name from RSS item.
	 */
	private function extractItemAuthor( $item ): ?string {
		if ( isset( $item->author ) ) {
			$author = $item->author;
			if ( is_object( $author ) && isset( $author->name ) ) {
				return (string) $author->name;
			}
			return (string) $author;
		}
		$dc_ns = $item->children( 'http://purl.org/dc/elements/1.1/' );
		if ( isset( $dc_ns->creator ) ) {
			return (string) $dc_ns->creator;
		}
		return null;
	}

	/**
	 * Extract categories/tags from RSS item.
	 */
	private function extractItemCategories( $item ): array {
		$categories = array();

		if ( isset( $item->category ) ) {
			foreach ( $item->category as $category ) {
				if ( isset( $category['term'] ) ) {
					$categories[] = (string) $category['term'];
				} else {
					$categories[] = (string) $category;
				}
			}
		}

		return $categories;
	}

	/**
	 * Extract enclosure URL from RSS item.
	 */
	private function extractItemEnclosure( $item ): ?string {
		if ( isset( $item->enclosure ) && isset( $item->enclosure['url'] ) ) {
			return (string) $item->enclosure['url'];
		}
		return null;
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
