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
use DataMachine\Core\Steps\Fetch\FreshCandidateCollector;
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
			null,
			null,
			array(
				'charLimit' => 40000,
				'scopes'    => 'identity read',
			)
		);
	}

	/**
	 * Lazy-load auth provider when needed.
	 *
	 * @return RedditAuth|null Auth provider instance or null if unavailable
	 */
	private function get_oauth_reddit() {
		if ( null === $this->oauth_reddit ) {
			$this->oauth_reddit = $this->getAuthProvider( 'reddit' );

			if ( null === $this->oauth_reddit ) {
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
	 * Delegates to FetchRedditAbility for Reddit-specific concerns
	 * (pagination, API parameters, source filtering, identifier extraction)
	 * and to the Data Machine core FreshCandidateCollector primitive for
	 * processed/claimed/reprocess eligibility decisions.
	 *
	 * The collector lets pagination keep scanning past already-processed
	 * top-of-feed results until it has enough fresh candidates for this
	 * fetch cycle, instead of returning a no-item run when the most recent
	 * subreddit posts have all already been imported.
	 *
	 * Final dedupe/claim/cap remain authoritative inside
	 * `FetchHandler::get_fetch_data()`.
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

		// Build the core fresh-candidate collector. Selection-time eligibility
		// (processed/claimed/reprocess) is decided by Data Machine core, not by
		// Reddit. The ability paginates Reddit and offers each candidate to the
		// collector; the collector stops the scan once we have enough fresh
		// candidates to satisfy this fetch cycle's max_items target.
		$max_items = (int) ( $config['max_items'] ?? $this->getDefaultMaxItems() );
		$collector = new FreshCandidateCollector( $context, $max_items );

		// Delegate Reddit-specific concerns to the ability.
		$ability_input = array(
			'subreddit'         => $config['subreddit'] ?? '',
			'access_token'      => $access_token,
			'sort_by'           => $config['sort_by'] ?? 'hot',
			'timeframe_limit'   => $config['timeframe_limit'] ?? 'all_time',
			'min_upvotes'       => isset( $config['min_upvotes'] ) ? absint( $config['min_upvotes'] ) : 0,
			'min_comment_count' => isset( $config['min_comment_count'] ) ? absint( $config['min_comment_count'] ) : 0,
			'comment_count'     => isset( $config['comment_count'] ) ? absint( $config['comment_count'] ) : 0,
			'search'            => $config['search'] ?? '',
			'fetch_batch_size'  => 100,
			'max_pages'         => 5,
			'download_images'   => true,
		);

		$ability = new FetchRedditAbility();
		$result  = $ability->executeWithCollector( $ability_input, $collector );

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

		// Surface collector diagnostics so an operator can tell whether a
		// no-item run was caused by full top-of-feed processing vs. natural
		// source exhaustion.
		$context->log(
			'debug',
			'Reddit: Fresh-candidate scan complete',
			$collector->getDiagnostics()
		);

		// No eligible items after selection-time filtering.
		if ( empty( $result['items'] ) ) {
			return array();
		}

		$eligible_items = array();

		foreach ( $result['items'] as $item ) {
			$data    = $item['data'];
			$item_id = $item['item_id'] ?? ( $data['metadata']['original_id'] ?? '' );

			// Wire the canonical Data Machine dedupe key so
			// FetchHandler::get_fetch_data() can run final
			// dedupe/claim/cap on these items.
			if ( $item_id ) {
				$data['metadata']['item_identifier'] = (string) $item_id;
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
