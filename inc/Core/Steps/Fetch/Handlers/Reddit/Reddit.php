<?php
/**
 * Fetch Reddit posts with timeframe and keyword filtering.
 *
 * @package DataMachine\Core\Steps\Fetch\Handlers\Reddit
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\Reddit;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\Fetch\FetchRedditAbility;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reddit extends FetchHandler {

	use HandlerRegistrationTrait;

	private $oauth_reddit;

	public function __construct() {
		parent::__construct( 'reddit' );

		// Self-register with filters
		self::registerHandler(
			'reddit',
			'fetch',
			self::class,
			'Reddit',
			'Fetch posts from Reddit subreddits',
			true,
			RedditAuth::class,
			RedditSettings::class,
			null
		);
	}

	/**
	 * Lazy-load auth provider when needed.
	 *
	 * @return object|null Auth provider instance or null if unavailable
	 */
	private function get_oauth_reddit() {
		if ( $this->oauth_reddit === null ) {
			$this->oauth_reddit = $this->getAuthProvider( 'reddit' );

			if ( $this->oauth_reddit === null ) {
				$auth_abilities = new AuthAbilities();
				do_action(
					'datamachine_log',
					'error',
					'Reddit Handler: Authentication service not available',
					array(
						'handler'             => 'reddit',
						'missing_service'     => 'reddit',
						'available_providers' => array_keys( $auth_abilities->getAllProviders() ),
					)
				);
			}
		}
		return $this->oauth_reddit;
	}

	/**
	 * Store Reddit image to file repository.
	 */
	private function store_reddit_image( string $image_url, ExecutionContext $context, string $item_id ): ?array {
		$url_path  = wp_parse_url( $image_url, PHP_URL_PATH );
		$extension = $url_path ? pathinfo( $url_path, PATHINFO_EXTENSION ) : 'jpg';
		if ( empty( $extension ) ) {
			$extension = 'jpg';
		}
		$filename = "reddit_image_{$item_id}.{$extension}";

		$options = array(
			'timeout'    => 30,
			'user_agent' => 'php:DataMachineWPPlugin:v' . DATAMACHINE_VERSION,
		);

		return $context->downloadFile( $image_url, $filename, $options );
	}

	/**
	 * Fetch Reddit posts with timeframe and keyword filtering.
	 *
	 * Delegates to FetchRedditAbility for core logic.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$oauth_reddit = $this->get_oauth_reddit();
		if ( ! $oauth_reddit ) {
			$context->log( 'error', 'Reddit: Authentication not configured' );
			return array();
		}

		$reddit_account   = $oauth_reddit->get_account();
		$access_token     = $reddit_account['access_token'] ?? null;
		$token_expires_at = $reddit_account['token_expires_at'] ?? 0;
		$needs_refresh    = empty( $access_token ) || time() >= ( $token_expires_at - 300 );

		if ( $needs_refresh && empty( $reddit_account['refresh_token'] ) ) {
			$context->log( 'error', 'Reddit: No refresh token available' );
			return array();
		}

		if ( $needs_refresh ) {
			$context->log( 'debug', 'Reddit: Attempting token refresh.' );
			$refreshed = $oauth_reddit->refresh_token();

			if ( ! $refreshed ) {
				$context->log( 'error', 'Reddit: Token refresh failed.' );
				return array();
			}

			$reddit_account = $oauth_reddit->get_account();
			if ( empty( $reddit_account['access_token'] ) ) {
				$context->log( 'error', 'Reddit: Token refresh successful, but failed to retrieve new token data.' );
				return array();
			}
			$context->log( 'debug', 'Reddit: Token refresh successful.' );
		}

		$access_token = $reddit_account['access_token'] ?? null;
		if ( empty( $access_token ) ) {
			$context->log( 'error', 'Reddit: Access token is still empty after checks/refresh.' );
			return array();
		}

		// Build processed items array from context
		$processed_items = array();
		// Note: The ability will check processed items internally

		// Delegate to ability
		$ability_input = array(
			'subreddit'         => $config['subreddit'] ?? '',
			'access_token'      => $access_token,
			'sort_by'           => $config['sort_by'] ?? 'hot',
			'timeframe_limit'   => $config['timeframe_limit'] ?? 'all_time',
			'min_upvotes'       => isset( $config['min_upvotes'] ) ? absint( $config['min_upvotes'] ) : 0,
			'min_comment_count' => isset( $config['min_comment_count'] ) ? absint( $config['min_comment_count'] ) : 0,
			'comment_count'     => isset( $config['comment_count'] ) ? absint( $config['comment_count'] ) : 0,
			'search'            => $config['search'] ?? '',
			'processed_items'   => $processed_items,
			'fetch_batch_size'  => 100,
			'max_pages'         => 5,
			'download_images'   => true,
		);

		$ability = new FetchRedditAbility();
		$result  = $ability->execute( $ability_input );

		// Log ability logs
		if ( ! empty( $result['logs'] ) && is_array( $result['logs'] ) ) {
			foreach ( $result['logs'] as $log_entry ) {
				$context->log(
					$log_entry['level'] ?? 'debug',
					$log_entry['message'] ?? '',
					$log_entry['data'] ?? array()
				);
			}
		}

		if ( ! $result['success'] ) {
			return array();
		}

		// If no data returned, return empty
		if ( empty( $result['data'] ) ) {
			return array();
		}

		$data    = $result['data'];
		$item_id = $result['item_id'] ?? ( $data['metadata']['original_id'] ?? '' );

		// Mark item as processed
		if ( $item_id ) {
			$context->markItemProcessed( $item_id );
		}

		// Download image if present
		if ( ! empty( $data['image_info'] ) && ! empty( $data['image_info']['url'] ) ) {
			$stored_image = $this->store_reddit_image( $data['image_info']['url'], $context, $item_id );
			if ( $stored_image ) {
				$data['file_info'] = array(
					'file_path' => $stored_image['path'],
					'file_name' => $stored_image['filename'],
					'mime_type' => $data['image_info']['mime_type'] ?? 'application/octet-stream',
					'file_size' => $stored_image['size'],
				);
			}
			unset( $data['image_info'] );
		}

		// Store engine data
		$context->storeEngineData(
			array(
				'source_url'      => $result['source_url'] ?? '',
				'image_file_path' => $data['file_info']['file_path'] ?? '',
			)
		);

		return $data;
	}

	public static function get_label(): string {
		return 'Reddit Subreddit';
	}
}
