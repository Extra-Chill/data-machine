<?php
/**
 * Fetch Reddit Ability
 *
 * Abilities API primitive for fetching Reddit posts.
 * Centralizes Reddit API interaction, pagination, and data extraction.
 *
 * @package DataMachine\Abilities\Fetch
 */

namespace DataMachine\Abilities\Fetch;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class FetchRedditAbility {

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
				'datamachine/fetch-reddit',
				array(
					'label'               => __( 'Fetch Reddit Posts', 'data-machine' ),
					'description'         => __( 'Fetch posts from Reddit subreddits with filtering and pagination', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'subreddit', 'access_token' ),
						'properties' => array(
							'subreddit'         => array(
								'type'        => 'string',
								'description' => __( 'Subreddit name to fetch from', 'data-machine' ),
							),
							'access_token'      => array(
								'type'        => 'string',
								'description' => __( 'Reddit OAuth access token', 'data-machine' ),
							),
							'sort_by'           => array(
								'type'        => 'string',
								'enum'        => array( 'hot', 'new', 'top', 'rising', 'controversial' ),
								'default'     => 'hot',
								'description' => __( 'Sort order for posts', 'data-machine' ),
							),
							'timeframe_limit'   => array(
								'type'        => 'string',
								'default'     => 'all_time',
								'description' => __( 'Timeframe filter (all_time, 24_hours, 7_days, 30_days)', 'data-machine' ),
							),
							'min_upvotes'       => array(
								'type'        => 'integer',
								'default'     => 0,
								'description' => __( 'Minimum upvotes required', 'data-machine' ),
							),
							'min_comment_count' => array(
								'type'        => 'integer',
								'default'     => 0,
								'description' => __( 'Minimum comment count required', 'data-machine' ),
							),
							'comment_count'     => array(
								'type'        => 'integer',
								'default'     => 0,
								'description' => __( 'Number of top comments to fetch per post', 'data-machine' ),
							),
							'search'            => array(
								'type'        => 'string',
								'default'     => '',
								'description' => __( 'Search term to filter posts', 'data-machine' ),
							),
							'processed_items'   => array(
								'type'        => 'array',
								'default'     => array(),
								'description' => __( 'Array of already processed item IDs to skip', 'data-machine' ),
							),
							'fetch_batch_size'  => array(
								'type'        => 'integer',
								'default'     => 100,
								'description' => __( 'Number of posts per API page', 'data-machine' ),
							),
							'max_pages'         => array(
								'type'        => 'integer',
								'default'     => 5,
								'description' => __( 'Maximum number of pages to fetch', 'data-machine' ),
							),
							'download_images'   => array(
								'type'        => 'boolean',
								'default'     => true,
								'description' => __( 'Whether to download post images', 'data-machine' ),
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

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
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
	 * Execute Reddit fetch ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with fetched data or error.
	 */
	public function execute( array $input ): array {
		$logs   = array();
		$config = $this->normalizeConfig( $input );

		$subreddit             = $config['subreddit'];
		$access_token          = $config['access_token'];
		$sort                  = $config['sort_by'];
		$timeframe_limit       = $config['timeframe_limit'];
		$min_upvotes           = $config['min_upvotes'];
		$min_comment_count     = $config['min_comment_count'];
		$comment_count_setting = $config['comment_count'];
		$search_term           = $config['search'];
		$processed_items       = $config['processed_items'];
		$fetch_batch_size      = $config['fetch_batch_size'];
		$max_pages             = $config['max_pages'];
		$download_images       = $config['download_images'];

		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $subreddit ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Reddit: Invalid subreddit name format.',
				'data'    => array( 'subreddit' => $subreddit ),
			);
			return array(
				'success' => false,
				'error'   => 'Invalid subreddit name format',
				'logs'    => $logs,
			);
		}

		$valid_sorts = array( 'hot', 'new', 'top', 'rising', 'controversial' );
		if ( ! in_array( $sort, $valid_sorts, true ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'Reddit: Invalid sort parameter.',
				'data'    => array(
					'invalid_sort' => $sort,
					'valid_sorts'  => $valid_sorts,
				),
			);
			return array(
				'success' => false,
				'error'   => 'Invalid sort parameter',
				'logs'    => $logs,
			);
		}

		$after_param   = null;
		$total_checked = 0;
		$pages_fetched = 0;

		while ( $pages_fetched < $max_pages ) {
			++$pages_fetched;

			$time_param = '';
			if ( in_array( $sort, array( 'top', 'controversial' ), true ) && 'all_time' !== $timeframe_limit ) {
				$reddit_time_map = array(
					'24_hours' => 'day',
					'72_hours' => 'week',
					'7_days'   => 'week',
					'30_days'  => 'month',
				);
				if ( isset( $reddit_time_map[ $timeframe_limit ] ) ) {
					$time_param = '&t=' . $reddit_time_map[ $timeframe_limit ];
					$logs[]     = array(
						'level'   => 'debug',
						'message' => 'Reddit: Using native API time filtering.',
						'data'    => array(
							'sort'              => $sort,
							'timeframe_limit'   => $timeframe_limit,
							'reddit_time_param' => $reddit_time_map[ $timeframe_limit ],
						),
					);
				}
			}

			$reddit_url = sprintf(
				'https://oauth.reddit.com/r/%s/%s.json?limit=%d%s%s',
				esc_attr( $subreddit ),
				esc_attr( $sort ),
				$fetch_batch_size,
				$after_param ? '&after=' . urlencode( $after_param ) : '',
				$time_param
			);

			$headers = array(
				'Authorization' => 'Bearer ' . $access_token,
			);

			$log_headers                  = $headers;
			$log_headers['Authorization'] = preg_replace( '/(Bearer )(.{4}).+(.{4})/', '$1$2...$3', $log_headers['Authorization'] );
			$logs[]                       = array(
				'level'   => 'debug',
				'message' => 'Reddit: Making API call.',
				'data'    => array(
					'page'    => $pages_fetched,
					'url'     => $reddit_url,
					'headers' => $log_headers,
				),
			);

			$result = $this->httpGet(
				$reddit_url,
				array(
					'headers' => $headers,
					'context' => 'Reddit API',
				)
			);

			if ( ! $result['success'] ) {
				if ( 1 === $pages_fetched ) {
					$logs[] = array(
						'level'   => 'error',
						'message' => 'Reddit: API request failed.',
						'data'    => array( 'error' => $result['error'] ),
					);
					return array(
						'success' => false,
						'error'   => $result['error'],
						'logs'    => $logs,
					);
				} else {
					break;
				}
			}

			$body   = $result['data'];
			$logs[] = array(
				'level'   => 'debug',
				'message' => 'Reddit: API Response Code',
				'data'    => array(
					'code' => $result['status_code'],
					'url'  => $reddit_url,
				),
			);

			$response_data = json_decode( $body, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$error_message = sprintf( __( 'Invalid JSON from Reddit API: %s', 'data-machine' ), json_last_error_msg() );
				if ( 1 === $pages_fetched ) {
					$logs[] = array(
						'level'   => 'error',
						'message' => 'Reddit: Invalid JSON response.',
						'data'    => array( 'error' => $error_message ),
					);
					return array(
						'success' => false,
						'error'   => $error_message,
						'logs'    => $logs,
					);
				} else {
					break;
				}
			}

			if ( empty( $response_data['data']['children'] ) || ! is_array( $response_data['data']['children'] ) ) {
				$logs[] = array(
					'level'   => 'debug',
					'message' => 'Reddit: No more posts found or invalid data structure.',
					'data'    => array( 'url' => $reddit_url ),
				);
				break;
			}

			foreach ( $response_data['data']['children'] as $post_wrapper ) {
				++$total_checked;
				if ( empty( $post_wrapper['data'] ) || empty( $post_wrapper['data']['id'] ) || empty( $post_wrapper['kind'] ) ) {
					$logs[] = array(
						'level'   => 'warning',
						'message' => 'Reddit: Skipping post with missing data.',
						'data'    => array( 'subreddit' => $subreddit ),
					);
					continue;
				}
				$item_data       = $post_wrapper['data'];
				$current_item_id = $item_data['id'];

				if ( ( $item_data['stickied'] ?? false ) || ( $item_data['pinned'] ?? false ) ) {
					$logs[] = array(
						'level'   => 'debug',
						'message' => 'Reddit: Skipping pinned/stickied post.',
						'data'    => array( 'item_id' => $current_item_id ),
					);
					continue;
				}

				$item_timestamp = (int) ( $item_data['created_utc'] ?? 0 );
				if ( ! $this->applyTimeframeFilter( $item_timestamp, $timeframe_limit ) ) {
					continue;
				}

				if ( $min_upvotes > 0 ) {
					if ( ! isset( $item_data['score'] ) || $item_data['score'] < $min_upvotes ) {
						$logs[] = array(
							'level'   => 'debug',
							'message' => 'Reddit: Skipping item (min upvotes).',
							'data'    => array(
								'item_id'      => $current_item_id,
								'score'        => $item_data['score'] ?? 'N/A',
								'min_required' => $min_upvotes,
							),
						);
						continue;
					}
				}

				if ( in_array( $current_item_id, $processed_items, true ) ) {
					$logs[] = array(
						'level'   => 'debug',
						'message' => 'Reddit: Skipping item (already processed).',
						'data'    => array( 'item_id' => $current_item_id ),
					);
					continue;
				}

				if ( $min_comment_count > 0 ) {
					if ( ! isset( $item_data['num_comments'] ) || $item_data['num_comments'] < $min_comment_count ) {
						$logs[] = array(
							'level'   => 'debug',
							'message' => 'Reddit: Skipping item (min comment count).',
							'data'    => array(
								'item_id'      => $current_item_id,
								'comments'     => $item_data['num_comments'] ?? 'N/A',
								'min_required' => $min_comment_count,
							),
						);
						continue;
					}
				}

				$title_to_check    = $item_data['title'] ?? '';
				$selftext_to_check = $item_data['selftext'] ?? '';
				$text_to_search    = $title_to_check . ' ' . $selftext_to_check;
				if ( ! $this->applyKeywordSearch( $text_to_search, $search_term ) ) {
					$logs[] = array(
						'level'   => 'debug',
						'message' => 'Reddit: Skipping item (search filter).',
						'data'    => array( 'item_id' => $current_item_id ),
					);
					continue;
				}

				$title     = $item_data['title'] ?? '';
				$selftext  = $item_data['selftext'] ?? '';
				$post_body = $item_data['body'] ?? '';

				$content_data = array(
					'title'   => trim( $title ),
					'content' => ! empty( $selftext ) ? trim( $selftext ) : ( ! empty( $post_body ) ? trim( $post_body ) : '' ),
				);

				$comments_array = array();
				if ( $comment_count_setting > 0 && ! empty( $item_data['permalink'] ) ) {
					$comments_array = $this->fetchComments( $item_data['permalink'], $access_token, $comment_count_setting );
				}

				$image_info = null;
				if ( $download_images ) {
					$image_info = $this->extractImageInfo( $item_data );
				}

				$metadata = array(
					'source_type'            => 'reddit',
					'item_identifier_to_log' => (string) $current_item_id,
					'original_id'            => $current_item_id,
					'original_title'         => $title,
					'original_date_gmt'      => gmdate( 'Y-m-d\TH:i:s\Z', (int) ( $item_data['created_utc'] ?? time() ) ),
					'subreddit'              => $subreddit,
					'upvotes'                => $item_data['score'] ?? 0,
					'comment_count'          => $item_data['num_comments'] ?? 0,
					'author'                 => $item_data['author'] ?? '[deleted]',
					'is_self_post'           => $item_data['is_self'] ?? false,
				);

				if ( ! empty( $comments_array ) ) {
					$content_data['comments'] = $comments_array;
				}

				$raw_data = array(
					'title'    => $content_data['title'],
					'content'  => $content_data['content'],
					'metadata' => $metadata,
				);

				if ( ! empty( $content_data['comments'] ) ) {
					$raw_data['content'] .= "\n\nComments:\n" . implode(
						"\n",
						array_map(
							function ( $comment ) {
								return "- {$comment['author']}: {$comment['body']}";
							},
							$content_data['comments']
						)
					);
				}

				if ( $image_info ) {
					$raw_data['image_info'] = $image_info;
				}

				$source_url = $item_data['permalink'] ? 'https://www.reddit.com' . $item_data['permalink'] : '';

				$logs[] = array(
					'level'   => 'debug',
					'message' => 'Reddit: Fetched data successfully',
					'data'    => array(
						'source_type'      => 'reddit',
						'item_id'          => $current_item_id,
						'has_image'        => ! empty( $image_info ),
						'image_url_domain' => ! empty( $image_info['url'] ) ? wp_parse_url( $image_info['url'], PHP_URL_HOST ) : null,
						'content_length'   => strlen( $title . ' ' . $selftext . ' ' . $post_body ),
					),
				);

				return array(
					'success'    => true,
					'data'       => $raw_data,
					'source_url' => $source_url,
					'item_id'    => $current_item_id,
					'logs'       => $logs,
				);
			}

			$after_param = $response_data['data']['after'] ?? null;
			if ( ! $after_param ) {
				$logs[] = array(
					'level'   => 'debug',
					'message' => "Reddit: No 'after' parameter found, ending pagination.",
				);
				break;
			}
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'Reddit: No eligible items found.',
			'data'    => array(
				'total_checked' => $total_checked,
				'pages_fetched' => $pages_fetched,
			),
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
			'subreddit'         => '',
			'access_token'      => '',
			'sort_by'           => 'hot',
			'timeframe_limit'   => 'all_time',
			'min_upvotes'       => 0,
			'min_comment_count' => 0,
			'comment_count'     => 0,
			'search'            => '',
			'processed_items'   => array(),
			'fetch_batch_size'  => 100,
			'max_pages'         => 5,
			'download_images'   => true,
		);

		return array_merge( $defaults, $input );
	}

	/**
	 * Make HTTP GET request.
	 */
	private function httpGet( string $url, array $options ): array {
		$args = array(
			'timeout' => $options['timeout'] ?? 30,
			'headers' => $options['headers'] ?? array(),
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
	 * Fetch comments for a Reddit post.
	 */
	private function fetchComments( string $permalink, string $access_token, int $comment_count ): array {
		$comments_array  = array();
		$comments_url    = 'https://oauth.reddit.com' . $permalink . '.json?limit=' . $comment_count . '&sort=top';
		$comments_result = $this->httpGet(
			$comments_url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'context' => 'Reddit API',
			)
		);

		if ( $comments_result['success'] ) {
			$comments_data = json_decode( $comments_result['data'], true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				if ( is_array( $comments_data ) && isset( $comments_data[1]['data']['children'] ) ) {
					$top_comments = array_slice( $comments_data[1]['data']['children'], 0, $comment_count );
					foreach ( $top_comments as $comment_wrapper ) {
						if ( isset( $comment_wrapper['data']['body'] ) && ! $comment_wrapper['data']['stickied'] ) {
							$comment_author = $comment_wrapper['data']['author'] ?? '[deleted]';
							$comment_body   = trim( $comment_wrapper['data']['body'] );
							if ( '' !== $comment_body ) {
								$comments_array[] = array(
									'author' => $comment_author,
									'body'   => $comment_body,
								);
							}
						}
						if ( count( $comments_array ) >= $comment_count ) {
							break;
						}
					}
				}
			}
		}

		return $comments_array;
	}

	/**
	 * Extract image information from Reddit post data.
	 */
	private function extractImageInfo( array $item_data ): ?array {
		$url      = $item_data['url'] ?? '';
		$is_imgur = preg_match( '#^https?://(www\.)?imgur\.com/([^./]+)$#i', $url, $imgur_matches );

		if ( ! empty( $item_data['is_gallery'] ) && ! empty( $item_data['media_metadata'] ) && is_array( $item_data['media_metadata'] ) ) {
			$first_media = reset( $item_data['media_metadata'] );
			if ( ! empty( $first_media['s']['u'] ) ) {
				$direct_url = html_entity_decode( $first_media['s']['u'] );
				return array(
					'url'       => $direct_url,
					'mime_type' => 'image/jpeg',
				);
			}
		} elseif (
			! empty( $url ) &&
			(
				( isset( $item_data['post_hint'] ) && 'image' === $item_data['post_hint'] ) ||
				preg_match( '/\.(jpg|jpeg|png|webp|gif)$/i', $url ) ||
				$is_imgur
			)
		) {
			if ( $is_imgur ) {
				$direct_url = $url . '.jpg';
				$mime_type  = 'image/jpeg';
			} else {
				$direct_url = $url;
				$ext        = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$mime_map   = array(
					'jpg'  => 'image/jpeg',
					'jpeg' => 'image/jpeg',
					'png'  => 'image/png',
					'webp' => 'image/webp',
					'gif'  => 'image/gif',
				);
				$mime_type  = $mime_map[ $ext ] ?? 'application/octet-stream';
			}
			return array(
				'url'       => $direct_url,
				'mime_type' => $mime_type,
			);
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
