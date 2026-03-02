<?php
/**
 * Fetch Reddit posts with timeframe and keyword filtering.
 *
 * Migrated from data-machine core to data-machine-socials.
 * Uses get_valid_access_token() for automatic token lifecycle management
 * instead of manual expiry checks.
 *
 * @package    DataMachineSocials
 * @subpackage Handlers\Reddit
 * @since      0.3.0
 */

namespace DataMachineSocials\Handlers\Reddit;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineSocials\Abilities\Reddit\FetchRedditAbility;

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
	 * @return RedditAuth|null Auth provider instance or null if unavailable
	 */
	private function get_oauth_reddit() {
		if ( $this->oauth_reddit === null ) {
			$this->oauth_reddit = $this->getAuthProvider( 'reddit' );

			if ( $this->oauth_reddit === null ) {
				$auth_abilities = new \DataMachine\Abilities\AuthAbilities();
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
	 * Delegates to FetchRedditAbility for core logic. Returns all eligible
	 * posts as raw arrays for batch fan-out.
	 *
	 * Uses get_valid_access_token() for automatic token lifecycle management.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$oauth_reddit = $this->get_oauth_reddit();
		if ( ! $oauth_reddit ) {
			$context->log( 'error', 'Reddit: Authentication not configured' );
			return array();
		}

		// Use the modern token lifecycle — handles expiry checks and refresh automatically.
		$access_token = $oauth_reddit->get_valid_access_token();
		if ( empty( $access_token ) ) {
			$context->log( 'error', 'Reddit: Failed to obtain valid access token (expired or refresh failed)' );
			return array();
		}

		// Build processed items array from context
		$processed_items = array();

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

		// No eligible items
		if ( empty( $result['items'] ) ) {
			return array();
		}

		$eligible_items = array();

		foreach ( $result['items'] as $item ) {
			$data    = $item['data'];
			$item_id = $item['item_id'] ?? ( $data['metadata']['original_id'] ?? '' );

			// Set dedup_key for centralized dedup in FetchHandler::dedup().
			if ( $item_id ) {
				$data['metadata']['dedup_key'] = $item_id;
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

			// Per-item engine data for batch fan-out.
			// PipelineBatchScheduler seeds _engine_data into each child job.
			$data['metadata']['_engine_data'] = array_filter(
				array(
					'source_url'      => $item['source_url'] ?? '',
					'image_file_path' => $data['file_info']['file_path'] ?? '',
				),
				static function ( $value ) {
					return '' !== $value && null !== $value;
				}
			);

			$eligible_items[] = $data;
		}

		if ( empty( $eligible_items ) ) {
			return array();
		}

		$context->log(
			'info',
			sprintf( 'Reddit: Returning %d eligible posts for batch fan-out', count( $eligible_items ) )
		);

		return array( 'items' => $eligible_items );
	}

	public static function get_label(): string {
		return 'Reddit Subreddit';
	}
}
